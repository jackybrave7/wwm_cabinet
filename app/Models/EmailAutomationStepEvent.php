<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class EmailAutomationStepEvent
{
    /**
     * @param array{automation_id: int, run_id: int, user_id: int, node_id: string, node_type: string, branch?: ?string, detail?: ?string} $data
     */
    public static function record(PDO $pdo, array $data): void
    {
        $pdo->prepare(
            'INSERT INTO email_automation_step_events (
                automation_id, run_id, user_id, node_id, node_type, branch, detail, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['automation_id'],
            $data['run_id'],
            $data['user_id'],
            $data['node_id'],
            $data['node_type'],
            $data['branch'] ?? null,
            $data['detail'] ?? null,
            gmdate('c'),
        ]);
    }

    /**
     * @return list<array{node_id: string, node_type: string, hits: int, unique_users: int, last_at: ?string}>
     */
    public static function statsByNode(PDO $pdo, int $automationId): array
    {
        $stmt = $pdo->prepare(
            'SELECT node_id, node_type,
                    COUNT(*) AS hits,
                    COUNT(DISTINCT user_id) AS unique_users,
                    MAX(created_at) AS last_at
             FROM email_automation_step_events
             WHERE automation_id = ?
             GROUP BY node_id, node_type
             ORDER BY hits DESC, node_id ASC'
        );
        $stmt->execute([$automationId]);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(static function (array $row): array {
            return [
                'node_id' => (string)$row['node_id'],
                'node_type' => (string)$row['node_type'],
                'hits' => (int)$row['hits'],
                'unique_users' => (int)$row['unique_users'],
                'last_at' => isset($row['last_at']) && $row['last_at'] !== '' ? (string)$row['last_at'] : null,
            ];
        }, $rows);
    }

    public static function totalEvents(PDO $pdo, int $automationId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM email_automation_step_events WHERE automation_id = ?');
        $stmt->execute([$automationId]);

        return (int)$stmt->fetchColumn();
    }
}
