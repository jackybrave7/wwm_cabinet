<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Auth\Password;
use Wwm\Models\Access;
use Wwm\Models\User;

/**
 * Import students from AVO bl-school CSV export (account lines, semicolon-separated).
 */
final class CsvLegacyImport
{
    private const SOURCE = 'csv-import';

    /**
     * @param array{
     *   csv_path: string,
     *   before_ts: int,
     *   dry_run: bool,
     * } $options
     * @return array<string, int|bool|string>
     */
    public function run(PDO $pdo, array $options): array
    {
        $path = (string)($options['csv_path'] ?? '');
        $beforeTs = (int)($options['before_ts'] ?? 0);
        $dryRun = !empty($options['dry_run']);

        $stats = [
            'dry_run' => $dryRun,
            'before_utc' => $beforeTs > 0 ? gmdate('c', $beforeTs) : '',
            'rows_read' => 0,
            'rows_in_window' => 0,
            'rows_skipped_date' => 0,
            'rows_skipped_email' => 0,
            'rows_skipped_product' => 0,
            'rows_skipped_status' => 0,
            'students_touched' => 0,
            'users_created' => 0,
            'users_updated' => 0,
            'paid_grants' => 0,
            'demo_grants' => 0,
            'access_unchanged' => 0,
            'skipped_admin' => 0,
        ];

        if ($path === '' || !is_readable($path)) {
            throw new \InvalidArgumentException('CSV file not readable: ' . $path);
        }
        if ($beforeTs <= 0) {
            throw new \InvalidArgumentException('before_ts required');
        }

        $resolver = new AvoGoodsCourseResolver();
        $catalog = new CourseCatalog();

        /** @var array<string, array{name: string, first_order_ts: int, courses: array<string, array{type: string, account_id: int, order_ts: int, paid_ts: int}>}> */
        $byEmail = [];

        foreach (BlSchoolAccountsCsv::rows($path) as $row) {
            $stats['rows_read']++;

            $orderTs = $row['order_ts'];
            if ($orderTs <= 0 || $orderTs >= $beforeTs) {
                $stats['rows_skipped_date']++;
                continue;
            }

            $email = $row['email'];
            if ($email === '') {
                $stats['rows_skipped_email']++;
                continue;
            }

            $slug = $resolver->slugForProductName($row['product']);
            if ($slug === null || $slug === '') {
                $stats['rows_skipped_product']++;
                continue;
            }

            $accessType = BlSchoolAccountsCsv::accessType(
                $row['status'],
                $row['sum'],
                $row['product'],
                $row['category']
            );
            if ($accessType === null) {
                $stats['rows_skipped_status']++;
                continue;
            }

            $stats['rows_in_window']++;

            $paidTs = (int)($row['paid_ts'] ?? 0);

            if (!isset($byEmail[$email])) {
                $byEmail[$email] = [
                    'name' => $row['name'],
                    'first_order_ts' => $orderTs,
                    'courses' => [],
                ];
            } elseif ($row['name'] !== '') {
                $byEmail[$email]['name'] = $row['name'];
            }
            if ($orderTs > 0) {
                $first = (int)$byEmail[$email]['first_order_ts'];
                if ($first <= 0 || $orderTs < $first) {
                    $byEmail[$email]['first_order_ts'] = $orderTs;
                }
            }

            $existing = $byEmail[$email]['courses'][$slug] ?? null;
            if ($existing === null) {
                $byEmail[$email]['courses'][$slug] = [
                    'type' => $accessType,
                    'account_id' => $row['account_id'],
                    'order_ts' => $orderTs,
                    'paid_ts' => $accessType === 'paid' ? ($paidTs > 0 ? $paidTs : $orderTs) : 0,
                ];
                continue;
            }
            if ($existing['type'] === 'paid') {
                continue;
            }
            if ($accessType === 'paid') {
                $byEmail[$email]['courses'][$slug] = [
                    'type' => 'paid',
                    'account_id' => $row['account_id'],
                    'order_ts' => $orderTs,
                    'paid_ts' => $paidTs > 0 ? $paidTs : $orderTs,
                ];
                continue;
            }
            if ($orderTs >= (int)$existing['order_ts']) {
                $byEmail[$email]['courses'][$slug] = [
                    'type' => 'demo',
                    'account_id' => $row['account_id'],
                    'order_ts' => $orderTs,
                    'paid_ts' => 0,
                ];
            }
        }

        $stats['students_touched'] = count($byEmail);

        foreach ($byEmail as $email => $bundle) {
            $user = User::findByEmail($pdo, $email);
            if ($user !== null && self::isStaff($user)) {
                $stats['skipped_admin']++;
                continue;
            }

            if ($dryRun) {
                if ($user === null) {
                    $stats['users_created']++;
                } else {
                    $stats['users_updated']++;
                }
                foreach ($bundle['courses'] as $grant) {
                    if ($grant['type'] === 'paid') {
                        $stats['paid_grants']++;
                    } else {
                        $stats['demo_grants']++;
                    }
                }
                continue;
            }

            $created = false;
            if ($user === null) {
                $userId = User::create(
                    $pdo,
                    $email,
                    Password::generateReadable(12),
                    (string)$bundle['name'],
                    User::REGISTRATION_CSV_IMPORT
                );
                $user = User::findById($pdo, $userId);
                $created = true;
                $stats['users_created']++;
            } else {
                $stats['users_updated']++;
                if ((string)$bundle['name'] !== '') {
                    User::updateName($pdo, (int)$user['id'], (string)$bundle['name']);
                }
            }

            if ($user === null) {
                continue;
            }

            $userId = (int)$user['id'];
            User::ensureAvoBulkImportSource($pdo, $userId, User::REGISTRATION_CSV_IMPORT);
            StudentAttribution::recordForUser($pdo, $userId, $created, [], false);

            $firstOrderTs = (int)($bundle['first_order_ts'] ?? 0);
            if ($firstOrderTs > 0) {
                User::mergeAvoFirstOrderAt($pdo, $userId, gmdate('c', $firstOrderTs));
            }

            foreach ($bundle['courses'] as $courseSlug => $grant) {
                if (self::userHasPaidGrant($pdo, $userId, $courseSlug) && $grant['type'] === 'demo') {
                    $stats['access_unchanged']++;
                    continue;
                }

                if ($grant['type'] === 'paid') {
                    Access::grant(
                        $pdo,
                        $userId,
                        $courseSlug,
                        'paid',
                        null,
                        self::SOURCE,
                        $grant['account_id'] > 0 ? (string)$grant['account_id'] : null,
                        self::avoOrderedIso($grant),
                        self::avoPaidIso($grant)
                    );
                    $stats['paid_grants']++;
                    continue;
                }

                $course = $catalog->getAdmin($courseSlug);
                $demoHours = (int)($course['demo_hours'] ?? wwm_config()['demo_hours'] ?? 48);
                if ($demoHours < 1) {
                    $demoHours = 48;
                }
                $expiresAt = gmdate('c', (int)$grant['order_ts'] + $demoHours * 3600);

                Access::grant(
                    $pdo,
                    $userId,
                    $courseSlug,
                    'demo',
                    $expiresAt,
                    self::SOURCE,
                    $grant['account_id'] > 0 ? (string)$grant['account_id'] : null,
                    self::avoOrderedIso($grant),
                    null
                );
                $stats['demo_grants']++;
            }
        }

        return $stats;
    }

