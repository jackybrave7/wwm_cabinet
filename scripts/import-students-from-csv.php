<?php
declare(strict_types=1);

/**
 * Import students and access from AVO bl-school CSV (account lines export).
 *
 *   php scripts/import-students-from-csv.php /path/to/bl-school-accounts.csv --dry-run
 *   php scripts/import-students-from-csv.php /path/to/bl-school-accounts.csv --apply
 *   php scripts/import-students-from-csv.php file.csv --apply --before=2026-08-15
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$argv = $argv ?? [];
$dryRun = !in_array('--apply', $argv, true);
$beforeDate = '2026-08-15';
$csvPath = '';

foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--before=')) {
        $beforeDate = substr($arg, 9);
        continue;
    }
    if (str_starts_with($arg, '--')) {
        continue;
    }
    if ($csvPath === '') {
        $csvPath = $arg;
    }
}

if ($csvPath === '') {
    fwrite(STDERR, "Usage: php scripts/import-students-from-csv.php <path.csv> [--dry-run|--apply] [--before=YYYY-MM-DD]\n");
    exit(1);
}

if (!is_file($csvPath)) {
    fwrite(STDERR, "File not found: {$csvPath}\n");
    exit(1);
}

$tz = new DateTimeZone('Europe/Moscow');
$before = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $beforeDate . ' 00:00:00', $tz);
if ($before === false) {
    fwrite(STDERR, "Invalid --before= date (use YYYY-MM-DD)\n");
    exit(1);
}

echo $dryRun ? "DRY RUN (no DB writes)\n" : "APPLY (writing to SQLite)\n";
echo 'CSV: ' . $csvPath . PHP_EOL;
echo 'Cutoff: orders before ' . $before->format('Y-m-d H:i T') . PHP_EOL;
echo "Paid lesson / any paid line → full course. Demo lines from Demos category or Demo of…\n\n";

try {
    $stats = (new Wwm\Services\CsvLegacyImport())->run(wwm_pdo(), [
        'csv_path' => $csvPath,
        'before_ts' => $before->getTimestamp(),
        'dry_run' => $dryRun,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($stats as $key => $value) {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo str_pad((string)$key, 22) . ': ' . $value . PHP_EOL;
}

exit(0);
