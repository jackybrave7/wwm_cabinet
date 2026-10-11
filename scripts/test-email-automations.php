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
$gateRun = \Wwm\Models\EmailAutomationRun::findActive($pdo, $idGate, $userIdGate)
    ?? $pdo->query('SELECT * FROM email_automation_runs WHERE automation_id = ' . (int)$idGate . ' AND user_id = ' . (int)$userIdGate . ' ORDER BY id DESC LIMIT 1')->fetch();
ok(is_array($gateRun), 'demo enroll creates a run');
$firstEv = $pdo->prepare(
    'SELECT node_id FROM email_automation_step_events WHERE run_id = ? ORDER BY id ASC LIMIT 1'
);
$firstEv->execute([(int)($gateRun['id'] ?? 0)]);
ok((string)$firstEv->fetchColumn() === 'start', 'demo enroll starts at start, not gate_paid_any_1');
$byNode = [];
foreach (\Wwm\Models\EmailAutomationStepEvent::statsByNode($pdo, $idGate) as $stat) {
    $byNode[(string)$stat['node_id']] = (int)$stat['unique_users'];
}
$grantN = $byNode['grant_demo'] ?? 0;
$gateN = $byNode['gate_paid_any_1'] ?? 0;
ok($grantN >= $gateN && $grantN > 0, 'later gate does not outcount grant_demo');
\Wwm\Models\EmailAutomation::update($pdo, $idGate, ['is_active' => false]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$idGate]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userIdGate]);

$runStmt = $pdo->prepare('SELECT id, current_node_id, status FROM email_automation_runs WHERE automation_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
$runStmt->execute([$id, $userId]);
$run = $runStmt->fetch();
ok(is_array($run), 'run exists after enroll');
$stats = \Wwm\Models\EmailAutomationStepEvent::statsByNode($pdo, $id);
ok($stats !== [], 'step statistics recorded after enroll');
ok(is_array($run) && in_array((string)$run['status'], ['active', 'completed'], true), 'run progressed or completed');

$cancelUserId = \Wwm\Models\User::create($pdo, 'automation-cancel-' . bin2hex(random_bytes(3)) . '@example.com', 'test-pass-123', 'Cancel');
$cancelFlowId = \Wwm\Models\EmailAutomation::create($pdo, [
    'slug' => 'test-cancel-' . bin2hex(random_bytes(3)),
    'title' => 'Cancel smoke',
    'description' => 'test',
    'course_slug' => 'elke-en',
    'entry_mode' => \Wwm\Models\EmailAutomation::ENTRY_MANUAL,
    'is_active' => true,
    'definition_json' => json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'wait' => ['type' => 'delay', 'seconds' => 86400, 'label' => 'Wait'],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'wait'],
            ['from' => 'wait', 'to' => 'end'],
        ],
    ], JSON_UNESCAPED_UNICODE),
]);
$cancelRunId = \Wwm\Services\EmailAutomationEnrollment::enrollManual($cancelFlowId, $cancelUserId, 'elke-en');
ok($cancelRunId !== null, 'manual enroll for cancel test');
$held = \Wwm\Models\EmailAutomationRun::findActive($pdo, $cancelFlowId, $cancelUserId);
ok(is_array($held) && (string)$held['status'] === 'active', 'cancel-test run is active on delay');
ok(\Wwm\Services\EmailAutomationEnrollment::cancelRun((int)$cancelRunId, $cancelFlowId, $cancelUserId), 'admin can cancel active run');
ok(\Wwm\Models\EmailAutomationRun::findActive($pdo, $cancelFlowId, $cancelUserId) === null, 'cancelled run is no longer active');
ok(!\Wwm\Services\EmailAutomationEnrollment::cancelRun((int)$cancelRunId, $cancelFlowId, $cancelUserId), 'second cancel is a no-op');
$reEnroll = \Wwm\Services\EmailAutomationEnrollment::enrollManual($cancelFlowId, $cancelUserId, 'elke-en');
ok($reEnroll !== null && (int)$reEnroll === (int)$cancelRunId, 'manual flow restarts the same run after cancel');
ok(\Wwm\Models\EmailAutomationRun::findActive($pdo, $cancelFlowId, $cancelUserId) !== null, 'restarted run is active');
\Wwm\Models\EmailAutomation::update($pdo, $cancelFlowId, ['is_active' => false]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$cancelFlowId]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$cancelUserId]);

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

