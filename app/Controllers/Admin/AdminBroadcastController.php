<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\EmailBroadcast;
use Wwm\Models\EmailMessage;
use Wwm\Models\User;
use Wwm\Services\AdminStudentListFilter;
use Wwm\Services\BroadcastAudience;
use Wwm\Services\BroadcastHtmlSanitizer;
use Wwm\Services\BroadcastImageUpload;
use Wwm\Services\BroadcastRunner;
use Wwm\Services\CourseCatalog;
use Wwm\Services\CourseWriter;
use Wwm\Services\Mailer;

final class AdminBroadcastController
{
    public function index(): void
    {
        $userId = Session::requireAdminBroadcasts();
        $user = User::findById(wwm_pdo(), $userId);

        $pdo = wwm_pdo();
        $broadcasts = EmailBroadcast::listRecent($pdo);
        $ids = array_map(static fn (array $row): int => (int)$row['id'], $broadcasts);
        $engagementById = EmailMessage::broadcastEngagementSummaries($pdo, $ids);
        $plannedCounts = [];
        foreach ($broadcasts as $row) {
            if ((string)($row['status'] ?? '') !== 'scheduled') {
                continue;
            }
            $plannedCounts[(int)$row['id']] = BroadcastAudience::countForBroadcast(
                $pdo,
                (string)($row['audience'] ?? 'all_students'),
                EmailBroadcast::decodeAudienceFilter((string)($row['audience_filter_json'] ?? ''))
            );
        }

        wwm_render_admin('broadcasts', [
            'title' => 'Broadcasts — Admin',
            'adminNav' => 'broadcasts',
            'user' => $user,
            'broadcasts' => $broadcasts,
            'engagementById' => $engagementById,
            'plannedCounts' => $plannedCounts,
            'message' => $this->flashMessage(),
            'error' => $this->errorMessage((string)($_GET['error'] ?? '')),
        ]);
    }

    public function createForm(): void
    {
        $userId = Session::requireAdminBroadcasts();
        $user = User::findById(wwm_pdo(), $userId);

        wwm_render_admin('broadcast-form', $this->formViewVars($user, null, '/admin/broadcasts'));
    }

