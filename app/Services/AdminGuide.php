<?php
declare(strict_types=1);

namespace Wwm\Services;

final class AdminGuide
{
    /**
     * @return array{meta: array<string, string>, sections: list<array<string, mixed>>}
     */
    public static function document(): array
    {
        $path = WWM_ROOT . '/data/admin-guide.php';
        if (!is_readable($path)) {
            return [
                'meta' => [
                    'title' => 'Administrator guide',
                    'revision' => '',
                ],
                'sections' => [],
            ];
        }

        /** @var array{meta?: array<string, string>, sections?: list<array<string, mixed>>} $data */
        $data = require $path;

        return [
            'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : [],
            'sections' => is_array($data['sections'] ?? null) ? $data['sections'] : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sectionsForUser(array $user): array
    {
        $doc = self::document();
        $out = [];
        foreach ($doc['sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }
            $access = (string)($section['access'] ?? 'panel');
            if (self::userCanSeeSection($user, $access)) {
                $out[] = $section;
            }
        }

        return $out;
    }

    public static function userCanSeeSection(array $user, string $access): bool
    {
        if (!AdminAccess::hasAdminPanelAccess($user)) {
            return false;
        }

        return match ($access) {
            'panel' => true,
            'dashboard' => AdminAccess::hasAdminPanelAccess($user),
            'courses' => AdminAccess::canManageCourses($user),
            'students' => AdminAccess::canManageStudents($user),
            'emails', 'automations' => AdminAccess::canManageEmails($user),
            'broadcasts' => AdminAccess::canManageBroadcasts($user),
            'settings' => AdminAccess::canManageSettings($user),
            'admins' => AdminAccess::canManageAdmins($user),
            default => false,
        };
    }
}