// --- flow_revision 4: single reminder (no mail_waiting → mail_still chain) ---
$edges = is_array($def['edges'] ?? null) ? $def['edges'] : [];
$waitingTargets = [];
foreach ($edges as $edge) {
    if (!is_array($edge) || (string)($edge['from'] ?? '') !== 'mail_waiting') {
        continue;
    }
    $waitingTargets[] = (string)($edge['to'] ?? '');
}
ok(
    $waitingTargets === ['wait_5h'] || (count($waitingTargets) === 1 && $waitingTargets[0] === 'wait_5h'),
    'elke funnel: mail_waiting goes to wait_5h (single reminder)'
);
ok((int)($def['meta']['flow_revision'] ?? 0) >= 4, 'elke funnel flow_revision >= 4');
ok(!isset($def['nodes']['mail_still']), 'elke funnel: mail_still node removed (legacy runs remapped on migrate)');

// --- stale skip + one email per cron tick ---
$staleSlug = 'test-stale-' . bin2hex(random_bytes(3));
$staleId = \Wwm\Models\EmailAutomation::create($pdo, [
    'slug' => $staleSlug,
    'title' => 'Stale catch-up',
    'description' => 'test',
    'course_slug' => 'elke-en',
    'entry_mode' => \Wwm\Models\EmailAutomation::ENTRY_MANUAL,
    'is_active' => true,
    'definition_json' => json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'mail_a' => [
                'type' => 'send_template',
                'template' => 'sale_demo_discount_24h',
                'stale_after_seconds' => 7200,
                'label' => '24h',
            ],
            'mail_b' => [
                'type' => 'send_template',
                'template' => 'sale_demo_discount_3h',
                'stale_after_seconds' => 3600,
                'label' => '3h',
            ],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'mail_a'],
            ['from' => 'mail_a', 'to' => 'mail_b'],
            ['from' => 'mail_b', 'to' => 'end'],
        ],
    ], JSON_UNESCAPED_UNICODE),
]);
$staleUserId = \Wwm\Models\User::create(
    $pdo,
    'stale-' . bin2hex(random_bytes(3)) . '@example.com',
    'test-pass-123',
    'Stale'
);
$staleRunId = \Wwm\Services\EmailAutomationEnrollment::enrollManual($staleId, $staleUserId, 'elke-en');
ok($staleRunId !== null, 'stale-test enroll');
// Park on mail_a with next_run_at far in the past (simulates cron downtime).
$pdo->prepare(
    'UPDATE email_automation_runs
     SET current_node_id = ?, next_run_at = ?, status = \'active\', completed_at = NULL, updated_at = ?
     WHERE id = ?'
)->execute(['mail_a', gmdate('c', time() - 86400), gmdate('c'), (int)$staleRunId]);
$pdo->prepare('DELETE FROM email_automation_step_events WHERE run_id = ? AND node_id IN (?, ?)')->execute([
    (int)$staleRunId,
    'mail_a',
    'mail_b',
]);

$n1 = \Wwm\Services\EmailAutomationRunner::processDue($pdo);
ok($n1 >= 1, 'processDue picks up stale run');
$branchA = $pdo->prepare(
    'SELECT branch FROM email_automation_step_events WHERE run_id = ? AND node_id = ? ORDER BY id DESC LIMIT 1'
);
$branchA->execute([(int)$staleRunId, 'mail_a']);
ok((string)$branchA->fetchColumn() === 'skipped_stale', 'overdue 24h mail marked skipped_stale');

// One email per cron tick: two fresh consecutive mails → only the first is sent.
$pdo->prepare(
    'UPDATE email_automation_runs
     SET current_node_id = ?, next_run_at = ?, status = \'active\', completed_at = NULL, updated_at = ?
     WHERE id = ?'
)->execute(['mail_a', gmdate('c', time() - 120), gmdate('c'), (int)$staleRunId]);
$pdo->prepare('DELETE FROM email_automation_step_events WHERE run_id = ? AND node_id IN (?, ?)')->execute([
    (int)$staleRunId,
    'mail_a',
    'mail_b',
]);
// Temporarily widen stale window so 2-minute lateness is allowed for 24h template.
$pdo->prepare(
    'UPDATE email_automations SET definition_json = ? WHERE id = ?'
)->execute([
    json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'mail_a' => [
                'type' => 'send_template',
                'template' => 'reminder_demo_no_login',
                'stale_after_seconds' => 86400,
                'label' => 'A',
            ],
            'mail_b' => [
                'type' => 'send_template',
                'template' => 'reminder_demo_expiring',
                'stale_after_seconds' => 86400,
                'label' => 'B',
            ],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'mail_a'],
            ['from' => 'mail_a', 'to' => 'mail_b'],
            ['from' => 'mail_b', 'to' => 'end'],
        ],
    ], JSON_UNESCAPED_UNICODE),
    $staleId,
]);
\Wwm\Services\EmailAutomationRunner::processDue($pdo);
$sentCount = static function (int $runId, string $node) use ($pdo): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM email_automation_step_events WHERE run_id = ? AND node_id = ? AND branch = \'sent\''
    );
    $stmt->execute([$runId, $node]);

    return (int)$stmt->fetchColumn();
};
ok($sentCount((int)$staleRunId, 'mail_a') === 1, 'first mail sent in cron tick');
ok($sentCount((int)$staleRunId, 'mail_b') === 0, 'second mail deferred to next cron tick');
$staleRun = \Wwm\Models\EmailAutomationRun::find($pdo, (int)$staleRunId);
ok(
    is_array($staleRun)
    && (string)$staleRun['status'] === 'active'
    && (string)$staleRun['current_node_id'] === 'mail_b',
    'run parked on second mail after one-email tick'
);

