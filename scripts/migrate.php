<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

Wwm\Database::migrate(wwm_pdo());
@file_put_contents(WWM_ROOT . '/data/.schema_version', (string)Wwm\Database::SCHEMA_VERSION);

echo 'Migration OK: ' . wwm_config()['db_path'] . PHP_EOL;
