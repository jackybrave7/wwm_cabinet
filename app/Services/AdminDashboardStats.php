<?php
declare(strict_types=1);

namespace Wwm\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class AdminDashboardStats
{
    private const PERIODS = ['7d', '30d', '90d', '365d', 'all'];
    private const GRANULARITIES = ['day', 'week', 'month'];

    public function __construct(
        private PDO $pdo,
        private AdminStats $adminStats,
    ) {
    }

    /**
     * @return array{period: string, group: string, from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public function resolveFilters(string $period, string $group): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : '30d';
        $group = in_array($group, self::GRANULARITIES, true) ? $group : 'day';

        $tz = new DateTimeZone('UTC');
        $to = new DateTimeImmutable('now', $tz);
        $from = match ($period) {
            '7d' => $to->modify('-6 days')->setTime(0, 0, 0),
            '30d' => $to->modify('-29 days')->setTime(0, 0, 0),
            '90d' => $to->modify('-89 days')->setTime(0, 0, 0),
            '365d' => $to->modify('-364 days')->setTime(0, 0, 0),
            default => $this->earliestActivity($tz),
        };

        if ($period === 'all' && $group === 'day') {
            $group = 'month';
        }
        if ($period === '90d' && $group === 'day') {
            $group = 'week';
        }
        if ($period === '365d' && $group === 'day') {
            $group = 'month';
        }

        return [
            'period' => $period,
            'group' => $group,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $courses = (new CourseCatalog())->all();

        return [
            'students_total' => $this->countStudents(),
            'paid_students_total' => $this->adminStats->totalPaidStudents(),
            'demo_active_total' => $this->adminStats->totalDemoActive(),
            'courses_total' => count($courses),
        ];
    }

    /**
     * @return array{new_students: int, demo_grants: int, paid_grants: int}
     */
    public function periodTotals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'new_students' => $this->countStudents($from, $to),
            'demo_grants' => $this->countGrants('demo', $from, $to),
            'paid_grants' => $this->countGrants('paid', $from, $to),
        ];
    }

    /**
     * @return array{new_students: int, demo_grants: int, paid_grants: int}
     */
    public function lifetimeGrantTotals(): array
    {
        $tz = new DateTimeZone('UTC');
        $from = $this->earliestActivity($tz);
        $to = new DateTimeImmutable('now', $tz);

        return $this->periodTotals($from, $to);
    }

    /**
     * @param array<string, int> $visitBuckets
     * @return array{labels: list<string>, demo: list<int>, paid: list<int>, visits: list<int>, max: int}
     */
    public function chartSeries(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $group,
        array $visitBuckets = [],
    ): array {
        $demo = $this->grantBuckets('demo', $from, $to, $group);
        $paid = $this->grantBuckets('paid', $from, $to, $group);
        $labels = [];
        $demoCounts = [];
        $paidCounts = [];
        $visitCounts = [];
        $max = 0;

        foreach ($this->bucketKeys($from, $to, $group) as $key => $label) {
            $labels[] = $label;
            $d = $demo[$key] ?? 0;
            $p = $paid[$key] ?? 0;
            $v = $visitBuckets[$key] ?? 0;
            $demoCounts[] = $d;
            $paidCounts[] = $p;
            $visitCounts[] = $v;
            $max = max($max, $d, $p, $v);
        }

        return [
            'labels' => $labels,
            'demo' => $demoCounts,
            'paid' => $paidCounts,
            'visits' => $visitCounts,
            'max' => $max,
        ];
    }

    /**
     * @return array{
     *   visit_to_demo_pct: ?float,
     *   visit_to_paid_pct: ?float,
     *   demo_to_paid_pct: ?float
     * }
     */
    public function conversionRates(int $visits, int $demos, int $paid): array
    {
        return [
            'visit_to_demo_pct' => $visits > 0 ? round(100 * $demos / $visits, 2) : null,
            'visit_to_paid_pct' => $visits > 0 ? round(100 * $paid / $visits, 2) : null,
            'demo_to_paid_pct' => $demos > 0 ? round(100 * $paid / $demos, 2) : null,
        ];
    }

    private function earliestActivity(DateTimeZone $tz): DateTimeImmutable
    {
        $stmt = $this->pdo->query(
            'SELECT MIN(dt) FROM (
                SELECT MIN(granted_at) AS dt FROM access
                UNION ALL
                SELECT MIN(created_at) AS dt FROM users
            )'
        );
        $min = $stmt ? (string)($stmt->fetchColumn() ?: '') : '';
        if ($min !== '') {
            try {
                return (new DateTimeImmutable($min, $tz))->setTime(0, 0, 0);
            } catch (\Throwable) {
            }
        }

        return (new DateTimeImmutable('now', $tz))->modify('-365 days')->setTime(0, 0, 0);
    }

    private function countStudents(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE is_admin = 0';
        $params = [];
        if ($from !== null) {
            $sql .= ' AND created_at >= ?';
            $params[] = $from->format('c');
        }
        if ($to !== null) {
            $sql .= ' AND created_at <= ?';
            $params[] = $to->format('c');
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    private function countGrants(string $type, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM access
             WHERE access_type = ? AND granted_at >= ? AND granted_at <= ?'
        );
        $stmt->execute([$type, $from->format('c'), $to->format('c')]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function grantBuckets(string $type, DateTimeImmutable $from, DateTimeImmutable $to, string $group): array
    {
        $expr = match ($group) {
            'week' => "strftime('%Y-%W', granted_at)",
            'month' => "strftime('%Y-%m', granted_at)",
            default => "strftime('%Y-%m-%d', granted_at)",
        };

        $stmt = $this->pdo->prepare(
            'SELECT ' . $expr . ' AS bucket, COUNT(*) AS cnt
             FROM access
             WHERE access_type = ? AND granted_at >= ? AND granted_at <= ?
             GROUP BY bucket'
        );
        $stmt->execute([$type, $from->format('c'), $to->format('c')]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string)$row['bucket']] = (int)$row['cnt'];
        }

        return $map;
    }

    /**
     * @return array<string, string> bucket key => axis label
     */
    private function bucketKeys(DateTimeImmutable $from, DateTimeImmutable $to, string $group): array
    {
        $keys = [];
        $cursor = $from;
        $end = $to;

        while ($cursor <= $end) {
            $key = $this->bucketKey($cursor, $group);
            if (!isset($keys[$key])) {
                $keys[$key] = $this->bucketLabel($cursor, $group);
            }
            $cursor = $this->advanceBucket($cursor, $group);
        }

        return $keys;
    }

    private function bucketKey(DateTimeImmutable $dt, string $group): string
    {
        return match ($group) {
            'week' => $dt->format('Y-W'),
            'month' => $dt->format('Y-m'),
            default => $dt->format('Y-m-d'),
        };
    }

    private function bucketLabel(DateTimeImmutable $dt, string $group): string
    {
        return match ($group) {
            'week' => 'W' . $dt->format('W'),
            'month' => $dt->format('M Y'),
            default => $dt->format('d M'),
        };
    }

    private function advanceBucket(DateTimeImmutable $dt, string $group): DateTimeImmutable
    {
        return match ($group) {
            'week' => $dt->modify('+1 week'),
            'month' => $dt->modify('first day of next month'),
            default => $dt->modify('+1 day'),
        };
    }
}
