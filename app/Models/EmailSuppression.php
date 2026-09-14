<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class EmailSuppression
{
    public static function isSuppressed(PDO $pdo, string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return true;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM email_suppressions WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);

        return (bool)$stmt->fetchColumn();
    }

    public static function suppress(PDO $pdo, string $email, ?int $userId = null, string $source = 'unsubscribe'): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO email_suppressions (email, user_id, source, created_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(email) DO UPDATE SET user_id = COALESCE(excluded.user_id, email_suppressions.user_id), source = excluded.source'
        );
        $stmt->execute([$email, $userId, $source, $now]);
    }
}
