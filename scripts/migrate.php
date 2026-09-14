<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = wwm_pdo();
Wwm\Database::migrate($pdo);
Wwm\Database::persistSchemaVersion($pdo);

echo 'Migration OK: ' . wwm_config()['db_path'] . PHP_EOL;
