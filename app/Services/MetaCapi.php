<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\BackgroundJob;
use Wwm\Models\User;

/**
 * Meta Conversions API. Browser pixel and this server event share event_id.
 */
final class MetaCapi
{
    public static function hash(?string $value): string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return '';
        }

        return hash('sha256', $value);
    }

    public static function newLeadId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function rememberLead(\PDO $pdo, int $userId, string $leadId, array $post): void
    {
        $fbp = self::cleanCookie((string)($post['_fbp'] ?? ''));
        $fbc = self::cleanCookie((string)($post['_fbc'] ?? ''));
        $sourceUrl = trim((string)($post['event_source_url'] ?? ''));
        if (strlen($sourceUrl) > 500) {
            $sourceUrl = substr($sourceUrl, 0, 500);
        }
        User::mergeMetaAds($pdo, $userId, [
            'lead_id' => $leadId,
            'fbp' => $fbp,
            'fbc' => $fbc,
            'client_ip' => (string)(wwm_client_ip() ?? ''),
            'user_agent' => substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500),
            'event_source_url' => $sourceUrl,
            'content_name' => preg_replace('/[^a-z0-9\-]/', '', (string)($post['course'] ?? '')) ?: '',
        ]);
    }

    public static function enqueueLead(\PDO $pdo, int $userId, string $leadId, string $contentName): void
    {
        BackgroundJob::enqueue($pdo, BackgroundJob::TYPE_META_CAPI, [
            'event_name' => 'Lead',
            'event_id' => $leadId,
            'user_id' => $userId,
            'content_name' => $contentName,
        ]);
    }

    public static function enqueuePurchase(
        \PDO $pdo,
        int $userId,
        string $eventId,
        ?float $value,
        string $currency
    ): void {
        if ($eventId === '' || $value === null || $value <= 0 || $currency === '') {
            return;
        }
        BackgroundJob::enqueue($pdo, BackgroundJob::TYPE_META_CAPI, [
            'event_name' => 'Purchase',
            'event_id' => $eventId,
            'user_id' => $userId,
            'value' => $value,
            'currency' => strtoupper(substr($currency, 0, 8)),
        ]);
    }

    /**
     * @param array<string, mixed> $job
     */
    public static function sendFromJob(\PDO $pdo, array $job): void
    {
        if (!self::isConfigured()) {
            wwm_log('meta capi skipped: META_PIXEL_ID or META_CAPI_TOKEN empty');

            return;
        }

        $user = User::findById($pdo, (int)($job['user_id'] ?? 0));
        if ($user === null) {
            throw new \RuntimeException('meta capi user missing');
        }

        $eventName = (string)($job['event_name'] ?? '');
        $eventId = (string)($job['event_id'] ?? '');
        if ($eventName === '' || $eventId === '') {
            throw new \InvalidArgumentException('meta capi missing event');
        }

        $ads = User::metaAds($user);
        $custom = [];
        if ($eventName === 'Lead') {
            $name = trim((string)($job['content_name'] ?? $ads['content_name'] ?? ''));
            if ($name !== '') {
                $custom['content_name'] = $name;
            }
        }
        if ($eventName === 'Purchase') {
            $custom['value'] = (float)($job['value'] ?? 0);
            $custom['currency'] = (string)($job['currency'] ?? '');
        }

        $body = self::requestBody($eventName, $eventId, $user, $ads, $custom);
        self::post($body);
        wwm_log(sprintf('meta capi sent event=%s event_id=%s user_id=%d', $eventName, $eventId, (int)$user['id']));
    }

    public static function isConfigured(): bool
    {
        $cfg = self::config();

        return $cfg['pixel_id'] !== '' && $cfg['token'] !== '';
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $ads
     * @param array<string, mixed> $custom
     * @return array<string, mixed>
     */
    public static function requestBody(
        string $eventName,
        string $eventId,
        array $user,
        array $ads,
        array $custom
    ): array {
        $userData = array_filter([
            'em' => self::hashList((string)($user['email'] ?? '')),
            'fn' => self::hashList((string)($user['name'] ?? '')),
            'fbp' => $ads['fbp'] ?? '',
            'fbc' => $ads['fbc'] ?? '',
            'client_ip_address' => $ads['client_ip'] ?? '',
            'client_user_agent' => $ads['user_agent'] ?? '',
        ], static fn ($v) => $v !== '' && $v !== []);

        $event = [
            'event_name' => $eventName,
            'event_time' => time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'user_data' => $userData,
        ];
        $source = trim((string)($ads['event_source_url'] ?? ''));
        if ($source !== '') {
            $event['event_source_url'] = $source;
        }
        if ($custom !== []) {
            $event['custom_data'] = $custom;
        }

        $body = ['data' => [$event]];
        $test = self::config()['test_event_code'];
        if ($test !== '') {
            $body['test_event_code'] = $test;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function post(array $body): void
    {
        $cfg = self::config();
        $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($cfg['pixel_id']) . '/events?access_token=' . rawurlencode($cfg['token']);
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('meta capi encode failed');
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 5,
            ]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if (!is_string($response) || $status >= 400) {
                throw new \RuntimeException('meta capi http ' . $status . ' ' . ($error !== '' ? $error : substr((string)$response, 0, 300)));
            }

            return;
        }

        throw new \RuntimeException('meta capi curl missing');
    }

    /**
     * @return array{pixel_id: string, token: string, test_event_code: string}
     */
    private static function config(): array
    {
        $cfg = wwm_config()['meta'] ?? [];
        if (!is_array($cfg)) {
            $cfg = [];
        }

        return [
            'pixel_id' => trim((string)($cfg['pixel_id'] ?? '')),
            'token' => trim((string)($cfg['capi_token'] ?? '')),
            'test_event_code' => trim((string)($cfg['test_event_code'] ?? '')),
        ];
    }

    /**
     * @return list<string>|string
     */
    private static function hashList(string $value): array|string
    {
        $hash = self::hash($value);

        return $hash === '' ? '' : [$hash];
    }

    private static function cleanCookie(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255) {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9._\-]+$/', $value)) {
            return '';
        }

        return $value;
    }
}
