<?php
declare(strict_types=1);

/**
 * Send all built-in email templates to an inbox for review.
 *
 *   php scripts/send-all-email-samples.php recipient@example.com "Name"
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$email = trim((string)($argv[1] ?? ''));
$name = trim((string)($argv[2] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/send-all-email-samples.php recipient@example.com [name]\n");
    exit(1);
}

$results = (new Wwm\Services\EmailSampleSender())->sendAll($email, $name !== '' ? $name : null);

foreach ($results as $row) {
    $status = $row['ok'] ? 'ok' : 'FAIL';
    echo sprintf("[%s] %s — %s\n", $status, $row['id'], $row['subject'] !== '' ? $row['subject'] : ($row['error'] ?? ''));
}

$failed = array_filter($results, static fn(array $row): bool => !$row['ok']);
exit($failed === [] ? 0 : 1);
