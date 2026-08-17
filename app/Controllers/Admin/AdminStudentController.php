<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\Access;
use Wwm\Models\EmailMessage;
use Wwm\Models\LessonOpen;
use Wwm\Models\User;
use Wwm\Services\AccessPeriod;
use Wwm\Services\CourseCatalog;
use Wwm\Services\CourseWriter;
use Wwm\Services\AvoContactName;
use Wwm\Services\AvoEngagementSync;
use Wwm\Services\AvoClient;
use Wwm\Services\StudentAttribution;

final class AdminStudentController
{
    private const STUDENTS_PER_PAGE = 50;

    public function index(): void
    {
        $userId = Session::requireAdmin();
        $user = User::findById(wwm_pdo(), $userId);
        $search = isset($_GET['q']) ? trim((string)$_GET['q']) : null;
        $search = $search === '' ? null : $search;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pdo = wwm_pdo();
        $catalog = new CourseCatalog();

        $publishedCourses = [];
        foreach ($catalog->all() as $course) {
            if (!CourseWriter::isPublished($course)) {
                continue;
            }
            $publishedCourses[] = $course;
        }

        $pagination = User::paginate($pdo, $search, $page, self::STUDENTS_PER_PAGE);
        $accessByUser = Access::groupedByUser($pdo);
        $openCountsByUser = LessonOpen::openCountsGrouped($pdo);
        $lastActivityByUser = LessonOpen::lastActivityGrouped($pdo);

        $students = [];
        foreach ($pagination['rows'] as $row) {
            $id = (int)$row['id'];
            $userAccessRows = $accessByUser[$id] ?? [];
            $stateMap = Access::stateMapFromRows($userAccessRows);
            $userOpenCounts = $openCountsByUser[$id] ?? [];
            $courseProgress = [];
            $totalOpened = 0;
            $totalLessons = 0;

            foreach ($publishedCourses as $course) {
                $slug = (string)$course['slug'];
                $state = $stateMap[$slug] ?? [
                    'has_paid' => false,
                    'has_demo' => false,
                    'demo_active' => false,
                    'paid_active' => false,
                ];
                if (!$state['has_paid'] && !$state['demo_active']) {
                    continue;
                }

                $lessonCount = CourseWriter::lessonCount($course);
                $opened = (int)($userOpenCounts[$slug] ?? 0);
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
                'access_label' => Access::accessLabelFromStateMap($stateMap),
                'courses' => $courseProgress,
                'opened' => $totalOpened,
                'total' => $totalLessons,
                'last_activity' => $lastActivityByUser[$id] ?? null,
            ];
        }

        $totalStudents = (int)$pagination['total'];
        $totalPages = max(1, (int)ceil($totalStudents / self::STUDENTS_PER_PAGE));

        wwm_render_admin('students', [
            'pageTitle' => 'Students — Admin',
            'user' => $user,
            'adminNav' => 'students',
            'students' => $students,
            'search' => $search ?? '',
            'totalStudents' => $totalStudents,
            'page' => $page,
            'totalPages' => $totalPages,
            'message' => isset($_GET['created']) ? 'Student created.' : null,
            'error' => match ($_GET['error'] ?? '') {
                'exists' => 'A student with this email already exists.',
                'delete_self' => 'You cannot delete your own account.',
                'delete_admin' => 'Admin accounts cannot be deleted.',
                default => null,
            },
        ]);
    }

