<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class PasswordReset
{
    public static function create(PDO $pdo, int $userId, string $tokenPlain, int $ttlSeconds = 3600): void
    {
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
        $stmt = $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            hash('sha256', $tokenPlain),
            gmdate('c', time() + $ttlSeconds),
        ]);
    }

    public static function findValid(PDO $pdo, string $tokenPlain): ?array
    {
        $hash = hash('sha256', $tokenPlain);
        $stmt = $pdo->prepare(
            'SELECT pr.*, u.email FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ?
             LIMIT 1'
        );
        $stmt->execute([$hash, gmdate('c')]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function markUsed(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ?');
        $stmt->execute([gmdate('c'), $id]);
    }
}
