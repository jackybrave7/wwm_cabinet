<?php
declare(strict_types=1);

/**
 * Forgot-password sends a logged reset letter without a session cookie,
 * and the link in that letter can set a new password.
 *
 *   php scripts/test-password-reset.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Auth\Password;
use Wwm\Controllers\AuthController;
use Wwm\Models\EmailMessage;
use Wwm\Models\PasswordReset;
use Wwm\Models\User;
use Wwm\Services\ForgotPasswordRequest;

$failed = 0;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

$base = wwm_base_url();
$signed = ForgotPasswordRequest::sign('Student@Example.com');
$verified = ForgotPasswordRequest::verify($signed);
ok($verified !== null && $verified['email'] === 'student@example.com', 'reset form token keeps the email');
ok(ForgotPasswordRequest::verify('not-a-token') === null, 'broken reset form token is rejected');
ok(
    ForgotPasswordRequest::redirectForTrackedForgot($base . '/forgot', 'student@example.com') !== null,
    'course-email forgot link opens the reset form for that student'
);
ok(
    ForgotPasswordRequest::redirectForTrackedForgot($base . '/login', 'student@example.com') === null,
    'other email links are not turned into a reset'
);

$pdo = wwm_pdo();
$email = 'reset-' . bin2hex(random_bytes(3)) . '@example.com';
$userId = User::create($pdo, $email, 'OldPassword1', 'Reset Student');
$issue = ForgotPasswordRequest::sign($email);
$_SESSION = [];
$_POST = [
    'csrf' => '',
    'issue' => $issue,
    'email' => '  ' . strtoupper($email) . '  ',
];

ob_start();
(new AuthController())->forgot();
$firstHtml = (string)ob_get_clean();
ok(str_contains($firstHtml, 'we sent a reset link'), 'form confirms the reset link was sent');

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM email_messages WHERE user_id = ? AND email_type = 'reset' AND status = 'sent'");
$countStmt->execute([$userId]);
ok((int)$countStmt->fetchColumn() === 1, 'reset letter is in the student email journal');

ob_start();
(new AuthController())->forgot();
ob_end_clean();
$countStmt->execute([$userId]);
ok((int)$countStmt->fetchColumn() === 1, 'a second press within a minute does not drop the first letter');

$linkStmt = $pdo->prepare(
    "SELECT el.target_url FROM email_links el
     INNER JOIN email_messages em ON em.id = el.message_id
     WHERE em.user_id = ? AND em.email_type = 'reset'
     ORDER BY el.id DESC LIMIT 1"
);
$linkStmt->execute([$userId]);
$targetUrl = (string)$linkStmt->fetchColumn();
preg_match('#/reset\?token=([a-f0-9]{64})#', $targetUrl, $tokenMatch);
$token = $tokenMatch[1] ?? '';
unset($log, $logAt);
ok($token !== '' && PasswordReset::findValid($pdo, $token) !== null, 'the letter contains a working reset link');

$_POST = [
    'csrf' => 'nope',
    'token' => $token,
    'password' => 'NewPassword1',
    'password_confirm' => 'NewPassword1',
];
ob_start();
(new AuthController())->reset();
$csrfHtml = (string)ob_get_clean();
ok(str_contains($csrfHtml, 'Open the reset link from your email again'), 'expired form explains what to do');
ok(PasswordReset::findValid($pdo, $token) !== null, 'a rejected form does not burn the reset link');

$_SESSION['csrf_token'] = 'reset-test-csrf';
$newPassword = 'NewPassword1';
$_POST = [
    'csrf' => 'reset-test-csrf',
    'token' => $token,
    'password' => $newPassword,
    'password_confirm' => $newPassword,
];

register_shutdown_function(static function () use ($pdo, $userId, $newPassword): void {
    global $failed;
    $user = User::findById($pdo, $userId);
    $works = is_array($user) && Password::verify($newPassword, (string)($user['password_hash'] ?? ''));
    if ($works) {
        echo "[ok] new password signs in\n";
    } else {
        $failed++;
        echo "[FAIL] new password signs in\n";
    }
    $pdo->prepare('DELETE FROM email_links WHERE message_id IN (SELECT id FROM email_messages WHERE user_id = ?)')->execute([$userId]);
    $pdo->prepare('DELETE FROM email_messages WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    echo $failed === 0 ? "ALL OK\n" : "FAILURES {$failed}\n";
    if ($failed !== 0) {
        exit(1);
    }
});

(new AuthController())->reset();
