<?php
declare(strict_types=1);

/**
 * List id_goods in an AVO product category and how they map to cabinet courses.
 *
 * AVO often has no REST API for category list — pass category id from the admin UI:
 *
 *   php scripts/list-avo-import-category.php 42
 *   php scripts/list-avo-import-category.php --id=42
 *
 * Optional: try auto-detect by name (may fail on your shop):
 *   php scripts/list-avo-import-category.php --name="watercolor masters"
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$categoryId = 0;
$nameNeedle = '';
foreach ($argv ?? [] as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--id=')) {
        $categoryId = (int)substr($arg, 5);
        continue;
    }
    if ($arg === '--id' && isset($argv[$i + 1])) {
        $categoryId = (int)$argv[$i + 1];
        continue;
    }
    if (str_starts_with($arg, '--name=')) {
        $nameNeedle = trim(substr($arg, 7));
        continue;
    }
    if ($arg === '--name' && isset($argv[$i + 1])) {
        $nameNeedle = trim((string)$argv[$i + 1]);
        continue;
    }
    if ($categoryId <= 0 && ctype_digit($arg)) {
        $categoryId = (int)$arg;
    }
}

$client = new Wwm\Services\AvoClient();
if (!$client->isEnabled()) {
    fwrite(STDERR, "Enable avo in config (API keys).\n");
    exit(1);
}

$helper = new Wwm\Services\AvoGoodsCategory();

if ($categoryId <= 0) {
    $configured = Wwm\Services\AvoGoodsCategory::importCategoryId();
    if ($configured > 0) {
        $categoryId = $configured;
        echo "Using import_goods_category_id from config: {$categoryId}\n\n";
    }
}

if ($categoryId <= 0 && $nameNeedle !== '') {
    $categories = $helper->listCategories($client);
    if ($categories !== []) {
        echo "AVO product categories (" . count($categories) . "):\n";
        foreach ($categories as $row) {
            echo '  id ' . $row['id'] . ' — ' . $row['name'] . PHP_EOL;
        }
        $categoryId = Wwm\Services\AvoGoodsCategory::findCategoryIdByName($categories, $nameNeedle);
    }
}

if ($categoryId <= 0) {
    fwrite(STDERR, <<<TEXT
Category id required (AVO API does not list categories on this shop).

Find id in AVO admin:
  Продажи → Категории товаров → open "World Watercolor Masters videoschool"
  → check browser address bar for id_goods_category=… or similar number.

Then run:
  php scripts/list-avo-import-category.php <id>

Add to config.php under avo:
  'import_goods_category_id' => <id>,

TEXT);
    exit(1);
}

echo "Category id: {$categoryId}\n";
echo "Config: 'import_goods_category_id' => {$categoryId} under 'avo'.\n\n";

$goodsIds = $helper->goodsIdsInCategory($client, $categoryId);
echo 'Goods in category (API): ' . count($goodsIds) . PHP_EOL;
$names = $helper->goodsNamesInCategory($client, $categoryId);
$resolver = new Wwm\Services\AvoGoodsCourseResolver($names);
foreach ($goodsIds as $gid) {
    $slug = $resolver->slugForGoods($gid) ?? '— no course match —';
    $title = $names[$gid] ?? '';
    echo "  id_goods {$gid} → {$slug}";
    if ($title !== '') {
        echo ' — ' . $title;
    }
    echo PHP_EOL;
}

if ($goodsIds === []) {
    fwrite(STDERR, "\nNo goods returned for this category id — wrong id or API search field differs.\n");
    exit(1);
}

exit(0);
