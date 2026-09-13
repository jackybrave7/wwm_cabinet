<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Maps AVO id_goods (full course or single-lesson products) to cabinet course slug.
 * Legacy rule: any paid lesson product → access to the full course.
 */
final class AvoGoodsCourseResolver
{
    /** @var array<int, string> */
    private array $explicitMap;

    /** @var array<int, string> */
    private array $productNames;

    /** @var array<int, string> */
    private array $resolvedCache = [];

    /**
     * @param array<int, string> $productNames id_goods => product title from AVO
     */
    public function __construct(array $productNames = [])
    {
        $this->productNames = $productNames;
        $this->explicitMap = self::buildExplicitMap();
    }

    public function slugForGoods(int $goodsId): ?string
    {
        if ($goodsId <= 0) {
            return null;
        }

        if (isset($this->resolvedCache[$goodsId])) {
            return $this->resolvedCache[$goodsId];
        }

        if (isset($this->explicitMap[$goodsId])) {
            return $this->resolvedCache[$goodsId] = $this->explicitMap[$goodsId];
        }

        $name = trim($this->productNames[$goodsId] ?? '');
        if ($name === '') {
            return null;
        }

        $slug = self::matchProductNameToSlug($name);
        if ($slug !== null) {
            $this->resolvedCache[$goodsId] = $slug;
        }

        return $slug;
    }

    /**
     * @return array<int, string>
     */
    public static function buildExplicitMap(): array
    {
        $map = AvoSalesLinks::goodsMap();
        $lesson = wwm_config()['avo_lesson_goods_to_course'] ?? [];
        if (!is_array($lesson)) {
            return $map;
        }

        foreach ($lesson as $goodsId => $slug) {
            $goodsId = (int)$goodsId;
            $slug = preg_replace('/[^a-z0-9\-]/', '', (string)$slug) ?? '';
            if ($goodsId > 0 && $slug !== '') {
                $map[$goodsId] = $slug;
            }
        }

        return $map;
    }

    public static function matchProductNameToSlug(string $productName): ?string
    {
        $name = mb_strtolower(trim($productName));
        if ($name === '') {
            return null;
        }

        if (self::looksGermanElke($name)) {
            return 'elke-de';
        }

        $rules = [
            'alvaro' => ['alvaro', 'castagnet', 'alfonso'],
            'angus' => ['angus', 'mcewan'],
            'la-fe' => ['la fe', 'la-fe', 'laura brea', 'brea'],
            'votsmush' => ['votsmush', 'alexander vots'],
            'nono' => ['nono', 'caruso'],
            'elke-en' => ['elke', 'berry', 'flowers in watercolor', 'watercolor masters'],
        ];

        $hits = [];
        foreach ($rules as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($name, $keyword)) {
                    $hits[$slug] = ($hits[$slug] ?? 0) + 1;
                    break;
                }
            }
        }

        if ($hits === []) {
            return null;
        }

        arsort($hits);

        return (string)array_key_first($hits);
    }

    private static function looksGermanElke(string $name): bool
    {
        foreach (['elke-de', 'elke de', ' deutsch', 'german', 'auf deutsch', '_de', '-de'] as $marker) {
            if (str_contains($name, $marker)) {
                return true;
            }
        }

        return false;
    }
}
