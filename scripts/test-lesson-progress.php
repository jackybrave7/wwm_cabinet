<?php
declare(strict_types=1);

/**
 * Lesson done marks and resume pointer stay separate from demo lesson_opens.
 *
 *   php scripts/test-lesson-progress.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Models\LessonProgress;
use Wwm\Models\User;

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
$email = 'lesson-progress-' . bin2hex(random_bytes(3)) . '@example.com';
$userId = User::create($pdo, $email, 'Progress1', 'Progress');
$slug = 'elke-en';

LessonProgress::touch($pdo, $userId, $slug, 3);
usleep(1100000);
LessonProgress::touch($pdo, $userId, $slug, 1);
ok(LessonProgress::lastOpenedNum($pdo, $userId, $slug) === 1, 'latest visit is the resume lesson');

$open = $pdo->prepare('SELECT COUNT(*) FROM lesson_opens WHERE user_id = ?');
$open->execute([$userId]);
ok((int)$open->fetchColumn() === 0, 'opening a lesson does not write lesson_opens');

LessonProgress::setCompleted($pdo, $userId, $slug, 3, true);
ok(isset(LessonProgress::completedMap($pdo, $userId, $slug)[3]), 'lesson 3 is marked done');
ok(!isset(LessonProgress::completedMap($pdo, $userId, $slug)[1]), 'lesson 1 stays unmarked');
ok(LessonProgress::lastOpenedNum($pdo, $userId, $slug) === 1, 'marking done does not move the resume pointer');

$row = $pdo->prepare('SELECT last_opened_at, completed_at FROM lesson_progress WHERE user_id = ? AND lesson_num = 3');
$row->execute([$userId]);
$lesson3 = $row->fetch();
ok(is_array($lesson3) && $lesson3['last_opened_at'] !== null && $lesson3['last_opened_at'] !== '', 'done mark keeps the earlier visit');
ok(is_array($lesson3) && is_string($lesson3['completed_at']) && $lesson3['completed_at'] !== '', 'done mark stores a time');

LessonProgress::setCompleted($pdo, $userId, $slug, 3, false);
$cleared = $pdo->prepare('SELECT completed_at FROM lesson_progress WHERE user_id = ? AND lesson_num = 3');
$cleared->execute([$userId]);
ok($cleared->fetchColumn() === null, 'clearing the mark removes the time');

$access = [
    1 => ['can_view_lesson' => false],
    3 => ['can_view_lesson' => true],
];
ok(LessonProgress::resumeLessonNum($pdo, $userId, $slug, $access) === null, 'locked last lesson is not resumed');
LessonProgress::touch($pdo, $userId, $slug, 3);
ok(LessonProgress::resumeLessonNum($pdo, $userId, $slug, $access) === 3, 'resume uses the latest lesson the student can still open');

$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

if ($failed > 0) {
    echo "{$failed} failed\n";
    exit(1);
}
echo "all passed\n";