    public function createForm(): void
    {
        $userId = Session::requireAdmin();
        $user = User::findById(wwm_pdo(), $userId);

        wwm_render_admin('student-create', [
            'pageTitle' => 'Add student — Admin',
            'user' => $user,
            'adminNav' => 'students',
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
            wwm_redirect('/admin/students/new?error=csrf');
        }

        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->renderCreateForm('Please enter a valid email.');
            return;
        }

        $pdo = wwm_pdo();
        if (User::findByEmail($pdo, $email) !== null) {
            wwm_redirect('/admin/students?error=exists');
        }

        if ($password === '') {
            $password = trim((string)(wwm_config()['demo_default_password'] ?? ''));
        }
        if ($password === '') {
            $password = bin2hex(random_bytes(8));
        }

        $userId = User::create($pdo, $email, $password, $name);
        wwm_log('admin created student user_id=' . $userId);

        wwm_redirect('/admin/students/' . $userId . '?created=1');
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
        $userAccessRows = Access::forUser($pdo, $id);
        $stateMap = Access::stateMapFromRows($userAccessRows);
        $grants = Access::grantsByCourseFromRows($userAccessRows);
        $opensByCourse = LessonOpen::groupedForUser($pdo, $id);
        $courseBlocks = [];
        $accessCourses = [];
        $totalOpened = 0;
        $totalLessons = 0;

        foreach ($catalog->all() as $course) {
            $slug = (string)$course['slug'];
            $demoGrant = $grants[$slug . ':demo'] ?? null;
            $paidGrant = $grants[$slug . ':paid'] ?? null;

            $accessCourses[] = [
                'course' => $course,
                'published' => CourseWriter::isPublished($course),
                'demo' => $this->grantView($demoGrant),
                'paid' => $this->grantView($paidGrant),
            ];

            $state = $stateMap[$slug] ?? [
                'has_paid' => false,
                'has_demo' => false,
                'demo_active' => false,
                'paid_active' => false,
            ];
            if (!$state['has_paid'] && !$state['demo_active']) {
                continue;
            }

            $opens = $opensByCourse[$slug] ?? [];
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

        $lastActivity = null;
        foreach ($opensByCourse as $courseOpens) {
            foreach ($courseOpens as $open) {
                $openedAt = (string)($open['last_opened_at'] ?? '');
                if ($openedAt !== '' && ($lastActivity === null || $openedAt > $lastActivity)) {
                    $lastActivity = $openedAt;
                }
            }
        }

        $avoClient = new AvoClient();
        $avoContactId = (int)($student['avo_contact_id'] ?? 0);
        if ($avoContactId <= 0 && $avoClient->isEnabled()) {
            $email = trim((string)($student['email'] ?? ''));
            if ($email !== '') {
                $found = $avoClient->findContactIdByEmail($email);
                if ($found !== null && $found > 0) {
                    $avoContactId = $found;
                }
            }
        }

        wwm_render_admin('student-view', [
            'pageTitle' => 'Student — ' . ($student['name'] ?: $student['email']),
            'user' => $admin,
            'adminNav' => 'students',
            'student' => $student,
            'access_label' => Access::accessLabelFromStateMap($stateMap),
            'access_courses' => $accessCourses,
            'access_periods' => AccessPeriod::presets(),
            'course_blocks' => $courseBlocks,
            'total_opened' => $totalOpened,
            'total_lessons' => $totalLessons,
            'last_activity' => $lastActivity,
            'avo_enabled' => $avoClient->isEnabled(),
            'avo_contact_id' => $avoContactId > 0 ? $avoContactId : null,
            'avo_logged_in_tagged' => !empty($student['avo_logged_in_tagged']),
            'avo_demo_opened_tagged' => !empty($student['avo_demo_opened_tagged']),
            'avo_has_logged_in_tag' => $avoContactId > 0 && $avoClient->tagId('logged_in') > 0
                ? $avoClient->contactHasTag($avoContactId, $avoClient->tagId('logged_in'))
                : null,
            'avo_has_demo_opened_tag' => $avoContactId > 0 && $avoClient->tagId('demo_opened') > 0
                ? $avoClient->contactHasTag($avoContactId, $avoClient->tagId('demo_opened'))
                : null,
            'email_messages' => EmailMessage::forUser($pdo, $id),
            'message' => match ($_GET['created'] ?? '') {
                '1' => 'Student created.',
                'access' => 'Access updated.',
                'revoked' => 'Access removed.',
                'avo' => 'AVO tags and UTM synced.',
                'avo_name' => 'Name updated from AVO.',
                'avo_name_utm' => 'Name, AVO tags, and UTM synced.',
                'avo_tags' => 'AVO tags synced. UTM could not be resolved from AVO API — check cabinet.log.',
                default => null,
            },
            'error' => match ($_GET['error'] ?? '') {
                'csrf' => 'Session expired. Please try again.',
                'course' => 'Course not found.',
                'period' => 'Invalid access period or date.',
                default => null,
            },
        ]);
    }

    public function grantAccess(int $id): void
    {
        Session::requireAdmin();

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/students/' . $id . '?error=csrf');
        }

        $pdo = wwm_pdo();
        if (User::findById($pdo, $id) === null) {
            http_response_code(404);
            return;
        }

        $courseSlug = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['course_slug'] ?? '')) ?: '';
        $accessType = (string)($_POST['access_type'] ?? '');
        $period = (string)($_POST['period'] ?? '');
        $expiresDate = trim((string)($_POST['expires_date'] ?? ''));

        if ($courseSlug === '' || (new CourseCatalog())->getAdmin($courseSlug) === null) {
            wwm_redirect('/admin/students/' . $id . '?error=course');
        }

        if (!in_array($accessType, ['demo', 'paid'], true)) {
            wwm_redirect('/admin/students/' . $id . '?error=course');
        }

        try {
            $expiresAt = AccessPeriod::resolve($period, $expiresDate !== '' ? $expiresDate : null);
        } catch (\InvalidArgumentException) {
            wwm_redirect('/admin/students/' . $id . '?error=period');
        }

        Access::grant($pdo, $id, $courseSlug, $accessType, $expiresAt, 'admin', 'manual');
        wwm_log(sprintf('admin grant access user_id=%d course=%s type=%s', $id, $courseSlug, $accessType));

        wwm_redirect('/admin/students/' . $id . '?created=access');
    }

    public function revokeAccess(int $id): void
    {
        Session::requireAdmin();

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/students/' . $id . '?error=csrf');
        }

        $pdo = wwm_pdo();
        if (User::findById($pdo, $id) === null) {
            http_response_code(404);
            return;
        }

        $courseSlug = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['course_slug'] ?? '')) ?: '';
        $accessType = (string)($_POST['access_type'] ?? '');

        if ($courseSlug === '' || !in_array($accessType, ['demo', 'paid'], true)) {
            wwm_redirect('/admin/students/' . $id . '?error=course');
        }

        Access::revoke($pdo, $id, $courseSlug, $accessType);
        wwm_log(sprintf('admin revoke access user_id=%d course=%s type=%s', $id, $courseSlug, $accessType));

        wwm_redirect('/admin/students/' . $id . '?created=revoked');
    }

    public function destroy(int $id): void
    {
        $adminId = Session::requireAdmin();

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/students/' . $id . '?error=csrf');
        }

        if ($id === $adminId) {
            wwm_redirect('/admin/students?error=delete_self');
        }

        $pdo = wwm_pdo();
        $student = User::findById($pdo, $id);
        if ($student === null) {
            wwm_redirect('/admin/students');
        }

        if (User::isAdmin($student)) {
            wwm_redirect('/admin/students?error=delete_admin');
        }

        User::delete($pdo, $id);
        wwm_log('admin deleted student user_id=' . $id);

        wwm_redirect('/admin/students');
    }

    /**
     * @param array<string, mixed>|null $grant
     * @return array{active: bool, label: string, expires_at: ?string}
     */
    private function grantView(?array $grant): array
    {
        if ($grant === null) {
            return ['active' => false, 'label' => 'None', 'expires_at' => null];
        }

        $expiresAt = isset($grant['expires_at']) && is_string($grant['expires_at']) && $grant['expires_at'] !== ''
            ? $grant['expires_at']
            : null;
        $active = Access::isGrantActive($grant);

        if ($active && $expiresAt === null) {
            $label = 'Active · no expiry';
        } elseif ($active) {
            $label = 'Active until ' . gmdate('Y-m-d H:i', strtotime($expiresAt) ?: 0) . ' UTC';
        } elseif ($expiresAt !== null) {
            $label = 'Expired';
        } else {
            $label = 'Inactive';
        }

        return ['active' => $active, 'label' => $label, 'expires_at' => $expiresAt];
    }

    public function resyncAvo(int $id): void
    {
        Session::requireAdmin();

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/students/' . $id . '?error=csrf');
        }

        $student = User::findById(wwm_pdo(), $id);
        if ($student === null) {
            http_response_code(404);
            wwm_render('error', ['pageTitle' => 'Not found', 'code' => 404, 'message' => 'Student not found.']);
            return;
        }

        AvoEngagementSync::resync($id);
        $nameSynced = AvoContactName::backfillFromAvo(wwm_pdo(), $id);
        $utmSynced = StudentAttribution::backfillUtmFromAvo(wwm_pdo(), $id);
        $created = match (true) {
            $nameSynced && $utmSynced => 'avo_name_utm',
            $nameSynced => 'avo_name',
            $utmSynced => 'avo',
            default => 'avo_tags',
        };
        wwm_redirect('/admin/students/' . $id . '?created=' . $created);
    }

    private function renderCreateForm(string $error): void
    {
        $userId = Session::requireAdmin();
        $user = User::findById(wwm_pdo(), $userId);

        wwm_render_admin('student-create', [
            'pageTitle' => 'Add student — Admin',
            'user' => $user,
            'adminNav' => 'students',
            'error' => $error,
        ]);
    }
}
