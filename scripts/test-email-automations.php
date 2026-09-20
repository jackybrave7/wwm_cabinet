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

$crossPath = WWM_ROOT . '/data/automations/post-purchase-cross-sell.v1.json';
ok(is_readable($crossPath), 'post-purchase cross-sell definition exists');
$crossDef = is_readable($crossPath) ? json_decode((string)file_get_contents($crossPath), true) : null;
ok(is_array($crossDef), 'post-purchase JSON parses');
try {
    if (is_array($crossDef)) {
        \Wwm\Services\AutomationDefinitionValidator::validate($crossDef);
    }
    ok(true, 'post-purchase definition validates');
} catch (Throwable $e) {
    ok(false, 'post-purchase definition validates: ' . $e->getMessage());
}

foreach (['sale_crosssell_50_offer', 'sale_crosssell_50_reminder'] as $tpl) {
    ok(\Wwm\Services\EmailTemplateCatalog::find($tpl) !== null, "template {$tpl} in catalog");
    ok(\Wwm\Services\MarketingEmailDelivery::isMarketingTemplate($tpl), "template {$tpl} is marketing");
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
ok((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= 27, 'schema >= 27 (cross-sell seed)');

$crossStmt = $pdo->prepare('SELECT id, entry_mode FROM email_automations WHERE slug = ? LIMIT 1');
$crossStmt->execute(['post-purchase-cross-sell']);
$crossRow = $crossStmt->fetch();
ok(is_array($crossRow), 'post-purchase-cross-sell automation seeded');
if (is_array($crossRow)) {
    ok((string)$crossRow['entry_mode'] === \Wwm\Models\EmailAutomation::ENTRY_PAYMENT_ANY, 'cross-sell entry_mode is payment_any');
}

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
$cntForFlow = $pdo->prepare('SELECT COUNT(*) FROM email_automation_runs WHERE automation_id = ? AND user_id = ?');
$cntForFlow->execute([$id, $userId]);
ok((int)$cntForFlow->fetchColumn() === 0, 'inactive automation does not enroll on demo');

\Wwm\Models\EmailAutomation::update($pdo, $id, ['is_active' => true]);
\Wwm\Services\EmailAutomationEnrollment::onDemoGranted($userId, 'elke-en');
$cntForFlow->execute([$id, $userId]);
ok((int)$cntForFlow->fetchColumn() === 1, 'active automation enrolls once');

$slugB = 'test-automation-b-' . bin2hex(random_bytes(3));
$idB = \Wwm\Models\EmailAutomation::create($pdo, [
    'slug' => $slugB,
    'title' => 'Test flow B',
    'description' => 'second matching demo funnel',
    'course_slug' => 'elke-en',
    'entry_mode' => \Wwm\Models\EmailAutomation::ENTRY_DEMO_GRANT,
    'is_active' => true,
    'definition_json' => json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'end'],
        ],
    ], JSON_UNESCAPED_UNICODE),
]);
$userIdB = \Wwm\Models\User::create($pdo, 'automation-test-b-' . bin2hex(random_bytes(3)) . '@example.com', 'test-pass-123', 'B');
\Wwm\Services\EmailAutomationEnrollment::onDemoGranted($userIdB, 'elke-en');
$cntForFlow->execute([$id, $userIdB]);
ok((int)$cntForFlow->fetchColumn() === 1, 'demo grant enrolls first matching flow');
$cntForFlow->execute([$idB, $userIdB]);
ok((int)$cntForFlow->fetchColumn() === 1, 'demo grant also enrolls second matching flow');
\Wwm\Services\EmailAutomationEnrollment::onDemoGranted($userIdB, 'alvaro');
$cntForFlow->execute([$idB, $userIdB]);
ok((int)$cntForFlow->fetchColumn() === 1, 'other course does not re-enroll demo flow');

