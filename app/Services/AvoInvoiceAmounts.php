<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * When AVO marks an invoice paid (including by hand) the cabinet webhook often
 * has id_account but no sum. Pull account_sum from the invoice and attach it.
 */
final class AvoInvoiceAmounts
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function mergeMissing(array $payload): array
    {
        if (self::hasAmount($payload)) {
            return $payload;
        }

        $accountId = (int)($payload['id_account'] ?? $payload['source_ref'] ?? 0);
        if ($accountId <= 0) {
            return $payload;
        }

        try {
            $client = new AvoClient();
            if (!$client->isEnabled()) {
                return $payload;
            }
            $rows = $client->searchRows('accounts', ['id_account' => (string)$accountId], ['pagesize' => 1]);
            $row = $rows[0] ?? null;
            if (!is_array($row)) {
                wwm_log('avo amount missing account=' . $accountId);
                return $payload;
            }
            $rub = self::rubFromRow($row);
            if ($rub === null || $rub <= 0) {
                return $payload;
            }
            $payload['account_sum'] = $rub;
            $payload['sum'] = $rub;
            $payload['amount_rub'] = $rub;
            if (trim((string)($payload['currency'] ?? '')) === '') {
                $payload['currency'] = 'RUB';
            }
            wwm_log(sprintf('avo amount matched account=%d sum=%s', $accountId, $rub));
        } catch (\Throwable $e) {
            wwm_log('avo amount lookup failed account=' . $accountId . ': ' . $e->getMessage());
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function hasAmount(array $payload): bool
    {
        foreach (['amount_original', 'amount_rub', 'account_sum', 'sum', 'amount', 'price', 'total'] as $key) {
            if (!isset($payload[$key])) {
                continue;
            }
            $raw = str_replace([' ', ','], ['', '.'], trim((string)$payload[$key]));
            if ($raw !== '' && is_numeric($raw) && (float)$raw > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function rubFromRow(array $row): ?float
    {
        foreach (['account_sum', 'sum', 'amount', 'total'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $raw = str_replace([' ', ','], ['', '.'], trim((string)$row[$key]));
            if ($raw === '' || !is_numeric($raw)) {
                continue;
            }
            $num = (float)$raw;
            if ($num > 0) {
                return $num;
            }
        }

        return null;
    }
}
