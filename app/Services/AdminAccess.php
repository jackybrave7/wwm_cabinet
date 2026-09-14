<?php
declare(strict_types=1);

namespace Wwm\Services;

use Wwm\Models\User;

final class AdminAccess
{
    public static function isConfigSuperAdmin(array $user, ?array $config = null): bool
    {
        $config ??= wwm_config();
        $emails = self::normalizeEmailList($config['admin_emails'] ?? []);
        if ($emails === []) {
            return false;
        }
        $email = strtolower(trim((string)($user['email'] ?? '')));

        return in_array($email, $emails, true);
    }

    public static function isSuperAdmin(array $user, ?array $config = null): bool
    {
        if (self::isConfigSuperAdmin($user, $config)) {
            return true;
        }

        return !empty($user['admin_super']);
    }

    public static function canManageStudents(array $user, ?array $config = null): bool
    {
        if (self::isSuperAdmin($user, $config)) {
            return true;
        }

        return !empty($user['admin_students']);
    }

    public static function canManageCourses(array $user, ?array $config = null): bool
    {
        if (self::isSuperAdmin($user, $config)) {
            return true;
        }

        return !empty($user['admin_courses']);
    }

    public static function canManageAdmins(array $user, ?array $config = null): bool
    {
        return self::isSuperAdmin($user, $config);
    }

    public static function canManageEmails(array $user, ?array $config = null): bool
    {
        return self::isSuperAdmin($user, $config);
    }

    public static function canManageBroadcasts(array $user, ?array $config = null): bool
    {
        if (self::isSuperAdmin($user, $config)) {
            return true;
        }

        return !empty($user['admin_broadcasts']);
    }

    public static function canManageSettings(array $user, ?array $config = null): bool
    {
        return self::isSuperAdmin($user, $config);
    }

    public static function hasAdminPanelAccess(array $user, ?array $config = null): bool
    {
        if (self::isConfigSuperAdmin($user, $config)) {
            return true;
        }
        if (!empty($user['is_admin'])) {
            return true;
        }

        return !empty($user['admin_super'])
            || !empty($user['admin_students'])
            || !empty($user['admin_courses'])
            || !empty($user['admin_broadcasts']);
    }

    public static function defaultAdminPath(array $user, ?array $config = null): string
    {
        if (self::canManageCourses($user, $config)) {
            return '/admin/courses';
        }
        if (self::canManageStudents($user, $config)) {
            return '/admin/students';
        }
        if (self::canManageBroadcasts($user, $config)) {
            return '/admin/broadcasts';
        }
        if (self::canManageAdmins($user, $config)) {
            return '/admin/admins';
        }

        return '/admin/courses';
    }

    /**
     * @return list<string>
     */
    public static function permissionLabels(array $user, ?array $config = null): array
    {
        if (self::isConfigSuperAdmin($user, $config)) {
            return ['Super admin (config)'];
        }

        $labels = [];
        if (!empty($user['admin_super'])) {
            $labels[] = 'Super admin';
        }
        if (!empty($user['admin_students'])) {
            $labels[] = 'Students';
        }
        if (!empty($user['admin_courses'])) {
            $labels[] = 'Courses';
        }
        if (!empty($user['admin_broadcasts'])) {
            $labels[] = 'Broadcasts';
        }

        if ($labels === [] && !empty($user['is_admin'])) {
            return ['Admin (legacy)'];
        }

        return $labels;
    }

    public static function isProtectedAccount(array $user, ?array $config = null): bool
    {
        return self::isConfigSuperAdmin($user, $config);
    }

    /**
     * @param mixed $emails
     * @return list<string>
     */
    private static function normalizeEmailList(mixed $emails): array
    {
        if (is_string($emails)) {
            $emails = array_filter(array_map('trim', preg_split('/[,;]+/', $emails) ?: []));
        }
        if (!is_array($emails)) {
            return [];
        }

        $out = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string)$email));
            if ($email !== '') {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }
}
