<?php
declare(strict_types=1);

namespace Wwm\Models;

use PDO;

final class User
{
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

    public static function create(PDO $pdo, string $email, string $password, string $name = ''): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            strtolower(trim($email)),
            \Wwm\Auth\Password::hash($password),
            trim($name),
            gmdate('c'),
        ]);
        return (int)$pdo->lastInsertId();
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
        if (!empty($user['is_admin'])) {
            return true;
        }
        $config ??= wwm_config();
        $emails = $config['admin_emails'] ?? [];
        if (is_string($emails)) {
            $emails = array_filter(array_map('trim', preg_split('/[,;]+/', $emails) ?: []));
        }
        if (!is_array($emails) || $emails === []) {
            return false;
        }
        $email = strtolower(trim((string)($user['email'] ?? '')));
        foreach ($emails as $adminEmail) {
            if ($email === strtolower(trim((string)$adminEmail))) {
                return true;
            }
        }
        return false;
    }

    public static function setAdmin(PDO $pdo, int $userId, bool $isAdmin): void
    {
        $stmt = $pdo->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
        $stmt->execute([$isAdmin ? 1 : 0, $userId]);
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
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function paginate(PDO $pdo, ?string $search, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        if ($search !== null && trim($search) !== '') {
            $q = '%' . trim($search) . '%';
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM users WHERE email LIKE ? OR name LIKE ?'
            );
            $countStmt->execute([$q, $q]);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT * FROM users WHERE email LIKE ? OR name LIKE ? ORDER BY created_at DESC LIMIT '
                . $perPage . ' OFFSET ' . $offset
            );
            $stmt->execute([$q, $q]);
            return ['rows' => $stmt->fetchAll() ?: [], 'total' => $total];
        }

        $total = (int)($pdo->query('SELECT COUNT(*) FROM users')?->fetchColumn() ?: 0);
        $stmt = $pdo->query(
            'SELECT * FROM users ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );

        return ['rows' => $stmt ? ($stmt->fetchAll() ?: []) : [], 'total' => $total];
    }

    public static function delete(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }
}
