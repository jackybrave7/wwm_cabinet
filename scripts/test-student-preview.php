<?php
declare(strict_types=1);

/**
 * An administrator viewing a student's cabinet cannot mark lessons done
 * or record that a lesson was opened.
 *
 *   php scripts/test-student-preview.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Auth\Session;
use Wwm\Controllers\LessonController;
use Wwm\Models\Access;
use Wwm\Models\LessonOpen;
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
$adminEmail = 'preview-admin-' . bin2hex(random_bytes(3)) . '@example.com';
$studentEmail = 'preview-student-' . bin2hex(random_bytes(3)) . '@example.com';
$adminId = User::create($pdo, $adminEmail, 'PreviewAdmin1', 'Preview Admin');
$studentId = User::create($pdo, $studentEmail, 'PreviewStudent1', 'Preview Student');
User::setAdminPermissions($pdo, $adminId, ['students' => true]);

$slug = 'elke-en';
Access::grant($pdo, $studentId, $slug, 'paid', null, 'test');
LessonProgress::touch($pdo, $studentId, $slug, 2);
$before = $pdo->prepare('SELECT last_opened_at, completed_at FROM lesson_progress WHERE user_id = ? AND course_slug = ? AND lesson_num = 2');
$before->execute([$studentId, $slug]);
$beforeRow = $before->fetch(PDO::FETCH_ASSOC);

Session::login($adminId);
Session::startStudentPreview($studentId);
ok(Session::studentPreviewId() === $studentId, 'preview points at the student');
ok(Session::cabinetUserId() === $studentId, 'cabinet reads use the student');

ob_start();
(new LessonController())->show($slug, 2);
(new LessonController())->markDone($slug, 2);
(new LessonController())->markViewed($slug, 2);
ob_end_clean();

$after = $pdo->prepare('SELECT last_opened_at, completed_at FROM lesson_progress WHERE user_id = ? AND course_slug = ? AND lesson_num = 2');
$after->execute([$studentId, $slug]);
$afterRow = $after->fetch(PDO::FETCH_ASSOC);
ok(is_array($afterRow) && $afterRow['completed_at'] === null, 'preview does not mark the lesson done');
ok(is_array($beforeRow) && is_array($afterRow) && $beforeRow['last_opened_at'] === $afterRow['last_opened_at'], 'preview does not move the resume pointer');

$opens = $pdo->prepare('SELECT COUNT(*) FROM lesson_opens WHERE user_id = ?');
$opens->execute([$studentId]);
ok((int)$opens->fetchColumn() === 0, 'preview does not record a lesson open');

Session::stopStudentPreview();
ok(Session::studentPreviewId() === null, 'leaving the cabinet clears the preview');

$pdo->prepare('DELETE FROM lesson_progress WHERE user_id = ?')->execute([$studentId]);
$pdo->prepare('DELETE FROM access WHERE user_id = ?')->execute([$studentId]);
$pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$adminId, $studentId]);

echo $failed === 0 ? "ALL OK\n" : "FAILURES {$failed}\n";
exit($failed === 0 ? 0 : 1);
