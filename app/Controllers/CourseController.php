<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Auth\Session;
use Wwm\Models\User;
use Wwm\Services\AccessChecker;
use Wwm\Services\CourseCatalog;

final class CourseController
{
    public function show(string $slug): void
    {
        $userId = Session::requireLogin();
        $user = User::findById(wwm_pdo(), $userId);
        $catalog = new CourseCatalog();
        $course = $catalog->get($slug);

        if ($course === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Course not found.']);
            return;
        }

        $checker = new AccessChecker(wwm_pdo());
        $lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
        $firstLesson = $lessons[0] ?? null;
        $access = $firstLesson
            ? $checker->lesson($userId, $course, $firstLesson)
            : ['can_view_course' => false, 'can_view_lesson' => false, 'access_label' => 'No access'];

        if (!$access['can_view_course']) {
            wwm_render('no-access', [
                'pageTitle' => (string)$course['title'],
                'course' => $course,
                'user' => $user,
            ]);
            return;
        }

        $lessonAccess = [];
        foreach ($lessons as $lesson) {
            if (!is_array($lesson)) {
                continue;
            }
            $la = $checker->lesson($userId, $course, $lesson);
            $lessonAccess[(int)($lesson['num'] ?? 0)] = $la;
        }

        foreach ($lessons as $lesson) {
            if (!is_array($lesson)) {
                continue;
            }
            $num = (int)($lesson['num'] ?? 0);
            $la = $lessonAccess[$num] ?? ['can_view_lesson' => false];
            if (!empty($la['can_view_lesson']) && $num > 0) {
                wwm_redirect('/c/' . rawurlencode($slug) . '/' . $num);
            }
        }

        wwm_render('course', [
            'pageTitle' => (string)$course['title'],
            'user' => $user,
            'course' => $course,
            'lessonAccess' => $lessonAccess,
            'accessLabel' => $access['access_label'],
        ]);
    }
}