    /**
     * @param array{order_ts: int, paid_ts?: int} $grant
     */
    private static function avoOrderedIso(array $grant): ?string
    {
        $ts = (int)($grant['order_ts'] ?? 0);

        return $ts > 0 ? gmdate('c', $ts) : null;
    }

    /**
     * @param array{order_ts: int, paid_ts?: int, type?: string} $grant
     */
    private static function avoPaidIso(array $grant): ?string
    {
        $paidTs = (int)($grant['paid_ts'] ?? 0);
        if ($paidTs <= 0) {
            $paidTs = (int)($grant['order_ts'] ?? 0);
        }

        return $paidTs > 0 ? gmdate('c', $paidTs) : null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function isStaff(array $user): bool
    {
        return !empty($user['is_admin'])
            || !empty($user['admin_super'])
            || !empty($user['admin_students'])
            || !empty($user['admin_courses']);
    }

    private static function userHasPaidGrant(PDO $pdo, int $userId, string $courseSlug): bool
    {
        return Access::findGrant($pdo, $userId, $courseSlug, 'paid') !== null;
    }
}

/**
 * Parser for bl-school AVO CSV (account line export).
 */
final class BlSchoolAccountsCsv
{
    /**
     * @return \Generator<int, array{
     *   email: string,
     *   name: string,
     *   product: string,
     *   category: string,
     *   status: string,
     *   sum: float,
     *   order_ts: int,
     *   paid_ts: int,
     *   account_id: int
     * }>
     */
    public static function rows(string $path): \Generator
    {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open CSV');
        }