    public function audiencePreview(): void
    {
        Session::requireAdminBroadcasts();
        $pdo = wwm_pdo();
        $audience = EmailBroadcast::normalizeAudience((string)($_GET['audience'] ?? 'all_students'));
        $filter = AdminStudentListFilter::fromBroadcastSource($_GET);
        $count = BroadcastAudience::countForBroadcast($pdo, $audience, $filter);

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['count' => $count], JSON_UNESCAPED_UNICODE);
    }

    public function uploadImage(): void
    {
        Session::requireAdminBroadcasts();
        header('Content-Type: application/json; charset=UTF-8');

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            http_response_code(403);
            echo json_encode(['error' => 'Session expired. Refresh the page and try again.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $result = BroadcastImageUpload::storeFromUpload($_FILES['image'] ?? []);
        if (!$result['ok']) {
            http_response_code(400);
            echo json_encode(['error' => $result['error']], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode([
            'url' => $result['url'],
            'path' => $result['path'],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function store(): void
    {
        Session::requireAdminBroadcasts();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/broadcasts/new?error=csrf');
        }

        $data = $this->dataFromPost();
        if ($data['error'] !== null) {
            $this->renderFormError(null, $data['error'], $data);
            return;
        }

        $adminId = Session::userId();
        $id = EmailBroadcast::createDraft(wwm_pdo(), $data, $adminId);

        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'schedule') {
            $at = $this->scheduledAtFromPost();
            if ($at === null) {
                wwm_redirect('/admin/broadcasts/' . $id . '/edit?error=schedule');
            }
            EmailBroadcast::markScheduled(wwm_pdo(), $id, $at);
            wwm_redirect('/admin/broadcasts/' . $id . '?scheduled=1');
        }

        if ($action === 'send') {
            $this->startSend($id);
            return;
        }

        wwm_redirect('/admin/broadcasts/' . $id . '/edit?saved=1');
    }

    public function show(int $id): void
    {
        $userId = Session::requireAdminBroadcasts();
        $user = User::findById(wwm_pdo(), $userId);
        $broadcast = EmailBroadcast::find(wwm_pdo(), $id);
        if ($broadcast === null) {
            $this->notFound();
            return;
        }

        $pdo = wwm_pdo();
        $pendingCount = 0;
        if ((string)$broadcast['status'] === 'sending') {
            $pendingStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM broadcast_recipients WHERE broadcast_id = ? AND status = \'pending\''
            );
            $pendingStmt->execute([$id]);
            $pendingCount = (int)$pendingStmt->fetchColumn();
        }
        $audienceFilter = EmailBroadcast::decodeAudienceFilter((string)($broadcast['audience_filter_json'] ?? ''));
        $engagement = EmailMessage::broadcastEngagementSummary($pdo, $id);
        $linkStats = EmailMessage::broadcastLinkStats($pdo, $id);
        $linkSort = (string)($_GET['lsort'] ?? '');
        if (!in_array($linkSort, ['url', 'unique', 'total'], true)) {
            $linkSort = '';
        }
        $linkDir = (string)($_GET['ldir'] ?? '') === 'asc' ? 'asc' : 'desc';
        if ($linkSort !== '') {
            $linkStats = self::sortLinkStats($linkStats, $linkSort, $linkDir);
        }
        $recipientTotal = EmailMessage::broadcastRecipientCount($pdo, $id);
        $recipientPageSize = 100;
        $recipientPages = max(1, (int)ceil($recipientTotal / $recipientPageSize));
        $recipientPage = max(1, (int)($_GET['rpage'] ?? 1));
        if ($recipientPage > $recipientPages) {
            $recipientPage = $recipientPages;
        }
        $recipientSort = (string)($_GET['rsort'] ?? '');
        if (!in_array($recipientSort, ['email', 'delivery', 'opened', 'clicks'], true)) {
            $recipientSort = '';
        }
        $recipientDir = (string)($_GET['rdir'] ?? '') === 'desc' ? 'desc' : 'asc';
        $recipientEngagement = EmailMessage::broadcastRecipientEngagement(
            $pdo,
            $id,
            $recipientPageSize,
            ($recipientPage - 1) * $recipientPageSize,
            $recipientSort,
            $recipientDir
        );

        wwm_render_admin('broadcast-view', [
            'title' => 'Broadcast #' . $id . ' — Admin',
            'adminNav' => 'broadcasts',
            'user' => $user,
            'broadcast' => $broadcast,
            'engagement' => $engagement,
            'linkStats' => $linkStats,
            'linkSort' => $linkSort,
            'linkDir' => $linkDir,
            'linksOpen' => $linkSort !== '',
            'recipientEngagement' => $recipientEngagement,
            'recipientTotal' => $recipientTotal,
            'recipientPage' => $recipientPage,
            'recipientPages' => $recipientPages,
            'recipientSort' => $recipientSort,
            'recipientDir' => $recipientDir,
            'recipientsOpen' => isset($_GET['rpage']) || $recipientSort !== '',
            'message' => $this->flashMessage(),
            'error' => $this->errorMessage((string)($_GET['error'] ?? '')),
            'pendingCount' => $pendingCount,
            'audienceSize' => BroadcastAudience::countForBroadcast(
                $pdo,
                (string)$broadcast['audience'],
                $audienceFilter
            ),
            'broadcastFrom' => Mailer::broadcastFromAddress(),
            'broadcastOwnMailbox' => Mailer::broadcastUsesOwnMailbox(),
        ]);
    }

    public function edit(int $id): void
    {
        $userId = Session::requireAdminBroadcasts();
        $user = User::findById(wwm_pdo(), $userId);
        $broadcast = EmailBroadcast::find(wwm_pdo(), $id);
        if ($broadcast === null) {
            $this->notFound();
            return;
        }
        if (!in_array((string)$broadcast['status'], ['draft', 'scheduled'], true)) {
            wwm_redirect('/admin/broadcasts/' . $id);
        }

        wwm_render_admin('broadcast-form', array_merge(
            $this->formViewVars($user, $broadcast, '/admin/broadcasts/' . $id),
            [
                'message' => $this->flashMessage(),
                'error' => $this->errorMessage((string)($_GET['error'] ?? '')),
            ]
        ));
    }

    public function update(int $id): void
    {
        Session::requireAdminBroadcasts();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/broadcasts/' . $id . '/edit?error=csrf');
        }

        $broadcast = EmailBroadcast::find(wwm_pdo(), $id);
        if ($broadcast === null) {
            $this->notFound();
            return;
        }

        $data = $this->dataFromPost();
        if ($data['error'] !== null) {
            $this->renderFormError($broadcast, $data['error'], $data);
            return;
        }

        $scheduledAt = (string)$broadcast['scheduled_at'];
        if ((string)($_POST['action'] ?? '') === 'schedule') {
            $at = $this->scheduledAtFromPost();
            if ($at === null) {
                wwm_redirect('/admin/broadcasts/' . $id . '/edit?error=schedule');
            }
            $scheduledAt = $at;
        }

        EmailBroadcast::update(wwm_pdo(), $id, [
            'title' => $data['title'],
            'subject' => $data['subject'],
            'body_text' => $data['body_text'],
            'body_html' => $data['body_html'],
            'audience' => $data['audience'],
            'audience_filter_json' => $data['audience_filter_json'],
            'scheduled_at' => $scheduledAt !== '' ? $scheduledAt : null,
        ]);

        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'schedule') {
            $at = $this->scheduledAtFromPost();
            if ($at !== null) {
                EmailBroadcast::markScheduled(wwm_pdo(), $id, $at);
            }
            wwm_redirect('/admin/broadcasts/' . $id . '?scheduled=1');
        }

        if ($action === 'send') {
            $this->startSend($id);
            return;
        }

        wwm_redirect('/admin/broadcasts/' . $id . '/edit?saved=1');
    }

    public function send(int $id): void
    {
        Session::requireAdminBroadcasts();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/broadcasts/' . $id . '?error=csrf');
        }
        $this->startSend($id);
    }

    public function duplicate(int $id): void
    {
        $adminId = Session::requireAdminBroadcasts();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/broadcasts/' . $id . '?error=csrf');
        }

        $newId = EmailBroadcast::duplicate(wwm_pdo(), $id, $adminId);
        if ($newId === null) {
            $this->notFound();
            return;
        }

        wwm_redirect('/admin/broadcasts/' . $newId . '/edit?copied=1');
    }

    public function destroy(int $id): void
    {
        Session::requireAdminBroadcasts();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/broadcasts/' . $id . '?error=csrf');
        }

        if (!EmailBroadcast::deleteIfUnsent(wwm_pdo(), $id)) {
            wwm_redirect('/admin/broadcasts/' . $id . '?error=not_deletable');
        }

        wwm_redirect('/admin/broadcasts?deleted=1');
    }

    public function cancel(int $id): void
    {
        Session::requireAdminBroadcasts();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/broadcasts/' . $id . '?error=csrf');
        }
        EmailBroadcast::markCancelled(wwm_pdo(), $id);
        wwm_redirect('/admin/broadcasts/' . $id . '?cancelled=1');
    }

    public function testSend(int $id): void
    {
        $adminId = Session::requireAdminBroadcasts();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/broadcasts/' . $id . '/edit?error=csrf');
        }

        $broadcast = EmailBroadcast::find(wwm_pdo(), $id);
        $admin = User::findById(wwm_pdo(), $adminId);
        if ($broadcast === null || !is_array($admin)) {
            $this->notFound();
            return;
        }

        $email = strtolower(trim((string)$admin['email']));
        $name = trim((string)($admin['name'] ?? ''));
        $send = BroadcastRunner::sendToRecipient($broadcast, $adminId, $email, $name);
        wwm_redirect('/admin/broadcasts/' . $id . '/edit?' . ($send['ok'] ? 'test_ok=1' : 'test_fail=1'));
    }

    /**
     * @param list<array{target_url: string, link_label: string, total_clicks: int, unique_clickers: int}> $rows
     * @return list<array{target_url: string, link_label: string, total_clicks: int, unique_clickers: int}>
     */
    private static function sortLinkStats(array $rows, string $sort, string $dir): array
    {
        $factor = $dir === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($sort, $factor): int {
            $cmp = match ($sort) {
                'unique' => ((int)$a['unique_clickers']) <=> ((int)$b['unique_clickers']),
                'total' => ((int)$a['total_clicks']) <=> ((int)$b['total_clicks']),
                default => strcasecmp((string)$a['target_url'], (string)$b['target_url']),
            };

            return $cmp * $factor;
        });

        return $rows;
    }

    private function startSend(int $id): void
    {
        $broadcast = EmailBroadcast::find(wwm_pdo(), $id);
        if ($broadcast === null) {
            $this->notFound();
            return;
        }

        $status = (string)$broadcast['status'];
        if (!in_array($status, ['draft', 'scheduled'], true)) {
            wwm_redirect('/admin/broadcasts/' . $id);
        }

        $pdo = wwm_pdo();
        $filter = EmailBroadcast::decodeAudienceFilter((string)($broadcast['audience_filter_json'] ?? ''));
        $count = BroadcastAudience::countForBroadcast($pdo, (string)$broadcast['audience'], $filter);
        $confirmed = filter_var($_POST['confirm_count'] ?? null, FILTER_VALIDATE_INT);
        $back = (string)($_POST['from'] ?? '') === 'view'
            ? '/admin/broadcasts/' . $id
            : '/admin/broadcasts/' . $id . '/edit';
        if ($count < 1) {
            wwm_redirect($back . '?error=empty');
        }
        if ($confirmed === false || (int)$confirmed !== $count) {
            wwm_redirect($back . '?error=audience');
        }

        BroadcastRunner::beginSending($pdo, $id);

        wwm_redirect('/admin/broadcasts/' . $id . '?sending=1');
    }

    /**
     * @return array{title: string, subject: string, body_text: string, body_html: string, content_mode: string, audience: string, audience_filter_json: string, listFilter: AdminStudentListFilter, error: ?string}
     */
    private function dataFromPost(): array
    {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $bodyText = trim((string)($_POST['body_text'] ?? ''));
        $bodyHtml = BroadcastHtmlSanitizer::sanitize((string)($_POST['body_html'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $audience = EmailBroadcast::normalizeAudience((string)($_POST['audience'] ?? 'all_students'));
        $contentMode = (string)($_POST['content_mode'] ?? 'plain') === 'html' ? 'html' : 'plain';
        $listFilter = AdminStudentListFilter::fromBroadcastSource($_POST);
        $filterJson = ($audience === 'filtered' || $listFilter->isActive())
            ? EmailBroadcast::encodeAudienceFilter($listFilter)
            : '';

        if ($contentMode === 'plain') {
            $bodyHtml = '';
        } else {
            if ($bodyHtml === '' && $bodyText !== '') {
                $bodyHtml = '<p>' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
            if ($bodyText === '' && $bodyHtml !== '') {
                $bodyText = BroadcastHtmlSanitizer::plainTextFromHtml($bodyHtml);
            }
        }

        $base = [
            'title' => $title,
            'subject' => $subject,
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'content_mode' => $contentMode,
            'audience' => $audience,
            'audience_filter_json' => $filterJson,
            'listFilter' => $listFilter,
            'error' => null,
        ];

        if ($subject === '') {
            return array_merge($base, ['error' => 'Subject is required.']);
        }
        if ($contentMode === 'plain' && $bodyText === '') {
            return array_merge($base, ['error' => 'Message body is required.']);
        }
        if ($contentMode === 'html' && $bodyHtml === '') {
            return array_merge($base, ['error' => 'HTML body is required in HTML mode.']);
        }

        return $base;
    }

    private function scheduledAtFromPost(): ?string
    {
        $local = trim((string)($_POST['scheduled_at'] ?? ''));
        if ($local === '') {
            return null;
        }
        $tz = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $local, $tz);
        if ($dt === false) {
            return null;
        }
        $now = new \DateTimeImmutable('now', $tz);
        if ($dt <= $now) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('c');
    }

    private function flashMessage(): ?string
    {
        if (isset($_GET['copied'])) {
            return 'Copy saved as a new draft. Nothing has been sent.';
        }
        if (isset($_GET['deleted'])) {
            return 'Unsent broadcast deleted.';
        }
        if (isset($_GET['saved'])) {
            return 'Draft saved.';
        }
        if (isset($_GET['scheduled'])) {
            return 'Broadcast scheduled.';
        }
        if (isset($_GET['sending'])) {
            return 'Sending started. The queue goes out in the background. Refresh this page to see progress.';
        }
        if (isset($_GET['cancelled'])) {
            return 'Broadcast cancelled.';
        }
        if (isset($_GET['test_ok'])) {
            return 'Test email sent to your admin address.';
        }
        if (isset($_GET['test_fail'])) {
            return 'Test send failed: ' . (Mailer::lastError() ?? 'unknown error');
        }

        return null;
    }

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'csrf' => 'The form expired. Reload the page and try again.',
            'schedule' => 'Pick a future date and time to schedule.',
            'audience' => 'The recipient count changed. Check the number and confirm again.',
            'empty' => 'There is nobody to send to.',
            'not_deletable' => 'Only a draft or a scheduled broadcast that has not started sending can be deleted.',
            default => '',
        };
    }

    /**
     * @param array<string, mixed>|null $broadcast
     * @param array{title: string, subject: string, body_text: string, body_html: string, audience: string} $data
     */
    private function renderFormError(?array $broadcast, string $error, array $data): void
    {
        $userId = Session::requireAdminBroadcasts();
        $user = User::findById(wwm_pdo(), $userId);
        $merged = $broadcast ?? [];
        $merged['title'] = $data['title'];
        $merged['subject'] = $data['subject'];
        $merged['body_text'] = $data['body_text'];
        $merged['body_html'] = $data['body_html'];
        $merged['audience'] = $data['audience'];
        $merged['audience_filter_json'] = $data['audience_filter_json'];

        wwm_render_admin('broadcast-form', array_merge(
            $this->formViewVars(
                $user,
                $merged,
                $broadcast === null ? '/admin/broadcasts' : '/admin/broadcasts/' . (int)$broadcast['id'],
                $data['listFilter'],
                $data['content_mode']
            ),
            ['error' => $error]
        ));
    }

    /**
     * @param array<string, mixed>|null $broadcast
     * @return array<string, mixed>
     */
    private function formViewVars(
        array $user,
        ?array $broadcast,
        string $formAction,
        ?AdminStudentListFilter $listFilter = null,
        ?string $contentMode = null,
    ): array {
        $pdo = wwm_pdo();
        if ($listFilter === null) {
            if (is_array($broadcast) && (string)($broadcast['audience'] ?? '') === 'filtered') {
                $listFilter = EmailBroadcast::decodeAudienceFilter((string)($broadcast['audience_filter_json'] ?? ''));
            } else {
                $listFilter = AdminStudentListFilter::fromArray([]);
            }
        }

        $audience = EmailBroadcast::normalizeAudience(
            is_array($broadcast) ? (string)($broadcast['audience'] ?? 'all_students') : 'all_students'
        );
        if ($contentMode === null) {
            $html = trim((string)(is_array($broadcast) ? ($broadcast['body_html'] ?? '') : ''));
            $contentMode = $html !== '' ? 'html' : 'plain';
        }

        return [
            'title' => is_array($broadcast) ? 'Edit broadcast — Admin' : 'New broadcast — Admin',
            'adminNav' => 'broadcasts',
            'user' => $user,
            'broadcast' => $broadcast,
            'formAction' => $formAction,
            'filterCourses' => $this->publishedCourses(),
            'listFilter' => $listFilter,
            'contentMode' => $contentMode,
            'audienceSize' => BroadcastAudience::countForBroadcast($pdo, $audience, $listFilter),
            'broadcastFrom' => Mailer::broadcastFromAddress(),
            'broadcastOwnMailbox' => Mailer::broadcastUsesOwnMailbox(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publishedCourses(): array
    {
        $published = [];
        foreach ((new CourseCatalog())->all() as $course) {
            if (CourseWriter::isPublished($course)) {
                $published[] = $course;
            }
        }

        return $published;
    }

    private function notFound(): void
    {
        http_response_code(404);
        wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Broadcast not found.']);
    }
}
