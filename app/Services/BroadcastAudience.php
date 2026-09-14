<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\EmailBroadcast;

final class BroadcastAudience
{
    /**
     * @param array<string, mixed> $broadcast
     */
    public static function recipientsForBroadcast(PDO $pdo, array $broadcast): array
    {
        $audience = EmailBroadcast::normalizeAudience((string)($broadcast['audience'] ?? 'all_students'));
        if ($audience === 'filtered') {
            $filter = EmailBroadcast::decodeAudienceFilter((string)($broadcast['audience_filter_json'] ?? ''));
            return self::recipientsForFilter($pdo, $filter);
        }

        return self::recipientsForPreset($pdo, $audience);
    }

    public static function countForBroadcast(PDO $pdo, string $audience, AdminStudentListFilter $filter): int
    {
        $audience = EmailBroadcast::normalizeAudience($audience);
        if ($audience === 'filtered') {
            return self::countForFilter($pdo, $filter);
        }

        return count(self::recipientsForPreset($pdo, $audience));
    }

    /**
     * @return list<array{id: int, email: string, name: string}>
     */
    public static function recipientsForPreset(PDO $pdo, string $audience): array
    {
        $audienceKey = EmailBroadcast::normalizeAudience($audience);

        if ($audienceKey === 'with_access') {
            $sql = 'SELECT DISTINCT u.id, u.email, u.name
                    FROM users u
                    INNER JOIN access a ON a.user_id = u.id
                    WHERE u.email IS NOT NULL AND TRIM(u.email) != \'\' AND u.is_admin = 0
                    ORDER BY u.id ASC';
            $stmt = $pdo->query($sql);
        } else {
            $sql = 'SELECT u.id, u.email, u.name
                    FROM users u
                    WHERE u.email IS NOT NULL AND TRIM(u.email) != \'\' AND u.is_admin = 0
                    ORDER BY u.id ASC';
            $stmt = $pdo->query($sql);
        }

        return self::mapRecipientRows($stmt);
    }

    /**
     * @return list<array{id: int, email: string, name: string}>
     */
    public static function recipientsForFilter(PDO $pdo, AdminStudentListFilter $filter): array
    {
        $built = $filter->sqlWhere();
        $sql = 'SELECT u.id, u.email, u.name FROM users u WHERE u.is_admin = 0'
            . ' AND u.email IS NOT NULL AND TRIM(u.email) != \'\''
            . ' AND (' . $built['where'] . ') ORDER BY u.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($built['params']);

        return self::mapRecipientRows($stmt);
    }

    public static function countForFilter(PDO $pdo, AdminStudentListFilter $filter): int
    {
        $built = $filter->sqlWhere();
        $sql = 'SELECT COUNT(*) FROM users u WHERE u.is_admin = 0'
            . ' AND u.email IS NOT NULL AND TRIM(u.email) != \'\''
            . ' AND (' . $built['where'] . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($built['params']);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @param \PDOStatement|false|null $stmt
     * @return list<array{id: int, email: string, name: string}>
     */
    private static function mapRecipientRows($stmt): array
    {
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
