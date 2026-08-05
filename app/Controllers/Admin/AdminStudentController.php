<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\Access;
use Wwm\Models\LessonOpen;
use Wwm\Models\User;
use Wwm\Services\AdminStats;
use Wwm\Services\CourseCatalog;
use Wwm\Services\CourseWriter;

final class AdminStudentController
{
    public function index(): void
    {
        $userId = Session::requireAdmin();
        $user = User::findById(wwm_pdo(), $userId);
        $search = isset($_GET['q']) ? trim((string)$_GET['q']) : null;
        $pdo = wwm_pdo();
        $catalog = new CourseCatalog();
        $stats = new AdminStats($pdo);

        $students = [];
        foreach (User::all($pdo, $search) as $row) {
            $id = (int)$row['id'];
            $accessRows = Access::forUser($pdo, $id);
            $courseProgress = [];
            $totalOpened = 0;
            $totalLessons = 0;

            foreach ($catalog->all() as $course) {
                if (!CourseWriter::isPublished($course)) {
                    continue;
                }
                $slug = (string)$course['slug'];
                $state = Access::courseState($pdo, $id, $slug);
                if (!$state['has_paid'] && !$state['demo_active']) {
                    continue;
                }
                $lessonCount = CourseWriter::lessonCount($course);
                $opened = LessonOpen::countForUserCourse($pdo, $id, $slug);
                $totalOpened += $opened;
                $totalLessons += $lessonCount;
                $courseProgress[] = [
                    'slug' => $slug,
                    'title' => (string)($course['title'] ?? $slug),
                    'opened' => $opened,
                    'total' => $lessonCount,
                    'access' => $state['has_paid'] ? 'Paid' : 'Demo',
                ];
            }

            $students[] = [
                'user' => $row,
                'access_label' => $stats->accessLabelForUser($id),
                'courses' => $courseProgress,
                'opened' => $totalOpened,
                'total' => $totalLessons,
                'last_activity' => LessonOpen::lastActivity($pdo, $id),
            ];
        }

        wwm_render_admin('students', [
            'pageTitle' => 'Students — Admin',
            'user' => $user,
            'adminNav' => 'students',
            'students' => $students,
            'search' => $search ?? '',
            'totalStudents' => count($students),
        ]);
    }

    public function show(int $id): void
    {
        $adminId = Session::requireAdmin();
        $admin = User::findById(wwm_pdo(), $adminId);
        $pdo = wwm_pdo();
        $student = User::findById($pdo, $id);

        if ($student === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Student not found.']);
            return;
        }

        $catalog = new CourseCatalog();
        $stats = new AdminStats($pdo);
        $courseBlocks = [];
        $totalOpened = 0;
        $totalLessons = 0;

        foreach ($catalog->all() as $course) {
            $slug = (string)$course['slug'];
            $state = Access::courseState($pdo, $id, $slug);
            if (!$state['has_paid'] && !$state['demo_active']) {
                continue;
            }

            $opens = LessonOpen::forUserCourse($pdo, $id, $slug);
            $lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
            $lessonRows = [];
            foreach ($lessons as $lesson) {
                if (!is_array($lesson)) {
                    continue;
                }
                $num = (int)($lesson['num'] ?? 0);
                $open = $opens[$num] ?? null;
                if ($open !== null) {
                    $totalOpened++;
                }
                $totalLessons++;
                $lessonRows[] = [
                    'num' => $num,
                    'title' => (string)($lesson['title'] ?? ''),
                    'opened' => $open !== null,
                    'first_opened_at' => $open['first_opened_at'] ?? null,
                    'last_opened_at' => $open['last_opened_at'] ?? null,
                ];
            }

            $courseBlocks[] = [
                'course' => $course,
                'access' => $state['has_paid'] ? 'Paid' : 'Demo',
                'lessons' => $lessonRows,
                'opened' => count($opens),
                'total' => count($lessonRows),
            ];
        }

        wwm_render_admin('student-view', [
            'pageTitle' => 'Student — ' . ($student['name'] ?: $student['email']),
            'user' => $admin,
            'adminNav' => 'students',
            'student' => $student,
            'access_label' => $stats->accessLabelForUser($id),
            'course_blocks' => $courseBlocks,
            'total_opened' => $totalOpened,
            'total_lessons' => $totalLessons,
            'last_activity' => LessonOpen::lastActivity($pdo, $id),
        ]);
    }
}
