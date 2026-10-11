<?php
declare(strict_types=1);

/**
 * Login speed / background AVO follow-up.
 *
 *   php scripts/test-login-background.php
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

echo "Login background jobs test\n";
echo str_repeat('=', 50) . PHP_EOL;

$pdo = wwm_pdo();
\Wwm\Database::migrateIfNeeded($pdo);
ok((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= 30, 'schema >= 30 (background_jobs)');

$email = 'login-bg-' . bin2hex(random_bytes(3)) . '@example.com';
$userId = \Wwm\Models\User::create($pdo, $email, 'LoginBgPass1', 'Login BG');

$t0 = microtime(true);
// Mirror AuthController::completeLogin without session cookies (CLI has no HTTP session).
\Wwm\Models\User::touchLogin($pdo, $userId);
\Wwm\Services\StudentAttribution::recordForUser($pdo, $userId, false, [], false);
\Wwm\Models\BackgroundJob::enqueue($pdo, \Wwm\Models\BackgroundJob::TYPE_LOGIN_FOLLOWUP, [
    'user_id' => $userId,
    'ip' => '127.0.0.1',
    'backfill_utm' => false,
]);
$fastMs = (microtime(true) - $t0) * 1000;
ok($fastMs < 500, sprintf('login critical path under 500ms (%.0f ms)', $fastMs));

$pending = $pdo->prepare(
    'SELECT COUNT(*) FROM background_jobs WHERE job_type = ? AND status = \'pending\''
);
$pending->execute([\Wwm\Models\BackgroundJob::TYPE_LOGIN_FOLLOWUP]);
ok((int)$pending->fetchColumn() >= 1, 'login_followup job enqueued');

// Process without requiring live AVO (disabled → onLogin sync no-ops cleanly).
$done = \Wwm\Services\BackgroundJobRunner::processDue($pdo, 10);
ok($done >= 1, 'background runner processed at least one job');

$doneRow = $pdo->prepare(
    'SELECT status FROM background_jobs WHERE job_type = ? ORDER BY id DESC LIMIT 1'
);
$doneRow->execute([\Wwm\Models\BackgroundJob::TYPE_LOGIN_FOLLOWUP]);
ok((string)$doneRow->fetchColumn() === 'done', 'login_followup marked done');

\Wwm\Services\AvoEngagementSync::onDemoLessonOpen($userId, true);
$demoPending = $pdo->prepare(
    'SELECT COUNT(*) FROM background_jobs WHERE job_type = ? AND status = \'pending\''
);
$demoPending->execute([\Wwm\Models\BackgroundJob::TYPE_AVO_DEMO_OPENED]);
ok((int)$demoPending->fetchColumn() >= 1, 'demo_opened job enqueued asynchronously');

ok(\Wwm\Auth\Password::BCRYPT_COST >= 10 && \Wwm\Auth\Password::BCRYPT_COST <= 12, 'bcrypt cost in 10–12');

$pdo->prepare('DELETE FROM background_jobs WHERE payload_json LIKE ?')->execute(['%"user_id":' . $userId . '%']);
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

echo PHP_EOL;
if ($failed > 0) {
    echo "FAILED: {$failed}\n";
    exit(1);
}
echo "All login background checks passed.\n";
