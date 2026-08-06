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
     * @return array{has_paid: bool, has_demo: bool, demo_active: bool, paid_active: bool}
     */
    public static function courseState(PDO $pdo, int $userId, string $courseSlug): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM access WHERE user_id = ? AND course_slug = ?'
        );
        $stmt->execute([$userId, $courseSlug]);
        $rows = $stmt->fetchAll() ?: [];

        $hasPaid = false;
        $paidActive = false;
        $hasDemo = false;
        $demoActive = false;

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
                }
            }
        }

        return [
            'has_paid' => $paidActive,
            'has_demo' => $hasDemo,
            'demo_active' => $demoActive,
            'paid_active' => $paidActive,
        ];
    }
}
