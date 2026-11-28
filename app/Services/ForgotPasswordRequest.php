<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Lets the forgot-password form work when the browser drops the session cookie.
 * The course-email link only opened /forgot; it did not send a reset letter.
 */
final class ForgotPasswordRequest
{
    private const TTL_SECONDS = 3600;

    /**
     * @return array{email: string}|null Email is empty when the token is for the form itself.
     */
    public static function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || !str_contains($decoded, '|')) {
            return null;
        }

        $sig = substr($decoded, 0, 64);
        $payload = substr($decoded, 64);
        if (strlen($sig) !== 64 || !str_starts_with($payload, '|')) {
            return null;
        }
        $payload = substr($payload, 1);
        $parts = explode('|', $payload, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $expires = (int)$parts[0];
        $email = self::normalizeEmail($parts[1]);
        if ($expires < time() || $expires > time() + self::TTL_SECONDS + 60) {
            return null;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $expected = hash_hmac('sha256', $expires . '|' . $email, self::secret());
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return ['email' => $email];
    }

    public static function sign(string $email): string
    {
        $email = self::normalizeEmail($email);
        $expires = time() + self::TTL_SECONDS;
        $payload = $expires . '|' . $email;
        $sig = hash_hmac('sha256', $payload, self::secret());

        return rtrim(strtr(base64_encode($sig . '|' . $payload), '+/', '-_'), '=');
    }

    /**
     * Course-email clicks land on /t/c/… and then on /forgot. Send the visitor
     * to a form that already knows their address and can submit without a cookie.
     */
    public static function redirectForTrackedForgot(string $target, string $email): ?string
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !self::isForgotTarget($target)) {
            return null;
        }

        return '/forgot?email=' . rawurlencode($email) . '&issue=' . rawurlencode(self::sign($email));
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function isForgotTarget(string $target): bool
    {
        $target = trim($target);
        if ($target === '') {
            return false;
        }

        $path = parse_url($target, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }
        $path = rtrim($path, '/') ?: '/';
        if ($path !== '/forgot') {
            return false;
        }

        $host = parse_url($target, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return str_starts_with($target, '/');
        }

        $baseHost = parse_url(wwm_base_url(), PHP_URL_HOST);

        return is_string($baseHost) && strcasecmp($host, $baseHost) === 0;
    }

    private static function secret(): string
    {
        $secret = (string)(wwm_config()['app_secret'] ?? '');
        if ($secret === '' || $secret === 'CHANGE_ME_LONG_RANDOM_SECRET') {
            $secret = 'wwm-forgot-fallback-' . (string)WWM_ROOT;
        }

        return $secret;
    }
}
