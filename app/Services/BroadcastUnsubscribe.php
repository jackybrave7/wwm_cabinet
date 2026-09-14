<?php
declare(strict_types=1);

namespace Wwm\Services;

final class BroadcastUnsubscribe
{
    public static function unsubscribeUrl(int $userId, string $email): string
    {
        $token = self::tokenFor($userId, $email);
        return wwm_base_url() . '/email/unsubscribe?t=' . rawurlencode($token);
    }

    public static function oneClickPostUrl(int $userId, string $email): string
    {
        return self::unsubscribeUrl($userId, $email);
    }

    public static function tokenFor(int $userId, string $email): string
    {
        $email = self::normalizeEmail($email);
        $payload = $userId . '|' . $email;
        $secret = (string)(wwm_config()['app_secret'] ?? '');
        if ($secret === '' || $secret === 'CHANGE_ME_LONG_RANDOM_SECRET') {
            $secret = 'wwm-broadcast-fallback-' . (string)WWM_ROOT;
        }
        $sig = hash_hmac('sha256', $payload, $secret, true);
        $raw = $sig . $payload;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array{user_id: int, email: string}|null
     */
    public static function verifyToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) < 32 + 3) {
            return null;
        }

        $sig = substr($decoded, 0, 32);
        $payload = substr($decoded, 32);
        if (!str_contains($payload, '|')) {
            return null;
        }

        [$userIdRaw, $email] = explode('|', $payload, 2);
        $userId = (int)$userIdRaw;
        $email = self::normalizeEmail($email);
        if ($userId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $secret = (string)(wwm_config()['app_secret'] ?? '');
        if ($secret === '' || $secret === 'CHANGE_ME_LONG_RANDOM_SECRET') {
            $secret = 'wwm-broadcast-fallback-' . (string)WWM_ROOT;
        }
        $expected = hash_hmac('sha256', $userId . '|' . $email, $secret, true);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return ['user_id' => $userId, 'email' => $email];
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