$slugGate = 'test-automation-gate-' . bin2hex(random_bytes(3));
$idGate = \Wwm\Models\EmailAutomation::create($pdo, [
    'slug' => $slugGate,
    'title' => 'Test gate order',
    'description' => 'must start at start, not gate_paid_any_1',
    'course_slug' => 'elke-en',
    'entry_mode' => \Wwm\Models\EmailAutomation::ENTRY_DEMO_GRANT,
    'is_active' => true,
    'definition_json' => json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'grant_demo' => ['type' => 'grant_demo', 'label' => 'Grant', 'skip_if_demo_active' => true],
            'gate_paid_any_1' => ['type' => 'condition', 'condition' => 'has_paid_any', 'label' => 'Paid?'],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'grant_demo'],
            ['from' => 'grant_demo', 'to' => 'gate_paid_any_1'],
            ['from' => 'gate_paid_any_1', 'to' => 'end', 'branch' => 'no'],
        ],
    ], JSON_UNESCAPED_UNICODE),
]);
$userIdGate = \Wwm\Models\User::create($pdo, 'automation-gate-' . bin2hex(random_bytes(3)) . '@example.com', 'test-pass-123', 'Gate');
\Wwm\Services\EmailAutomationEnrollment::onDemoGranted($userIdGate, 'elke-en');
$gateRun = \Wwm\Models\EmailAutomationRun::findActive($pdo, $idGate, $userIdGate);
ok($gateRun !== null && (string)$gateRun['current_node_id'] === 'start', 'demo enroll starts at start, not gate_paid_any_1');
if ($gateRun !== null) {
    \Wwm\Services\EmailAutomationRunner::processRun($pdo, $gateRun);
    $byNode = [];
    foreach (\Wwm\Models\EmailAutomationStepEvent::statsByNode($pdo, $idGate) as $stat) {
        $byNode[(string)$stat['node_id']] = (int)$stat['unique_users'];
    }
    $grantN = $byNode['grant_demo'] ?? 0;
    $gateN = $byNode['gate_paid_any_1'] ?? 0;
    ok($grantN >= $gateN && $grantN > 0, 'later gate does not outcount grant_demo');
}
\Wwm\Models\EmailAutomation::update($pdo, $idGate, ['is_active' => false]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$idGate]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userIdGate]);

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
\Wwm\Models\EmailAutomation::update($pdo, $idB, ['is_active' => false]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$id]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$idB]);

$paySlug = 'test-payment-any-' . bin2hex(random_bytes(3));
$payId = \Wwm\Models\EmailAutomation::create($pdo, [
    'slug' => $paySlug,
    'title' => 'Payment any smoke',
    'description' => 'test',
    'course_slug' => '',
    'entry_mode' => \Wwm\Models\EmailAutomation::ENTRY_PAYMENT_ANY,
    'is_active' => true,
    'definition_json' => json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'end'],
        ],
    ], JSON_UNESCAPED_UNICODE),
]);
$payUserId = \Wwm\Models\User::create($pdo, 'pay-once-' . bin2hex(random_bytes(3)) . '@example.com', 'x', 'Pay');
\Wwm\Services\EmailAutomationEnrollment::onPaymentRecorded($payUserId, 'elke-en', gmdate('c'));
$cnt = $pdo->prepare('SELECT COUNT(*) FROM email_automation_runs WHERE automation_id = ? AND user_id = ?');
$cnt->execute([$payId, $payUserId]);
ok((int)$cnt->fetchColumn() === 1, 'payment_any enrolls on first payment');
\Wwm\Services\EmailAutomationEnrollment::onPaymentRecorded($payUserId, 'alvaro', gmdate('c'));
$cnt->execute([$payId, $payUserId]);
ok((int)$cnt->fetchColumn() === 1, 'payment_any does not re-enroll on second payment');
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$payId]);

$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userIdB]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$payUserId]);

echo PHP_EOL;
if ($failed > 0) {
    echo "FAILED: {$failed}\n";
    exit(1);
}
echo "All automation checks passed.\n";
