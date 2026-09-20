<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\User;

final class StaffNotifyRecipients
{
    /**
     * Options for automation editor (checkbox list).
     *
     * @return list<array{value: string, email: string, name: string, roles: string}>
     */
    public static function optionsForEditor(?PDO $pdo = null): array
    {
        $pdo ??= wwm_pdo();
        $config = wwm_config();
        $out = [];
        $seenEmails = [];

        foreach (self::configSuperAdminEmails($config) as $email) {
            $user = User::findByEmail($pdo, $email);
            if ($user !== null && AdminAccess::hasAdminPanelAccess($user, $config)) {
                $id = (int)($user['id'] ?? 0);
                if ($id > 0) {
                    $seenEmails[$email] = true;
                    $out[] = self::optionFromUser($user, $config);
                    continue;
                }
            }
            $seenEmails[$email] = true;
            $out[] = [
                'value' => self::emailKey($email),
                'email' => $email,
                'name' => $email,
                'roles' => 'Super admin (config)',
            ];
        }

        foreach (self::listCabinetAdmins($pdo) as $user) {
            if (!AdminAccess::hasAdminPanelAccess($user, $config)) {
                continue;
            }
            $email = strtolower(trim((string)($user['email'] ?? '')));
            if ($email === '' || isset($seenEmails[$email])) {
                continue;
            }
            $seenEmails[$email] = true;
            $out[] = self::optionFromUser($user, $config);
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['email'], $b['email']));

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    public static function resolveFromNode(array $node, ?PDO $pdo = null): array
    {
        $pdo ??= wwm_pdo();
        $ids = $node['staff_admin_ids'] ?? null;
        if (is_array($ids) && $ids !== []) {
            $emails = self::emailsForAdminKeys($ids, $pdo);
            if ($emails !== []) {
                return $emails;
            }
        }

        $raw = trim((string)($node['staff_recipients'] ?? ''));
        if ($raw !== '') {
            $list = [];
            foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $part) {
                $email = strtolower(trim($part));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $list[$email] = $email;
                }
            }
            if ($list !== []) {
                return array_values($list);
            }
        }

        return [];
    }

    /**
     * @param list<mixed> $keys
     * @return list<string>
     */
    public static function emailsForAdminKeys(array $keys, ?PDO $pdo = null): array
    {
        $pdo ??= wwm_pdo();
        $config = wwm_config();
        $map = [];
        foreach (self::optionsForEditor($pdo) as $opt) {
            $map[(string)$opt['value']] = strtolower((string)$opt['email']);
        }

        $out = [];
        foreach ($keys as $key) {
            if (is_int($key) || (is_string($key) && ctype_digit($key))) {
                $key = 'u:' . (int)$key;
            }
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            if (str_starts_with($key, 'e:')) {
                $email = strtolower(substr($key, 2));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $out[$email] = $email;
                }
                continue;
            }
            if (isset($map[$key])) {
                $email = $map[$key];
                $out[$email] = $email;
            }
        }

        return array_values($out);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listCabinetAdmins(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT * FROM users WHERE is_admin = 1 OR admin_super = 1 OR admin_students = 1 OR admin_courses = 1
             OR admin_broadcasts = 1
             ORDER BY email COLLATE NOCASE'
        );

        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    /**
     * @return list<string>
     */
    private static function configSuperAdminEmails(array $config): array
    {
        $emails = $config['admin_emails'] ?? [];
        if (is_string($emails)) {
            $emails = array_filter(array_map('trim', preg_split('/[,;]+/', $emails) ?: []));
        }
        if (!is_array($emails)) {
            return [];
        }
        $out = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string)$email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $user
     * @return array{value: string, email: string, name: string, roles: string}
     */
    private static function optionFromUser(array $user, array $config): array
    {
        $id = (int)($user['id'] ?? 0);
        $email = strtolower(trim((string)($user['email'] ?? '')));
        $name = trim((string)($user['name'] ?? ''));
        $roles = implode(', ', AdminAccess::permissionLabels($user, $config));

        return [
            'value' => 'u:' . $id,
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'roles' => $roles !== '' ? $roles : 'Admin',
        ];
    }

    private static function emailKey(string $email): string
    {
        return 'e:' . strtolower(trim($email));
    }
}
