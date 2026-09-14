<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\EmailBroadcast;

final class BroadcastAudience
{
    /**
     * @return list<array{id: int, email: string, name: string}>
     */
    public static function recipientsForAudience(PDO $pdo, string $audience): array
    {
        $audienceKey = EmailBroadcast::normalizeAudience($audience);

        if ($audienceKey === 'with_access') {
            $sql = 'SELECT DISTINCT u.id, u.email, u.name
                    FROM users u
                    INNER JOIN access a ON a.user_id = u.id
                    WHERE u.email IS NOT NULL AND TRIM(u.email) != \'\' AND u.is_admin = 0
                    ORDER BY u.id ASC';
        } else {
            $sql = 'SELECT u.id, u.email, u.name
                    FROM users u
                    WHERE u.email IS NOT NULL AND TRIM(u.email) != \'\' AND u.is_admin = 0
                    ORDER BY u.id ASC';
        }

        $stmt = $pdo->query($sql);
        if (!$stmt) {
            return [];
        }

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $rows[] = [
                'id' => (int)$row['id'],
                'email' => $email,
                'name' => trim((string)($row['name'] ?? '')),
            ];
        }

        return $rows;
    }
}
