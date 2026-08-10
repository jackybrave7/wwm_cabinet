<?php
declare(strict_types=1);

namespace Wwm;

use Wwm\Controllers\AccountController;
use Wwm\Controllers\Admin\AdminCourseController;
use Wwm\Controllers\Admin\AdminLessonController;
use Wwm\Controllers\Admin\AdminMailController;
use Wwm\Controllers\Admin\AdminStudentController;
use Wwm\Controllers\Api\DemoWebhookController;
use Wwm\Controllers\Api\EngagementController;
use Wwm\Controllers\Api\MailWebhookController;
use Wwm\Controllers\Api\PaymentWebhookController;
use Wwm\Controllers\AuthController;
use Wwm\Controllers\CourseController;
use Wwm\Controllers\DashboardController;
use Wwm\Controllers\EmailTrackingController;
use Wwm\Controllers\LessonController;

final class Router
{
    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        if ($method === 'GET' && $path === '/login') {
            (new AuthController())->showLogin();
            return;
        }
        if ($method === 'POST' && $path === '/login') {
            (new AuthController())->login();
            return;
        }
        if ($method === 'GET' && $path === '/auth/magic') {
            (new AuthController())->consumeMagicLink();
            return;
        }
        if ($method === 'POST' && $path === '/auth/magic/request') {
            (new AuthController())->requestMagicLink();
            return;
        }
        if ($method === 'GET' && $path === '/logout') {
            (new AuthController())->logout();
            return;
        }
        if ($method === 'GET' && $path === '/forgot') {
            (new AuthController())->showForgot();
            return;
        }
        if ($method === 'POST' && $path === '/forgot') {
            (new AuthController())->forgot();
            return;
        }
        if ($method === 'GET' && $path === '/reset') {
            (new AuthController())->showReset();
            return;
        }
        if ($method === 'POST' && $path === '/reset') {
            (new AuthController())->reset();
            return;
        }
        if ($method === 'GET' && $path === '/api/health') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'service' => 'wwm-cabinet']);
            return;
        }
        if (($method === 'POST' || $method === 'GET') && $path === '/api/demo') {
            (new DemoWebhookController())->grant();
            return;
        }
        if (($method === 'POST' || $method === 'GET') && $path === '/api/payment') {
            (new PaymentWebhookController())->grant();
            return;
        }
        if ($method === 'GET' && $path === '/api/engagement') {
            (new EngagementController())->show();
            return;
        }
        if (($method === 'POST' || $method === 'GET') && $path === '/api/mail') {
            (new MailWebhookController())->send();
            return;
        }
        if ($method === 'GET' && preg_match('#^/t/o/([a-f0-9]{32})$#', $path, $m)) {
            (new EmailTrackingController())->open($m[1]);
            return;
        }
        if ($method === 'GET' && preg_match('#^/t/c/([a-f0-9]{32})$#', $path, $m)) {
            (new EmailTrackingController())->click($m[1]);
            return;
        }

        if ($method === 'GET' && ($path === '/admin' || $path === '/admin/courses')) {
            (new AdminCourseController())->index();
            return;
        }
        if ($method === 'GET' && $path === '/admin/courses/new') {
            (new AdminCourseController())->createForm();
            return;
        }
        if ($method === 'POST' && $path === '/admin/courses') {
            (new AdminCourseController())->store();
            return;
        }
        if ($method === 'GET' && preg_match('#^/admin/courses/([a-z0-9\-]+)$#', $path, $m)) {
            (new AdminCourseController())->edit($m[1]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/courses/([a-z0-9\-]+)$#', $path, $m)) {
            (new AdminCourseController())->update($m[1]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/courses/([a-z0-9\-]+)/sections$#', $path, $m)) {
            (new AdminCourseController())->storeSection($m[1]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/courses/([a-z0-9\-]+)/sections/(\d+)/delete$#', $path, $m)) {
            (new AdminCourseController())->destroySection($m[1], (int)$m[2]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/courses/([a-z0-9\-]+)/lessons/(\d+)/delete$#', $path, $m)) {
            (new AdminLessonController())->destroy($m[1], (int)$m[2]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/courses/([a-z0-9\-]+)/lessons$#', $path, $m)) {
            (new AdminLessonController())->store($m[1]);
            return;
        }
        if ($method === 'GET' && preg_match('#^/admin/courses/([a-z0-9\-]+)/lessons/(\d+)$#', $path, $m)) {
            (new AdminLessonController())->edit($m[1], (int)$m[2]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/courses/([a-z0-9\-]+)/lessons/(\d+)$#', $path, $m)) {
            (new AdminLessonController())->update($m[1], (int)$m[2]);
            return;
        }
        if ($method === 'GET' && $path === '/admin/students') {
            (new AdminStudentController())->index();
            return;
        }
        if ($method === 'GET' && $path === '/admin/students/new') {
            (new AdminStudentController())->createForm();
            return;
        }
        if ($method === 'POST' && $path === '/admin/students') {
            (new AdminStudentController())->store();
            return;
        }
        if ($method === 'GET' && preg_match('#^/admin/students/(\d+)$#', $path, $m)) {
            (new AdminStudentController())->show((int)$m[1]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/students/(\d+)/access$#', $path, $m)) {
            (new AdminStudentController())->grantAccess((int)$m[1]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/students/(\d+)/access/revoke$#', $path, $m)) {
            (new AdminStudentController())->revokeAccess((int)$m[1]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/students/(\d+)/delete$#', $path, $m)) {
            (new AdminStudentController())->destroy((int)$m[1]);
            return;
        }
        if ($method === 'POST' && preg_match('#^/admin/students/(\d+)/avo-sync$#', $path, $m)) {
            (new AdminStudentController())->resyncAvo((int)$m[1]);
            return;
        }

        if ($method === 'GET' && $path === '/admin/mail-test') {
            (new AdminMailController())->test();
            return;
        }
        if ($method === 'GET' && $path === '/admin/emails') {
            (new AdminMailController())->index();
            return;
        }
        if ($method === 'GET' && preg_match('#^/admin/emails/([a-z0-9_]+)$#', $path, $m)) {
            (new AdminMailController())->preview($m[1]);
            return;
        }

        if ($method === 'GET' && $path === '/') {
            (new DashboardController())->index();
            return;
        }
        if ($method === 'GET' && $path === '/account') {
            (new AccountController())->show();
            return;
        }
        if ($method === 'POST' && $path === '/account/profile') {
            (new AccountController())->updateProfile();
            return;
        }
        if ($method === 'POST' && $path === '/account/password') {
            (new AccountController())->updatePassword();
            return;
        }

        if ($method === 'GET' && preg_match('#^/c/([a-z0-9\-]+)$#', $path, $m)) {
            (new CourseController())->show($m[1]);
            return;
        }

        if ($method === 'GET' && preg_match('#^/c/([a-z0-9\-]+)/(\d+)$#', $path, $m)) {
            (new LessonController())->show($m[1], (int)$m[2]);
            return;
        }

        http_response_code(404);
        wwm_render('error', [
            'pageTitle' => 'Not found',
            'code' => 404,
            'message' => 'Page not found',
        ]);
    }
}
