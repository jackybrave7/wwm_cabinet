<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Auth\Password;
use Wwm\Auth\Session;
use Wwm\Models\User;

final class AccountController
{
    public function show(): void
    {
        $userId = Session::requireLogin();
        $user = User::findById(wwm_pdo(), $userId);

        wwm_render('account', [
            'pageTitle' => 'Account',
            'user' => $user,
            'message' => null,
            'error' => null,
        ]);
    }

    public function updatePassword(): void
    {
        $userId = Session::requireLogin();
        $user = User::findById(wwm_pdo(), $userId);

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_render('account', [
                'pageTitle' => 'Account',
                'user' => $user,
                'message' => null,
                'error' => 'Invalid request.',
            ]);
            return;
        }

        $current = (string)($_POST['current_password'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');

        if ($user === null || !Password::verify($current, (string)($user['password_hash'] ?? ''))) {
            wwm_render('account', [
                'pageTitle' => 'Account',
                'user' => $user,
                'message' => null,
                'error' => 'Current password is incorrect.',
            ]);
            return;
        }

        if (strlen($password) < 8) {
            wwm_render('account', [
                'pageTitle' => 'Account',
                'user' => $user,
                'message' => null,
                'error' => 'New password must be at least 8 characters.',
            ]);
            return;
        }

        if ($password !== $confirm) {
            wwm_render('account', [
                'pageTitle' => 'Account',
                'user' => $user,
                'message' => null,
                'error' => 'Passwords do not match.',
            ]);
            return;
        }

        User::updatePassword(wwm_pdo(), $userId, $password);
        wwm_log('password changed user_id=' . $userId);

        wwm_render('account', [
            'pageTitle' => 'Account',
            'user' => $user,
            'message' => 'Password updated.',
            'error' => null,
        ]);
    }
}
