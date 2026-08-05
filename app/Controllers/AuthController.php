<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Auth\Password;
use Wwm\Auth\Session;
use Wwm\Models\PasswordReset;
use Wwm\Models\User;
use Wwm\Services\Mailer;

final class AuthController
{
    public function showLogin(): void
    {
        if (Session::userId() !== null) {
            wwm_redirect('/');
        }
        wwm_render('login', [
            'pageTitle' => 'Sign in',
            'error' => null,
            'next' => (string)($_GET['next'] ?? '/'),
        ]);
    }

    public function login(): void
    {
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            wwm_render('login', ['pageTitle' => 'Sign in', 'error' => 'Invalid request.', 'next' => '/']);
            return;
        }

        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $next = (string)($_POST['next'] ?? '/');
        if ($next === '' || !str_starts_with($next, '/')) {
            $next = '/';
        }

        $user = User::findByEmail(wwm_pdo(), $email);
        if ($user === null || !Password::verify($password, (string)($user['password_hash'] ?? ''))) {
            wwm_render('login', [
                'pageTitle' => 'Sign in',
                'error' => 'Invalid email or password.',
                'next' => $next,
            ]);
            return;
        }

        Session::login((int)$user['id']);
        User::touchLogin(wwm_pdo(), (int)$user['id']);
        wwm_log('login user_id=' . $user['id']);
        wwm_redirect($next);
    }

    public function logout(): void
    {
        Session::logout();
        wwm_redirect('/login');
    }

    public function showForgot(): void
    {
        wwm_render('forgot', [
            'pageTitle' => 'Reset password',
            'message' => null,
            'error' => null,
        ]);
    }

    public function forgot(): void
    {
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            wwm_render('forgot', ['pageTitle' => 'Reset password', 'message' => null, 'error' => 'Invalid request.']);
            return;
        }

        $email = trim((string)($_POST['email'] ?? ''));
        $user = User::findByEmail(wwm_pdo(), $email);

        // Не раскрываем, есть ли email в базе
        if ($user !== null) {
            $token = bin2hex(random_bytes(32));
            PasswordReset::create(wwm_pdo(), (int)$user['id'], $token);
            $base = rtrim((string)wwm_config()['base_url'], '/');
            $link = $base . '/reset?token=' . urlencode($token);
            Mailer::send(
                (string)$user['email'],
                'Reset your WWM password',
                "Open this link to set a new password (valid 1 hour):\n\n" . $link . "\n"
            );
        }

        wwm_render('forgot', [
            'pageTitle' => 'Reset password',
            'message' => 'If this email is registered, we sent a reset link.',
            'error' => null,
        ]);
    }

    public function showReset(): void
    {
        $token = (string)($_GET['token'] ?? '');
        $row = $token !== '' ? PasswordReset::findValid(wwm_pdo(), $token) : null;
        if ($row === null) {
            wwm_render('error', [
                'pageTitle' => 'Invalid link',
                'code' => 400,
                'message' => 'This reset link is invalid or expired.',
            ]);
            return;
        }
        wwm_render('reset', [
            'pageTitle' => 'New password',
            'token' => $token,
            'error' => null,
        ]);
    }

    public function reset(): void
    {
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            wwm_render('reset', ['pageTitle' => 'New password', 'token' => '', 'error' => 'Invalid request.']);
            return;
        }

        $token = (string)($_POST['token'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');

        $row = PasswordReset::findValid(wwm_pdo(), $token);
        if ($row === null) {
            wwm_render('error', [
                'pageTitle' => 'Invalid link',
                'code' => 400,
                'message' => 'This reset link is invalid or expired.',
            ]);
            return;
        }

        if (strlen($password) < 8) {
            wwm_render('reset', [
                'pageTitle' => 'New password',
                'token' => $token,
                'error' => 'Password must be at least 8 characters.',
            ]);
            return;
        }

        if ($password !== $confirm) {
            wwm_render('reset', [
                'pageTitle' => 'New password',
                'token' => $token,
                'error' => 'Passwords do not match.',
            ]);
            return;
        }

        User::updatePassword(wwm_pdo(), (int)$row['user_id'], $password);
        PasswordReset::markUsed(wwm_pdo(), (int)$row['id']);
        wwm_log('password reset user_id=' . $row['user_id']);
        wwm_redirect('/login');
    }
}
