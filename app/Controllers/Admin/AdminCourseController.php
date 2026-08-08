<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\User;
use Wwm\Services\AdminStats;
use Wwm\Services\CourseCatalog;
use Wwm\Services\CourseWriter;

final class AdminCourseController
{
    public function index(): void
    {
        $userId = Session::requireAdmin();
        $user = User::findById(wwm_pdo(), $userId);
        $catalog = new CourseCatalog();
        $stats = new AdminStats(wwm_pdo());
        $accessByCourse = $stats->accessByCourse();

        $rows = [];
        foreach ($catalog->all() as $course) {
            $slug = (string)$course['slug'];
            $access = $accessByCourse[$slug] ?? ['paid' => 0, 'demo_active' => 0];
            $rows[] = [
                'course' => $course,
                'sections' => CourseWriter::sectionCount($course),
                'lessons' => CourseWriter::lessonCount($course),
                'paid' => $access['paid'],
                'demo_active' => $access['demo_active'],
                'published' => CourseWriter::isPublished($course),
            ];
        }

        wwm_render_admin('courses', [
            'pageTitle' => 'Courses — Admin',
            'user' => $user,
            'adminNav' => 'courses',
            'rows' => $rows,
            'totalPaid' => $stats->totalPaidStudents(),
            'totalDemo' => $stats->totalDemoActive(),
        ]);
    }

    public function edit(string $slug): void
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

        wwm_render_admin('course-edit', [
            'pageTitle' => 'Edit course — ' . ($course['title'] ?? $slug),
            'user' => $user,
            'adminNav' => 'courses',
            'course' => $course,
            'saved' => isset($_GET['saved']),
            'error' => $_GET['error'] ?? null,
        ]);
    }

    public function update(string $slug): void
    {
        Session::requireAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/courses/' . rawurlencode($slug) . '?error=csrf');
        }

        $catalog = new CourseCatalog();
        $course = $catalog->getAdmin($slug);
        if ($course === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Course not found.']);
            return;
        }

        $course['title'] = trim((string)($_POST['title'] ?? $course['title'] ?? ''));
        $course['subtitle'] = trim((string)($_POST['subtitle'] ?? $course['subtitle'] ?? ''));
        $course['buy_url'] = trim((string)($_POST['buy_url'] ?? $course['buy_url'] ?? ''));
        $course['cover_image'] = trim((string)($_POST['cover_image'] ?? $course['cover_image'] ?? ''));
        $course['avo_goods_id'] = (int)($_POST['avo_goods_id'] ?? $course['avo_goods_id'] ?? 0) ?: null;
        $course['avo_training_id'] = (int)($_POST['avo_training_id'] ?? $course['avo_training_id'] ?? 0) ?: null;
        $course['status'] = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
        $course['demo_hours'] = max(1, (int)($_POST['demo_hours'] ?? $course['demo_hours'] ?? 48));
        unset($course['demo_lessons']);

        $demoFlags = $_POST['lesson_demo'] ?? [];
        if (is_array($demoFlags) && is_array($course['lessons'] ?? null)) {
            foreach ($course['lessons'] as $i => $lesson) {
                if (!is_array($lesson)) {
                    continue;
                }
                $num = (int)($lesson['num'] ?? 0);
                $course['lessons'][$i]['demo'] = isset($demoFlags[(string)$num]);
            }
        }

        $course = CourseWriter::applyLessonOrder($course, $_POST);

        $sectionTitles = $_POST['section_title'] ?? [];
        if (is_array($sectionTitles) && is_array($course['sections'] ?? null)) {
            foreach ($course['sections'] as $i => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $key = (string)$i;
                if (array_key_exists($key, $sectionTitles)) {
                    $course['sections'][$i]['title'] = trim((string)$sectionTitles[$key]);
                }
            }
        }

        try {
            (new CourseWriter())->save($slug, $course);
        } catch (\Throwable $e) {
            wwm_log('Course save failed: ' . $e->getMessage());
            wwm_redirect('/admin/courses/' . rawurlencode($slug) . '?error=save');
        }

        wwm_redirect('/admin/courses/' . rawurlencode($slug) . '?saved=1');
    }

    public function createForm(): void
    {
        $userId = Session::requireAdmin();
        $user = User::findById(wwm_pdo(), $userId);

        wwm_render_admin('course-create', [
            'pageTitle' => 'Add course — Admin',
            'user' => $user,
            'adminNav' => 'courses',
            'error' => match ($_GET['error'] ?? '') {
                'csrf' => 'Session expired. Please try again.',
                default => null,
            },
        ]);
    }

    public function store(): void
    {
        Session::requireAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/courses/new?error=csrf');
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?? '';

        if ($title === '' || $slug === '') {
            wwm_render_admin('course-create', [
                'pageTitle' => 'Add course — Admin',
                'user' => User::findById(wwm_pdo(), Session::userId() ?? 0),
                'adminNav' => 'courses',
                'error' => 'Title and URL slug are required.',
            ]);
            return;
        }

        $catalog = new CourseCatalog();
        if ($catalog->getAdmin($slug) !== null || (new CourseWriter())->exists($slug)) {
            wwm_render_admin('course-create', [
                'pageTitle' => 'Add course — Admin',
                'user' => User::findById(wwm_pdo(), Session::userId() ?? 0),
                'adminNav' => 'courses',
                'error' => 'A course with this slug already exists.',
            ]);
            return;
        }

        $course = [
            'slug' => $slug,
            'title' => $title,
            'subtitle' => trim((string)($_POST['subtitle'] ?? '')),
            'buy_url' => trim((string)($_POST['buy_url'] ?? '')),
            'status' => 'draft',
            'demo_hours' => max(1, (int)($_POST['demo_hours'] ?? 48)),
            'lessons' => [],
        ];

        try {
            (new CourseWriter())->save($slug, $course);
        } catch (\Throwable $e) {
            wwm_log('Course create failed: ' . $e->getMessage());
            wwm_render_admin('course-create', [
                'pageTitle' => 'Add course — Admin',
                'user' => User::findById(wwm_pdo(), Session::userId() ?? 0),
                'adminNav' => 'courses',
                'error' => 'Failed to create course.',
            ]);
            return;
        }

        wwm_log('admin created course slug=' . $slug);
        wwm_redirect('/admin/courses/' . rawurlencode($slug) . '?saved=1');
    }

    public function storeSection(string $slug): void
    {
        Session::requireAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/courses/' . rawurlencode($slug) . '?error=csrf');
        }

        $catalog = new CourseCatalog();
        $course = $catalog->getAdmin($slug);
        if ($course === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Course not found.']);
            return;
        }

        $title = trim((string)($_POST['title'] ?? 'New section'));
        $course = CourseWriter::addSection($course, $title);

        try {
            (new CourseWriter())->save($slug, $course);
        } catch (\Throwable $e) {
            wwm_log('Section create failed: ' . $e->getMessage());
            wwm_redirect('/admin/courses/' . rawurlencode($slug) . '?error=save');
        }

        wwm_log('admin created section course=' . $slug);
        wwm_redirect('/admin/courses/' . rawurlencode($slug) . '?saved=1#structure');
    }
}
