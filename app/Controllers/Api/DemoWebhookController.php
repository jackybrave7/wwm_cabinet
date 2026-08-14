<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

use Wwm\Services\AvoUtmResolver;
use Wwm\Services\AvoWebhookPayload;
use Wwm\Services\DemoAccess;
use Wwm\Services\StudentAttribution;

final class DemoWebhookController
{
    public function grant(): void
    {
        WebhookAuth::requireDemo();

        $payload = AvoWebhookPayload::read();
        $email = trim((string)($payload['email'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $courseSlug = DemoAccess::resolveCourseSlug(
            isset($payload['course']) ? (string)$payload['course'] : null,
            isset($payload['id_goods']) ? (int)$payload['id_goods'] : null
        );
        $source = trim((string)($payload['source'] ?? 'avo'));
        $sourceRef = trim((string)($payload['source_ref'] ?? ''));
        $avoContactId = (int)($payload['id_contact'] ?? 0);
        if ($source === '') {
            $source = 'avo';
        }

        if ($email === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'email_required']);
        }

        if ($courseSlug === null || $courseSlug === '') {
            wwm_json_response(400, ['ok' => false, 'error' => 'course_required']);
        }

        try {
            $result = (new DemoAccess())->grant(
                $email,
                $name,
                $courseSlug,
                $source,
                $sourceRef !== '' ? $sourceRef : null,
                $this->resolveUtmSafely($payload),
                $avoContactId > 0 ? $avoContactId : null
            );
        } catch (\InvalidArgumentException $e) {
            wwm_json_response(400, ['ok' => false, 'error' => 'invalid_email']);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Course not found') {
                wwm_json_response(404, ['ok' => false, 'error' => 'course_not_found']);
            }
            wwm_log('demo webhook failed: ' . $e->getMessage());
            wwm_json_response(500, ['ok' => false, 'error' => 'grant_failed']);
        }

        wwm_json_response(200, ['ok' => true] + $result);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function resolveUtmSafely(array $payload): array
    {
        try {
            return (new AvoUtmResolver())->resolve($payload);
        } catch (\Throwable $e) {
            wwm_log('avo utm resolve failed: ' . $e->getMessage());
            return StudentAttribution::utmFromAvoPayload($payload);
        }
    }
}
