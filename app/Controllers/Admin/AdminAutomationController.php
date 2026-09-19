<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\EmailAutomation;
use Wwm\Models\EmailAutomationStepEvent;
use Wwm\Services\AvoAutomationImporter;
use Wwm\Services\EmailAutomationRunner;

final class AdminAutomationController
{
    public function index(): void
    {
        Session::requireSuperAdmin();
        $pdo = wwm_pdo();

        wwm_render_admin('automations', [
            'title' => 'Email automations — Admin',
            'adminNav' => 'automations',
            'automations' => EmailAutomation::listAll($pdo),
            'message' => $this->flashMessage(),
        ]);
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

        wwm_render_admin('automation-edit', [
            'title' => 'Edit automation — Admin',
            'adminNav' => 'automations',
            'automation' => $row,
            'definitionPretty' => $this->prettyJson((string)$row['definition_json']),
            'nodeStats' => EmailAutomationStepEvent::statsByNode($pdo, $id),
            'stepEventTotal' => EmailAutomationStepEvent::totalEvents($pdo, $id),
            'message' => $this->flashMessage(),
            'error' => (string)($_GET['error'] ?? ''),
        ]);
    }

    public function save(int $id): void
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

        $definitionRaw = (string)($_POST['definition_json'] ?? '');
        $definition = json_decode($definitionRaw, true);
        if (!is_array($definition)) {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=invalid_json');
        }

        try {
            AvoAutomationImporter::validateDefinition($definition);
        } catch (\InvalidArgumentException $e) {
            wwm_redirect('/admin/automations/' . $id . '/edit?error=invalid_definition');
        }

        EmailAutomation::update($pdo, $id, [
            'title' => trim((string)($_POST['title'] ?? $row['title'])),
            'description' => trim((string)($_POST['description'] ?? '')),
            'course_slug' => preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['course_slug'] ?? '')) ?: (string)$row['course_slug'],
            'is_active' => !empty($_POST['is_active']),
            'definition_json' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: $definitionRaw,
        ]);

        wwm_redirect('/admin/automations/' . $id . '/edit?saved=1');
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

    public function runNow(): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/automations?error=csrf');
        }

        $count = EmailAutomationRunner::processDue();
        wwm_redirect('/admin/automations?ran=' . $count);
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
            return 'Saved.';
        }
        if (!empty($_GET['imported'])) {
            return 'Imported from AVO export. Review the flow JSON before activating.';
        }
        if (isset($_GET['ran'])) {
            return 'Processed ' . (int)$_GET['ran'] . ' automation run(s).';
        }

        return '';
    }
}
