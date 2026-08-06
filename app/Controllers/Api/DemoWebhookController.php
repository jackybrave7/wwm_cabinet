<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

use Wwm\Services\DemoAccess;

final class DemoWebhookController
{
    public function grant(): void
    {
        $cfg = wwm_config()['webhooks'] ?? [];
        if (empty($cfg['enabled'])) {
            wwm_json_response(503, ['ok' => false, 'error' => 'webhooks_disabled']);
        }

        $expectedToken = trim((string)($cfg['demo_token'] ?? ''));
        if ($expectedToken === '') {
            wwm_json_response(503, ['ok' => false, 'error' => 'demo_token_not_configured']);
        }

        if (!$this->isAuthorized($expectedToken)) {
            wwm_json_response(403, ['ok' => false, 'error' => 'invalid_token']);
        }

        $payload = $this->readPayload();
        $email = trim((string)($payload['email'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $courseSlug = DemoAccess::resolveCourseSlug(
            isset($payload['course']) ? (string)$payload['course'] : null,
            isset($payload['id_goods']) ? (int)$payload['id_goods'] : null
        );
        $source = trim((string)($payload['source'] ?? 'avo'));
        $sourceRef = trim((string)($payload['source_ref'] ?? ''));
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
                $sourceRef !== '' ? $sourceRef : null
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

    private function isAuthorized(string $expectedToken): bool
    {
        $provided = trim((string)($_SERVER['HTTP_X_WWM_DEMO_TOKEN'] ?? ''));
        if ($provided === '') {
            $provided = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
        }

        return $provided !== '' && hash_equals($expectedToken, $provided);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if ($_POST !== []) {
            return $_POST;
        }

        return $_GET;
    }
}
