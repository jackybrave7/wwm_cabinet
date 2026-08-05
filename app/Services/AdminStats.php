<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\Access;

final class AdminStats
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string, array{paid: int, demo_active: int}>
     */
    public function accessByCourse(): array
    {
        $stmt = $this->pdo->query(
            "SELECT course_slug, access_type, COUNT(DISTINCT user_id) AS cnt
             FROM access
             WHERE access_type = 'paid'
                OR (access_type = 'demo' AND (expires_at IS NULL OR expires_at > datetime('now')))
             GROUP BY course_slug, access_type"
        );
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $map = [];

        foreach ($rows as $row) {
            $slug = (string)$row['course_slug'];
            if (!isset($map[$slug])) {
                $map[$slug] = ['paid' => 0, 'demo_active' => 0];
            }
            $cnt = (int)$row['cnt'];
            if ($row['access_type'] === 'paid') {
                $map[$slug]['paid'] = $cnt;
            } else {
                $map[$slug]['demo_active'] = $cnt;
            }
        }

        return $map;
    }

    public function totalPaidStudents(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(DISTINCT user_id) FROM access WHERE access_type = 'paid'"
        );
        return (int)($stmt ? $stmt->fetchColumn() : 0);
    }

    public function totalDemoActive(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(DISTINCT user_id) FROM access
             WHERE access_type = 'demo' AND (expires_at IS NULL OR expires_at > datetime('now'))"
        );
        return (int)($stmt ? $stmt->fetchColumn() : 0);
    }

    /**
     * @return array<string, string>
     */
    public function accessLabelForUser(int $userId): string
    {
        $rows = Access::forUser($this->pdo, $userId);
        if ($rows === []) {
            return 'No access';
        }
        $hasPaid = false;
        $hasDemo = false;
        foreach ($rows as $row) {
            $state = Access::courseState($this->pdo, $userId, (string)$row['course_slug']);
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
}
