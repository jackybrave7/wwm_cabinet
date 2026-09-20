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
        $filter = EmailBroadcast::decodeAudienceFilter((string)($broadcast['audience_filter_json'] ?? ''));

        if ($audience === 'filtered' || $filter->isActive()) {
            return self::recipientsMatching($pdo, $audience, $filter);
        }

        return self::recipientsForPreset($pdo, $audience);
    }

    public static function countForBroadcast(PDO $pdo, string $audience, AdminStudentListFilter $filter): int
    {
        return count(self::recipientUserIds($pdo, $audience, $filter));
    }

    /**
     * @return list<int>
     */
    public static function recipientUserIds(PDO $pdo, string $audience, AdminStudentListFilter $filter): array
    {
        $audience = EmailBroadcast::normalizeAudience($audience);
        if ($audience === 'filtered' || $filter->isActive()) {
            $rows = self::recipientsMatching($pdo, $audience, $filter);

            return array_values(array_map(static fn (array $row): int => (int)$row['id'], $rows));
        }

        $rows = self::recipientsForPreset($pdo, $audience);

        return array_values(array_map(static fn (array $row): int => (int)$row['id'], $rows));
    }

    /**
     * @return list<array{id: int, email: string, name: string}>
     */
    private static function recipientsMatching(PDO $pdo, string $audience, AdminStudentListFilter $filter): array
    {
        [$sql, $params] = self::matchingSql($audience, $filter, false);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return self::mapRecipientRows($stmt);
    }

    private static function countMatching(PDO $pdo, string $audience, AdminStudentListFilter $filter): int
    {
        [$sql, $params] = self::matchingSql($audience, $filter, true);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private static function matchingSql(string $audience, AdminStudentListFilter $filter, bool $countOnly): array
    {
        $built = $filter->sqlWhere();
        $parts = [
            'u.is_admin = 0',
            'u.email IS NOT NULL AND TRIM(u.email) != \'\'',
            '(' . $built['where'] . ')',
        ];
        $params = $built['params'];

        $preset = $audience === 'filtered' ? 'all_students' : EmailBroadcast::normalizeAudience($audience);
        if ($preset === 'with_access') {
            $parts[] = 'EXISTS (SELECT 1 FROM access a WHERE a.user_id = u.id)';
        }

        $where = implode(' AND ', $parts);
        if ($countOnly) {
            return ['SELECT COUNT(*) FROM users u WHERE ' . $where, $params];
        }

        return ['SELECT u.id, u.email, u.name FROM users u WHERE ' . $where . ' ORDER BY u.id ASC', $params];
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