// Very late 3h mail must be skipped_stale (time-sensitive).
$pdo->prepare(
    'UPDATE email_automations SET definition_json = ? WHERE id = ?'
)->execute([
    json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'mail_a' => [
                'type' => 'send_template',
                'template' => 'sale_demo_discount_24h',
                'stale_after_seconds' => 7200,
                'label' => '24h',
            ],
            'mail_b' => [
                'type' => 'send_template',
                'template' => 'sale_demo_discount_3h',
                'stale_after_seconds' => 3600,
                'label' => '3h',
            ],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'mail_a'],
            ['from' => 'mail_a', 'to' => 'mail_b'],
            ['from' => 'mail_b', 'to' => 'end'],
        ],
    ], JSON_UNESCAPED_UNICODE),
    $staleId,
]);
$pdo->prepare(
    'UPDATE email_automation_runs
     SET current_node_id = ?, next_run_at = ?, status = \'active\', completed_at = NULL, updated_at = ?
     WHERE id = ?'
)->execute(['mail_b', gmdate('c', time() - 7200), gmdate('c'), (int)$staleRunId]);
$pdo->prepare('DELETE FROM email_automation_step_events WHERE run_id = ? AND node_id = ?')->execute([
    (int)$staleRunId,
    'mail_b',
]);
\Wwm\Services\EmailAutomationRunner::processDue($pdo);
$branchB = $pdo->prepare(
    'SELECT branch FROM email_automation_step_events WHERE run_id = ? AND node_id = ? ORDER BY id DESC LIMIT 1'
);
$branchB->execute([(int)$staleRunId, 'mail_b']);
ok((string)$branchB->fetchColumn() === 'skipped_stale', 'time-sensitive 3h mail skipped when too late');

// Lock: nested processDue while holding the same lock file should no-op.
$lockPath = WWM_ROOT . '/data/locks/email-automations.lock';
if (!is_dir(dirname($lockPath))) {
    mkdir(dirname($lockPath), 0775, true);
}
$lockFh = fopen($lockPath, 'c+');
ok($lockFh !== false && flock($lockFh, LOCK_EX | LOCK_NB), 'acquired automation lock for contention test');
$nested = \Wwm\Services\EmailAutomationRunner::processDue($pdo);
ok($nested === 0, 'processDue returns 0 when lock is busy');
flock($lockFh, LOCK_UN);
fclose($lockFh);

\Wwm\Models\EmailAutomation::update($pdo, $staleId, ['is_active' => false]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$staleId]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$staleUserId]);

// --- payment stops active demo funnel ---
$demoStopSlug = 'test-demo-stop-' . bin2hex(random_bytes(3));
$demoStopId = \Wwm\Models\EmailAutomation::create($pdo, [
    'slug' => $demoStopSlug,
    'title' => 'Demo stop on pay',
    'description' => 'test',
    'course_slug' => 'elke-en',
    'entry_mode' => \Wwm\Models\EmailAutomation::ENTRY_DEMO_FORM,
    'is_active' => true,
    'definition_json' => json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'wait' => ['type' => 'delay', 'seconds' => 86400, 'label' => 'Wait'],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'wait'],
            ['from' => 'wait', 'to' => 'end'],
        ],
    ], JSON_UNESCAPED_UNICODE),
]);
$demoStopUser = \Wwm\Models\User::create(
    $pdo,
    'demo-stop-' . bin2hex(random_bytes(3)) . '@example.com',
    'test-pass-123',
    'DemoStop'
);
\Wwm\Services\EmailAutomationEnrollment::onCabinetDemoForm($demoStopUser, 'elke-en');
$activeDemo = \Wwm\Models\EmailAutomationRun::findActive($pdo, $demoStopId, $demoStopUser);
ok(is_array($activeDemo), 'demo-form run is active before payment');
\Wwm\Services\EmailAutomationEnrollment::onPaymentRecorded($demoStopUser, 'elke-en', gmdate('c'));
ok(
    \Wwm\Models\EmailAutomationRun::findActive($pdo, $demoStopId, $demoStopUser) === null,
    'payment cancels active demo funnel for the course'
);
$payCancel = $pdo->prepare(
    'SELECT detail FROM email_automation_step_events
     WHERE run_id = ? AND branch = \'cancelled\' ORDER BY id DESC LIMIT 1'
);
$payCancel->execute([(int)($activeDemo['id'] ?? 0)]);
ok((string)$payCancel->fetchColumn() === 'payment', 'demo funnel cancel detail is payment');
\Wwm\Models\EmailAutomation::update($pdo, $demoStopId, ['is_active' => false]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$demoStopId]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$demoStopUser]);

