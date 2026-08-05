<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\User;
use Wwm\Services\CourseCatalog;
use Wwm\Services\CourseWriter;

final class AdminLessonController
{
    public function edit(string $slug, int $num): void
    {
        $userId = Session::requireAdmin();
        $user = User::findById(wwm_pdo(), $userId);
        $catalog = new CourseCatalog();
        $course = $catalog->getAdmin($slug);

        if ($course === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Course not found.']);
            return;
        }

        $lesson = CourseWriter::findLesson($course, $num);
        if ($lesson === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Lesson not found.']);
            return;
        }

        wwm_render_admin('lesson-edit', [
            'pageTitle' => 'Edit lesson — ' . ($lesson['title'] ?? 'Lesson'),
            'user' => $user,
            'adminNav' => 'courses',
            'course' => $course,
            'lesson' => $lesson,
            'sectionIndex' => CourseWriter::sectionIndexForLesson($course, $num),
            'saved' => isset($_GET['saved']),
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function update(string $slug, int $num): void
    {
        Session::requireAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/courses/' . rawurlencode($slug) . '/lessons/' . $num . '?error=csrf');
        }

        $catalog = new CourseCatalog();
        $course = $catalog->getAdmin($slug);
        if ($course === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Course not found.']);
            return;
        }

        $found = false;
        $lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
        foreach ($lessons as $i => $lesson) {
            if (!is_array($lesson) || (int)($lesson['num'] ?? 0) !== $num) {
                continue;
            }
            $found = true;
            $lessons[$i]['title'] = trim((string)($_POST['title'] ?? $lesson['title'] ?? ''));
            $lessons[$i]['duration'] = trim((string)($_POST['duration'] ?? $lesson['duration'] ?? ''));
            $lessons[$i]['demo'] = !empty($_POST['demo']);

            $htmlBody = wwm_sanitize_lesson_html($_POST['html_body'] ?? '');
            if ($htmlBody !== '') {
                $lessons[$i]['html_body'] = $htmlBody;
                unset($lessons[$i]['description'], $lessons[$i]['video']);
            } else {
                unset($lessons[$i]['html_body'], $lessons[$i]['video']);
                $lessons[$i]['description'] = trim((string)($_POST['description'] ?? $lesson['description'] ?? ''));
            }

            $titles = $_POST['material_title'] ?? [];
            $urls = $_POST['material_url'] ?? [];
            $materials = [];
            if (is_array($titles) && is_array($urls)) {
                foreach ($titles as $j => $title) {
                    $title = trim((string)$title);
                    $url = trim((string)($urls[$j] ?? ''));
                    if ($title === '' && $url === '') {
                        continue;
                    }
                    $materials[] = [
                        'title' => $title !== '' ? $title : $url,
                        'url' => $url !== '' ? $url : $title,
                    ];
                }
            }
            $lessons[$i]['materials'] = $materials;
            break;
        }

        if (!$found) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Lesson not found.']);
            return;
        }

        $course['lessons'] = $lessons;

        if (isset($_POST['section_index']) && $_POST['section_index'] !== '') {
            $course = CourseWriter::setLessonSection($course, $num, (int)$_POST['section_index']);
        }

        try {
            (new CourseWriter())->save($slug, $course);
        } catch (\Throwable $e) {
            wwm_log('Lesson save failed: ' . $e->getMessage());
            wwm_redirect('/admin/courses/' . rawurlencode($slug) . '/lessons/' . $num . '?error=save');
        }

        wwm_redirect('/admin/courses/' . rawurlencode($slug) . '/lessons/' . $num . '?saved=1');
    }
}
