<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\EmailAutomation;
use Wwm\Models\EmailAutomationStepEvent;
use Wwm\Models\User;
use Wwm\Models\EmailBroadcast;
use Wwm\Services\AdminStudentListFilter;
use Wwm\Services\AvoAutomationImporter;
use Wwm\Services\BroadcastAudience;
use Wwm\Services\CourseCatalog;
use Wwm\Services\EmailAutomationEnrollment;
use Wwm\Services\EmailAutomationNodeCatalog;
use Wwm\Services\EmailAutomationRunner;

final class AdminAutomationController
{
    public function index(): void
    {
        Session::requireSuperAdmin();
        $pdo = wwm_pdo();
        $archivedView = isset($_GET['view']) && (string)$_GET['view'] === 'archived';

        wwm_render_admin('automations', [
            'title' => 'Email automations — Admin',
            'adminNav' => 'automations',
            'automations' => EmailAutomation::listAll($pdo, $archivedView),
            'archivedView' => $archivedView,
            'entryModeLabels' => EmailAutomation::entryModeLabels(),
            'courseSlugs' => $this->courseSlugOptions(),
            'message' => $this->listFlashMessage(),
        ]);
    }

    public function create(): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/automations?error=csrf');
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $entryMode = EmailAutomation::normalizeEntryMode((string)($_POST['entry_mode'] ?? ''));
        $courseSlug = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['course_slug'] ?? '')) ?: '';

        $pdo = wwm_pdo();
        try {
            $id = EmailAutomation::createBlank($pdo, $title, $entryMode, $courseSlug);
        } catch (\InvalidArgumentException $e) {
            wwm_redirect('/admin/automations?error=course_required');
        }

        wwm_redirect('/admin/automations/' . $id . '/edit?created=1');
    }

    public function edit(int $id): void
    {
        Session::requireSuperAdmin();
        $pdo = wwm_pdo();
        $row = EmailAutomation::find($pdo, $id);
        if ($row === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $definition = json_decode((string)$row['definition_json'], true);
        if (!is_array($definition)) {
            $definition = [];
        }

        $nodeStats = EmailAutomationStepEvent::statsByNode($pdo, $id);
        $stepStatsByNode = [];
        foreach ($nodeStats as $stat) {
            $stepStatsByNode[(string)$stat['node_id']] = $stat;
        }

        $runsStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM email_automation_runs WHERE automation_id = ? AND status = \'active\''
        );
        $runsStmt->execute([$id]);
        $activeRuns = (int)$runsStmt->fetchColumn();

        wwm_render_admin('automation-edit', [
            'title' => 'Edit automation — Admin',
            'adminNav' => 'automations',
            'automation' => $row,
            'flowDefinition' => $definition,
            'flowEditorConfig' => EmailAutomationNodeCatalog::editorConfig(),
            'flowStepStats' => $stepStatsByNode,
            'definitionPretty' => $this->prettyJson((string)$row['definition_json']),
            'nodeStats' => $nodeStats,
            'stepEventTotal' => EmailAutomationStepEvent::totalEvents($pdo, $id),
            'flowActiveRuns' => $activeRuns,
            'isArchivedFlow' => EmailAutomation::isArchived($row),
            'entryModeLabels' => EmailAutomation::entryModeLabels(),
            'courseSlugs' => $this->courseSlugOptions(),
            'message' => $this->flashMessage(),
            'error' => (string)($_GET['error'] ?? ''),
        ]);
    }

    public function enrollAudience(int $id): void
    {
        Session::requireSuperAdmin();
        $filter = AdminStudentListFilter::fromBroadcastSource($_POST);
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            $this->redirectStudentsAutomationLaunch($filter, null, 'csrf');
            return;
        }

        $pdo = wwm_pdo();
        $row = EmailAutomation::find($pdo, $id);
        if ($row === null) {
            $this->redirectStudentsAutomationLaunch($filter, null, 'not_found');
            return;
        }
        if (EmailAutomation::isArchived($row)) {
            $this->redirectStudentsAutomationLaunch($filter, null, 'bulk_archived');
            return;
        }
        if (empty($row['is_active'])) {
            $this->redirectStudentsAutomationLaunch($filter, null, 'bulk_inactive');
            return;
        }

        $audience = EmailBroadcast::normalizeAudience((string)($_POST['audience'] ?? 'filtered'));
        if ($audience === 'filtered' && !$filter->isActive()) {
            $this->redirectStudentsAutomationLaunch($filter, null, 'filter_required');
            return;
        }

        $userIds = BroadcastAudience::recipientUserIds($pdo, $audience, $filter);
        if ($userIds === []) {
            $this->redirectStudentsAutomationLaunch($filter, null, 'bulk_none');
            return;
        }
        if (count($userIds) > EmailAutomationEnrollment::BULK_ENROLL_LIMIT) {
            $this->redirectStudentsAutomationLaunch($filter, null, 'bulk_limit');
            return;
        }

        $courseContext = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['enroll_course_slug'] ?? '')) ?: '';
        if ($courseContext === '') {
            $courseContext = preg_replace('/[^a-z0-9\-]/', '', (string)($row['course_slug'] ?? '')) ?: '';
        }

        $stats = EmailAutomationEnrollment::enrollBulk($id, $userIds, $courseContext);
        $this->redirectStudentsAutomationLaunch($filter, $stats);
    }

    public function save(int $id): void
    {
        Session::requireSuperAdmin();
        $jsonSave = $this->wantsAutomationJsonSave();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            $this->finishAutomationSave($id, $jsonSave, false, 'csrf');
            return;
        }

        $pdo = wwm_pdo();
        $row = EmailAutomation::find($pdo, $id);
        if ($row === null) {
            if ($jsonSave) {
                $this->automationSaveJson(false, 'not_found', 404);
            }
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $definitionRaw = (string)($_POST['definition_json'] ?? '');
        $definition = json_decode($definitionRaw, true);
        if (!is_array($definition)) {
            $this->finishAutomationSave($id, $jsonSave, false, 'invalid_json');
            return;
        }

        try {
            AvoAutomationImporter::validateDefinition($definition);
        } catch (\InvalidArgumentException $e) {
            $this->finishAutomationSave($id, $jsonSave, false, 'invalid_definition', $e->getMessage());
            return;
        }

        if (isset($definition['nodes']) && is_array($definition['nodes'])) {
            foreach ($definition['nodes'] as $nodeId => $node) {
                if (!is_array($node)) {
                    unset($definition['nodes'][$nodeId]);
                    continue;
                }
                unset($definition['nodes'][$nodeId]['node_id']);
            }
        }

        $entryMode = EmailAutomation::normalizeEntryMode((string)($_POST['entry_mode'] ?? (string)($row['entry_mode'] ?? '')));
        $courseSlug = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['course_slug'] ?? '')) ?: '';
        if (EmailAutomation::entryModeRequiresCourseSlug($entryMode) && $courseSlug === '') {
            $this->finishAutomationSave($id, $jsonSave, false, 'course_required');
            return;
        }

        EmailAutomation::update($pdo, $id, [
            'title' => trim((string)($_POST['title'] ?? $row['title'])),
            'description' => trim((string)($_POST['description'] ?? '')),
            'course_slug' => $courseSlug,
            'entry_mode' => $entryMode,
            'is_active' => !empty($_POST['is_active']),
            'definition_json' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: $definitionRaw,
        ]);

        $this->finishAutomationSave($id, $jsonSave, true, '');
    }

    public function enrollStudent(int $id): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=csrf');
        }

        $pdo = wwm_pdo();
        $row = EmailAutomation::find($pdo, $id);
        if ($row === null) {
            wwm_redirect('/admin/automations?error=not_found');
        }

        $email = mb_strtolower(trim((string)($_POST['student_email'] ?? '')));
        if ($email === '') {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=enroll_email');
        }

        $user = User::findByEmail($pdo, $email);
        if ($user === null) {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=enroll_not_found');
        }

        $courseContext = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['enroll_course_slug'] ?? '')) ?: '';
        $runId = EmailAutomationEnrollment::enrollManual($id, (int)$user['id'], $courseContext);
        if ($runId === null) {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=enroll_failed');
        }

        wwm_redirect('/admin/automations/' . $id . '/edit?enrolled=1');
    }

    public function importAvo(int $id): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=csrf');
        }

        $pdo = wwm_pdo();
        $row = EmailAutomation::find($pdo, $id);
        if ($row === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $upload = $_FILES['avo_export'] ?? null;
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=upload');
        }

        $tmp = (string)($upload['tmp_name'] ?? '');
        $json = is_readable($tmp) ? (string)file_get_contents($tmp) : '';
        if ($json === '') {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=upload');
        }

        try {
            $imported = AvoAutomationImporter::fromAvoExport($json);
        } catch (\Throwable $e) {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=import');
        }

        EmailAutomation::update($pdo, $id, [
            'title' => $imported['title'],
            'definition_json' => json_encode($imported['definition'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'avo_export_json' => $json,
            'course_slug' => (string)($imported['definition']['meta']['course_slug'] ?? $row['course_slug']),
        ]);

        wwm_redirect('/admin/automations/' . $id . '/edit?imported=1');
    }

    public function stepEvents(int $id): void
    {
        Session::requireSuperAdmin();
        $pdo = wwm_pdo();
        $row = EmailAutomation::find($pdo, $id);
        if ($row === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $nodeId = trim((string)($_GET['node_id'] ?? ''));
        if ($nodeId === '') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'node_id_required'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $summary = null;
        foreach (EmailAutomationStepEvent::statsByNode($pdo, $id) as $stat) {
            if ($stat['node_id'] === $nodeId) {
                $summary = $stat;
                break;
            }
        }

        $events = EmailAutomationStepEvent::listByNode($pdo, $id, $nodeId);
        $payload = [
            'node_id' => $nodeId,
            'summary' => $summary,
            'events' => array_map(static function (array $ev): array {
                return $ev + [
                    'student_url' => '/admin/students/' . (int)$ev['user_id'],
                ];
            }, $events),
        ];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function runNow(): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/automations?error=csrf');
        }

        $count = EmailAutomationRunner::processDue();
        wwm_redirect('/admin/automations?ran=' . $count);
    }

    public function duplicate(int $id): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/automations?error=csrf');
        }

        $pdo = wwm_pdo();
        $newId = EmailAutomation::duplicate($pdo, $id);
        if ($newId === null) {
            wwm_redirect('/admin/automations?error=not_found');
        }

        wwm_redirect('/admin/automations/' . $newId . '/edit?duplicated=1');
    }

    public function archive(int $id): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/automations?error=csrf');
        }

        $pdo = wwm_pdo();
        if (!EmailAutomation::archive($pdo, $id)) {
            wwm_redirect('/admin/automations?error=archive_failed');
        }

        wwm_redirect('/admin/automations?archived=1');
    }

    public function unarchive(int $id): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/automations?view=archived&error=csrf');
        }

        $pdo = wwm_pdo();
        if (!EmailAutomation::unarchive($pdo, $id)) {
            wwm_redirect('/admin/automations?view=archived&error=unarchive_failed');
        }

        wwm_redirect('/admin/automations?unarchived=1');
    }

    public function delete(int $id): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/automations?error=csrf');
        }

        $pdo = wwm_pdo();
        $row = EmailAutomation::find($pdo, $id);
        if ($row === null) {
            wwm_redirect('/admin/automations?error=not_found');
        }
        $returnArchived = EmailAutomation::isArchived($row);

        if (!EmailAutomation::delete($pdo, $id)) {
            $target = $returnArchived ? '/admin/automations?view=archived&error=delete_blocked' : '/admin/automations?error=delete_blocked';
            wwm_redirect($target);
        }

        $target = $returnArchived ? '/admin/automations?view=archived&deleted=1' : '/admin/automations?deleted=1';
        wwm_redirect($target);
    }

    private function prettyJson(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $raw;
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: $raw;
    }

    private function flashMessage(): string
    {
        if (!empty($_GET['saved'])) {
            return 'Сохранено.';
        }
        if (!empty($_GET['imported'])) {
            return 'Imported from AVO export. Review the flow JSON before activating.';
        }
        if (!empty($_GET['duplicated'])) {
            return 'Копия создана как черновик (выключена). Проверьте slug и схему перед включением.';
        }
        if (!empty($_GET['created'])) {
            return 'Новый процесс создан. Настройте схему и тип входа, затем включите Active.';
        }
        if (!empty($_GET['enrolled'])) {
            return 'Ученик добавлен в процесс (или уже был в активном run).';
        }
        if (isset($_GET['ran'])) {
            return 'Processed ' . (int)$_GET['ran'] . ' automation run(s).';
        }

        return '';
    }

    private function listFlashMessage(): string
    {
        if (!empty($_GET['archived'])) {
            return 'Процесс в архиве и выключен. Новые ученики в него не попадают.';
        }
        if (!empty($_GET['unarchived'])) {
            return 'Процесс восстановлен из архива (по умолчанию всё ещё выключен — включите Active при необходимости).';
        }
        if (!empty($_GET['deleted'])) {
            return 'Процесс удалён вместе с историей запусков в базе.';
        }
        if (isset($_GET['ran'])) {
            return 'Processed ' . (int)$_GET['ran'] . ' automation run(s).';
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function courseSlugOptions(): array
    {
        $out = [];
        foreach ((new CourseCatalog())->all() as $course) {
            $slug = trim((string)($course['slug'] ?? ''));
            if ($slug !== '') {
                $out[] = $slug;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @param array{created: int, already_active: int, failed: int}|null $stats
     */
    private function redirectStudentsAutomationLaunch(AdminStudentListFilter $filter, ?array $stats, ?string $error = null): void
    {
        $qs = $filter->queryParams();
        $qs['filters'] = '1';
        if ($stats !== null) {
            $qs['bulk_created'] = (string)$stats['created'];
            $qs['bulk_active'] = (string)$stats['already_active'];
            $qs['bulk_failed'] = (string)$stats['failed'];
        }
        if ($error !== null && $error !== '') {
            $qs['error'] = $error;
        }
        wwm_redirect('/admin/students?' . http_build_query($qs) . '#students-automation-launch');
    }

    private function wantsAutomationJsonSave(): bool
    {
        return strtolower((string)($_SERVER['HTTP_X_WWM_AUTOMATION_SAVE'] ?? '')) === '1';
    }

    private function finishAutomationSave(int $id, bool $jsonSave, bool $ok, string $errorCode, string $detail = ''): void
    {
        if ($jsonSave) {
            if ($ok) {
                $this->automationSaveJson(true, '', 200);
            } else {
                $this->automationSaveJson(false, $errorCode, 400, $detail);
            }

            return;
        }

        if ($ok) {
            wwm_redirect('/admin/automations/' . $id . '/edit?saved=1');
        }

        $code = $errorCode !== '' ? $errorCode : 'save';
        wwm_redirect('/admin/automations/' . $id . '/edit?error=' . rawurlencode($code));
    }

    private function automationSaveJson(bool $ok, string $errorCode, int $status, string $detail = ''): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $payload = $ok
            ? ['ok' => true, 'message' => 'Сохранено.']
            : ['ok' => false, 'error' => $errorCode, 'detail' => $detail];
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
