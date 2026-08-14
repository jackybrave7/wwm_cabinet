<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\User;

/**
 * Persists advertising / UTM fields from AVO webhook payloads.
 * AVO REST often does not expose contact-card UTM history; webhooks do.
 */
final class AvoAdvertisingSnapshot
{
    private const PAYLOAD_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'advertising_channel_type_traffic',
        'advertising_channel_keyword',
        'advertising_channel_location',
        'id_advertising_channel_page',
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public static function captureFromPayload(PDO $pdo, int $userId, array $payload): void
    {
        $snapshot = self::extractSnapshot($payload);
        if ($snapshot === []) {
            return;
        }

        User::setAvoAdSnapshot($pdo, $userId, $snapshot);

        $utm = StudentAttribution::utmFromAvoPayload($snapshot);
        if ($utm !== []) {
            StudentAttribution::recordForUser($pdo, $userId, false, $utm, false);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function utmFromUser(array $user): array
    {
        $raw = trim((string)($user['avo_ad_snapshot'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return StudentAttribution::utmFromAvoPayload($decoded);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function extractSnapshot(array $payload): array
    {
        $snapshot = [];
        foreach (self::PAYLOAD_KEYS as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                $snapshot[$key] = mb_substr($value, 0, 255);
            }
        }

        return $snapshot;
    }
}
