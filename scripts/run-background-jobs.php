<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$count = \Wwm\Services\BackgroundJobRunner::processDue();
echo gmdate('c') . ' Processed background jobs: ' . $count . PHP_EOL;
