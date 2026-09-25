<?php
declare(strict_types=1);

namespace Wwm\Services;

use PDO;
use Wwm\Models\User;

final class StudentLoginCredentialsMail
{
    /**
     * @param string|null $plainPassword When set, included in the email and used for the prefilled sign-in link.
     */
    public static function send(PDO $pdo, int $userId, ?string $plainPassword): bool
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
        $forgotUrl = wwm_base_url() . '/forgot';
        $recoverHelpUrl = wwm_base_url() . '/help/recover-access';
        $accountUrl = wwm_base_url() . '/account';

        if ($plainPassword !== null && $plainPassword !== '') {
            $loginUrl = wwm_login_url($email, $plainPassword, '/');
            $passwordLine = 'Password: ' . $plainPassword;
            $passwordHint = $plainPassword;
        } else {
            $loginUrl = wwm_base_url() . '/login';
            $passwordLine = 'Password: use your existing password, or reset it using the link below.';
            $passwordHint = '';
        }

        $message = EmailTemplateRenderer::render('login_credentials', [
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
            'login_url' => $loginUrl,
            'password' => $passwordHint,
            'password_line' => $passwordLine,
            'forgot_url' => $forgotUrl,
            'recover_help_url' => $recoverHelpUrl,
            'account_url' => $accountUrl,
        ]);

        $links = [
            ['url' => $loginUrl, 'label' => 'Sign in'],
            ['url' => $forgotUrl, 'label' => 'Reset password'],
            ['url' => $recoverHelpUrl, 'label' => 'Recover access guide'],
            ['url' => $accountUrl, 'label' => 'Account'],
        ];

        $sent = EmailTracker::compose($userId, $email, 'login_credentials', $message['subject'])
            ->deliver($message['text'], $message['html'], $links);

        wwm_log(sprintf(
            'student login credentials mail user_id=%d email=%s sent=%s reset=%s',
            $userId,
            $email,
            $sent ? 'yes' : 'no',
            $plainPassword !== null && $plainPassword !== '' ? 'yes' : 'no'
        ));

        return $sent;
    }
}