        $first = (string)fgets($fp);
        if (str_starts_with($first, "\xEF\xBB\xBF")) {
            $first = substr($first, 3);
        }

        $header = str_getcsv(rtrim($first, "\r\n"), ';');
        $index = self::headerIndex($header);

        while (($line = fgets($fp)) !== false) {
            $cols = str_getcsv(rtrim($line, "\r\n"), ';');
            if ($cols === [null] || $cols === []) {
                continue;
            }

            $email = self::col($cols, $index, 'E-mail');
            $email = strtolower(trim($email));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = '';
            }

            $surname = self::col($cols, $index, 'Фамилия');
            $firstName = self::col($cols, $index, 'Имя');
            $middle = self::col($cols, $index, 'Отчество');
            $name = trim(implode(' ', array_filter([$surname, $firstName, $middle], static fn(string $p): bool => $p !== '')));

            $product = self::col($cols, $index, 'Товар');
            $category = self::col($cols, $index, 'Категория товара');
            $status = self::col($cols, $index, 'Статус');
            $sum = self::parseMoney(self::col($cols, $index, 'Сумма'));
            $dateStr = self::col($cols, $index, 'Дата заказа');
            $orderTs = self::parseDate($dateStr);
            $paidDateStr = self::col($cols, $index, 'Дата оплаты');
            $paidTs = self::parseDate($paidDateStr);
            $accountId = (int)preg_replace('/\D/', '', self::col($cols, $index, 'ID (код счета)'));

            yield [
                'email' => $email,
                'name' => $name,
                'product' => $product,
                'category' => $category,
                'status' => $status,
                'sum' => $sum,
                'order_ts' => $orderTs,
                'paid_ts' => $paidTs,
                'account_id' => $accountId,
            ];
        }

        fclose($fp);
    }

    public static function accessType(string $status, float $sum, string $product, string $category): ?string
    {
        if (self::isDemoProduct($product, $category)) {
            return 'demo';
        }

        $statusNorm = mb_strtolower(trim($status));
        if ($statusNorm === 'создан') {
            return null;
        }

        if ($statusNorm !== 'оплачен') {
            return null;
        }

        if ($sum > 0.01) {
            return 'paid';
        }

        return 'demo';
    }

    public static function isDemoProduct(string $product, string $category): bool
    {
        $p = mb_strtolower(trim($product));
        $c = mb_strtolower(trim($category));

        if (str_contains($c, 'demos of world watercolor')) {
            return true;
        }
        if (str_starts_with($p, 'demo of')) {
            return true;
        }
        if (str_contains($p, 'test watercolor')) {
            return true;
        }
        if (str_contains($p, "video course 'test ")) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string|null> $header
     * @return array<string, int>
     */
    private static function headerIndex(array $header): array
    {
        $index = [];
        foreach ($header as $i => $label) {
            $label = trim((string)$label, " \t\n\r\0\x0B\"");
            if ($label !== '' && !isset($index[$label])) {
                $index[$label] = (int)$i;
            }
        }

        return $index;
    }

    /**
     * @param list<string|null> $cols
     * @param array<string, int> $index
     */
    private static function col(array $cols, array $index, string $name): string
    {
        if (!isset($index[$name])) {
            return '';
        }

        $i = $index[$name];

        return trim((string)($cols[$i] ?? ''));
    }

    private static function parseMoney(string $value): float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));

        return is_numeric($value) ? (float)$value : 0.0;
    }

    private static function parseDate(string $value): int
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return 0;
        }

        $ts = strtotime($value);

        return $ts !== false ? $ts : 0;
    }
}
