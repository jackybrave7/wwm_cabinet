<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\Payment;

/**
 * Persists AVO payment webhooks for revenue / ROAS analytics.
 */
final class PaymentRecorder
{
    /**
     * @param array<string, mixed> $payload
     * @return bool True when a row was written
     */
    public static function recordFromWebhook(
        \PDO $pdo,
        int $userId,
        string $courseSlug,
        string $source,
        array $payload,
        ?string $orderedAt,
        ?string $paidAt,
        ?int $avoContactId = null
    ): bool {
        $accountId = trim((string)($payload['id_account'] ?? $payload['source_ref'] ?? ''));
        if ($accountId === '') {
            return false;
        }

        $utm = StudentAttribution::utmFromAvoPayload($payload);
        $adSnapshot = self::adSnapshotJson($payload);
        $pricing = Payment::pricingFromPayload($payload);
        $amountRub = $pricing['amount_rub'] ?? self::amount($payload);

        return Payment::upsert($pdo, [
            'user_id' => $userId,
            'avo_account_id' => $accountId,
            'course_slug' => $courseSlug,
            'id_goods' => self::idGoods($payload),
            'amount' => $amountRub,
            'currency' => $amountRub !== null ? 'RUB' : self::currency($payload),
            'amount_original' => $pricing['amount_original'],
            'currency_original' => $pricing['currency_original'],
            'fx_rate' => $pricing['fx_rate'],
            'source' => $source !== '' ? $source : 'avo',
            'ordered_at' => $orderedAt,
            'paid_at' => $paidAt,
            'utm_source' => $utm['utm_source'] ?? null,
            'utm_medium' => $utm['utm_medium'] ?? null,
            'utm_campaign' => $utm['utm_campaign'] ?? null,
            'utm_term' => $utm['utm_term'] ?? null,
            'utm_content' => $utm['utm_content'] ?? null,
            'ad_snapshot' => $adSnapshot,
            'avo_contact_id' => $avoContactId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function amount(array $payload): ?float
    {
        foreach (['sum', 'price', 'amount', 'total'] as $key) {
            if (!isset($payload[$key])) {
                continue;
            }
            $raw = str_replace([' ', ','], ['', '.'], trim((string)$payload[$key]));
            if ($raw === '') {
                continue;
            }
            $num = (float)$raw;
            if ($num > 0) {
                return $num;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function currency(array $payload): string
    {
        foreach (['currency', 'currency_code', 'curr'] as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                return mb_substr($value, 0, 12);
            }
        }
        if (isset($payload['id_currency']) && (string)$payload['id_currency'] !== '') {
            return 'id:' . (int)$payload['id_currency'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function idGoods(array $payload): ?int
    {
        $id = (int)($payload['id_goods'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function adSnapshotJson(array $payload): ?string
    {
        $keys = [
            'advertising_channel_type_traffic',
            'advertising_channel_keyword',
            'advertising_channel_location',
            'id_advertising_channel_page',
        ];
        $snapshot = [];
        foreach ($keys as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                $snapshot[$key] = mb_substr($value, 0, 255);
            }
        }
        if ($snapshot === []) {
            return null;
        }

        return json_encode($snapshot, JSON_UNESCAPED_UNICODE) ?: null;
    }
}
