<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Wwm\Services\BroadcastRunner;

$pdo = wwm_pdo();

$started = BroadcastRunner::processDueScheduled($pdo);
if ($started > 0) {
    echo "Started scheduled broadcasts: {$started}\n";
}

$stmt = $pdo->query("SELECT id FROM email_broadcasts WHERE status = 'sending' ORDER BY id ASC");
$ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

foreach ($ids as $id) {
    $id = (int)$id;
    $totalSent = 0;
    for ($i = 0; $i < 10; $i++) {
        $result = BroadcastRunner::processBatch($pdo, $id, BroadcastRunner::BATCH_SIZE);
        $totalSent += $result['sent'];
        if ($result['done']) {
            break;
        }
    }
    echo "Broadcast #{$id}: sent {$totalSent} in this run\n";
}

echo "Done.\n";
