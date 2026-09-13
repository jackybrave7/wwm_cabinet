<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Loads AVO account (order) rows for legacy import without hundreds of per-product API calls.
 */
final class AvoLegacyOrderFetch
{
    /**
     * @param list<int> $allowedGoodsIds
     * @param list<int> $priorityGoodsIds usually full-course id_goods (188, 191, …)
     * @return list<array<string, mixed>>
     */
    public static function collect(
        AvoClient $client,
        int $categoryId,
        array $allowedGoodsIds,
        array $priorityGoodsIds,
        int $pauseMicros,
        ?string &$mode = null
    ): array {
        /** @var array<int, true> */
        $allowed = [];
        foreach ($allowedGoodsIds as $id) {
            if ($id > 0) {
                $allowed[$id] = true;
            }
        }

        foreach (self::categorySearchPlans($categoryId) as [$resource, $search]) {
            if (PHP_SAPI === 'cli') {
                echo 'Trying AVO ' . $resource . ' category filter…' . PHP_EOL;
                if (function_exists('flush')) {
                    flush();
                }
            }
            $rows = self::dedupeAccounts($client->searchAllPages($resource, $search, 100, $pauseMicros));
            $rows = self::filterRowsByGoods($rows, $allowed);
            if ($rows !== []) {
                $mode = 'category:' . $resource;

                return $rows;
            }
        }

        $merged = [];
        foreach ($priorityGoodsIds as $goodsId) {
            if ($goodsId <= 0) {
                continue;
            }
            $batch = $client->searchAllPages('accounts', ['id_goods' => (string)$goodsId], 100, $pauseMicros);
            foreach ($batch as $row) {
                $merged = self::dedupeMerge($merged, [$row]);
            }
        }
        $merged = self::filterRowsByGoods($merged, $allowed);
        if ($merged !== []) {
            $mode = 'accounts_by_course_goods';

            return $merged;
        }

        $mode = 'accounts_full_scan';
        if (PHP_SAPI === 'cli') {
            echo 'Scanning all AVO accounts (can take 10–20 min), matching WWM products…' . PHP_EOL;
            if (function_exists('flush')) {
                flush();
            }
        }

        $out = [];
        $page = 1;
        $pageSize = 100;
        while ($page <= 5000) {
            $batch = $client->searchRows('accounts', [], [
                'pagesize' => $pageSize,
                'currentpage' => $page,
            ]);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                if (self::rowTouchesGoods($row, $allowed)) {
                    $out = self::dedupeMerge($out, [$row]);
                }
            }

            if (PHP_SAPI === 'cli' && ($page === 1 || $page % 10 === 0)) {
                echo '  accounts page ' . $page . ', WWM orders matched: ' . count($out) . PHP_EOL;
                if (function_exists('flush')) {
                    flush();
                }
            }

            if (count($batch) < $pageSize) {
                break;
            }

            $page++;
            if ($pauseMicros > 0) {
                usleep($pauseMicros);
            }
        }

        return $out;
    }

    /**
     * @return list<array{0: string, 1: array<string, string>}>
     */
    private static function categorySearchPlans(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $id = (string)$categoryId;
        $keys = ['f_category', 'id_goods_category', 'goods_category', 'id_category'];
        $plans = [];
        foreach (['accounts', 'accountlines', 'accountline'] as $resource) {
            foreach ($keys as $key) {
                $plans[] = [$resource, [$key => $id]];
            }
        }

        return $plans;
    }

    /**
     * @param array<int, true> $allowed
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function filterRowsByGoods(array $rows, array $allowed): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (self::rowTouchesGoods($row, $allowed)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<int, true> $allowed
     * @param array<string, mixed> $row
     */
    public static function rowTouchesGoods(array $row, array $allowed): bool
    {
        return AvoAccountRow::matchingGoodsIds($row, $allowed) !== [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function dedupeAccounts(array $rows): array
    {
        return self::dedupeMerge([], $rows);
    }

    /**
     * @param list<array<string, mixed>> $base
     * @param list<array<string, mixed>> $add
     * @return list<array<string, mixed>>
     */
    private static function dedupeMerge(array $base, array $add): array
    {
        /** @var array<int, array<string, mixed>> */
        $byId = [];
        foreach (array_merge($base, $add) as $row) {
            $id = AvoAccountRow::accountId($row);
            if ($id > 0) {
                $byId[$id] = $row;
            }
        }

        return array_values($byId);
    }
}
