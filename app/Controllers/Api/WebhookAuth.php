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
    }

    public static function requireDemo(): void
    {
        self::requireEnabled();
        self::requireToken('demo_token');
    }

    public static function requirePayment(): void
    {
        self::requireEnabled();
        self::requireToken('payment_token');
    }

    private static function requireToken(string $configKey): void
    {
        $cfg = wwm_config()['webhooks'] ?? [];
        $expectedToken = trim((string)($cfg[$configKey] ?? ''));
        if ($expectedToken === '') {
            wwm_json_response(503, ['ok' => false, 'error' => $configKey . '_not_configured']);
        }

        if (!self::isAuthorized($expectedToken)) {
            wwm_json_response(403, ['ok' => false, 'error' => 'invalid_token']);
        }
    }

    public static function isAuthorized(string $expectedToken): bool
    {
        $provided = trim((string)($_SERVER['HTTP_X_WWM_DEMO_TOKEN'] ?? ''));
        if ($provided === '') {
            $provided = trim((string)($_SERVER['HTTP_X_WWM_PAYMENT_TOKEN'] ?? ''));
        }
        if ($provided === '') {
            $provided = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
        }

        return $provided !== '' && hash_equals($expectedToken, $provided);
    }
}
