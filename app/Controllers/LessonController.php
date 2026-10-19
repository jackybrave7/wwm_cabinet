<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Auth\Session;
use Wwm\Models\LessonOpen;
use Wwm\Models\User;
use Wwm\Services\AccessChecker;
use Wwm\Services\AvoEngagementSync;
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

        // Opening the URL (including auto-login after signup) is not a view.
        // The page pings markViewed after dwell or video start.

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
            'demoExpiresAt' => $access['demo_expires_at'] ?? null,
            'prevLesson' => $prevLesson,
            'nextLesson' => $nextLesson,
            'prevLocked' => $prevLocked,
            'nextLocked' => $nextLocked,
            'trackLessonView' => !empty($lesson['demo']),
        ]);
    }

    public function markViewed(string $slug, int $num): void
    {
        $userId = Session::requireLogin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo '{"ok":false}';
            return;
        }

        $catalog = new CourseCatalog();
        $course = $catalog->get($slug);
        if (!is_array($course)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo '{"ok":false}';
            return;
        }
        $lesson = null;
        foreach (is_array($course['lessons'] ?? null) ? $course['lessons'] : [] as $item) {
            if (is_array($item) && (int)($item['num'] ?? 0) === $num) {
                $lesson = $item;
                break;
            }
        }
        if ($course === null || $lesson === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo '{"ok":false}';
            return;
        }

        $access = (new AccessChecker(wwm_pdo()))->lesson($userId, $course, $lesson);
        if (empty($access['can_view_lesson'])) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo '{"ok":false}';
            return;
        }

        $first = LessonOpen::record(wwm_pdo(), $userId, $slug, $num);
        if ($first && !empty($lesson['demo'])) {
            AvoEngagementSync::onDemoLessonOpen($userId);
        }

        header('Content-Type: application/json');
        echo '{"ok":true}';
    }
}
