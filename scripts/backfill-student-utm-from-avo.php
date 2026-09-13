<?php
declare(strict_types=1);

/**
 * Backfill student UTM / marketing channels from AVO.
 *
 *   php scripts/backfill-student-utm-from-avo.php
 *   php scripts/backfill-student-utm-from-avo.php --fast
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$fast = in_array('--fast', $argv ?? [], true);
$pauseMicros = $fast ? 0 : 150000;

$stats = \Wwm\Services\StudentAttribution::backfillAllUtmFromAvo(wwm_pdo(), $pauseMicros);

if (!empty($stats['disabled'])) {
    fwrite(STDERR, "AVO integration is disabled in config.\n");
    exit(1);
}

echo "AVO UTM backfill complete\n";
echo 'Total students: ' . $stats['total'] . PHP_EOL;
echo 'Updated: ' . $stats['updated'] . PHP_EOL;
echo 'Already complete: ' . $stats['unchanged'] . PHP_EOL;
echo 'No UTM in AVO: ' . $stats['empty'] . PHP_EOL;

exit(0);
