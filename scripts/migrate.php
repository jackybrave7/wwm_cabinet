<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

Wwm\Database::migrate(wwm_pdo());

echo 'Migration OK: ' . wwm_config()['db_path'] . PHP_EOL;
