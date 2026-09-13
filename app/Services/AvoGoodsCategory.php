<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Discover AVO goods / categories for legacy import (matches CRM product category filter).
 */
final class AvoGoodsCategory
{
    public static function importCategoryId(): int
    {
        $avo = wwm_config()['avo'] ?? [];
        if (!is_array($avo)) {
            return 0;
        }

        return (int)($avo['import_goods_category_id'] ?? 0);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function listCategories(AvoClient $client, int $pauseMicros = 0): array
    {
        $out = [];
        foreach (['goodscategories', 'goods_categories', 'goodscategory', 'goods_category', 'categories'] as $resource) {
            $rows = $client->searchAllPages($resource, [], 100, $pauseMicros);
            if ($rows === []) {
                continue;
            }
            foreach ($rows as $row) {
                $id = (int)($row['id_goods_category'] ?? $row['id_category'] ?? $row['id'] ?? 0);
                $name = trim((string)($row['name'] ?? $row['category'] ?? $row['goods_category'] ?? ''));
                if ($id > 0 && $name !== '') {
                    $out[] = ['id' => $id, 'name' => $name];
                }
            }
            if ($out !== []) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public function goodsIdsInCategory(AvoClient $client, int $categoryId, int $pauseMicros = 0): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $ids = [];
        $searchKeys = ['id_goods_category', 'goods_category', 'id_category'];
        foreach (['goods', 'good'] as $resource) {
            foreach ($searchKeys as $searchKey) {
                $rows = $client->searchAllPages($resource, [$searchKey => (string)$categoryId], 100, $pauseMicros);
                if ($rows === []) {
                    continue;
                }
                foreach ($rows as $row) {
                    $gid = (int)($row['id_goods'] ?? $row['id'] ?? 0);
                    if ($gid > 0) {
                        $ids[$gid] = $gid;
                    }
                }
                if ($ids !== []) {
                    break 2;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<int, string> id_goods => product name
     */
    public function goodsNamesInCategory(AvoClient $client, int $categoryId, int $pauseMicros = 0): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $names = [];
        $searchKeys = ['id_goods_category', 'goods_category', 'id_category'];
        foreach (['goods', 'good'] as $resource) {
            foreach ($searchKeys as $searchKey) {
                $rows = $client->searchAllPages($resource, [$searchKey => (string)$categoryId], 100, $pauseMicros);
                if ($rows === []) {
                    continue;
                }
                foreach ($rows as $row) {
                    $gid = (int)($row['id_goods'] ?? $row['id'] ?? 0);
                    $title = trim((string)($row['name'] ?? $row['goods'] ?? $row['title'] ?? ''));
                    if ($gid > 0 && $title !== '') {
                        $names[$gid] = $title;
                    }
                }
                if ($names !== []) {
                    break 2;
                }
            }
        }

        return $names;
    }

    /**
     * @param list<array{id: int, name: string}> $categories
     */
    public static function findCategoryIdByName(array $categories, string $needle): int
    {
        $needle = mb_strtolower(trim($needle));
        if ($needle === '') {
            return 0;
        }

        foreach ($categories as $row) {
            $name = mb_strtolower($row['name']);
            if (str_contains($name, $needle)) {
                return (int)$row['id'];
            }
        }

        return 0;
    }
}
