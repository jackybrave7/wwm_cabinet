<?php
declare(strict_types=1);

/**
 * Resolve UTM for an AVO contact via API (diagnostics).
 *
 *   php scripts/test-avo-utm.php 7761
 *   php scripts/test-avo-utm.php email@example.com
 *   php scripts/test-avo-utm.php email@example.com --debug
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$arg = trim((string)($argv[1] ?? ''));
$debug = in_array('--debug', $argv ?? [], true);

if ($arg === '' || $arg === '--debug') {
    fwrite(STDERR, "Usage: php scripts/test-avo-utm.php <id_contact|email> [--debug]\n");
    exit(1);
}

$client = new Wwm\Services\AvoClient();
if (!$client->isEnabled()) {
    fwrite(STDERR, "Enable avo.enabled and API keys in config/config.php\n");
    exit(1);
}

$contactId = ctype_digit($arg) ? (int)$arg : 0;
$email = $contactId > 0 ? '' : strtolower($arg);

if ($contactId <= 0) {
    $found = $client->findContactIdByEmail($email);
    if ($found === null) {
        fwrite(STDERR, "Contact not found for email: {$arg}\n");
        exit(1);
    }
    $contactId = $found;
}

$payload = ['id_contact' => $contactId];
if ($email !== '') {
    $payload['email'] = $email;
}

$resolver = new Wwm\Services\AvoUtmResolver($client);

if ($debug) {
    echo json_encode($resolver->resolveDebug($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$utm = $resolver->resolve($payload);

echo json_encode([
    'contact_id' => $contactId,
    'utm' => $utm,
    'avo_last_error' => $client->lastError(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
