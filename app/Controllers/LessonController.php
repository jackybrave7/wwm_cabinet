<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Auth\Session;
use Wwm\Models\User;
use Wwm\Services\AccessChecker;
use Wwm\Services\CourseCatalog;
use Wwm\Services\CourseWriter;

final class LessonController
{
    public function show(string $slug, int $num): void
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

        $lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
        $lesson = null;
        foreach ($lessons as $item) {
            if (is_array($item) && (int)($item['num'] ?? 0) === $num) {
                $lesson = $item;
                break;
            }
        }

        if ($lesson === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Lesson not found.']);
            return;
        }

        $checker = new AccessChecker(wwm_pdo());
        $access = $checker->lesson($userId, $course, $lesson);

        if (!$access['can_view_lesson']) {
            wwm_render('no-access', [
                'pageTitle' => (string)($lesson['title'] ?? 'Lesson'),
                'course' => $course,
                'user' => $user,
                'upgrade' => true,
            ]);
            return;
        }

        \Wwm\Models\LessonOpen::record(wwm_pdo(), $userId, $slug, $num);

        $lessonAccess = [];
        foreach ($lessons as $item) {
            if (!is_array($item)) {
                continue;
            }
            $la = $checker->lesson($userId, $course, $item);
            $lessonAccess[(int)($item['num'] ?? 0)] = $la;
        }

        $adjacent = CourseWriter::adjacentLessons($course, $num);
        $prevLesson = $adjacent['prev'];
        $nextLesson = $adjacent['next'];
        $prevLocked = $prevLesson !== null
            && empty($lessonAccess[(int)($prevLesson['num'] ?? 0)]['can_view_lesson']);
        $nextLocked = $nextLesson !== null
            && empty($lessonAccess[(int)($nextLesson['num'] ?? 0)]['can_view_lesson']);

        wwm_render('lesson', [
            'pageTitle' => (string)($lesson['title'] ?? 'Lesson'),
            'user' => $user,
            'course' => $course,
            'lesson' => $lesson,
            'lessonAccess' => $lessonAccess,
            'accessLabel' => $access['access_label'],
            'prevLesson' => $prevLesson,
            'nextLesson' => $nextLesson,
            'prevLocked' => $prevLocked,
            'nextLocked' => $nextLocked,
        ]);
    }
}
