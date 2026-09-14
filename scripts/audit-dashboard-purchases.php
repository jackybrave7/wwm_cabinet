<?php
declare(strict_types=1);

/**
 * List paid access rows counted on a calendar day (Europe/Moscow) for dashboard debugging.
 *
 * Usage: php scripts/audit-dashboard-purchases.php 2026-09-12
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$day = $argv[1] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    fwrite(STDERR, "Usage: php scripts/audit-dashboard-purchases.php YYYY-MM-DD\n");
    exit(1);
}

$pdo = wwm_pdo();
$eventAt = "COALESCE(NULLIF(avo_paid_at, ''), NULLIF(avo_ordered_at, ''), granted_at)";
$bucketAt = "datetime({$eventAt}, '+3 hours')";
$dedup = "CASE
    WHEN source_ref IS NOT NULL AND TRIM(source_ref) != '' AND TRIM(source_ref) != 'manual'
        THEN 'order:' || TRIM(source_ref)
    ELSE 'grant:' || user_id || ':' || course_slug
END";

$sql = <<<SQL
SELECT
  a.id,
  u.email,
  a.course_slug,
  a.source,
  a.source_ref,
  a.granted_at,
  a.avo_ordered_at,
  a.avo_paid_at,
  strftime('%Y-%m-%d', {$bucketAt}) AS msk_day,
  {$dedup} AS purchase_key
FROM access a
JOIN users u ON u.id = a.user_id
WHERE a.access_type = 'paid'
  AND strftime('%Y-%m-%d', {$bucketAt}) = ?
ORDER BY a.avo_paid_at, a.granted_at, u.email
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([$day]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$unique = [];
foreach ($rows as $row) {
    $unique[(string)$row['purchase_key']] = true;
}

echo "Paid rows on {$day} (MSK): " . count($rows) . PHP_EOL;
echo 'Unique purchases (dashboard): ' . count($unique) . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;

foreach ($rows as $row) {
    echo sprintf(
        "%s | %s | %s | ref=%s | paid=%s | source=%s\n",
        $row['purchase_key'],
        $row['email'],
        $row['course_slug'],
        $row['source_ref'] ?? '',
        $row['avo_paid_at'] ?? $row['avo_ordered_at'] ?? $row['granted_at'],
        $row['source'] ?? ''
    );
}
