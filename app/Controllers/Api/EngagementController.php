<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

use Wwm\Services\CourseCatalog;
use Wwm\Services\DemoAccess;
use Wwm\Services\StudentEngagement;

final class EngagementController
{
    public function show(): void
    {
        WebhookAuth::requireDemo();

        $email = trim((string)($_GET['email'] ?? ''));
        $courseSlug = DemoAccess::resolveCourseSlug(
            isset($_GET['course']) ? (string)$_GET['course'] : null,
            isset($_GET['id_goods']) ? (int)$_GET['id_goods'] : null
        );

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            wwm_json_response(400, ['ok' => false, 'error' => 'email_required']);
        }

        if ($courseSlug === null || $courseSlug === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'course_required']);
        }

        $catalog = new CourseCatalog();
        if ($catalog->get($courseSlug) === null) {
            wwm_json_response(404, ['ok' => false, 'error' => 'course_not_found']);
        }

        $status = (new StudentEngagement())->forEmail($email, $courseSlug);
        wwm_json_response(200, $status);
    }
}
