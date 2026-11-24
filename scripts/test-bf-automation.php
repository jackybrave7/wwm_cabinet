<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use Wwm\Models\EmailAutomation;
use Wwm\Models\EmailAutomationRun;
use Wwm\Models\User;
use Wwm\Services\AutomationDefinitionValidator;
use Wwm\Services\EmailAutomationEnrollment;
use Wwm\Services\EmailTemplateCatalog;

$fail = 0;
$ok = static function (bool $cond, string $msg) use (&$fail): void {
    if ($cond) {
        echo "OK  $msg\n";
        return;
    }
    $fail++;
    echo "FAIL $msg\n";
};

$path = WWM_ROOT . '/data/automations/black-friday-2026.json';
$def = json_decode((string)file_get_contents($path), true);
$ok(is_array($def), 'definition json');
$issues = is_array($def) ? AutomationDefinitionValidator::issues($def) : ['not array'];
$ok($issues === [], 'definition valid: ' . implode('; ', $issues));

foreach (['bf2026_announce', 'bf2026_reminder', 'bf2026_last_day', 'bf2026_hours_left'] as $id) {
    $meta = EmailTemplateCatalog::find($id);
    $ok(is_array($meta), "template $id exists");
    $draft = \Wwm\Services\EmailTemplateDrafts::draft($id);
    $ok(str_contains($draft['text'], 'https://worldwatercolormasters.art/angus-mcewan'), "$id lists Angus");
    $ok(str_contains($draft['text'], '$30 instead of $60'), "$id has Angus half price");
    $ok(!str_contains($draft['text'], '{{coupon_code}}') && !str_contains($draft['text'], 'WWM5'), "$id has no coupon code");
    $ok(!str_contains(strtolower($draft['text']), 'new york'), "$id does not name New York");
}

$pdo = wwm_pdo();
$pdo->beginTransaction();
try {
    $futureId = EmailAutomation::create($pdo, [
        'slug' => 'test-bf-future-' . bin2hex(random_bytes(3)),
        'title' => 'BF future',
        'description' => 'test',
        'course_slug' => 'elke-en',
        'entry_mode' => EmailAutomation::ENTRY_MANUAL,
        'is_active' => true,
        'definition_json' => json_encode([
            'version' => 1,
            'nodes' => [
                'start' => ['type' => 'trigger', 'entry_mode' => 'manual', 'label' => 'Start'],
                'wait' => ['type' => 'delay', 'seconds' => 0, 'until' => '2026-11-25T10:00:00-05:00', 'label' => 'Wait'],
                'end' => ['type' => 'end', 'label' => 'End'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'wait'],
                ['from' => 'wait', 'to' => 'end'],
            ],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $userFuture = User::create($pdo, 'bf-future-' . bin2hex(random_bytes(3)) . '@example.com', 'x', 'Future');
    $runFuture = EmailAutomationEnrollment::enrollManual($futureId, $userFuture, 'elke-en');
    $row = EmailAutomationRun::find($pdo, (int)$runFuture);
    $ok(is_array($row) && (string)$row['current_node_id'] === 'wait', 'future until stays on the delay');
    $wake = strtotime((string)($row['next_run_at'] ?? ''));
    $ok($wake === strtotime('2026-11-25T10:00:00-05:00'), 'future until wakes at Nov 25 10:00 New York');

    $pastId = EmailAutomation::create($pdo, [
        'slug' => 'test-bf-past-' . bin2hex(random_bytes(3)),
        'title' => 'BF past',
        'description' => 'test',
        'course_slug' => 'elke-en',
        'entry_mode' => EmailAutomation::ENTRY_MANUAL,
        'is_active' => true,
        'definition_json' => json_encode([
            'version' => 1,
            'nodes' => [
                'start' => ['type' => 'trigger', 'entry_mode' => 'manual', 'label' => 'Start'],
                'wait' => ['type' => 'delay', 'seconds' => 0, 'until' => '2020-01-01T00:00:00-05:00', 'label' => 'Wait'],
                'mail' => [
                    'type' => 'send_template',
                    'template' => 'bf2026_announce',
                    'course_slug' => 'elke-en',
                    'skip_after' => '2020-01-02T00:00:00-05:00',
                    'label' => 'Old letter',
                ],
                'end' => ['type' => 'end', 'label' => 'End'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'wait'],
                ['from' => 'wait', 'to' => 'mail'],
                ['from' => 'mail', 'to' => 'end'],
            ],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $userPast = User::create($pdo, 'bf-past-' . bin2hex(random_bytes(3)) . '@example.com', 'x', 'Past');
    $runPast = EmailAutomationEnrollment::enrollManual($pastId, $userPast, 'elke-en');
    $done = EmailAutomationRun::find($pdo, (int)$runPast);
    $ok(is_array($done) && (string)$done['status'] === 'completed', 'past window finishes the run');
    $sent = $pdo->prepare(
        "SELECT COUNT(*) FROM email_automation_step_events WHERE run_id = ? AND node_id = 'mail' AND branch = 'sent'"
    );
    $sent->execute([(int)$runPast]);
    $ok((int)$sent->fetchColumn() === 0, 'past letter is not sent');
    $skipped = $pdo->prepare(
        "SELECT COUNT(*) FROM email_automation_step_events WHERE run_id = ? AND node_id = 'mail' AND branch = 'skipped'"
    );
    $skipped->execute([(int)$runPast]);
    $ok((int)$skipped->fetchColumn() === 1, 'past letter is skipped');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo $fail === 0 ? "ALL OK\n" : "FAILURES $fail\n";
exit($fail === 0 ? 0 : 1);
