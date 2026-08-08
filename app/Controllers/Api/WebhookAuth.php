<?php
declare(strict_types=1);

namespace Wwm\Controllers\Api;

final class WebhookAuth
{
    public static function requireEnabled(): void
    {
        $cfg = wwm_config()['webhooks'] ?? [];
        if (empty($cfg['enabled'])) {
            wwm_json_response(503, ['ok' => false, 'error' => 'webhooks_disabled']);
        }

        $expectedToken = trim((string)($cfg['demo_token'] ?? ''));
        if ($expectedToken === '') {
            wwm_json_response(503, ['ok' => false, 'error' => 'demo_token_not_configured']);
        }

        if (!self::isAuthorized($expectedToken)) {
            wwm_json_response(403, ['ok' => false, 'error' => 'invalid_token']);
        }
    }

    public static function isAuthorized(string $expectedToken): bool
    {
        $provided = trim((string)($_SERVER['HTTP_X_WWM_DEMO_TOKEN'] ?? ''));
        if ($provided === '') {
            $provided = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
        }

        return $provided !== '' && hash_equals($expectedToken, $provided);
    }
}
