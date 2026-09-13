<?php
declare(strict_types=1);

namespace Wwm\Services;

final class AdminStudentListFilter
{
    public const ACCESS_OPTIONS = [
        '' => 'Any access',
        'paid' => 'Paid (active)',
        'demo' => 'Demo (active)',
        'expired' => 'Demo expired',
        'none' => 'No access',
    ];

    public const ACTIVITY_OPTIONS = [
        '' => 'Any activity',
        'active_7d' => 'Active in last 7 days',
        'active_30d' => 'Active in last 30 days',
        'inactive_30d' => 'No activity 30+ days',
        'never' => 'Never opened a lesson',
    ];

    public const SORT_COLUMNS = [
        'registered',
        'location',
        'access',
        'progress',
        'activity',
    ];

    public function __construct(
        public readonly string $search = '',
        public readonly string $access = '',
        public readonly string $courseSlug = '',
        public readonly string $courseAccess = '',
        public readonly string $registeredFrom = '',
        public readonly string $registeredTo = '',
        public readonly string $activity = '',
        public readonly string $country = '',
        public readonly string $utmSource = '',
        public readonly string $utmMedium = '',
        public readonly string $utmCampaign = '',
        public readonly string $hasUtm = '',
        public readonly string $sort = 'registered',
        public readonly string $dir = 'desc',
    ) {
    }

    public static function fromRequest(): self
    {
        return new self(
            search: trim((string)($_GET['q'] ?? '')),
            access: self::normalizeKey((string)($_GET['access'] ?? ''), array_keys(self::ACCESS_OPTIONS)),
            courseSlug: trim((string)($_GET['course'] ?? '')),
            courseAccess: self::normalizeKey((string)($_GET['course_access'] ?? ''), ['', 'any', 'paid', 'demo']),
            registeredFrom: self::normalizeDate((string)($_GET['registered_from'] ?? '')),
            registeredTo: self::normalizeDate((string)($_GET['registered_to'] ?? '')),
            activity: self::normalizeKey((string)($_GET['activity'] ?? ''), array_keys(self::ACTIVITY_OPTIONS)),
            country: trim((string)($_GET['country'] ?? '')),
            utmSource: trim((string)($_GET['utm_source'] ?? '')),
            utmMedium: trim((string)($_GET['utm_medium'] ?? '')),
            utmCampaign: trim((string)($_GET['utm_campaign'] ?? '')),
            hasUtm: (string)($_GET['has_utm'] ?? '') === '1' ? '1' : '',
            sort: self::normalizeKey((string)($_GET['sort'] ?? 'registered'), self::SORT_COLUMNS) ?: 'registered',
            dir: self::normalizeKey((string)($_GET['dir'] ?? 'desc'), ['asc', 'desc']) ?: 'desc',
        );
    }

    public function sortDirForLink(string $column): string
    {
        if (!in_array($column, self::SORT_COLUMNS, true)) {
            return 'desc';
        }
        if ($this->sort === $column) {
            return $this->dir === 'asc' ? 'desc' : 'asc';
        }

        return match ($column) {
            'location' => 'asc',
            default => 'desc',
        };
    }

    public function isSortedBy(string $column): bool
    {
        return $this->sort === $column;
    }

    public function isActive(): bool
    {
        return $this->access !== ''
            || $this->courseSlug !== ''
            || $this->registeredFrom !== ''
            || $this->registeredTo !== ''
            || $this->activity !== ''
            || $this->country !== ''
            || $this->utmSource !== ''
            || $this->utmMedium !== ''
            || $this->utmCampaign !== ''
            || $this->hasUtm === '1';
    }

