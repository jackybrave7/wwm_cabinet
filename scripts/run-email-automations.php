<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$count = \Wwm\Services\EmailAutomationRunner::processDue();
echo 'Processed automation runs: ' . $count . PHP_EOL;
