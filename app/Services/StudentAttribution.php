<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\User;

final class StudentAttribution
{
    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public static function captureFromRequest(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $hasUtm = false;
        foreach (self::UTM_KEYS as $key) {
            if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
                $hasUtm = true;
                break;
            }
        }
        if (!$hasUtm) {
            return;
        }

        $utm = is_array($_SESSION['wwm_utm'] ?? null) ? $_SESSION['wwm_utm'] : [];
        foreach (self::UTM_KEYS as $key) {
            $value = trim((string)($_GET[$key] ?? ''));
            if ($value !== '') {
                $utm[$key] = mb_substr($value, 0, 255);
            }
        }
        $_SESSION['wwm_utm'] = $utm;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public static function utmFromAvoPayload(array $payload): array
    {
        $utm = self::utmFromPayload($payload);

        $map = [
            'utm_medium' => (string)($payload['advertising_channel_type_traffic'] ?? ''),
            'utm_term' => (string)($payload['advertising_channel_keyword'] ?? ''),
            'utm_content' => (string)($payload['advertising_channel_location'] ?? ''),
        ];
        foreach ($map as $key => $value) {
            $value = trim($value);
            if ($value !== '' && !isset($utm[$key])) {
                $utm[$key] = mb_substr($value, 0, 255);
            }
        }

        return $utm;
    }

    /**
     * @param array<string, string> $base
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    public static function mergeUtm(array $base, array $extra): array
    {
        foreach (self::UTM_KEYS as $key) {
            if (isset($base[$key]) && $base[$key] !== '') {
                continue;
            }
            $value = trim((string)($extra[$key] ?? ''));
            if ($value !== '') {
                $base[$key] = mb_substr($value, 0, 255);
            }
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function utmFromPayload(array $payload): array
    {
        $utm = [];
        foreach (self::UTM_KEYS as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                $utm[$key] = mb_substr($value, 0, 255);
            }
        }

        return $utm;
    }

    /**
     * @return array<string, string>
     */
    public static function utmFromSession(): array
    {
        $utm = $_SESSION['wwm_utm'] ?? [];
        if (!is_array($utm)) {
            return [];
        }

        $result = [];
        foreach (self::UTM_KEYS as $key) {
            $value = trim((string)($utm[$key] ?? ''));
            if ($value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $utmOverride
     * @param bool $captureGeo Record IP/city/country (false for server-side webhooks)
     */
    public static function recordForUser(
        PDO $pdo,
        int $userId,
        bool $isNewUser = false,
        array $utmOverride = [],
        bool $captureGeo = true
    ): void {
        $utm = $utmOverride !== [] ? $utmOverride : self::utmFromSession();
        $data = [
            'utm' => $utm,
            'is_new_user' => $isNewUser,
        ];

        if ($captureGeo) {
            $ip = wwm_client_ip();
            $geo = GeoIp::lookup($ip);
            $data['ip'] = $ip;
            $data['country'] = $geo['country'];
            $data['city'] = $geo['city'];
        }

        User::recordAttribution($pdo, $userId, $data);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function signupLocationLabel(array $user): string
    {
        return self::locationFromFields(
            trim((string)($user['signup_city'] ?? '')),
            trim((string)($user['signup_country'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function lastLoginLocationLabel(array $user): string
    {
        return self::locationFromFields(
            trim((string)($user['last_city'] ?? '')),
            trim((string)($user['last_country'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function locationLabel(array $user, bool $preferSignup = true): string
    {
        $signup = self::signupLocationLabel($user);
        $last = self::lastLoginLocationLabel($user);

        if ($preferSignup) {
            return $signup !== '—' ? $signup : $last;
        }

        return $last !== '—' ? $last : $signup;
    }

    private static function locationFromFields(string $city, string $country): string
    {
        if ($city !== '' && $country !== '') {
            return $city . ', ' . $country;
        }
        if ($country !== '') {
            return $country;
        }
        if ($city !== '') {
            return $city;
        }

        return '—';
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function channelLabel(array $user): string
    {
        $parts = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            $value = trim((string)($user[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        if ($parts === []) {
            return '—';
        }

        return implode(' / ', $parts);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function channelDetail(array $user): ?string
    {
        $term = trim((string)($user['utm_term'] ?? ''));
        $content = trim((string)($user['utm_content'] ?? ''));
        $parts = [];
        if ($term !== '') {
            $parts[] = 'term: ' . $term;
        }
        if ($content !== '') {
            $parts[] = 'content: ' . $content;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function firstNonEmpty(array $user, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string)($user[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
