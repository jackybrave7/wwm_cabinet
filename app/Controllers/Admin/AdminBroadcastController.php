<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\EmailBroadcast;
use Wwm\Models\User;
use Wwm\Services\AdminStudentListFilter;
use Wwm\Services\BroadcastAudience;
use Wwm\Services\BroadcastHtmlSanitizer;
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

        wwm_render_admin('broadcasts', [
            'title' => 'Broadcasts — Admin',
            'adminNav' => 'broadcasts',
            'user' => $user,
            'broadcasts' => EmailBroadcast::listRecent(wwm_pdo()),
            'message' => $this->flashMessage(),
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

        if ((string)$broadcast['status'] === 'sending') {
            BroadcastRunner::processBatch(wwm_pdo(), $id, BroadcastRunner::BATCH_SIZE);
            $broadcast = EmailBroadcast::find(wwm_pdo(), $id) ?? $broadcast;
        }

        wwm_render_admin('broadcast-view', [
            'title' => 'Broadcast #' . $id . ' — Admin',
            'adminNav' => 'broadcasts',
            'user' => $user,
            'broadcast' => $broadcast,
            'message' => $this->flashMessage(),
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
                'error' => (string)($_GET['error'] ?? ''),
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
        $ok = BroadcastRunner::sendToRecipient($broadcast, $adminId, $email, $name);
        wwm_redirect('/admin/broadcasts/' . $id . '/edit?' . ($ok ? 'test_ok=1' : 'test_fail=1'));
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

        BroadcastRunner::beginSending(wwm_pdo(), $id);
        $loops = 0;
        while ($loops < 5) {
            $result = BroadcastRunner::processBatch(wwm_pdo(), $id, BroadcastRunner::BATCH_SIZE);
            $loops++;
            if ($result['done']) {
                break;
            }
        }

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
        $filterJson = $audience === 'filtered'
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
        if (isset($_GET['saved'])) {
            return 'Draft saved.';
        }
        if (isset($_GET['scheduled'])) {
            return 'Broadcast scheduled.';
        }
        if (isset($_GET['sending'])) {
            return 'Sending started. Refresh this page to see progress.';
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
