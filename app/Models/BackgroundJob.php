<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class BackgroundJob
{
    public const TYPE_LOGIN_FOLLOWUP = 'login_followup';
    public const TYPE_AVO_LOGGED_IN = 'avo_logged_in';
    public const TYPE_AVO_DEMO_OPENED = 'avo_demo_opened';
    public const TYPE_META_CAPI = 'meta_capi';

    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS background_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_type TEXT NOT NULL,
                payload_json TEXT NOT NULL DEFAULT \'{}\',
                status TEXT NOT NULL DEFAULT \'pending\' CHECK (status IN (\'pending\', \'processing\', \'done\', \'failed\')),
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                available_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
             )'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_background_jobs_due
             ON background_jobs(status, available_at, id)'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function enqueue(PDO $pdo, string $jobType, array $payload, ?string $availableAt = null): int
    {
        self::ensureTable($pdo);
        $now = gmdate('c');
        $availableAt = $availableAt !== null && $availableAt !== '' ? $availableAt : $now;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $pdo->prepare(
            'INSERT INTO background_jobs (job_type, payload_json, status, attempts, available_at, created_at, updated_at)
             VALUES (?, ?, \'pending\', 0, ?, ?, ?)'
        )->execute([$jobType, $json, $availableAt, $now, $now]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function claimDue(PDO $pdo, int $limit = 20): array
    {
        self::ensureTable($pdo);
        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'SELECT * FROM background_jobs
             WHERE status = \'pending\' AND available_at <= ?
             ORDER BY id ASC
             LIMIT ?'
        );
        $stmt->execute([$now, $limit]);
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return [];
        }

        $claimed = [];
        foreach ($rows as $row) {
            $upd = $pdo->prepare(
                'UPDATE background_jobs
                 SET status = \'processing\', attempts = attempts + 1, updated_at = ?
                 WHERE id = ? AND status = \'pending\''
            );
            $upd->execute([$now, (int)$row['id']]);
            if ($upd->rowCount() > 0) {
                $row['attempts'] = (int)$row['attempts'] + 1;
                $row['status'] = 'processing';
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public static function markDone(PDO $pdo, int $id): void
    {
        $pdo->prepare(
            'UPDATE background_jobs SET status = \'done\', last_error = NULL, updated_at = ? WHERE id = ?'
        )->execute([gmdate('c'), $id]);
    }

    public static function markFailed(PDO $pdo, int $id, string $error, bool $retry, int $retryAfterSeconds = 120): void
    {
        $now = gmdate('c');
        if ($retry) {
            $pdo->prepare(
                'UPDATE background_jobs
                 SET status = \'pending\', last_error = ?, available_at = ?, updated_at = ?
                 WHERE id = ?'
            )->execute([
                mb_substr($error, 0, 500),
                gmdate('c', time() + max(1, $retryAfterSeconds)),
                $now,
                $id,
            ]);

            return;
        }

        $pdo->prepare(
            'UPDATE background_jobs SET status = \'failed\', last_error = ?, updated_at = ? WHERE id = ?'
        )->execute([mb_substr($error, 0, 500), $now, $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(array $row): array
    {
        $decoded = json_decode((string)($row['payload_json'] ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
