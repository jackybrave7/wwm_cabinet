<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Auth\Password;
use Wwm\Auth\Session;
use Wwm\Models\LoginLink;
use Wwm\Models\PasswordReset;
use Wwm\Models\User;
use Wwm\Services\AvoEngagementSync;
use Wwm\Services\EmailTracker;

final class AuthController
{
    public function showLogin(): void
    {
        if (Session::userId() !== null) {
            wwm_redirect('/');
        }

        $email = trim((string)($_GET['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        $password = (string)($_GET['password'] ?? '');
        if (strlen($password) > 128) {
            $password = '';
        }

        $next = wwm_sanitize_internal_path((string)($_GET['next'] ?? '/'));
        $error = null;
        $message = isset($_GET['reset']) ? 'Password updated. Sign in with your new password.' : null;

        if ($email !== '' && $password !== '') {
            $user = User::findByEmail(wwm_pdo(), $email);
            if ($user !== null && Password::verify($password, (string)($user['password_hash'] ?? ''))) {
                Session::login((int)$user['id']);
                User::touchLogin(wwm_pdo(), (int)$user['id']);
                StudentAttribution::recordForUser(wwm_pdo(), (int)$user['id']);
                AvoEngagementSync::onLogin((int)$user['id']);
                wwm_log('login via url user_id=' . $user['id']);
                wwm_redirect($next);
            }
            $error = 'Invalid email or password.';
        }

        wwm_render('login', [
            'pageTitle' => 'Sign in',
            'error' => $error,
            'message' => $message,
            'next' => $next,
            'email' => $email,
            'password' => $password,
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
                'email' => $email,
                'password' => '',
            ]);
            return;
        }

        Session::login((int)$user['id']);
        User::touchLogin(wwm_pdo(), (int)$user['id']);
        StudentAttribution::recordForUser(wwm_pdo(), (int)$user['id']);
        AvoEngagementSync::onLogin((int)$user['id']);
        wwm_log('login user_id=' . $user['id']);
        wwm_redirect($next);
    }

    public function consumeMagicLink(): void
    {
        $token = trim((string)($_GET['token'] ?? ''));
        $row = $token !== '' ? LoginLink::findValid(wwm_pdo(), $token) : null;
        if ($row === null) {
            wwm_render('error', [
                'pageTitle' => 'Invalid link',
                'code' => 400,
                'message' => 'This sign-in link is invalid or has expired.',
            ]);
            return;
        }

        LoginLink::markUsed(wwm_pdo(), (int)$row['id']);
        Session::login((int)$row['user_id']);
        User::touchLogin(wwm_pdo(), (int)$row['user_id']);
        StudentAttribution::recordForUser(wwm_pdo(), (int)$row['user_id']);
        AvoEngagementSync::onLogin((int)$row['user_id']);
        wwm_log('magic login user_id=' . $row['user_id']);

        $next = LoginLink::sanitizeNextPath((string)($row['next_path'] ?? '/'));
        wwm_redirect($next);
    }

    public function requestMagicLink(): void
    {
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            wwm_render('login', [
                'pageTitle' => 'Sign in',
                'error' => 'Invalid request.',
                'message' => null,
                'next' => '/',
            ]);
            return;
        }

        $email = trim((string)($_POST['email'] ?? ''));
        $next = LoginLink::sanitizeNextPath((string)($_POST['next'] ?? '/'));
        $user = User::findByEmail(wwm_pdo(), $email);

        if ($user !== null) {
            $loginUrl = LoginLink::issue(wwm_pdo(), (int)$user['id'], $next, LoginLink::ttlSeconds());
            $textBody = implode("\n", [
                'Hello,',
                '',
                'Open this link to sign in to your account:',
                $loginUrl,
                '',
                'The link is single-use and expires in ' . (int)(LoginLink::ttlSeconds() / 3600) . ' hours.',
                '',
                'If you did not request this, you can ignore this email.',
                '',
                'World Watercolor Masters',
            ]);
            EmailTracker::compose((int)$user['id'], (string)$user['email'], 'magic', 'Your sign-in link — World Watercolor Masters')
                ->deliver($textBody, null, [
                    ['url' => $loginUrl, 'label' => 'Sign in'],
                ]);
        }

        wwm_render('login', [
            'pageTitle' => 'Sign in',
            'error' => null,
            'message' => 'If this email is registered, we sent a sign-in link.',
            'next' => $next,
        ]);
    }

    public function logout(): void
    {
        Session::logout();
        wwm_redirect('/login');
    }

    public function showForgot(): void
    {
        $loggedInEmail = null;
        $userId = Session::userId();
        if ($userId !== null) {
            $user = User::findById(wwm_pdo(), $userId);
            $loggedInEmail = is_array($user) ? trim((string)($user['email'] ?? '')) : null;
            if ($loggedInEmail === '') {
                $loggedInEmail = null;
            }
        }

        wwm_render('forgot', [
            'pageTitle' => 'Reset password',
            'message' => null,
            'error' => null,
            'loggedInEmail' => $loggedInEmail,
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
            $link = wwm_base_url() . '/reset?token=' . urlencode($token);
            $textBody = "Open this link to set a new password (valid 1 hour):\n\n" . $link . "\n";
            $sent = EmailTracker::compose((int)$user['id'], (string)$user['email'], 'reset', 'Reset your WWM password')
                ->deliver($textBody, null, [
                    ['url' => $link, 'label' => 'Reset password'],
                ]);
            wwm_log('password reset mail to user_id=' . $user['id'] . ' sent=' . ($sent ? 'yes' : 'no'));
        } else {
            wwm_log('password reset requested for unknown email: ' . $email);
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

        if (Session::userId() !== null) {
            // Clear login only — do not session_destroy() here or the reset form CSRF token is lost.
            unset($_SESSION['user_id']);
        }

        wwm_render('reset', [
            'pageTitle' => 'New password',
            'token' => $token,
            'email' => (string)($row['email'] ?? ''),
            'error' => null,
        ]);
    }

    public function reset(): void
    {
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            $token = (string)($_POST['token'] ?? '');
            $row = $token !== '' ? PasswordReset::findValid(wwm_pdo(), $token) : null;
            wwm_render('reset', [
                'pageTitle' => 'New password',
                'token' => $token,
                'email' => is_array($row) ? (string)($row['email'] ?? '') : '',
                'error' => 'Session expired. Open the reset link from your email again and submit the form.',
            ]);
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
                'email' => (string)($row['email'] ?? ''),
                'error' => 'Password must be at least 8 characters.',
            ]);
            return;
        }

        if ($password !== $confirm) {
            wwm_render('reset', [
                'pageTitle' => 'New password',
                'token' => $token,
                'email' => (string)($row['email'] ?? ''),
                'error' => 'Passwords do not match.',
            ]);
            return;
        }

        User::updatePassword(wwm_pdo(), (int)$row['user_id'], $password);
        PasswordReset::markUsed(wwm_pdo(), (int)$row['id']);
        wwm_log(sprintf(
            'password reset user_id=%d email=%s',
            (int)$row['user_id'],
            (string)($row['email'] ?? '')
        ));
        Session::logout();
        wwm_redirect('/login?reset=1');
    }
}
