<?php
declare(strict_types=1);

/**
 * Reconcile dashboard KPIs vs daily chart buckets for a period preset.
 *
 * Usage: php scripts/audit-dashboard-period.php 7d
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$period = $argv[1] ?? '7d';
$group = 'day';
$customFrom = $argv[2] ?? null;
$customTo = $argv[3] ?? null;

$pdo = wwm_pdo();
$dashboard = new Wwm\Services\AdminDashboardStats($pdo, new Wwm\Services\AdminStats($pdo));
$metrika = new Wwm\Services\YandexMetrikaReporting($pdo);

$filters = $dashboard->resolveFilters($period, $group, $customFrom, $customTo);
$from = $filters['from'];
$to = $filters['to'];

$totals = $dashboard->periodTotals($from, $to);
$traffic = $metrika->visitsForPeriod($from, $to, $group);
$chart = $dashboard->chartSeries($from, $to, $group, $traffic['buckets'] ?? []);

$sumVisits = array_sum($chart['visits'] ?? []);
$sumDemo = array_sum($chart['demo'] ?? []);
$sumPaid = array_sum($chart['paid'] ?? []);

echo 'Period: ' . $period . PHP_EOL;
echo 'Range (Moscow): ' . $from->format('Y-m-d H:i') . ' — ' . $to->format('Y-m-d H:i') . PHP_EOL;
echo PHP_EOL;

echo 'KPI totals:' . PHP_EOL;
echo '  new_students: ' . $totals['new_students'] . PHP_EOL;
echo '  demo_signups: ' . $totals['demo_grants'] . PHP_EOL;
echo '  purchases:    ' . $totals['paid_grants'] . PHP_EOL;
echo '  visits (API): ' . (int)($traffic['visits_total'] ?? 0) . PHP_EOL;
echo PHP_EOL;

echo 'Sum of chart buckets:' . PHP_EOL;
echo '  visits: ' . $sumVisits . PHP_EOL;
echo '  demo:   ' . $sumDemo . PHP_EOL;
echo '  paid:   ' . $sumPaid . PHP_EOL;
echo PHP_EOL;

$visitsApi = (int)($traffic['visits_total'] ?? 0);
if ($visitsApi !== $sumVisits) {
    echo 'NOTE: Metrika visits_total (' . $visitsApi . ') != sum(daily visits) (' . $sumVisits . ') — API quirk or partial day.' . PHP_EOL;
}
if ($sumDemo !== (int)$totals['demo_grants']) {
    echo 'MISMATCH: demo chart sum != KPI' . PHP_EOL;
}
if ($sumPaid !== (int)$totals['paid_grants']) {
    echo 'MISMATCH: paid chart sum != KPI' . PHP_EOL;
}
if ($sumDemo === (int)$totals['demo_grants'] && $sumPaid === (int)$totals['paid_grants']) {
    echo 'OK: demo and paid chart sums match KPI cards.' . PHP_EOL;
}

echo PHP_EOL . 'By day:' . PHP_EOL;
foreach ($chart['labels'] ?? [] as $i => $label) {
    echo sprintf(
        "  %-8s  visits %3d  demo %2d  paid %2d\n",
        $label,
        (int)($chart['visits'][$i] ?? 0),
        (int)($chart['demo'][$i] ?? 0),
        (int)($chart['paid'][$i] ?? 0),
    );
}
