<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class User
{
    public const REGISTRATION_CSV_IMPORT = 'csv-import';
    public const REGISTRATION_AVO_IMPORT = 'avo-import';

    public static function findByEmail(PDO $pdo, string $email): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? COLLATE NOCASE LIMIT 1');
        $stmt->execute([trim($email)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(
        PDO $pdo,
        string $email,
        string $password,
        string $name = '',
        string $registrationSource = ''
    ): int {
        $registrationSource = self::normalizeRegistrationSource($registrationSource);
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, created_at, registration_source) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            strtolower(trim($email)),
            \Wwm\Auth\Password::hash($password),
            trim($name),
            gmdate('c'),
            $registrationSource,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Mark user as legacy AVO import when access came only from bulk import (not cabinet / webhooks).
     */
    public static function ensureAvoBulkImportSource(PDO $pdo, int $userId, string $source): void
    {
        $source = self::normalizeRegistrationSource($source);
        if (!in_array($source, [self::REGISTRATION_CSV_IMPORT, self::REGISTRATION_AVO_IMPORT], true)) {
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE users SET registration_source = ?
             WHERE id = ?
               AND (
                 registration_source IN (?, ?)
                 OR (
                   registration_source = \'\'
                   AND NOT EXISTS (
                     SELECT 1 FROM access a
                     WHERE a.user_id = users.id
                       AND COALESCE(a.source, \'\') NOT IN (\'csv-import\', \'avo-import\', \'\')
                   )
                 )
               )'
        );
        $stmt->execute([
            $source,
            $userId,
            self::REGISTRATION_CSV_IMPORT,
            self::REGISTRATION_AVO_IMPORT,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function isAvoBulkImport(array $user): bool
    {
        $source = (string)($user['registration_source'] ?? '');

        return in_array($source, [self::REGISTRATION_CSV_IMPORT, self::REGISTRATION_AVO_IMPORT], true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function registeredAtForDisplay(array $user): string
    {
        if (!self::isAvoBulkImport($user)) {
            return trim((string)($user['created_at'] ?? ''));
        }

        $avo = trim((string)($user['avo_contact_registered_at'] ?? ''));
        if ($avo !== '') {
            return $avo;
        }
        $firstOrder = trim((string)($user['avo_first_order_at'] ?? ''));
        if ($firstOrder !== '') {
            return $firstOrder;
        }

        return trim((string)($user['created_at'] ?? ''));
    }

    public static function sqlRegisteredAtExpression(): string
    {
        return 'CASE WHEN u.registration_source IN (\'' . self::REGISTRATION_CSV_IMPORT . '\', \''
            . self::REGISTRATION_AVO_IMPORT . '\') THEN '
            . 'COALESCE(NULLIF(u.avo_contact_registered_at, \'\'), NULLIF(u.avo_first_order_at, \'\'), u.created_at) '
            . 'ELSE u.created_at END';
    }

    private static function normalizeRegistrationSource(string $source): string
    {
        $source = trim($source);

        return in_array($source, [self::REGISTRATION_CSV_IMPORT, self::REGISTRATION_AVO_IMPORT], true)
            ? $source
            : '';
    }

    public static function updatePassword(PDO $pdo, int $userId, string $password): void
    {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([\Wwm\Auth\Password::hash($password), $userId]);
    }

    public static function updateName(PDO $pdo, int $userId, string $name): void
    {
        $stmt = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
        $stmt->execute([trim($name), $userId]);
    }

    public static function touchLogin(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
        $stmt->execute([gmdate('c'), $userId]);
    }

    /**
     * @param array{avo_contact_id?: int, avo_logged_in_tagged?: int, avo_demo_opened_tagged?: int} $flags
     */
    public static function setAvoFlags(PDO $pdo, int $userId, array $flags): void
    {
        $allowed = ['avo_contact_id', 'avo_logged_in_tagged', 'avo_demo_opened_tagged'];
        $sets = [];
        $params = [];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $flags)) {
                continue;
            }
            $value = (int)$flags[$column];
            if ($column === 'avo_contact_id' && $value <= 0) {
                continue;
            }
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }

        if ($sets === []) {
            return;
        }

        $params[] = $userId;
        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    /**
     * @param array<string, string> $snapshot
     */
    public static function setAvoAdSnapshot(PDO $pdo, int $userId, array $snapshot): void
    {
        if ($snapshot === []) {
            return;
        }

        $stmt = $pdo->prepare('UPDATE users SET avo_ad_snapshot = ? WHERE id = ?');
        $stmt->execute([
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $userId,
        ]);
    }

    /**
     * @param array{
     *   ip?: ?string,
     *   country?: ?string,
     *   city?: ?string,
     *   utm?: array<string, string>,
     *   is_new_user?: bool
     * } $data
     */
    public static function recordAttribution(PDO $pdo, int $userId, array $data): void
    {
        $user = self::findById($pdo, $userId);
        if ($user === null) {
            return;
        }

        $ip = isset($data['ip']) && is_string($data['ip']) && $data['ip'] !== '' ? $data['ip'] : null;
        $country = isset($data['country']) && is_string($data['country']) && $data['country'] !== ''
            ? $data['country']
            : null;
        $city = isset($data['city']) && is_string($data['city']) && $data['city'] !== ''
            ? $data['city']
            : null;
        $utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];

        $sets = [];
        $params = [];

        if ($ip !== null) {
            $sets[] = 'last_ip = ?';
            $params[] = $ip;
            if ($country !== null) {
                $sets[] = 'last_country = ?';
                $params[] = $country;
            }
            if ($city !== null) {
                $sets[] = 'last_city = ?';
                $params[] = $city;
            }
        }

        $isNewUser = !empty($data['is_new_user']);
        $needsSignup = trim((string)($user['signup_ip'] ?? '')) === '';
        if ($ip !== null && ($isNewUser || $needsSignup)) {
            $sets[] = 'signup_ip = ?';
            $params[] = $ip;
            if ($country !== null) {
                $sets[] = 'signup_country = ?';
                $params[] = $country;
            }
            if ($city !== null) {
                $sets[] = 'signup_city = ?';
                $params[] = $city;
            }
        }

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            if (trim((string)($user[$key] ?? '')) !== '') {
                continue;
            }
            $value = trim((string)($utm[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $sets[] = $key . ' = ?';
            $params[] = mb_substr($value, 0, 255);
        }

        if ($sets === []) {
            return;
        }

        $params[] = $userId;
        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    public static function isAdmin(array $user, ?array $config = null): bool
    {
        return \Wwm\Services\AdminAccess::hasAdminPanelAccess($user, $config);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listDelegatedAdmins(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT * FROM users WHERE is_admin = 1 OR admin_super = 1 OR admin_students = 1 OR admin_courses = 1
             ORDER BY email COLLATE NOCASE'
        );

        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    /**
     * @param array{super?: bool, students?: bool, courses?: bool, broadcasts?: bool} $permissions
     */
    public static function setAdminPermissions(PDO $pdo, int $userId, array $permissions): void
    {
        $super = !empty($permissions['super']);
        $students = $super || !empty($permissions['students']);
        $courses = $super || !empty($permissions['courses']);
        $broadcasts = $super || !empty($permissions['broadcasts']);
        $isAdmin = $super || $students || $courses || $broadcasts;

        $stmt = $pdo->prepare(
            'UPDATE users SET is_admin = ?, admin_super = ?, admin_students = ?, admin_courses = ?, admin_broadcasts = ? WHERE id = ?'
        );
        $stmt->execute([
            $isAdmin ? 1 : 0,
            $super ? 1 : 0,
            $students ? 1 : 0,
            $courses ? 1 : 0,
            $broadcasts ? 1 : 0,
            $userId,
        ]);
    }

    public static function revokeAdmin(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare(
            'UPDATE users SET is_admin = 0, admin_super = 0, admin_students = 0, admin_courses = 0, admin_broadcasts = 0 WHERE id = ?'
        );
        $stmt->execute([$userId]);
    }

    public static function setAdmin(PDO $pdo, int $userId, bool $isAdmin): void
    {
        if ($isAdmin) {
            self::setAdminPermissions($pdo, $userId, [
                'super' => false,
                'students' => true,
                'courses' => true,
            ]);
            return;
        }

        self::revokeAdmin($pdo, $userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(PDO $pdo, ?string $search = null): array
    {
        if ($search !== null && trim($search) !== '') {
            $q = '%' . trim($search) . '%';
            $stmt = $pdo->prepare(
                'SELECT * FROM users WHERE email LIKE ? OR name LIKE ? ORDER BY created_at DESC'
            );
            $stmt->execute([$q, $q]);
            return $stmt->fetchAll() ?: [];
        }
        $stmt = $pdo->query('SELECT * FROM users ORDER BY created_at DESC');
        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    /**
     * @return list<array{id: int, email: string, name: string}>
     */
    public static function searchSuggest(PDO $pdo, string $query, int $limit = 12): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $contains = '%' . $query . '%';
        $prefix = $query . '%';
        $stmt = $pdo->prepare(
            'SELECT id, email, name FROM users
             WHERE email LIKE ? OR name LIKE ?
             ORDER BY
               CASE
                 WHEN email LIKE ? THEN 0
                 WHEN name LIKE ? THEN 1
                 ELSE 2
               END,
               email ASC
             LIMIT ' . $limit
        );
        $stmt->execute([$contains, $contains, $prefix, $prefix]);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'email' => (string)($row['email'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function paginate(PDO $pdo, ?string $search, int $page, int $perPage): array
    {
        $filter = new \Wwm\Services\AdminStudentListFilter(search: trim((string)($search ?? '')));

        return self::paginateFiltered($pdo, $filter, $page, $perPage);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function paginateFiltered(
        PDO $pdo,
        \Wwm\Services\AdminStudentListFilter $filter,
        int $page,
        int $perPage
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $built = $filter->sqlWhere();
        $where = $built['where'];
        $params = $built['params'];

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . $where);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = 'SELECT u.* FROM users u WHERE ' . $where . ' ' . $filter->sqlOrderBy() . ' LIMIT '
            . $perPage . ' OFFSET ' . $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll() ?: [], 'total' => $total];
    }

    public static function mergeAvoContactRegisteredAt(PDO $pdo, int $userId, string $iso): void
    {
        self::mergeEarliestIsoColumn($pdo, $userId, 'avo_contact_registered_at', $iso);
    }

    public static function mergeAvoFirstOrderAt(PDO $pdo, int $userId, string $iso): void
    {
        self::mergeEarliestIsoColumn($pdo, $userId, 'avo_first_order_at', $iso);
    }

    private static function mergeEarliestIsoColumn(PDO $pdo, int $userId, string $column, string $iso): void
    {
        $iso = trim($iso);
        if ($iso === '') {
            return;
        }

        $allowed = ['avo_contact_registered_at', 'avo_first_order_at'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid column');
        }

        $stmt = $pdo->prepare('SELECT ' . $column . ' FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $current = $stmt->fetchColumn();
        $current = is_string($current) ? trim($current) : '';

        if ($current !== '' && $current <= $iso) {
            return;
        }

        $update = $pdo->prepare('UPDATE users SET ' . $column . ' = ? WHERE id = ?');
        $update->execute([$iso, $userId]);
    }

    public static function delete(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }
}
