<?php
declare(strict_types=1);

namespace Wwm\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Wwm\Models\SiteSettings;

final class YandexMetrikaReporting
{
    public const KEY_COUNTER_ID = SiteSettings::KEY_METRIKA_COUNTER_ID;
    public const KEY_OAUTH_TOKEN = SiteSettings::KEY_METRIKA_OAUTH_TOKEN;

    private const CACHE_TTL = 900;
    private const CACHE_TTL_LIFETIME = 3600;

    public function __construct(private PDO $pdo)
    {
    }

    public function isConfigured(): bool
    {
        return $this->counterId() > 0 && $this->oauthToken() !== '';
    }

    public function counterId(): int
    {
        $cfg = wwm_config()['metrika'] ?? [];
        $id = (int)($cfg['counter_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }

        $stored = (int)SiteSettings::get($this->pdo, self::KEY_COUNTER_ID, '0');
        if ($stored > 0) {
            return $stored;
        }

        return self::parseCounterIdFromSnippet(SiteSettings::analyticsHead($this->pdo));
    }

    public static function parseCounterIdFromSnippet(string $snippet): int
    {
        if (preg_match('/\bym\s*\(\s*(\d{5,12})/', $snippet, $m)) {
            return (int)$m[1];
        }

        return 0;
    }

    public function oauthToken(): string
    {
        $cfg = wwm_config()['metrika'] ?? [];
        $token = trim((string)($cfg['oauth_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        return trim(SiteSettings::get($this->pdo, self::KEY_OAUTH_TOKEN));
    }

    /**
     * @return array{
     *   ok: bool,
     *   error: ?string,
     *   visits_total: int,
     *   users_total: int,
     *   buckets: array<string, int>
     * }
     */
    public function visitsForPeriod(DateTimeImmutable $from, DateTimeImmutable $to, string $group): array
    {
        $empty = [
            'ok' => false,
            'error' => null,
            'visits_total' => 0,
            'users_total' => 0,
            'buckets' => [],
        ];

        if (!$this->isConfigured()) {
            $empty['error'] = 'not_configured';

            return $empty;
        }

        $cacheKey = sprintf(
            'bytime_%d_%s_%s_%s_%s',
            $this->counterId(),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $group,
            substr(hash('sha256', $this->visitsFilterParam() ?? 'all'), 0, 12)
        );
        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $metrikaGroup = match ($group) {
            'week' => 'week',
            'month' => 'month',
            default => 'day',
        };

        $query = $this->buildQuery([
            'ids' => (string)$this->counterId(),
            'metrics' => 'ym:s:visits,ym:s:users',
            'date1' => $from->format('Y-m-d'),
            'date2' => $to->format('Y-m-d'),
            'group' => $metrikaGroup,
            'timezone' => '+03:00',
            'accuracy' => 'full',
        ]);

        $url = 'https://api-metrika.yandex.net/stat/v1/data/bytime?' . $query;
        $body = $this->httpGet($url);
        if ($body === null) {
            $empty['error'] = 'request_failed';

            return $empty;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $empty['error'] = 'bad_response';

            return $empty;
        }

        if (!empty($decoded['errors'])) {
            $empty['error'] = 'api_error';

            return $empty;
        }

        $buckets = $this->parseBytimeBuckets($decoded, $group);
        $totals = $decoded['totals'] ?? [];
        $visitsTotal = (int)($totals[0][0] ?? array_sum($buckets));
        $usersTotal = (int)($totals[1][0] ?? 0);

        $result = [
            'ok' => true,
            'error' => null,
            'visits_total' => $visitsTotal,
            'users_total' => $usersTotal,
            'buckets' => $buckets,
        ];

        $this->writeCache($cacheKey, $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, int>
     */
    private function parseBytimeBuckets(array $decoded, string $group): array
    {
        $buckets = [];
        $intervals = $decoded['time_intervals'] ?? [];
        if (!is_array($intervals)) {
            return $buckets;
        }

        $dataRow = $decoded['data'][0] ?? null;
        if (!is_array($dataRow)) {
            return $buckets;
        }

        $metrics = $dataRow['metrics'] ?? [];
        $visitSeries = is_array($metrics[0] ?? null) ? $metrics[0] : [];

        foreach ($intervals as $index => $interval) {
            if (!is_array($interval)) {
                continue;
            }
            $startDate = (string)($interval[0] ?? '');
            if ($startDate === '') {
                continue;
            }
            $visits = (int)($visitSeries[$index] ?? 0);
            $key = $this->bucketKeyFromMetrika($startDate, $group);
            $buckets[$key] = ($buckets[$key] ?? 0) + $visits;
        }

        return $buckets;
    }

    /**
     * @return array{ok: bool, error: ?string, visits_total: int, users_total: int}
     */
    public function visitsTotal(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured', 'visits_total' => 0, 'users_total' => 0];
        }

        $cacheKey = sprintf(
            'total_%d_%s_%s_%s',
            $this->counterId(),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            substr(hash('sha256', $this->visitsFilterParam() ?? 'all'), 0, 12)
        );
        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $query = $this->buildQuery([
            'ids' => (string)$this->counterId(),
            'metrics' => 'ym:s:visits,ym:s:users',
            'date1' => $from->format('Y-m-d'),
            'date2' => $to->format('Y-m-d'),
            'timezone' => '+03:00',
        ]);

        $url = 'https://api-metrika.yandex.net/stat/v1/data?' . $query;
        $body = $this->httpGet($url);
        if ($body === null) {
            return ['ok' => false, 'error' => 'request_failed', 'visits_total' => 0, 'users_total' => 0];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !empty($decoded['errors'])) {
            return ['ok' => false, 'error' => 'api_error', 'visits_total' => 0, 'users_total' => 0];
        }

        $totals = $decoded['totals'] ?? [];
        $result = [
            'ok' => true,
            'error' => null,
            'visits_total' => (int)($totals[0] ?? 0),
            'users_total' => (int)($totals[1] ?? 0),
        ];
        $this->writeCache($cacheKey, $result, self::CACHE_TTL_LIFETIME);

        return $result;
    }

    /**
     * @return list<string>
     */
    public function visitHostnames(): array
    {
        $cfg = wwm_config()['metrika'] ?? [];
        $raw = $cfg['visit_hostnames'] ?? ['worldwatercolormasters.art', 'www.worldwatercolormasters.art'];
        if (!is_array($raw)) {
            $raw = array_map('trim', explode(',', (string)$raw));
        }
        $hosts = [];
        foreach ($raw as $host) {
            $host = strtolower(trim((string)$host));
            $host = preg_replace('#^https?://#', '', $host) ?? $host;
            $host = rtrim($host, '/');
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    public function visitHostnamesLabel(): string
    {
        $hosts = $this->visitHostnames();

        return $hosts !== [] ? implode(', ', $hosts) : 'all hosts';
    }

    private function visitsFilterParam(): ?string
    {
        $hosts = $this->visitHostnames();
        if ($hosts === []) {
            return null;
        }

        $parts = [];
        foreach ($hosts as $host) {
            $escaped = str_replace("'", "\\'", $host);
            $parts[] = "ym:s:startURLDomain=='{$escaped}'";
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * @param array<string, string> $params
     */
    private function buildQuery(array $params): string
    {
        $filter = $this->visitsFilterParam();
        if ($filter !== null) {
            $params['filters'] = $filter;
        }

        return http_build_query($params);
    }

    private function bucketKeyFromMetrika(string $dim, string $group): string
    {
        $dim = trim($dim);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dim, $m)) {
            $dim = $m[1];
        }

        $tz = new DateTimeZone('Europe/Moscow');
        try {
            $dt = new DateTimeImmutable($dim, $tz);
        } catch (\Throwable) {
            return $dim;
        }

        return match ($group) {
            'week' => $dt->format('Y-W'),
            'month' => $dt->format('Y-m'),
            default => $dt->format('Y-m-d'),
        };
    }

    private function httpGet(string $url): ?string
    {
        $token = $this->oauthToken();
        $headers = [
            'Authorization: OAuth ' . $token,
            'Accept: application/json',
        ];

        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $status >= 400) {
            if ($status >= 400) {
                wwm_log('metrika api http ' . $status . ' ' . mb_substr((string)$response, 0, 400));
            }

            return null;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $key): ?array
    {
        $path = $this->cachePath($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['expires'], $decoded['payload'])) {
            return null;
        }
        if ((int)$decoded['expires'] < time()) {
            return null;
        }

        return is_array($decoded['payload']) ? $decoded['payload'] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(string $key, array $payload, int $ttl = self::CACHE_TTL): void
    {
        $dir = dirname(__DIR__, 2) . '/data/cache/metrika';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $this->cachePath($key);
        $envelope = [
            'expires' => time() + $ttl,
            'payload' => $payload,
        ];
        @file_put_contents($path, json_encode($envelope, JSON_UNESCAPED_UNICODE));
    }

    private function cachePath(string $key): string
    {
        return dirname(__DIR__, 2) . '/data/cache/metrika/' . hash('sha256', $key) . '.json';
    }
}
