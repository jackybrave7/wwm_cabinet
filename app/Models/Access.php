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

    /**
     * @return array{has_paid: bool, has_demo: bool, demo_active: bool}
     */
    public static function courseState(PDO $pdo, int $userId, string $courseSlug): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM access WHERE user_id = ? AND course_slug = ?'
        );
        $stmt->execute([$userId, $courseSlug]);
        $rows = $stmt->fetchAll() ?: [];

        $hasPaid = false;
        $hasDemo = false;
        $demoActive = false;
        $now = time();

        foreach ($rows as $row) {
            if ($row['access_type'] === 'paid') {
                $hasPaid = true;
            }
            if ($row['access_type'] === 'demo') {
                $hasDemo = true;
                $exp = $row['expires_at'] ?? null;
                if ($exp === null || strtotime((string)$exp) > $now) {
                    $demoActive = true;
                }
            }
        }

        return [
            'has_paid' => $hasPaid,
            'has_demo' => $hasDemo,
            'demo_active' => $demoActive,
        ];
    }
}
