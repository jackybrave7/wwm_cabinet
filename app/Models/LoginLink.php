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

        $base = rtrim((string)wwm_config()['base_url'], '/');
        return $base . '/auth/magic?token=' . urlencode($token);
    }

    public static function create(
        PDO $pdo,
        int $userId,
        string $tokenPlain,
        string $nextPath,
        int $ttlSeconds = 259200
    ): void {
        $nextPath = self::sanitizeNextPath($nextPath);
        $pdo->prepare('DELETE FROM login_links WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $stmt = $pdo->prepare(
            'INSERT INTO login_links (user_id, token_hash, next_path, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            hash('sha256', $tokenPlain),
            $nextPath,
            gmdate('c', time() + $ttlSeconds),
            gmdate('c'),
        ]);
    }

    public static function findValid(PDO $pdo, string $tokenPlain): ?array
    {
        $hash = hash('sha256', $tokenPlain);
        $stmt = $pdo->prepare(
            'SELECT ll.*, u.email FROM login_links ll
             JOIN users u ON u.id = ll.user_id
             WHERE ll.token_hash = ? AND ll.used_at IS NULL AND ll.expires_at > ?
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
