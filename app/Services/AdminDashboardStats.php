<?php
declare(strict_types=1);

namespace Wwm\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Wwm\Models\Payment;
use Wwm\Models\User;

final class AdminDashboardStats
{
    private const PERIODS = ['yesterday', '7d', '30d', '90d', '365d', 'all', 'custom'];
    private const GRANULARITIES = ['day', 'week', 'month'];
    private const REPORT_TZ = 'Europe/Moscow';

    public function __construct(
        private PDO $pdo,
        private AdminStats $adminStats,
    ) {
    }

    /**
     * @return array{period: string, group: string, from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public function resolveFilters(
        string $period,
        string $group,
        ?string $customFrom = null,
        ?string $customTo = null,
    ): array {
        $period = in_array($period, self::PERIODS, true) ? $period : '7d';
        $group = in_array($group, self::GRANULARITIES, true) ? $group : 'day';

        $tz = new DateTimeZone(self::REPORT_TZ);
        if ($period === 'yesterday') {
            $from = (new DateTimeImmutable('yesterday', $tz))->setTime(0, 0, 0);
            $to = $from->setTime(23, 59, 59);
            $group = 'day';
        } elseif ($period === 'custom') {
            $from = self::parseReportDate($customFrom, $tz, true);
            $to = self::parseReportDate($customTo, $tz, false);
            if ($from === null || $to === null || $from > $to) {
                return $this->resolveFilters('7d', $group);
            }
            $today = new DateTimeImmutable('today', $tz);
            if ($to->format('Y-m-d') === $today->format('Y-m-d')) {
                $to = new DateTimeImmutable('now', $tz);
            } else {
                $to = $to->setTime(23, 59, 59);
            }
            $spanDays = (int)$from->diff($to)->days;
            if ($spanDays > 365 && $group === 'day') {
                $group = 'month';
            } elseif ($spanDays > 90 && $group === 'day') {
                $group = 'week';
            }
        } else {
            $to = new DateTimeImmutable('now', $tz);
            $from = match ($period) {
                '7d' => $to->modify('-6 days')->setTime(0, 0, 0),
                '30d' => $to->modify('-29 days')->setTime(0, 0, 0),
                '90d' => $to->modify('-89 days')->setTime(0, 0, 0),
                '365d' => $to->modify('-364 days')->setTime(0, 0, 0),
                default => $this->earliestActivity($tz),
            };
        }

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

    private static function parseReportDate(?string $raw, DateTimeZone $tz, bool $startOfDay): ?DateTimeImmutable
    {
        $raw = trim((string)$raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($raw, $tz);
        } catch (\Throwable) {
            return null;
        }

        return $startOfDay ? $dt->setTime(0, 0, 0) : $dt->setTime(0, 0, 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $coursesDir = WWM_ROOT . '/data/courses';
        $courseFiles = is_dir($coursesDir) ? (glob($coursesDir . '/*.json') ?: []) : [];

        $tz = new DateTimeZone(self::REPORT_TZ);
        $from = $this->earliestActivity($tz);
        $to = new DateTimeImmutable('now', $tz);

        return [
            'students_total' => $this->countStudents(),
            'paid_students_total' => $this->adminStats->totalPaidStudents(),
            'demo_active_total' => $this->adminStats->totalDemoActive(),
            'courses_total' => count($courseFiles),
            'revenue' => $this->revenueTotals($from, $to),
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
            'revenue' => $this->revenueTotals($from, $to),
        ];
    }

    /**
     * @return array{
     *   payment_count: int,
     *   rub_total: float,
     *   tilda_total: float,
     *   tilda_currency: string,
     *   tilda_estimated_count: int,
     *   has_mixed_tilda_currencies: bool
     * }
     */
    public function revenueTotals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $paidAt = $this->paymentEventAtSql();
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payments
             WHERE (' . $paidAt . ') >= ? AND (' . $paidAt . ') <= ?
             ORDER BY (' . $paidAt . ') ASC'
        );
        $stmt->execute([$from->format('c'), $to->format('c')]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return Payment::aggregateRevenue($rows);
    }

    private function paymentEventAtSql(): string
    {
        return "COALESCE(NULLIF(paid_at, ''), NULLIF(ordered_at, ''), created_at)";
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
        $accessAt = $this->accessEventAtSql();
        $registeredAt = User::sqlRegisteredAtExpression();
        $stmt = $this->pdo->query(
            'SELECT MIN(dt) FROM (
                SELECT MIN(' . $accessAt . ') AS dt FROM access
                UNION ALL
                SELECT MIN(' . $registeredAt . ') AS dt FROM users u WHERE u.is_admin = 0
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
        $registeredAt = User::sqlRegisteredAtExpression();
        $sql = 'SELECT COUNT(*) FROM users u WHERE u.is_admin = 0';
        $params = [];
        if ($from !== null) {
            $sql .= ' AND (' . $registeredAt . ') >= ?';
            $params[] = $from->format('c');
        }
        if ($to !== null) {
            $sql .= ' AND (' . $registeredAt . ') <= ?';
            $params[] = $to->format('c');
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    private function countGrants(string $type, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $eventAt = $this->accessEventAtSql($type);
        if ($type === 'paid') {
            $dedup = $this->purchaseDedupKeySql();
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(DISTINCT ' . $dedup . ') FROM access
                 WHERE access_type = ? AND (' . $eventAt . ') >= ? AND (' . $eventAt . ') <= ?'
                 . $this->paidCabinetSourceSql()
            );
            $stmt->execute([$type, $from->format('c'), $to->format('c')]);

            return (int)$stmt->fetchColumn();
        }

        return $this->countDemoSignups($from, $to);
    }

    private function countDemoSignups(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $registeredAt = User::sqlRegisteredAtExpression();
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT u.id) FROM users u
             INNER JOIN access a ON a.user_id = u.id AND a.access_type = \'demo\''
            . $this->demoCabinetSourceSql('a') . '
             WHERE u.is_admin = 0
               AND (' . $registeredAt . ') >= ? AND (' . $registeredAt . ') <= ?'
        );
        $stmt->execute([$from->format('c'), $to->format('c')]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function grantBuckets(string $type, DateTimeImmutable $from, DateTimeImmutable $to, string $group): array
    {
        if ($type === 'demo') {
            return $this->demoSignupBuckets($from, $to, $group);
        }

        $eventAt = $this->accessEventAtSql($type);
        $bucketAt = $this->accessEventAtInReportTz($type);
        $expr = match ($group) {
            'week' => "strftime('%Y-%W', " . $bucketAt . ')',
            'month' => "strftime('%Y-%m', " . $bucketAt . ')',
            default => "strftime('%Y-%m-%d', " . $bucketAt . ')',
        };

        $countExpr = $type === 'paid'
            ? 'COUNT(DISTINCT ' . $this->purchaseDedupKeySql() . ')'
            : 'COUNT(*)';

        $sourceSql = $type === 'paid' ? $this->paidCabinetSourceSql() : '';
        $stmt = $this->pdo->prepare(
            'SELECT ' . $expr . ' AS bucket, ' . $countExpr . ' AS cnt
             FROM access
             WHERE access_type = ? AND (' . $eventAt . ') >= ? AND (' . $eventAt . ') <= ?'
             . $sourceSql . '
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
     * @return array<string, int>
     */
    private function demoSignupBuckets(DateTimeImmutable $from, DateTimeImmutable $to, string $group): array
    {
        $registeredAt = User::sqlRegisteredAtExpression();
        $bucketAt = 'datetime(' . $registeredAt . ", '+3 hours')";
        $expr = match ($group) {
            'week' => "strftime('%Y-%W', " . $bucketAt . ')',
            'month' => "strftime('%Y-%m', " . $bucketAt . ')',
            default => "strftime('%Y-%m-%d', " . $bucketAt . ')',
        };

        $stmt = $this->pdo->prepare(
            'SELECT ' . $expr . ' AS bucket, COUNT(DISTINCT u.id) AS cnt
             FROM users u
             INNER JOIN access a ON a.user_id = u.id AND a.access_type = \'demo\''
            . $this->demoCabinetSourceSql('a') . '
             WHERE u.is_admin = 0
               AND (' . $registeredAt . ') >= ? AND (' . $registeredAt . ') <= ?
             GROUP BY bucket'
        );
        $stmt->execute([$from->format('c'), $to->format('c')]);

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
        $dt = $dt->setTimezone(new DateTimeZone(self::REPORT_TZ));

        return match ($group) {
            'week' => $dt->format('Y-W'),
            'month' => $dt->format('Y-m'),
            default => $dt->format('Y-m-d'),
        };
    }

    private function bucketLabel(DateTimeImmutable $dt, string $group): string
    {
        $dt = $dt->setTimezone(new DateTimeZone(self::REPORT_TZ));

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

    /**
     * Analytics date: AVO order/paid time for imports, granted_at for live webhooks.
     */
    private function accessEventAtSql(?string $accessType = null): string
    {
        if ($accessType === 'paid') {
            return "COALESCE(NULLIF(avo_paid_at, ''), NULLIF(avo_ordered_at, ''), granted_at)";
        }
        if ($accessType === 'demo') {
            return "COALESCE(NULLIF(avo_ordered_at, ''), granted_at)";
        }

        return "CASE access_type
            WHEN 'paid' THEN COALESCE(NULLIF(avo_paid_at, ''), NULLIF(avo_ordered_at, ''), granted_at)
            ELSE COALESCE(NULLIF(avo_ordered_at, ''), granted_at)
        END";
    }

    private function accessEventAtInReportTz(?string $accessType = null): string
    {
        return "datetime(" . $this->accessEventAtSql($accessType) . ", '+3 hours')";
    }

    /**
     * One AVO order (source_ref) with several courses must count as one purchase.
     */
    private function purchaseDedupKeySql(): string
    {
        return "CASE
            WHEN source_ref IS NOT NULL AND TRIM(source_ref) != '' AND TRIM(source_ref) != 'manual'
                THEN 'order:' || TRIM(source_ref)
            ELSE 'grant:' || user_id || ':' || course_slug
        END";
    }

    /** Live cabinet / webhook sales only — exclude legacy bulk import rows. */
    private function paidCabinetSourceSql(): string
    {
        return $this->demoCabinetSourceSql();
    }

    private function demoCabinetSourceSql(string $alias = ''): string
    {
        $col = $alias !== '' ? $alias . '.source' : 'source';

        return " AND COALESCE({$col}, '') NOT IN ('avo-import', 'csv-import')";
    }
}