    /**
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        $params = [];
        if ($this->search !== '') {
            $params['q'] = $this->search;
        }
        if ($this->access !== '') {
            $params['access'] = $this->access;
        }
        if ($this->courseSlug !== '') {
            $params['course'] = $this->courseSlug;
        }
        if ($this->courseAccess !== '' && $this->courseAccess !== 'any') {
            $params['course_access'] = $this->courseAccess;
        }
        if ($this->registeredFrom !== '') {
            $params['registered_from'] = $this->registeredFrom;
        }
        if ($this->registeredTo !== '') {
            $params['registered_to'] = $this->registeredTo;
        }
        if ($this->activity !== '') {
            $params['activity'] = $this->activity;
        }
        if ($this->country !== '') {
            $params['country'] = $this->country;
        }
        if ($this->utmSource !== '') {
            $params['utm_source'] = $this->utmSource;
        }
        if ($this->utmMedium !== '') {
            $params['utm_medium'] = $this->utmMedium;
        }
        if ($this->utmCampaign !== '') {
            $params['utm_campaign'] = $this->utmCampaign;
        }
        if ($this->hasUtm === '1') {
            $params['has_utm'] = '1';
        }
        if ($this->sort !== 'registered') {
            $params['sort'] = $this->sort;
        }
        if ($this->dir !== 'desc' || $this->sort !== 'registered') {
            $params['dir'] = $this->dir;
        }

        return $params;
    }

    /**
     * @return array{where: string, params: list<mixed>}
     */
    public function sqlWhere(): array
    {
        $parts = [];
        $params = [];

        if ($this->search !== '') {
            $parts[] = '(u.email LIKE ? OR u.name LIKE ?)';
            $q = '%' . $this->search . '%';
            $params[] = $q;
            $params[] = $q;
        }

        if ($this->access === 'paid') {
            $parts[] = self::sqlActivePaidExists();
        } elseif ($this->access === 'demo') {
            $parts[] = self::sqlActiveDemoExists() . ' AND NOT ' . self::sqlActivePaidExists();
        } elseif ($this->access === 'expired') {
            $parts[] = 'EXISTS (SELECT 1 FROM access a WHERE a.user_id = u.id AND a.access_type = \'demo\')'
                . ' AND NOT ' . self::sqlActivePaidExists()
                . ' AND NOT ' . self::sqlActiveDemoExists();
        } elseif ($this->access === 'none') {
            $parts[] = 'NOT EXISTS (SELECT 1 FROM access a WHERE a.user_id = u.id)';
        }

        if ($this->courseSlug !== '') {
            $type = $this->courseAccess;
            if ($type === 'paid') {
                $parts[] = 'EXISTS (SELECT 1 FROM access a WHERE a.user_id = u.id AND a.course_slug = ? AND a.access_type = \'paid\''
                    . ' AND (a.expires_at IS NULL OR a.expires_at > datetime(\'now\')))';
                $params[] = $this->courseSlug;
            } elseif ($type === 'demo') {
                $parts[] = 'EXISTS (SELECT 1 FROM access a WHERE a.user_id = u.id AND a.course_slug = ? AND a.access_type = \'demo\''
                    . ' AND (a.expires_at IS NULL OR a.expires_at > datetime(\'now\')))';
                $params[] = $this->courseSlug;
            } else {
                $parts[] = 'EXISTS (SELECT 1 FROM access a WHERE a.user_id = u.id AND a.course_slug = ?)';
                $params[] = $this->courseSlug;
            }
        }

        if ($this->registeredFrom !== '') {
            $parts[] = 'u.created_at >= ?';
            $params[] = $this->registeredFrom . 'T00:00:00+00:00';
        }
        if ($this->registeredTo !== '') {
            $parts[] = 'u.created_at <= ?';
            $params[] = $this->registeredTo . 'T23:59:59+00:00';
        }

        if ($this->activity === 'never') {
            $parts[] = 'NOT EXISTS (SELECT 1 FROM lesson_opens lo WHERE lo.user_id = u.id)';
        } elseif ($this->activity === 'active_7d') {
            $parts[] = '(SELECT MAX(lo.last_opened_at) FROM lesson_opens lo WHERE lo.user_id = u.id) >= datetime(\'now\', \'-7 days\')';
        } elseif ($this->activity === 'active_30d') {
            $parts[] = '(SELECT MAX(lo.last_opened_at) FROM lesson_opens lo WHERE lo.user_id = u.id) >= datetime(\'now\', \'-30 days\')';
        } elseif ($this->activity === 'inactive_30d') {
            $parts[] = 'EXISTS (SELECT 1 FROM lesson_opens lo WHERE lo.user_id = u.id)'
                . ' AND (SELECT MAX(lo.last_opened_at) FROM lesson_opens lo WHERE lo.user_id = u.id) < datetime(\'now\', \'-30 days\')';
        }

        if ($this->country !== '') {
            $parts[] = '(LOWER(COALESCE(u.signup_country, \'\')) LIKE ? OR LOWER(COALESCE(u.last_country, \'\')) LIKE ?)';
            $c = '%' . mb_strtolower($this->country) . '%';
            $params[] = $c;
            $params[] = $c;
        }

        if ($this->hasUtm === '1') {
            $parts[] = '('
                . 'COALESCE(u.utm_source, \'\') <> \'\' OR COALESCE(u.utm_medium, \'\') <> \'\''
                . ' OR COALESCE(u.utm_campaign, \'\') <> \'\' OR COALESCE(u.utm_term, \'\') <> \'\''
                . ' OR COALESCE(u.utm_content, \'\') <> \'\''
                . ')';
        }

        if ($this->utmSource !== '') {
            $parts[] = 'u.utm_source LIKE ?';
            $params[] = '%' . $this->utmSource . '%';
        }
        if ($this->utmMedium !== '') {
            $parts[] = 'u.utm_medium LIKE ?';
            $params[] = '%' . $this->utmMedium . '%';
        }
        if ($this->utmCampaign !== '') {
            $parts[] = 'u.utm_campaign LIKE ?';
            $params[] = '%' . $this->utmCampaign . '%';
        }

        $where = $parts === [] ? '1=1' : implode(' AND ', $parts);

        return ['where' => $where, 'params' => $params];
    }

