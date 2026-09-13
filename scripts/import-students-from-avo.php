<?php
declare(strict_types=1);

/**
 * Import students and course access from AVO orders (silent, no email).
 *
 * Includes paid (id_account_status=5) and demo/free orders for mapped id_goods.
 * Orders on or after --before are excluded (default: 2026-08-15 00:00 Europe/Moscow).
 * Lesson progress is NOT imported (not exposed in AVO REST API used by this project).
 *
 *   php scripts/import-students-from-avo.php --dry-run
 *   php scripts/import-students-from-avo.php --apply
 *   php scripts/import-students-from-avo.php --apply --before=2026-08-15 --fast
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$argv = $argv ?? [];
$dryRun = !in_array('--apply', $argv, true);
$fast = in_array('--fast', $argv, true);
$beforeDate = '2026-08-15';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--before=')) {
        $beforeDate = substr($arg, 9);
    }
}

$tz = new DateTimeZone('Europe/Moscow');
$before = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $beforeDate . ' 00:00:00', $tz);
if ($before === false) {
    fwrite(STDERR, "Invalid --before= date (use YYYY-MM-DD)\n");
    exit(1);
}
$beforeTs = $before->getTimestamp();

echo $dryRun ? "DRY RUN (no DB writes)\n" : "APPLY (writing to SQLite)\n";
echo 'Cutoff: orders before ' . $before->format('Y-m-d H:i T') . ' (' . gmdate('c', $beforeTs) . " UTC)\n";
echo "Conflict rule: AVO data wins for grants; existing paid access is never removed.\n";
echo "New users: random password (use /forgot when you announce migration).\n\n";
if (function_exists('flush')) {
    flush();
}

try {
    $stats = (new Wwm\Services\AvoLegacyImport())->run(wwm_pdo(), [
        'before_ts' => $beforeTs,
        'dry_run' => $dryRun,
        'pause_micros' => $fast ? 0 : 100000,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!empty($stats['disabled'])) {
    fwrite(STDERR, "AVO is disabled or not configured in config.php\n");
    exit(1);
}

foreach ($stats as $key => $value) {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo str_pad((string)$key, 22) . ': ' . $value . PHP_EOL;
}

exit(0);
