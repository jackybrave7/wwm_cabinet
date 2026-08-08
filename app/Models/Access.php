<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class Access
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM access WHERE user_id = ? ORDER BY course_slug, access_type'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    public static function groupedByUser(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT * FROM access ORDER BY user_id, course_slug, access_type');
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['user_id']][] = $row;
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{has_paid: bool, has_demo: bool, demo_active: bool, paid_active: bool, demo_expires_at: ?string}>
     */
    public static function stateMapFromRows(array $rows): array
    {
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[(string)$row['course_slug']][] = $row;
        }

        $map = [];
        foreach ($bySlug as $slug => $slugRows) {
            $map[$slug] = self::courseStateFromRows($slugRows);
        }

        return $map;
    }

    /**
     * @return array<string, array{has_paid: bool, has_demo: bool, demo_active: bool, paid_active: bool, demo_expires_at: ?string}>
     */
    public static function stateMapForUser(PDO $pdo, int $userId): array
    {
        return self::stateMapFromRows(self::forUser($pdo, $userId));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    public static function grantsByCourseFromRows(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $slug = (string)$row['course_slug'];
            $type = (string)$row['access_type'];
            $map[$slug . ':' . $type] = $row;
        }

        return $map;
    }

    /**
     * @param array<string, array{has_paid: bool, has_demo: bool, demo_active: bool, paid_active: bool}> $stateMap
     */
    public static function accessLabelFromStateMap(array $stateMap): string
    {
        if ($stateMap === []) {
            return 'No access';
        }

        $hasPaid = false;
        $hasDemo = false;
        foreach ($stateMap as $state) {
            if ($state['has_paid']) {
                $hasPaid = true;
            }
            if ($state['demo_active']) {
                $hasDemo = true;
            }
        }

        if ($hasPaid) {
            return 'Paid';
        }
        if ($hasDemo) {
            return 'Demo';
        }

        return 'Demo expired';
    }

    public static function grant(
        PDO $pdo,
        int $userId,
        string $courseSlug,
        string $accessType,
        ?string $expiresAt,
        string $source,
        ?string $sourceRef = null
    ): void {
        if (!in_array($accessType, ['demo', 'paid'], true)) {
            throw new \InvalidArgumentException('Invalid access type');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO access (user_id, course_slug, access_type, granted_at, expires_at, source, source_ref)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id, course_slug, access_type) DO UPDATE SET
               granted_at = excluded.granted_at,
               expires_at = excluded.expires_at,
               source = excluded.source,
               source_ref = excluded.source_ref'
        );
        $stmt->execute([
            $userId,
            $courseSlug,
            $accessType,
            gmdate('c'),
            $expiresAt,
            $source,
            $sourceRef,
        ]);
    }

    public static function revoke(PDO $pdo, int $userId, string $courseSlug, string $accessType): void
    {
        $stmt = $pdo->prepare(
            'DELETE FROM access WHERE user_id = ? AND course_slug = ? AND access_type = ?'
        );
        $stmt->execute([$userId, $courseSlug, $accessType]);
    }

    public static function findGrant(PDO $pdo, int $userId, string $courseSlug, string $accessType): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM access WHERE user_id = ? AND course_slug = ? AND access_type = ? LIMIT 1'
        );
        $stmt->execute([$userId, $courseSlug, $accessType]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function isGrantActive(?array $row): bool
    {
        if ($row === null) {
            return false;
        }
        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt === '') {
            return true;
        }
        $ts = strtotime((string)$expiresAt);

        return $ts !== false && $ts > time();
    }

    /**
     * @return array{has_paid: bool, has_demo: bool, demo_active: bool, paid_active: bool, demo_expires_at: ?string, demo_granted_at: ?string}
     */
    public static function courseState(PDO $pdo, int $userId, string $courseSlug): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM access WHERE user_id = ? AND course_slug = ?'
        );
        $stmt->execute([$userId, $courseSlug]);
        $rows = $stmt->fetchAll() ?: [];

        return self::courseStateFromRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{has_paid: bool, has_demo: bool, demo_active: bool, paid_active: bool, demo_expires_at: ?string, demo_granted_at: ?string}
     */
    private static function courseStateFromRows(array $rows): array
    {
        $hasPaid = false;
        $paidActive = false;
        $hasDemo = false;
        $demoActive = false;
        $demoExpiresAt = null;
        $demoGrantedAt = null;

        foreach ($rows as $row) {
            if ($row['access_type'] === 'paid') {
                $hasPaid = true;
                if (self::isGrantActive($row)) {
                    $paidActive = true;
                }
            }
            if ($row['access_type'] === 'demo') {
                $hasDemo = true;
                if (self::isGrantActive($row)) {
                    $demoActive = true;
                    $expires = $row['expires_at'] ?? null;
                    if (is_string($expires) && $expires !== '') {
                        $demoExpiresAt = $expires;
                    }
                    $granted = $row['granted_at'] ?? null;
                    if (is_string($granted) && $granted !== '') {
                        $demoGrantedAt = $granted;
                    }
                }
            }
        }

        return [
            'has_paid' => $paidActive,
            'has_demo' => $hasDemo,
            'demo_active' => $demoActive,
            'paid_active' => $paidActive,
            'demo_expires_at' => $demoExpiresAt,
            'demo_granted_at' => $demoGrantedAt,
        ];
    }
}