// --- after cancel + restart, prior "sent" must not block a new send ---
$resendSlug = 'test-resend-' . bin2hex(random_bytes(3));
$resendId = \Wwm\Models\EmailAutomation::create($pdo, [
    'slug' => $resendSlug,
    'title' => 'Resend after restart',
    'description' => 'test',
    'course_slug' => 'elke-en',
    'entry_mode' => \Wwm\Models\EmailAutomation::ENTRY_MANUAL,
    'is_active' => true,
    'definition_json' => json_encode([
        'version' => 1,
        'nodes' => [
            'start' => ['type' => 'trigger', 'label' => 'Start'],
            'mail' => [
                'type' => 'send_template',
                'template' => 'reminder_demo_no_login',
                'stale_after_seconds' => 86400,
                'label' => 'Mail',
            ],
            'wait' => ['type' => 'delay', 'seconds' => 86400, 'label' => 'Wait'],
            'end' => ['type' => 'end', 'label' => 'End'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'mail'],
            ['from' => 'mail', 'to' => 'wait'],
            ['from' => 'wait', 'to' => 'end'],
        ],
    ], JSON_UNESCAPED_UNICODE),
]);
$resendUserId = \Wwm\Models\User::create(
    $pdo,
    'resend-' . bin2hex(random_bytes(3)) . '@example.com',
    'test-pass-123',
    'Resend'
);
$resendRunId = \Wwm\Services\EmailAutomationEnrollment::enrollManual($resendId, $resendUserId, 'elke-en');
ok($resendRunId !== null, 'resend-test enroll');
$sentStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM email_automation_step_events
     WHERE run_id = ? AND node_id = \'mail\' AND branch = \'sent\''
);
$sentStmt->execute([(int)$resendRunId]);
ok((int)$sentStmt->fetchColumn() === 1, 'first pass sends mail once');
$firstEnrolled = (string)(\Wwm\Models\EmailAutomationRun::find($pdo, (int)$resendRunId)['enrolled_at'] ?? '');
ok($firstEnrolled !== '', 'first pass enrolled_at set');
ok(
    \Wwm\Models\EmailAutomationStepEvent::hasBranch($pdo, (int)$resendRunId, 'mail', 'sent', $firstEnrolled),
    'hasBranch sees sent in current pass'
);

ok(
    \Wwm\Services\EmailAutomationEnrollment::cancelRun((int)$resendRunId, $resendId, $resendUserId),
    'resend-test cancel'
);
// Ensure restart gets a newer enrolled_at than the previous sent event.
usleep(1100000);
$restartedId = \Wwm\Models\EmailAutomationRun::restart(
    $pdo,
    (int)$resendRunId,
    'elke-en',
    'start',
    ['trigger' => 'manual']
);
ok((int)$restartedId === (int)$resendRunId, 'resend-test restart same run id');
$afterRestart = \Wwm\Models\EmailAutomationRun::find($pdo, (int)$resendRunId);
$secondEnrolled = is_array($afterRestart) ? (string)($afterRestart['enrolled_at'] ?? '') : '';
ok($secondEnrolled !== '' && $secondEnrolled > $firstEnrolled, 'restart bumps enrolled_at');
ok(
    !\Wwm\Models\EmailAutomationStepEvent::hasBranch($pdo, (int)$resendRunId, 'mail', 'sent', $secondEnrolled),
    'hasBranch ignores sent from previous pass after restart'
);
\Wwm\Services\EmailAutomationRunner::processRun($pdo, $afterRestart);
$sentStmt->execute([(int)$resendRunId]);
ok((int)$sentStmt->fetchColumn() === 2, 'second pass sends mail again after restart');

\Wwm\Models\EmailAutomation::update($pdo, $resendId, ['is_active' => false]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$resendId]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$resendUserId]);

echo PHP_EOL;
if ($failed > 0) {
    echo "FAILED: {$failed}\n";
    exit(1);
}
echo "All automation checks passed.\n";
