<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\User;

final class AdminInviteMail
{
    /**
     * @param array{super?: bool, students?: bool, courses?: bool} $permissions
     */
    public static function send(PDO $pdo, int $userId, array $permissions, ?string $plainPassword): bool
    {
        $user = User::findById($pdo, $userId);
        if ($user === null) {
            return false;
        }

        $email = strtolower(trim((string)($user['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $name = trim((string)($user['name'] ?? ''));
        $permissionsList = self::permissionsLabel($permissions);
        $forgotUrl = wwm_base_url() . '/forgot';

        if ($plainPassword !== null && $plainPassword !== '') {
            $loginUrl = wwm_login_url($email, $plainPassword, '/admin');
            $passwordLine = 'Password: ' . $plainPassword;
            $passwordHint = $plainPassword;
        } else {
            $loginUrl = wwm_base_url() . '/login?next=' . rawurlencode('/admin');
            $passwordLine = 'Password: use your existing account password. If you forgot it: ' . $forgotUrl;
            $passwordHint = '';
        }

        $message = EmailTemplateRenderer::render('admin_invite', [
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
            'login_url' => $loginUrl,
            'permissions_list' => $permissionsList,
            'password_line' => $passwordLine,
            'password' => $passwordHint,
            'forgot_url' => $forgotUrl,
        ]);

        $sent = EmailTracker::compose($userId, $email, 'admin_invite', $message['subject'])
            ->deliver($message['text'], $message['html'], [
                ['url' => $loginUrl, 'label' => 'Open admin panel'],
            ]);

        wwm_log(sprintf(
            'admin invite mail user_id=%d email=%s sent=%s',
            $userId,
            $email,
            $sent ? 'yes' : 'no'
        ));

        return $sent;
    }

    /**
     * @param array{super?: bool, students?: bool, courses?: bool} $permissions
     */
    public static function permissionsLabel(array $permissions): string
    {
        if (!empty($permissions['super'])) {
            return 'Super administrator (full access)';
        }

        $parts = [];
        if (!empty($permissions['students'])) {
            $parts[] = 'Students — add students and manage course access';
        }
        if (!empty($permissions['courses'])) {
            $parts[] = 'Courses — edit course content and lessons';
        }

        return $parts === [] ? 'Administrator' : implode('; ', $parts);
    }
}
