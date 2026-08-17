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
            foreach (['utm_medium', 'utm_term', 'utm_content'] as $key) {
                $value = trim((string)($user[$key] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
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
     * Backfill missing UTM fields from AVO for an existing cabinet user.
     */
    public static function backfillUtmFromAvo(\PDO $pdo, int $userId, ?AvoClient $client = null): bool
    {
        return self::backfillUtmStatus($pdo, $userId, $client) === 'updated';
    }

    /**
     * @return 'updated'|'unchanged'|'empty'|'disabled'|'missing_user'
     */
    public static function backfillUtmStatus(\PDO $pdo, int $userId, ?AvoClient $client = null): string
    {
        $user = User::findById($pdo, $userId);
        if ($user === null) {
            return 'missing_user';
        }

        $client ??= new AvoClient();
        if (!$client->isEnabled()) {
            return 'disabled';
        }

        $before = self::utmFields($user);
        $utm = AvoAdvertisingSnapshot::utmFromUser($user);
        $resolved = (new AvoUtmResolver($client))->resolve(self::avoPayloadForUser($pdo, $user));
        $utm = self::mergeUtm($utm, $resolved);

        if ($utm === []) {
            wwm_log(sprintf(
                'avo utm backfill empty for user %d contact %s email %s last_error=%s',
                $userId,
                (int)($user['avo_contact_id'] ?? 0) > 0 ? (string)$user['avo_contact_id'] : 'n/a',
                (string)$user['email'],
                $client->lastError() ?? 'none'
            ));

            return 'empty';
        }

        $contactId = (int)($user['avo_contact_id'] ?? 0);
        if ($contactId <= 0) {
            $found = $client->findContactIdByEmail((string)$user['email']);
            if ($found !== null && $found > 0) {
                User::setAvoFlags($pdo, $userId, ['avo_contact_id' => $found]);
            }
        }

        self::recordForUser($pdo, $userId, false, $utm, false);

        $afterUser = User::findById($pdo, $userId);
        $after = $afterUser !== null ? self::utmFields($afterUser) : $before;

        return count($after) > count($before) ? 'updated' : 'unchanged';
    }

    /**
     * @return array{
     *   total: int,
     *   updated: int,
     *   unchanged: int,
     *   empty: int,
     *   disabled: bool
     * }
     */
    public static function backfillAllUtmFromAvo(\PDO $pdo, int $pauseMicros = 150000): array
    {
        $stats = [
            'total' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'empty' => 0,
            'disabled' => false,
        ];

        $client = new AvoClient();
        if (!$client->isEnabled()) {
            $stats['disabled'] = true;

            return $stats;
        }

        $stmt = $pdo->query('SELECT id FROM users ORDER BY id ASC');
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $stats['total'] = count($rows);

        foreach ($rows as $row) {
            $status = self::backfillUtmStatus($pdo, (int)$row['id'], $client);
            if ($status === 'updated') {
                $stats['updated']++;
            } elseif ($status === 'unchanged') {
                $stats['unchanged']++;
            } elseif ($status === 'empty' || $status === 'missing_user') {
                $stats['empty']++;
            }

            if ($pauseMicros > 0) {
                usleep($pauseMicros);
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function avoPayloadForUser(\PDO $pdo, array $user): array
    {
        $payload = ['email' => (string)$user['email']];
        $contactId = (int)($user['avo_contact_id'] ?? 0);
        if ($contactId > 0) {
            $payload['id_contact'] = $contactId;
        }

        $accountId = self::guessAccountId($pdo, (int)$user['id']);
        if ($accountId > 0) {
            $payload['id_account'] = $accountId;
        }

        return $payload;
    }

    private static function guessAccountId(\PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare(
            'SELECT source_ref FROM access WHERE user_id = ? AND source_ref IS NOT NULL AND source_ref != "" '
            . 'ORDER BY granted_at ASC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as $row) {
            $ref = trim((string)($row['source_ref'] ?? ''));
            if ($ref !== '' && ctype_digit($ref)) {
                return (int)$ref;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, string>
     */
    public static function utmFields(array $user): array
    {
        $fields = [];
        foreach (self::UTM_KEYS as $key) {
            $value = trim((string)($user[$key] ?? ''));
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
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