    public function sqlOrderBy(): string
    {
        $dir = $this->dir === 'asc' ? 'ASC' : 'DESC';
        $tie = $this->dir === 'asc' ? 'ASC' : 'DESC';

        return match ($this->sort) {
            'location' => 'ORDER BY LOWER(' . self::sqlLocationSortKey() . ') ' . $dir . ', u.id ' . $tie,
            'access' => 'ORDER BY ' . self::sqlAccessRank() . ' ' . $dir . ', u.created_at DESC, u.id DESC',
            'progress' => 'ORDER BY ' . self::sqlProgressCount() . ' ' . $dir . ', u.id ' . $tie,
            'activity' => 'ORDER BY ' . self::sqlLastActivity() . ' ' . $dir . ', u.id ' . $tie,
            default => 'ORDER BY u.created_at ' . $dir . ', u.id ' . $tie,
        };
    }

    private static function sqlLocationSortKey(): string
    {
        return "COALESCE(NULLIF(TRIM(u.signup_country), ''), NULLIF(TRIM(u.last_country), ''), "
            . "NULLIF(TRIM(u.signup_city), ''), NULLIF(TRIM(u.last_city), ''), '')";
    }

    private static function sqlAccessRank(): string
    {
        $paid = self::sqlActivePaidExists();
        $demo = self::sqlActiveDemoExists();

        return 'CASE WHEN ' . $paid . ' THEN 3 WHEN ' . $demo . ' THEN 2'
            . ' WHEN EXISTS (SELECT 1 FROM access a WHERE a.user_id = u.id AND a.access_type = \'demo\') THEN 1'
            . ' ELSE 0 END';
    }

    private static function sqlProgressCount(): string
    {
        return '(SELECT COUNT(*) FROM lesson_opens lo WHERE lo.user_id = u.id)';
    }

    private static function sqlLastActivity(): string
    {
        return '(SELECT MAX(lo.last_opened_at) FROM lesson_opens lo WHERE lo.user_id = u.id)';
    }

    private static function sqlActivePaidExists(): string
    {
        return 'EXISTS (SELECT 1 FROM access a WHERE a.user_id = u.id AND a.access_type = \'paid\''
            . ' AND (a.expires_at IS NULL OR a.expires_at > datetime(\'now\')))';
    }

    private static function sqlActiveDemoExists(): string
    {
        return 'EXISTS (SELECT 1 FROM access a WHERE a.user_id = u.id AND a.access_type = \'demo\''
            . ' AND (a.expires_at IS NULL OR a.expires_at > datetime(\'now\')))';
    }

    /**
     * @param list<string> $allowed
     */
    private static function normalizeKey(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    private static function normalizeDate(string $value): string
    {
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }
}
