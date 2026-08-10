<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

use Wwm\Services\AvoUtmResolver;
use Wwm\Services\AvoWebhookPayload;
use Wwm\Services\CabinetMail;
use Wwm\Services\DemoAccess;
use Wwm\Services\EmailTemplateCatalog;
use Wwm\Services\StudentAttribution;

final class MailWebhookController
{
    public function send(): void
    {
        WebhookAuth::requireDemo();

        $payload = AvoWebhookPayload::read();
        $template = $this->normalizeTemplate((string)($payload['template'] ?? $_GET['template'] ?? ''));
        $email = trim((string)($payload['email'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $courseSlug = DemoAccess::resolveCourseSlug(
            isset($payload['course']) ? (string)$payload['course'] : null,
            isset($payload['id_goods']) ? (int)$payload['id_goods'] : null
        );
        if ($courseSlug === null || $courseSlug === '') {
            $courseSlug = 'elke-en';
        }
        $avoContactId = (int)($payload['id_contact'] ?? 0);

        if ($template === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'template_required']);
        }
        if ($email === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'email_required']);
        }
        if (EmailTemplateCatalog::find($template) === null) {
            wwm_json_response(400, ['ok' => false, 'error' => 'unknown_template']);
        }

        try {
            $result = (new CabinetMail())->sendTemplate(
                $template,
                $email,
                $name !== '' ? $name : null,
                $courseSlug,
                $avoContactId > 0 ? $avoContactId : null
            );
            if (isset($result['user_id'])) {
                StudentAttribution::recordForUser(
                    wwm_pdo(),
                    (int)$result['user_id'],
                    false,
                    (new AvoUtmResolver())->resolve($payload),
                    false
                );
            }
        } catch (\InvalidArgumentException $e) {
            wwm_json_response(400, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Course not found') {
                wwm_json_response(404, ['ok' => false, 'error' => 'course_not_found']);
            }
            if ($e->getMessage() === 'User not found') {
                wwm_json_response(404, ['ok' => false, 'error' => 'user_not_found']);
            }
            wwm_log('mail webhook failed: ' . $e->getMessage());
            wwm_json_response(500, ['ok' => false, 'error' => 'send_failed']);
        }

        wwm_json_response(200, ['ok' => true] + $result);
    }

    private function normalizeTemplate(string $template): string
    {
        $template = strtolower(trim($template));
        $template = str_replace('-', '_', $template);

        return preg_replace('/[^a-z0-9_]/', '', $template) ?? '';
    }
}
