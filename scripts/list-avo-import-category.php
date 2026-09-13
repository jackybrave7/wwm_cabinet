<?php
declare(strict_types=1);

/**
 * Find AVO product category id (e.g. "World Watercolor Masters videoschool") and list id_goods in it.
 *
 *   php scripts/list-avo-import-category.php
 *   php scripts/list-avo-import-category.php "watercolor masters"
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$needle = trim((string)($argv[1] ?? 'watercolor masters'));
$client = new Wwm\Services\AvoClient();
if (!$client->isEnabled()) {
    fwrite(STDERR, "Enable avo in config (API keys).\n");
    exit(1);
}

$helper = new Wwm\Services\AvoGoodsCategory();
$categories = $helper->listCategories($client);
if ($categories === []) {
    fwrite(STDERR, "Could not list categories via API. Set avo.import_goods_category_id manually in config.\n");
    exit(1);
}

echo "AVO product categories (" . count($categories) . "):\n";
foreach ($categories as $row) {
    echo '  id ' . $row['id'] . ' — ' . $row['name'] . PHP_EOL;
}

$matchId = Wwm\Services\AvoGoodsCategory::findCategoryIdByName($categories, $needle);
if ($matchId <= 0) {
    fwrite(STDERR, "\nNo category matching \"{$needle}\". Pass another substring as argv[1].\n");
    exit(1);
}

echo "\nMatched category id: {$matchId}\n";
echo "Add to config: 'import_goods_category_id' => {$matchId} under 'avo'.\n\n";

$goodsIds = $helper->goodsIdsInCategory($client, $matchId);
echo 'Goods in category: ' . count($goodsIds) . PHP_EOL;
$names = $helper->goodsNamesInCategory($client, $matchId);
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

exit(0);
