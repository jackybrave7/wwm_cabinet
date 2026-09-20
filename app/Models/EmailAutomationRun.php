<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class EmailAutomationRun
{
    public static function hasAnyRun(PDO $pdo, int $automationId, int $userId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM email_automation_runs WHERE automation_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$automationId, $userId]);

        return (bool)$stmt->fetchColumn();
    }

    public static function findActive(PDO $pdo, int $automationId, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM email_automation_runs
             WHERE automation_id = ? AND user_id = ? AND status = \'active\' LIMIT 1'
        );
        $stmt->execute([$automationId, $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function enroll(
        PDO $pdo,
        int $automationId,
        int $userId,
        string $courseSlug,
        string $startNodeId,
        array $context = []
    ): int {
        $existing = self::findActive($pdo, $automationId, $userId);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $now = gmdate('c');
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $pdo->prepare(
            'INSERT INTO email_automation_runs (
                automation_id, user_id, course_slug, status, current_node_id, next_run_at,
                context_json, enrolled_at, updated_at
             ) VALUES (?, ?, ?, \'active\', ?, ?, ?, ?, ?)'
        )->execute([
            $automationId,
            $userId,
            $courseSlug,
            $startNodeId,
            $now,
            $contextJson,
            $now,
            $now,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dueRuns(PDO $pdo, int $limit = 50): array
    {
        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'SELECT r.* FROM email_automation_runs r
             INNER JOIN email_automations a ON a.id = r.automation_id AND a.is_active = 1
             WHERE r.status = \'active\'
               AND (r.next_run_at IS NULL OR r.next_run_at <= ?)
             ORDER BY r.next_run_at ASC, r.id ASC
             LIMIT ?'
        );
        $stmt->execute([$now, $limit]);

        return $stmt->fetchAll() ?: [];
    }

    public static function saveProgress(
        PDO $pdo,
        int $runId,
        string $currentNodeId,
        ?string $nextRunAt
    ): void {
        $pdo->prepare(
            'UPDATE email_automation_runs SET current_node_id = ?, next_run_at = ?, updated_at = ? WHERE id = ?'
        )->execute([$currentNodeId, $nextRunAt, gmdate('c'), $runId]);
    }

    public static function complete(PDO $pdo, int $runId): void
    {
        $now = gmdate('c');
        $pdo->prepare(
            'UPDATE email_automation_runs SET status = \'completed\', completed_at = ?, next_run_at = NULL, updated_at = ? WHERE id = ?'
        )->execute([$now, $now, $runId]);
    }

    public static function cancel(PDO $pdo, int $runId): void
    {
        $pdo->prepare(
            'UPDATE email_automation_runs SET status = \'cancelled\', next_run_at = NULL, updated_at = ? WHERE id = ?'
        )->execute([gmdate('c'), $runId]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function context(array $run): array
    {
        $raw = (string)($run['context_json'] ?? '{}');
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
