<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;
use Wwm\Services\CbrUsdRubRates;

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
                ?? $payload['account_sum']
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
     * One Tilda checkout is notified twice: the Tilda order key and the AVO invoice id.
     * Returns the account id the new row should use, moving an existing alias onto it.
     *
     * @param list<string> $externalKeys
     */
    public static function canonicalAccountId(
        PDO $pdo,
        int $userId,
        string $courseSlug,
        string $incomingAccountId,
        array $externalKeys = [],
        ?string $paidAt = null,
        ?float $amountOriginal = null,
        ?float $amountRub = null
    ): string {
        $incomingAccountId = trim($incomingAccountId);
        $courseSlug = trim($courseSlug);
        if ($incomingAccountId === '' || $userId <= 0 || $courseSlug === '') {
            return $incomingAccountId;
        }

        $exact = self::findByAccount($pdo, $incomingAccountId, $courseSlug);
        if ($exact !== null) {
            return $incomingAccountId;
        }

        $match = null;
        foreach (self::forUser($pdo, $userId) as $row) {
            if ((string)($row['course_slug'] ?? '') !== $courseSlug) {
                continue;
            }
            if ((string)($row['avo_account_id'] ?? '') === $incomingAccountId || self::isRefunded($row)) {
                continue;
            }
            if (!self::samePurchase($row, $incomingAccountId, $externalKeys, $paidAt, $amountOriginal, $amountRub)) {
                continue;
            }
            if ($match !== null) {
                return $incomingAccountId;
            }
            $match = $row;
        }
        if ($match === null) {
            return $incomingAccountId;
        }

        $existingId = (string)$match['avo_account_id'];
        $canonical = self::preferredAccountId($existingId, $incomingAccountId);
        if ($canonical !== $existingId) {
            self::rekeyAccount($pdo, (int)$match['id'], $existingId, $canonical, $courseSlug);
        }

        return $canonical;
    }

    /**
     * Drops a tilda:* payment when the same checkout already has an AVO invoice row.
     * Returns how many extra rows were removed.
     */
    public static function collapseAliasDuplicates(PDO $pdo): int
    {
        $rows = $pdo->query('SELECT * FROM payments ORDER BY user_id, course_slug, id')->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return 0;
        }

        $groups = [];
        foreach ($rows as $row) {
            $key = (int)$row['user_id'] . "\0" . (string)$row['course_slug'];
            $groups[$key][] = $row;
        }

        $removed = 0;
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $removed += self::collapseGroup($pdo, $group);
        }

        return $removed;
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

        if ($original !== null && $currencyOriginal !== '') {
            $primary = self::formatMoney($original, $currencyOriginal);
            $secondary = $rub !== null ? self::formatMoney($rub, 'RUB') : null;

            return ['primary' => $primary, 'secondary' => $secondary, 'estimated' => false];
        }

        if ($rub !== null) {
            $rate = CbrUsdRubRates::rubPerUsdForPayment($pay);
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

    /**
     * @param array<string, mixed> $pay
     */
    public static function isRefunded(array $pay): bool
    {
        return trim((string)($pay['refunded_at'] ?? '')) !== '';
    }

    /**
     * Refunded payments stay in the student profile and drop out of dashboard revenue.
     * A later webhook upsert does not clear this flag.
     */
    public static function setRefunded(PDO $pdo, int $paymentId, int $userId, bool $refunded): bool
    {
        if ($paymentId <= 0 || $userId <= 0) {
            return false;
        }

        $now = gmdate('c');
        if ($refunded) {
            $stmt = $pdo->prepare(
                'UPDATE payments SET refunded_at = ?, updated_at = ?
                 WHERE id = ? AND user_id = ? AND (refunded_at IS NULL OR refunded_at = \'\')'
            );
            $stmt->execute([$now, $now, $paymentId, $userId]);

            return $stmt->rowCount() > 0;
        }

        $stmt = $pdo->prepare(
            'UPDATE payments SET refunded_at = NULL, updated_at = ?
             WHERE id = ? AND user_id = ? AND refunded_at IS NOT NULL AND refunded_at != \'\''
        );
        $stmt->execute([$now, $paymentId, $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Instant of the payment for dashboard windows. Offsets are real time, not string order.
     *
     * @param array<string, mixed> $row
     */
    public static function eventTimestamp(array $row): ?int
    {
        foreach (['paid_at', 'ordered_at', 'created_at'] as $key) {
            $raw = trim((string)($row[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                return (new \DateTimeImmutable($raw))->getTimestamp();
            } catch (\Throwable) {
                $ts = strtotime($raw);
                if ($ts !== false) {
                    return $ts;
                }
            }
        }

        return null;
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
        CbrUsdRubRates::warmCacheForPayments($rows);

        $rubTotal = 0.0;
        $count = 0;
        $tildaByCurrency = [];
        $estimatedCount = 0;

        foreach ($rows as $pay) {
            if (self::isRefunded($pay)) {
                continue;
            }
            $rub = self::positiveFloat($pay['amount'] ?? null);
            $original = self::positiveFloat($pay['amount_original'] ?? null);
            $currencyOriginal = strtoupper(trim((string)($pay['currency_original'] ?? '')));
            if ($rub === null && $original === null) {
                continue;
            }
            $count++;
            if ($rub !== null) {
                $rubTotal += $rub;
            }
            if ($original !== null) {
                $code = $currencyOriginal !== '' ? $currencyOriginal : 'USD';
                $tildaByCurrency[$code] = ($tildaByCurrency[$code] ?? 0.0) + $original;
                continue;
            }

            $fx = CbrUsdRubRates::rubPerUsdForPayment($pay);
            if ($fx <= 0) {
                continue;
            }
            $tildaByCurrency['USD'] = ($tildaByCurrency['USD'] ?? 0.0) + ($rub / $fx);
            $estimatedCount++;
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

    /**
     * Fill UTM only when all five payment columns are empty. Existing values stay.
     *
     * @param array<string, string> $utm
     */
    public static function fillEmptyUtm(PDO $pdo, int $id, array $utm, string $origin): bool
    {
        if ($id <= 0 || $utm === []) {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT utm_source, utm_medium, utm_campaign, utm_term, utm_content, ad_snapshot
             FROM payments WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            if (trim((string)($row[$key] ?? '')) !== '') {
                return false;
            }
        }

        $snapshot = [];
        $existing = $row['ad_snapshot'] ?? '';
        if (is_string($existing) && $existing !== '') {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) {
                $snapshot = $decoded;
            }
        }
        $snapshot['utm_origin'] = $origin;
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE) ?: null;

        $update = $pdo->prepare(
            'UPDATE payments SET
                utm_source = ?,
                utm_medium = ?,
                utm_campaign = ?,
                utm_term = ?,
                utm_content = ?,
                ad_snapshot = ?,
                updated_at = ?
             WHERE id = ?
               AND COALESCE(utm_source, \'\') = \'\'
               AND COALESCE(utm_medium, \'\') = \'\'
               AND COALESCE(utm_campaign, \'\') = \'\'
               AND COALESCE(utm_term, \'\') = \'\'
               AND COALESCE(utm_content, \'\') = \'\''
        );
        $update->execute([
            $utm['utm_source'] ?? null,
            $utm['utm_medium'] ?? null,
            $utm['utm_campaign'] ?? null,
            $utm['utm_term'] ?? null,
            $utm['utm_content'] ?? null,
            $json,
            gmdate('c'),
            $id,
        ]);

        return $update->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $externalKeys
     */
    private static function samePurchase(
        array $row,
        string $incomingAccountId,
        array $externalKeys,
        ?string $paidAt,
        ?float $amountOriginal,
        ?float $amountRub
    ): bool {
        $siblingId = trim((string)($row['avo_account_id'] ?? ''));
        if ($siblingId === '' || $siblingId === $incomingAccountId) {
            return false;
        }
        if (!self::amountsCompatible($row, $amountOriginal, $amountRub)) {
            return false;
        }

        $linked = false;
        foreach ($externalKeys as $key) {
            if (trim((string)$key) === $siblingId) {
                $linked = true;
                break;
            }
        }
        if ($linked) {
            return true;
        }

        $siblingIsTilda = self::isTildaAccountId($siblingId);
        $incomingIsTilda = self::isTildaAccountId($incomingAccountId);
        $oneIsTilda = $siblingIsTilda !== $incomingIsTilda;
        $otherIsPlain = $siblingIsTilda
            ? self::isPlainAccountId($incomingAccountId)
            : self::isPlainAccountId($siblingId);
        if (!$oneIsTilda || !$otherIsPlain) {
            return false;
        }

        return self::timesCompatible($row, $paidAt);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function amountsCompatible(array $row, ?float $amountOriginal, ?float $amountRub): bool
    {
        $theirOriginal = self::positiveFloat($row['amount_original'] ?? null);
        $theirRub = self::positiveFloat($row['amount'] ?? null);
        if ($theirOriginal !== null && $amountOriginal !== null && abs($theirOriginal - $amountOriginal) > 0.02) {
            return false;
        }
        if ($theirRub !== null && $amountRub !== null && abs($theirRub - $amountRub) > 1.0) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function timesCompatible(array $row, ?string $paidAt): bool
    {
        $their = self::eventTimestamp($row);
        if ($their === null) {
            return false;
        }
        $incoming = $their;
        $paidAt = trim((string)$paidAt);
        if ($paidAt !== '') {
            try {
                $incoming = (new \DateTimeImmutable($paidAt))->getTimestamp();
            } catch (\Throwable) {
                $parsed = strtotime($paidAt);
                $incoming = $parsed !== false ? $parsed : time();
            }
        } else {
            $incoming = time();
        }

        return abs($their - $incoming) <= 3 * 3600;
    }

    private static function preferredAccountId(string $existing, string $incoming): string
    {
        if (self::isPlainAccountId($incoming) && !self::isPlainAccountId($existing)) {
            return $incoming;
        }

        return $existing;
    }

    private static function isTildaAccountId(string $id): bool
    {
        return str_starts_with($id, 'tilda:');
    }

    private static function isPlainAccountId(string $id): bool
    {
        return $id !== '' && !self::isTildaAccountId($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findByAccount(PDO $pdo, string $accountId, string $courseSlug): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE avo_account_id = ? AND course_slug = ? LIMIT 1');
        $stmt->execute([$accountId, $courseSlug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function rekeyAccount(PDO $pdo, int $paymentId, string $fromAccountId, string $toAccountId, string $courseSlug): void
    {
        if ($paymentId <= 0 || $fromAccountId === '' || $toAccountId === '' || $fromAccountId === $toAccountId) {
            return;
        }
        if (self::findByAccount($pdo, $toAccountId, $courseSlug) !== null) {
            return;
        }

        PaymentPricingPending::applyForPayment($pdo, $fromAccountId, $courseSlug);
        $stmt = $pdo->prepare('UPDATE payments SET avo_account_id = ?, updated_at = ? WHERE id = ? AND avo_account_id = ?');
        $stmt->execute([$toAccountId, gmdate('c'), $paymentId, $fromAccountId]);
        self::movePendingAccount($pdo, $fromAccountId, $toAccountId, $courseSlug);
    }

    private static function movePendingAccount(PDO $pdo, string $fromAccountId, string $toAccountId, string $courseSlug): void
    {
        PaymentPricingPending::ensureTable($pdo);
        if (self::pendingExists($pdo, $toAccountId, $courseSlug)) {
            return;
        }
        $stmt = $pdo->prepare(
            'UPDATE payment_pricing_pending SET avo_account_id = ? WHERE avo_account_id = ? AND course_slug = ?'
        );
        $stmt->execute([$toAccountId, $fromAccountId, $courseSlug]);
    }

    private static function pendingExists(PDO $pdo, string $accountId, string $courseSlug): bool
    {
        PaymentPricingPending::ensureTable($pdo);
        $stmt = $pdo->prepare(
            'SELECT 1 FROM payment_pricing_pending WHERE avo_account_id = ? AND course_slug = ? LIMIT 1'
        );
        $stmt->execute([$accountId, $courseSlug]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param list<array<string, mixed>> $group
     */
    private static function collapseGroup(PDO $pdo, array $group): int
    {
        $removed = 0;
        $open = array_values($group);
        $guard = 0;
        while (count($open) >= 2 && $guard < 20) {
            $guard++;
            $pair = self::aliasPair($open);
            if ($pair === null) {
                break;
            }
            [$keepIndex, $dropIndex] = $pair;
            $keeper = $open[$keepIndex];
            $donor = $open[$dropIndex];
            self::copyMissingPaymentFields($pdo, $keeper, $donor);
            PaymentPricingPending::applyForPayment($pdo, (string)$donor['avo_account_id'], (string)$donor['course_slug']);
            self::copyMissingPaymentFields($pdo, $keeper, self::findByAccount($pdo, (string)$donor['avo_account_id'], (string)$donor['course_slug']) ?? $donor);
            $del = $pdo->prepare('DELETE FROM payments WHERE id = ?');
            $del->execute([(int)$donor['id']]);
            $pending = $pdo->prepare('DELETE FROM payment_pricing_pending WHERE avo_account_id = ? AND course_slug = ?');
            $pending->execute([(string)$donor['avo_account_id'], (string)$donor['course_slug']]);
            array_splice($open, $dropIndex, 1);
            $fresh = self::findByAccount($pdo, (string)$keeper['avo_account_id'], (string)$keeper['course_slug']);
            if ($fresh !== null) {
                $open[$keepIndex > $dropIndex ? $keepIndex - 1 : $keepIndex] = $fresh;
            }
            $removed++;
        }

        return $removed;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{0: int, 1: int}|null
     */
    private static function aliasPair(array $rows): ?array
    {
        $count = count($rows);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (self::isRefunded($rows[$i]) || self::isRefunded($rows[$j])) {
                    continue;
                }
                $leftId = (string)($rows[$i]['avo_account_id'] ?? '');
                $rightId = (string)($rows[$j]['avo_account_id'] ?? '');
                $leftPlain = self::isPlainAccountId($leftId);
                $rightPlain = self::isPlainAccountId($rightId);
                if ($leftPlain === $rightPlain) {
                    continue;
                }
                $plain = $leftPlain ? $rows[$i] : $rows[$j];
                $alias = $leftPlain ? $rows[$j] : $rows[$i];
                $plainPaid = trim((string)($plain['paid_at'] ?? '')) !== ''
                    ? (string)$plain['paid_at']
                    : (string)($plain['created_at'] ?? '');
                if (!self::samePurchase(
                    $alias,
                    (string)$plain['avo_account_id'],
                    [],
                    $plainPaid,
                    self::positiveFloat($plain['amount_original'] ?? null),
                    self::positiveFloat($plain['amount'] ?? null)
                )) {
                    continue;
                }

                return $leftPlain ? [$i, $j] : [$j, $i];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $keeper
     * @param array<string, mixed> $donor
     */
    private static function copyMissingPaymentFields(PDO $pdo, array $keeper, array $donor): void
    {
        $stmt = $pdo->prepare(
            'UPDATE payments SET
                id_goods = COALESCE(id_goods, ?),
                amount = COALESCE(amount, ?),
                currency = CASE WHEN TRIM(COALESCE(currency, \'\')) = \'\' THEN ? ELSE currency END,
                ordered_at = COALESCE(ordered_at, ?),
                paid_at = COALESCE(paid_at, ?),
                utm_source = COALESCE(NULLIF(utm_source, \'\'), ?),
                utm_medium = COALESCE(NULLIF(utm_medium, \'\'), ?),
                utm_campaign = COALESCE(NULLIF(utm_campaign, \'\'), ?),
                utm_term = COALESCE(NULLIF(utm_term, \'\'), ?),
                utm_content = COALESCE(NULLIF(utm_content, \'\'), ?),
                ad_snapshot = CASE WHEN ad_snapshot IS NULL OR ad_snapshot = \'\' THEN ? ELSE ad_snapshot END,
                avo_contact_id = COALESCE(avo_contact_id, ?),
                amount_original = COALESCE(amount_original, ?),
                currency_original = CASE WHEN TRIM(COALESCE(currency_original, \'\')) = \'\' THEN ? ELSE currency_original END,
                fx_rate = COALESCE(fx_rate, ?),
                updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $donor['id_goods'] ?? null,
            $donor['amount'] ?? null,
            (string)($donor['currency'] ?? ''),
            $donor['ordered_at'] ?? null,
            $donor['paid_at'] ?? null,
            $donor['utm_source'] ?? null,
            $donor['utm_medium'] ?? null,
            $donor['utm_campaign'] ?? null,
            $donor['utm_term'] ?? null,
            $donor['utm_content'] ?? null,
            $donor['ad_snapshot'] ?? null,
            $donor['avo_contact_id'] ?? null,
            $donor['amount_original'] ?? null,
            (string)($donor['currency_original'] ?? ''),
            $donor['fx_rate'] ?? null,
            gmdate('c'),
            (int)$keeper['id'],
        ]);
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
