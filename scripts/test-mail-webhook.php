<?php
declare(strict_types=1);

/**
 * Local test for GET/POST /api/mail
 *
 *   php scripts/test-mail-webhook.php demo@example.com reminder_demo_no_login elke-en
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$email = $argv[1] ?? '';
$template = $argv[2] ?? 'reminder_demo_no_login';
$course = $argv[3] ?? 'elke-en';
$name = $argv[4] ?? 'Demo User';

if ($email === '') {
    fwrite(STDERR, "Usage: php scripts/test-mail-webhook.php EMAIL [template] [course] [name]\n");
    exit(1);
}

$cfg = wwm_config();
$token = trim((string)($cfg['webhooks']['demo_token'] ?? ''));
if ($token === '') {
    fwrite(STDERR, "Set webhooks.demo_token and webhooks.enabled=true in config/config.php\n");
    exit(1);
}

if (empty($cfg['webhooks']['enabled'])) {
    fwrite(STDERR, "Enable webhooks.enabled in config/config.php\n");
    exit(1);
}

$query = http_build_query([
    'token' => $token,
    'template' => $template,
    'email' => $email,
    'name' => $name,
    'course' => $course,
]);

$url = rtrim(wwm_base_url(), '/') . '/api/mail?' . $query;
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'ignore_errors' => true,
        'timeout' => 30,
    ],
]);

$response = @file_get_contents($url, false, $context);
if ($response === false) {
    fwrite(STDERR, "Request failed: {$url}\n");
    exit(1);
}

echo $response . PHP_EOL;
