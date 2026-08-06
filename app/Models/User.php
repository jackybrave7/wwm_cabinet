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

    public static function isAdmin(array $user, ?array $config = null): bool
    {
        if (!empty($user['is_admin'])) {
            return true;
        }
        $config ??= wwm_config();
        $emails = $config['admin_emails'] ?? [];
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
}
