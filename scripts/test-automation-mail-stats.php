<?php
declare(strict_types=1);

/**
 * Letter blocks count sent / opened / clicked, including letters sent before the link existed.
 *
 *   php scripts/test-automation-mail-stats.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Models\EmailAutomation;
use Wwm\Models\EmailAutomationRun;
use Wwm\Models\EmailAutomationStepEvent;
use Wwm\Models\EmailMessage;
use Wwm\Models\User;
use Wwm\Services\EmailTracker;

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

$pdo = wwm_pdo();
$email = 'mail-stats-' . bin2hex(random_bytes(3)) . '@example.com';
$userId = User::create($pdo, $email, 'Stats1', 'Reader');
$automationId = EmailAutomation::createBlank($pdo, 'Mail stats test', 'demo_form', 'elke-en');
$runId = EmailAutomationRun::enroll($pdo, $automationId, $userId, 'elke-en', 'mail_story', []);

$linked = EmailMessage::create($pdo, $userId, $email, 'v2_value_story', 'Story', null, $automationId, 'mail_story');
EmailMessage::markStatus($pdo, $linked['id'], true);
EmailTracker::recordOpen($linked['open_token']);
$clickToken = bin2hex(random_bytes(16));
EmailMessage::addLink($pdo, $linked['id'], $clickToken, 'https://example.test/buy', 'buy');
EmailTracker::clickTarget($clickToken);

$quiet = EmailMessage::create($pdo, $userId, $email, 'v2_offer_open', 'Offer', null, $automationId, 'mail_offer');
EmailMessage::markStatus($pdo, $quiet['id'], true);

$stats = EmailMessage::automationNodeStats($pdo, $automationId);
ok(($stats['mail_story']['sent'] ?? 0) === 1, 'story counts as sent');
ok(($stats['mail_story']['opened'] ?? 0) === 1, 'story counts as opened');
ok(($stats['mail_story']['clicked'] ?? 0) === 1, 'story counts as clicked');
ok(($stats['mail_offer']['sent'] ?? 0) === 1, 'offer counts as sent');
ok(($stats['mail_offer']['opened'] ?? 0) === 0, 'unopened offer stays closed');
ok(($stats['mail_offer']['clicked'] ?? 0) === 0, 'unopened offer has no click');

$marks = EmailMessage::automationNodeEngagement($pdo, $automationId, 'mail_story');
ok(!empty($marks[$userId]['opened']) && !empty($marks[$userId]['clicked']), 'reader is marked opened and clicked');

$past = EmailMessage::create($pdo, $userId, $email, 'v2_value_proof', 'Proof');
EmailMessage::markStatus($pdo, $past['id'], true);
EmailTracker::recordOpen($past['open_token']);
EmailAutomationStepEvent::record($pdo, [
    'automation_id' => $automationId,
    'run_id' => $runId,
    'user_id' => $userId,
    'node_id' => 'mail_proof',
    'node_type' => 'send_template',
    'branch' => 'sent',
    'detail' => 'v2_value_proof',
]);

$method = new ReflectionMethod(Wwm\Database::class, 'backfillAutomationMailLinks');
$method->setAccessible(true);
$method->invoke(null, $pdo);

$after = EmailMessage::automationNodeStats($pdo, $automationId);
ok(($after['mail_proof']['sent'] ?? 0) === 1 && ($after['mail_proof']['opened'] ?? 0) === 1, 'older letter is linked to its block');

$pdo->prepare('DELETE FROM email_messages WHERE user_id = ?')->execute([$userId]);
$pdo->prepare('DELETE FROM email_automations WHERE id = ?')->execute([$automationId]);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

if ($failed > 0) {
    echo "{$failed} failed\n";
    exit(1);
}
echo "all passed\n";
