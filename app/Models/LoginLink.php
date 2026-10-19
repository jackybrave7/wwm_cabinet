<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class LoginLink
{
    public static function issue(PDO $pdo, int $userId, string $nextPath = '/', int $ttlSeconds = 259200): string
    {
        $token = bin2hex(random_bytes(32));
        self::create($pdo, $userId, $token, $nextPath, $ttlSeconds);

        return wwm_base_url() . '/auth/magic?token=' . urlencode($token);
    }

    public static function create(
        PDO $pdo,
        int $userId,
        string $tokenPlain,
        string $nextPath,
        int $ttlSeconds = 259200
    ): void {
        $nextPath = self::sanitizeNextPath($nextPath);
        $pdo->prepare('DELETE FROM login_links WHERE user_id = ? AND used_at IS NULL AND COALESCE(reusable, 0) = 0')->execute([$userId]);
        self::insert($pdo, $userId, $tokenPlain, $nextPath, $ttlSeconds, false);
    }

    public static function issueReusable(PDO $pdo, int $userId, string $nextPath, int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32));
        $nextPath = self::sanitizeNextPath($nextPath);
        self::insert($pdo, $userId, $token, $nextPath, $ttlSeconds, true);

        return wwm_base_url() . '/auth/magic?token=' . urlencode($token);
    }

    private static function insert(
        PDO $pdo,
        int $userId,
        string $tokenPlain,
        string $nextPath,
        int $ttlSeconds,
        bool $reusable
    ): void {
        self::ensureReusableColumn($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO login_links (user_id, token_hash, next_path, expires_at, created_at, reusable)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            hash('sha256', $tokenPlain),
            $nextPath,
            gmdate('c', time() + max(60, $ttlSeconds)),
            gmdate('c'),
            $reusable ? 1 : 0,
        ]);
    }

    private static function ensureReusableColumn(PDO $pdo): void
    {
        $cols = $pdo->query('PRAGMA table_info(login_links)')->fetchAll() ?: [];
        foreach ($cols as $col) {
            if ((string)($col['name'] ?? '') === 'reusable') {
                return;
            }
        }
        $pdo->exec('ALTER TABLE login_links ADD COLUMN reusable INTEGER NOT NULL DEFAULT 0');
    }

    public static function findValid(PDO $pdo, string $tokenPlain): ?array
    {
        $hash = hash('sha256', $tokenPlain);
        self::ensureReusableColumn($pdo);
        $stmt = $pdo->prepare(
            'SELECT ll.*, u.email FROM login_links ll
             JOIN users u ON u.id = ll.user_id
             WHERE ll.token_hash = ? AND ll.expires_at > ?
               AND (ll.used_at IS NULL OR COALESCE(ll.reusable, 0) = 1)
             LIMIT 1'
        );
        $stmt->execute([$hash, gmdate('c')]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function markUsed(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('UPDATE login_links SET used_at = ? WHERE id = ?');
        $stmt->execute([gmdate('c'), $id]);
    }

    public static function sanitizeNextPath(string $nextPath): string
    {
        $nextPath = trim($nextPath);
        if ($nextPath === '' || !str_starts_with($nextPath, '/') || str_starts_with($nextPath, '//')) {
            return '/';
        }
        return $nextPath;
    }

    public static function ttlSeconds(): int
    {
        $hours = (int)(wwm_config()['magic_link_hours'] ?? 72);
        if ($hours < 1) {
            $hours = 72;
        }
        return $hours * 3600;
    }
}
