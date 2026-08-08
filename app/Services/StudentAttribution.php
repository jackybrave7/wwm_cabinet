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
     */
    public static function recordForUser(PDO $pdo, int $userId, bool $isNewUser = false, array $utmOverride = []): void
    {
        $ip = wwm_client_ip();
        $geo = GeoIp::lookup($ip);
        $utm = $utmOverride !== [] ? $utmOverride : self::utmFromSession();

        User::recordAttribution($pdo, $userId, [
            'ip' => $ip,
            'country' => $geo['country'],
            'city' => $geo['city'],
            'utm' => $utm,
            'is_new_user' => $isNewUser,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function locationLabel(array $user, bool $preferSignup = true): string
    {
        $city = $preferSignup
            ? self::firstNonEmpty($user, 'signup_city', 'last_city')
            : self::firstNonEmpty($user, 'last_city', 'signup_city');
        $country = $preferSignup
            ? self::firstNonEmpty($user, 'signup_country', 'last_country')
            : self::firstNonEmpty($user, 'last_country', 'signup_country');

        if ($city !== null && $country !== null) {
            return $city . ', ' . $country;
        }
        if ($country !== null) {
            return $country;
        }
        if ($city !== null) {
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
