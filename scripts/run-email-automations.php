<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$count = \Wwm\Services\EmailAutomationRunner::processDue();
echo gmdate('c') . ' Processed automation runs: ' . $count . PHP_EOL;

// Same cron also drains login/AVO follow-up jobs so tags still land without a second schedule.
$jobs = \Wwm\Services\BackgroundJobRunner::processDue();
echo gmdate('c') . ' Processed background jobs: ' . $jobs . PHP_EOL;
