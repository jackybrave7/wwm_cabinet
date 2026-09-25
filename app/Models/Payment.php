<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class Payment
{
    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   amount_original: ?float,
     *   currency_original: string,
     *   amount_rub: ?float,
     *   fx_rate: ?float
     * }
     */
    public static function pricingFromPayload(array $payload): array
    {
        $original = self::positiveFloat(
            $payload['amount_original']
                ?? $payload['amount_orig']
                ?? $payload['tilda_amount']
                ?? null
        );
        $currencyOriginal = strtoupper(trim((string)(
            $payload['currency_original']
            ?? $payload['currency_orig']
            ?? $payload['tilda_currency']
            ?? ''
        )));
        $rub = self::positiveFloat(
            $payload['amount_rub']
                ?? $payload['sum_rub']
                ?? null
        );
        $fx = self::positiveFloat($payload['fx_rate'] ?? $payload['rate'] ?? null);

        return [
            'amount_original' => $original,
            'currency_original' => mb_substr($currencyOriginal, 0, 12),
            'amount_rub' => $rub,
            'fx_rate' => $fx,
        ];
    }

    /**
     * @param array{
     *   user_id: int,
     *   avo_account_id: string,
     *   course_slug: string,
     *   id_goods: ?int,
     *   amount: ?float,
     *   currency: string,
     *   source: string,
     *   ordered_at: ?string,
     *   paid_at: ?string,
     *   utm_source: ?string,
     *   utm_medium: ?string,
     *   utm_campaign: ?string,
     *   utm_term: ?string,
     *   utm_content: ?string,
     *   ad_snapshot: ?string,
     *   avo_contact_id: ?int,
     *   amount_original?: ?float,
     *   currency_original?: string,
     *   fx_rate?: ?float
     * } $data
     */
    public static function upsert(PDO $pdo, array $data): bool
    {
        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO payments (
                user_id, avo_account_id, course_slug, id_goods, amount, currency, source,
                ordered_at, paid_at, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                ad_snapshot, avo_contact_id, amount_original, currency_original, fx_rate,
                created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(avo_account_id, course_slug) DO UPDATE SET
                user_id = excluded.user_id,
                id_goods = COALESCE(excluded.id_goods, payments.id_goods),
                amount = COALESCE(excluded.amount, payments.amount),
                currency = CASE WHEN excluded.currency != \'\' THEN excluded.currency ELSE payments.currency END,
                source = excluded.source,
                ordered_at = COALESCE(excluded.ordered_at, payments.ordered_at),
                paid_at = COALESCE(excluded.paid_at, payments.paid_at),
                utm_source = COALESCE(excluded.utm_source, payments.utm_source),
                utm_medium = COALESCE(excluded.utm_medium, payments.utm_medium),
                utm_campaign = COALESCE(excluded.utm_campaign, payments.utm_campaign),
                utm_term = COALESCE(excluded.utm_term, payments.utm_term),
                utm_content = COALESCE(excluded.utm_content, payments.utm_content),
                ad_snapshot = COALESCE(excluded.ad_snapshot, payments.ad_snapshot),
                avo_contact_id = COALESCE(excluded.avo_contact_id, payments.avo_contact_id),
                amount_original = COALESCE(excluded.amount_original, payments.amount_original),
                currency_original = CASE
                    WHEN excluded.currency_original != \'\' THEN excluded.currency_original
                    ELSE payments.currency_original
                END,
                fx_rate = COALESCE(excluded.fx_rate, payments.fx_rate),
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            $data['user_id'],
            $data['avo_account_id'],
            $data['course_slug'],
            $data['id_goods'],
            $data['amount'],
            $data['currency'],
            $data['source'],
            $data['ordered_at'],
            $data['paid_at'],
            $data['utm_source'],
            $data['utm_medium'],
            $data['utm_campaign'],
            $data['utm_term'],
            $data['utm_content'],
            $data['ad_snapshot'],
            $data['avo_contact_id'],
            $data['amount_original'] ?? null,
            $data['currency_original'] ?? '',
            $data['fx_rate'] ?? null,
            $now,
            $now,
        ]);

        PaymentPricingPending::applyForPayment($pdo, (string)$data['avo_account_id'], (string)$data['course_slug']);

        return true;
    }

    /**
     * @param array{
     *   amount_original: ?float,
     *   currency_original: string,
     *   amount_rub: ?float,
     *   fx_rate: ?float
     * } $pricing
     */
    public static function applyPricingFields(PDO $pdo, string $avoAccountId, string $courseSlug, array $pricing): bool
    {
        $amountRub = $pricing['amount_rub'];
        $stmt = $pdo->prepare(
            'UPDATE payments SET
                amount = COALESCE(?, amount),
                currency = CASE WHEN ? IS NOT NULL AND ? > 0 THEN \'RUB\' ELSE currency END,
                amount_original = COALESCE(?, amount_original),
                currency_original = CASE WHEN ? != \'\' THEN ? ELSE currency_original END,
                fx_rate = COALESCE(?, fx_rate),
                updated_at = ?
             WHERE avo_account_id = ? AND course_slug = ?'
        );
        $now = gmdate('c');
        $stmt->execute([
            $amountRub,
            $amountRub,
            $amountRub,
            $pricing['amount_original'],
            $pricing['currency_original'],
            $pricing['currency_original'],
            $pricing['fx_rate'],
            $now,
            $avoAccountId,
            $courseSlug,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM payments WHERE user_id = ? ORDER BY COALESCE(paid_at, ordered_at, created_at) DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<string, mixed> $pay
     * @return array{primary: string, secondary: ?string, estimated: bool}
     */
    public static function amountDisplayLines(array $pay): array
    {
        $rub = self::positiveFloat($pay['amount'] ?? null);
        $original = self::positiveFloat($pay['amount_original'] ?? null);
        $currencyOriginal = strtoupper(trim((string)($pay['currency_original'] ?? '')));
        $fx = self::positiveFloat($pay['fx_rate'] ?? null);

        if ($original !== null && $currencyOriginal !== '') {
            $primary = self::formatMoney($original, $currencyOriginal);
            $secondary = $rub !== null ? self::formatMoney($rub, 'RUB') : null;

            return ['primary' => $primary, 'secondary' => $secondary, 'estimated' => false];
        }

        if ($rub !== null) {
            $rate = $fx ?? self::fallbackUsdRubRate();
            $estimatedUsd = $rate > 0 ? $rub / $rate : null;
            if ($estimatedUsd !== null && $estimatedUsd > 0) {
                return [
                    'primary' => self::formatMoney($estimatedUsd, 'USD'),
                    'secondary' => self::formatMoney($rub, 'RUB'),
                    'estimated' => true,
                ];
            }

            return ['primary' => self::formatMoney($rub, 'RUB'), 'secondary' => null, 'estimated' => false];
        }

        return ['primary' => '—', 'secondary' => null, 'estimated' => false];
    }

    public static function formatAmount(?float $amount, string $currency = ''): string
    {
        if ($amount === null || $amount <= 0) {
            return '—';
        }

        return self::formatMoney($amount, $currency !== '' && !str_starts_with($currency, 'id:') ? $currency : '');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{
     *   payment_count: int,
     *   rub_total: float,
     *   tilda_total: float,
     *   tilda_currency: string,
     *   tilda_estimated_count: int,
     *   has_mixed_tilda_currencies: bool
     * }
     */
    public static function aggregateRevenue(array $rows): array
    {
        $rubTotal = 0.0;
        $count = 0;
        $tildaByCurrency = [];
        $estimatedCount = 0;

        foreach ($rows as $pay) {
            $rub = self::positiveFloat($pay['amount'] ?? null);
            if ($rub === null) {
                continue;
            }
            $rubTotal += $rub;
            $count++;

            $original = self::positiveFloat($pay['amount_original'] ?? null);
            $currencyOriginal = strtoupper(trim((string)($pay['currency_original'] ?? '')));
            if ($original !== null && $currencyOriginal !== '') {
                $tildaByCurrency[$currencyOriginal] = ($tildaByCurrency[$currencyOriginal] ?? 0.0) + $original;
                continue;
            }

            $fx = self::positiveFloat($pay['fx_rate'] ?? null) ?? self::fallbackUsdRubRate();
            if ($fx > 0) {
                $tildaByCurrency['USD'] = ($tildaByCurrency['USD'] ?? 0.0) + ($rub / $fx);
                $estimatedCount++;
            }
        }

        $primaryCurrency = 'USD';
        $tildaTotal = $tildaByCurrency['USD'] ?? 0.0;
        $mixed = count($tildaByCurrency) > 1
            || (count($tildaByCurrency) === 1 && !isset($tildaByCurrency['USD']));

        if ($mixed && $tildaByCurrency !== []) {
            $primaryCurrency = (string)array_key_first($tildaByCurrency);
            $tildaTotal = (float)($tildaByCurrency[$primaryCurrency] ?? 0.0);
        }

        return [
            'payment_count' => $count,
            'rub_total' => round($rubTotal, 2),
            'tilda_total' => round($tildaTotal, 2),
            'tilda_currency' => $primaryCurrency,
            'tilda_estimated_count' => $estimatedCount,
            'has_mixed_tilda_currencies' => $mixed,
        ];
    }

    /**
     * @param array{
     *   payment_count: int,
     *   rub_total: float,
     *   tilda_total: float,
     *   tilda_currency: string,
     *   tilda_estimated_count: int,
     *   has_mixed_tilda_currencies: bool
     * } $agg
     */
    public static function formatRevenuePrimary(array $agg): string
    {
        if (($agg['payment_count'] ?? 0) === 0 || ($agg['tilda_total'] ?? 0) <= 0) {
            return '—';
        }

        $prefix = ($agg['tilda_estimated_count'] ?? 0) > 0 ? '~' : '';

        return $prefix . self::formatMoney((float)$agg['tilda_total'], (string)($agg['tilda_currency'] ?? 'USD'));
    }

    /**
     * @param array{rub_total: float, payment_count: int} $agg
     */
    public static function formatRevenueRub(array $agg): string
    {
        if (($agg['payment_count'] ?? 0) === 0 || ($agg['rub_total'] ?? 0) <= 0) {
            return '';
        }

        return self::formatMoney((float)$agg['rub_total'], 'RUB');
    }

    public static function formatMoney(float $amount, string $currency): string
    {
        $formatted = number_format($amount, 2, '.', ' ');
        $code = strtoupper(trim($currency));

        return match ($code) {
            'USD' => '$' . $formatted,
            'EUR' => '€' . $formatted,
            'GBP' => '£' . $formatted,
            'RUB' => $formatted . ' ₽',
            '' => $formatted,
            default => $formatted . ' ' . $code,
        };
    }

    private static function fallbackUsdRubRate(): float
    {
        $rate = (float)(wwm_config()['payment_usd_rub_fallback_rate'] ?? 0);

        return $rate > 0 ? $rate : 100.0;
    }

    private static function positiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $num = (float)str_replace([' ', ','], ['', '.'], trim((string)$value));
        if ($num <= 0) {
            return null;
        }

        return $num;
    }
}
