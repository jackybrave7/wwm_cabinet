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
     * @param array<string, string>|null $utmOverride
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
        ?int $avoContactId = null,
        ?array $utmOverride = null
    ): bool {
        $accountId = trim((string)($payload['id_account'] ?? $payload['source_ref'] ?? ''));
        if ($accountId === '') {
            return false;
        }

        $payload = AvoInvoiceAmounts::mergeMissing($payload);
        [$utm, $origin] = self::chooseUtm($pdo, $userId, $payload, $utmOverride);
        $adSnapshot = self::adSnapshotJson($pdo, $accountId, $courseSlug, $payload, $origin);
        $pricing = Payment::pricingFromPayload($payload);
        $amountRub = $pricing['amount_rub'] ?? self::amount($payload);

        $wrote = Payment::upsert($pdo, [
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

        if ($wrote && $paidAt !== null && trim($paidAt) !== '') {
            self::enqueueMetaPurchase($pdo, $userId, $accountId, $courseSlug, $pricing, $amountRub);
        }

        wwm_log(sprintf(
            'payment utm origin=%s src=%s user_id=%d account=%s',
            $origin,
            $utm['utm_source'] ?? '',
            $userId,
            $accountId
        ));

        return $wrote;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string>|null $utmOverride
     * @return array{0: array<string, string>, 1: string}
     */
    private static function chooseUtm(\PDO $pdo, int $userId, array $payload, ?array $utmOverride): array
    {
        $override = self::cleanUtm($utmOverride ?? []);
        $extracted = PaymentUtmExtractor::extract($payload);
        $avo = StudentAttribution::utmFromAvoPayload($payload);

        if ($override !== []) {
            $origin = PaymentUtmExtractor::bodyOrigin($payload) === 'payload' ? 'payload' : 'avo';

            return [$override, $origin];
        }
        if ($extracted !== []) {
            $origin = PaymentUtmExtractor::bodyOrigin($payload);
            if ($origin === 'empty') {
                $origin = 'payload';
            }

            return [$extracted, $origin];
        }
        if ($avo !== []) {
            return [$avo, 'avo'];
        }

        $user = \Wwm\Models\User::findById($pdo, $userId);
        $first = self::cleanUtm(is_array($user) ? $user : []);
        if ($first !== []) {
            return [$first, 'first_touch'];
        }

        return [[], 'empty'];
    }

    /**
     * @param array<string, mixed> $utm
     * @return array<string, string>
     */
    private static function cleanUtm(array $utm): array
    {
        $out = [];
        foreach (PaymentUtmExtractor::KEYS as $key) {
            $value = trim((string)($utm[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = mb_substr($value, 0, 255);
            }
        }

        return $out;
    }

    /**
     * @param array{amount_original: ?float, currency_original: string, fx_rate: ?float, amount_rub: ?float} $pricing
     */
    private static function enqueueMetaPurchase(
        \PDO $pdo,
        int $userId,
        string $accountId,
        string $courseSlug,
        array $pricing,
        ?float $amountRub
    ): void {
        try {
            $stmt = $pdo->prepare(
                'SELECT id FROM payments WHERE avo_account_id = ? AND course_slug = ? LIMIT 1'
            );
            $stmt->execute([$accountId, $courseSlug]);
            $paymentId = (int)$stmt->fetchColumn();
            $value = $pricing['amount_original'] ?? null;
            $currency = trim((string)($pricing['currency_original'] ?? ''));
            if ($value === null || $value <= 0 || $currency === '') {
                $value = $amountRub;
                $currency = $amountRub !== null ? 'RUB' : '';
            }
            MetaCapi::enqueuePurchase(
                $pdo,
                $userId,
                $paymentId > 0 ? (string)$paymentId : $accountId,
                $value,
                $currency
            );
        } catch (\Throwable $e) {
            wwm_log('meta purchase enqueue failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function amount(array $payload): ?float
    {
        foreach (['account_sum', 'sum', 'price', 'amount', 'total'] as $key) {
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
    private static function adSnapshotJson(
        \PDO $pdo,
        string $accountId,
        string $courseSlug,
        array $payload,
        string $origin
    ): ?string {
        $keys = [
            'advertising_channel_type_traffic',
            'advertising_channel_keyword',
            'advertising_channel_location',
            'id_advertising_channel_page',
        ];
        $snapshot = [];
        $stmt = $pdo->prepare(
            'SELECT ad_snapshot FROM payments WHERE avo_account_id = ? AND course_slug = ? LIMIT 1'
        );
        $stmt->execute([$accountId, $courseSlug]);
        $existing = $stmt->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) {
                $snapshot = $decoded;
            }
        }
        foreach ($keys as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                $snapshot[$key] = mb_substr($value, 0, 255);
            }
        }
        $snapshot['utm_origin'] = $origin;

        return json_encode($snapshot, JSON_UNESCAPED_UNICODE) ?: null;
    }
}
