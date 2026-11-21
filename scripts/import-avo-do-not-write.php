<?php
declare(strict_types=1);

/**
 * Load AVO "Не писать" contact export into email_suppressions.
 * Marketing broadcasts and automations skip these addresses.
 * Sign-in and password emails are unchanged.
 *
 *   php scripts/import-avo-do-not-write.php path.csv
 *   php scripts/import-avo-do-not-write.php path.csv --apply
 */
$argv = $argv ?? [];
$apply = in_array('--apply', $argv, true);
$csvPath = '';
foreach ($argv as $i => $arg) {
    if ($i === 0 || str_starts_with($arg, '--')) {
        continue;
    }
    if ($csvPath === '') {
        $csvPath = $arg;
    }
}

if ($csvPath === '' || !is_file($csvPath)) {
    fwrite(STDERR, "Usage: php scripts/import-avo-do-not-write.php <contacts.csv> [--apply]\n");
    exit(1);
}

$fh = fopen($csvPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "Cannot open {$csvPath}\n");
    exit(1);
}

$header = fgetcsv($fh, 0, ';', '"', '\\');
if (!is_array($header)) {
    fwrite(STDERR, "CSV has no header\n");
    exit(1);
}
$header = array_map(static function ($name): string {
    $name = (string)$name;
    $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;

    return trim($name);
}, $header);
$emailIdx = array_search('Email', $header, true);
if ($emailIdx === false) {
    fwrite(STDERR, "CSV has no Email column\n");
    exit(1);
}

$seen = [];
$invalid = 0;
$rows = 0;
while (($row = fgetcsv($fh, 0, ';', '"', '\\')) !== false) {
    if ($row === [null]) {
        continue;
    }
    $rows++;
    $email = strtolower(trim((string)($row[$emailIdx] ?? '')));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $invalid++;
        continue;
    }
    $seen[$email] = true;
}
fclose($fh);

$emails = array_keys($seen);
echo ($apply ? "APPLY\n" : "DRY RUN (no DB writes)\n");
echo 'CSV: ' . $csvPath . PHP_EOL;
echo 'rows: ' . $rows . PHP_EOL;
echo 'unique_emails: ' . count($emails) . PHP_EOL;
echo 'invalid_or_empty: ' . $invalid . PHP_EOL;

if (!$apply) {
    exit(0);
}

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = wwm_pdo();
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
echo 'db_users: ' . $userCount . PHP_EOL;
if ($userCount === 0) {
    fwrite(STDERR, "Refusing to write: users table is empty. This is not the live cabinet database.\n");
    exit(1);
}

$now = gmdate('c');
$insert = $pdo->prepare(
    'INSERT INTO email_suppressions (email, user_id, source, created_at)
     VALUES (?, (SELECT id FROM users WHERE email = ? LIMIT 1), ?, ?)
     ON CONFLICT(email) DO NOTHING'
);
$linked = 0;
$inserted = 0;
$pdo->beginTransaction();
try {
    foreach ($emails as $email) {
        $insert->execute([$email, $email, 'avo-do-not-write', $now]);
        $inserted += $insert->rowCount();
    }
    $linked = (int)$pdo->exec(
        'UPDATE email_suppressions
         SET user_id = (SELECT id FROM users WHERE users.email = email_suppressions.email)
         WHERE source = \'avo-do-not-write\'
           AND user_id IS NULL
           AND EXISTS (SELECT 1 FROM users WHERE users.email = email_suppressions.email)'
    );
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'inserted: ' . $inserted . PHP_EOL;
echo 'already_present: ' . (count($emails) - $inserted) . PHP_EOL;
echo 'linked_to_students: ' . $linked . PHP_EOL;
exit(0);
