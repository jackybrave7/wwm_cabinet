<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Normalizes AVO account (order) rows from REST API.
 */
final class AvoAccountRow
{
    /**
     * @param array<string, mixed> $row
     */
    public static function orderTimestamp(array $row): int
    {
        foreach (['date_transition', 'date_of_order', 'creation_date', 'date_registration', 'datetime_notify', 'confirmed_date'] as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $ts = strtotime($value);
            if ($ts !== false) {
                return $ts;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isPaid(array $row): bool
    {
        if (isset($row['id_account_status'])) {
            return (int)$row['id_account_status'] === 5;
        }

        foreach (['sum', 'price', 'amount', 'total'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $num = (float)str_replace(',', '.', (string)$row[$key]);
            if ($num > 0) {
                return true;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $goodsMap
     * @return list<int>
     */
    public static function goodsIds(array $row, array $goodsMap): array
    {
        $ids = [];
        $top = (int)($row['id_goods'] ?? 0);
        if ($top > 0 && isset($goodsMap[$top])) {
            $ids[$top] = $top;
        }

        $lines = $row['lines']
            ?? $row['accountlines']
            ?? $row['account_lines']
            ?? $row['accountline']
            ?? $row['goods']
            ?? null;
        if (!is_array($lines)) {
            return array_values($ids);
        }

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $idGoods = (int)($line['id_goods'] ?? 0);
            if ($idGoods > 0 && isset($goodsMap[$idGoods])) {
                $ids[$idGoods] = $idGoods;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<int, true> $allowedGoodsIds
     * @return list<int>
     */
    public static function matchingGoodsIds(array $row, array $allowedGoodsIds): array
    {
        $ids = [];
        $top = (int)($row['id_goods'] ?? 0);
        if ($top > 0 && isset($allowedGoodsIds[$top])) {
            $ids[$top] = $top;
        }

        $lines = $row['lines']
            ?? $row['accountlines']
            ?? $row['account_lines']
            ?? $row['accountline']
            ?? $row['goods']
            ?? null;
        if (!is_array($lines)) {
            return array_values($ids);
        }

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $idGoods = (int)($line['id_goods'] ?? 0);
            if ($idGoods > 0 && isset($allowedGoodsIds[$idGoods])) {
                $ids[$idGoods] = $idGoods;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function email(array $row): string
    {
        $email = strtolower(trim((string)($row['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function contactId(array $row): int
    {
        return (int)($row['id_contact'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function accountId(array $row): int
    {
        return (int)($row['id_account'] ?? $row['id'] ?? 0);
    }
}
