<?php
declare(strict_types=1);

/**
 * Smoke tests for email automations (no outbound mail).
 *
 *   php scripts/test-email-automations.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;

function ok(bool $cond, string $label): void
{
    global $failed;
    if ($cond) {
        echo "[ok] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}\n";
}

echo "Email automations smoke test\n";
echo str_repeat('=', 50) . PHP_EOL;

$canonical = WWM_ROOT . '/data/automations/elke-en-demo-subscription.v1.json';
ok(is_readable($canonical), 'canonical definition file exists');

$def = json_decode((string)file_get_contents($canonical), true);
ok(is_array($def), 'canonical JSON parses');

try {
    \Wwm\Services\AvoAutomationImporter::validateDefinition($def);
    ok(true, 'canonical definition validates');
} catch (Throwable $e) {
    ok(false, 'canonical definition validates: ' . $e->getMessage());
}

$converted = \Wwm\Services\AvoAutomationImporter::canonicalElkeDemoDefinition('elke-en');
ok(isset($converted['nodes']['start']), 'canonicalElkeDemoDefinition has start node');

$avoSample = getenv('WWM_AVO_BP_JSON') ?: '';
if ($avoSample !== '' && is_readable($avoSample)) {
    try {
        $imported = \Wwm\Services\AvoAutomationImporter::fromAvoExport((string)file_get_contents($avoSample));
        ok(isset($imported['definition']['nodes']), 'AVO import returns definition');
    } catch (Throwable $e) {
        ok(false, 'AVO import: ' . $e->getMessage());
    }
} else {
    echo "[skip] AVO import (set WWM_AVO_BP_JSON to full export path)\n";
}

$pdo = wwm_pdo();
\Wwm\Database::migrateIfNeeded($pdo);
ok((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= 23, 'schema >= 23 (automations tables)');

$tables = ['email_automations', 'email_automation_runs', 'email_automation_step_events'];
foreach ($tables as $table) {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table));
    ok($stmt && $stmt->fetch() !== false, "table {$table}");
}

$slug = 'test-automation-' . bin2hex(random_bytes(4));
$id = \Wwm\Models\EmailAutomation::create($pdo, [
    'slug' => $slug,
    'title' => 'Test flow',
    'description' => 'smoke test',
    'course_slug' => 'elke-en',
    'is_active' => false,
    'definition_json' => json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'check' => ['type' => 'condition', 'condition' => 'has_paid_any', 'label' => 'Paid?'],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'check'],
            ['from' => 'check', 'to' => 'end', 'branch' => 'no'],
        ],
    ], JSON_UNESCAPED_UNICODE),
]);

$email = 'automation-test-' . bin2hex(random_bytes(3)) . '@example.com';
$userId = \Wwm\Models\User::create($pdo, $email, 'test-pass-123', 'Automation Tester');

\Wwm\Services\EmailAutomationEnrollment::onDemoGranted($userId, 'elke-en');
$inactiveRun = $pdo->prepare('SELECT COUNT(*) FROM email_automation_runs WHERE user_id = ?');
$inactiveRun->execute([$userId]);
ok((int)$inactiveRun->fetchColumn() === 0, 'inactive automation does not enroll on demo');

\Wwm\Models\EmailAutomation::update($pdo, $id, ['is_active' => true]);
\Wwm\Services\EmailAutomationEnrollment::onDemoGranted($userId, 'elke-en');
$inactiveRun->execute([$userId]);
ok((int)$inactiveRun->fetchColumn() === 1, 'active automation enrolls once');

$run = \Wwm\Models\EmailAutomationRun::findActive($pdo, $id, $userId);
ok($run !== null, 'active run exists');
if ($run !== null) {
    \Wwm\Services\EmailAutomationRunner::processRun($pdo, $run);
    $stats = \Wwm\Models\EmailAutomationStepEvent::statsByNode($pdo, $id);
    ok($stats !== [], 'step statistics recorded after runner');
    $runAfter = $pdo->prepare('SELECT current_node_id, status FROM email_automation_runs WHERE id = ?');
    $runAfter->execute([(int)$run['id']]);
    $row = $runAfter->fetch();
    ok(is_array($row) && (string)$row['status'] === 'active' || (string)$row['status'] === 'completed', 'run progressed or completed');
}

\Wwm\Models\EmailAutomation::update($pdo, $id, ['is_active' => false]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$id]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

echo PHP_EOL;
if ($failed > 0) {
    echo "FAILED: {$failed}\n";
    exit(1);
}
echo "All automation checks passed.\n";
