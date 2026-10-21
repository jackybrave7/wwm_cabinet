<?php
declare(strict_types=1);

/**
 * Backfill cabinet payments (and paid access) from paid AVO accounts.
 *
 *   php scripts/backfill-payments-from-avo.php --dry-run --since=2025-09-01
 *   php scripts/backfill-payments-from-avo.php --apply --since=2025-09-01 --limit=20
 *   php scripts/backfill-payments-from-avo.php --apply --since=2025-09-01 --goods=188
 *
 * Run on production server (config.php + AVO API). Dry-run is default.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
$dryRun = in_array('--dry-run', $argv ?? [], true) || !$apply;

$since = '2025-09-01';
$until = '';
$limit = 0;
$goodsId = 0;
$sendEmail = in_array('--send-email', $argv ?? [], true);
$paymentsOnly = in_array('--payments-only', $argv ?? [], true);
$grantOnly = in_array('--grant-only', $argv ?? [], true);
$fast = in_array('--fast', $argv ?? [], true);

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--since=')) {
        $since = substr($arg, 8);
    } elseif (str_starts_with($arg, '--until=')) {
        $until = substr($arg, 8);
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, 8);
    } elseif (str_starts_with($arg, '--goods=')) {
        $goodsId = (int)substr($arg, 8);
    }
}

$sinceTs = strtotime($since . ' 00:00:00 Europe/Moscow');
if ($sinceTs === false) {
    fwrite(STDERR, "Invalid --since date (use YYYY-MM-DD)\n");
    exit(1);
}
$untilTs = 0;
if ($until !== '') {
    $untilTs = strtotime($until . ' 23:59:59 Europe/Moscow');
    if ($untilTs === false) {
        fwrite(STDERR, "Invalid --until date\n");
        exit(1);
    }
}

try {
    $stats = (new Wwm\Services\AvoPaymentBackfill())->run(wwm_pdo(), [
        'dry_run' => $dryRun,
        'since_ts' => $sinceTs,
        'until_ts' => $untilTs,
        'limit' => $limit,
        'pause_micros' => $fast ? 0 : 80000,
        'grant_access' => !$paymentsOnly,
        'record_payments' => !$grantOnly,
        'send_email' => $sendEmail,
        'goods_id' => $goodsId,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if (!empty($stats['disabled'])) {
    fwrite(STDERR, "AVO disabled in config.\n");
    exit(1);
}

echo $dryRun ? "Dry run (no changes). Use --apply to write.\n" : "Applied.\n";
foreach ($stats as $key => $value) {
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    }
    echo $key . ': ' . $value . PHP_EOL;
}

exit((int)($stats['errors'] ?? 0) > 0 ? 1 : 0);
