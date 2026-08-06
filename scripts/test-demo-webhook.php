<?php
declare(strict_types=1);

/**
 * Local test for POST /api/demo
 *
 *   php scripts/test-demo-webhook.php demo@example.com elke-en
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$email = $argv[1] ?? 'demo-webhook@wwm.test';
$course = $argv[2] ?? 'elke-en';
$name = $argv[3] ?? 'Demo User';

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

$service = new Wwm\Services\DemoAccess();
$result = $service->grant($email, $name, $course, 'test', 'cli');
echo json_encode(['ok' => true] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
