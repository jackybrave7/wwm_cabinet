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
            'profileMessage' => null,
            'profileError' => null,
            'passwordMessage' => null,
            'passwordError' => null,
        ]);
    }

    public function updateProfile(): void
    {
        $userId = Session::requireLogin();
        $user = User::findById(wwm_pdo(), $userId);

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            $this->renderAccount($user, profileError: 'Invalid request.');
            return;
        }

        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $this->renderAccount($user, profileError: 'Please enter your name.');
            return;
        }

        if (mb_strlen($name) > 120) {
            $this->renderAccount($user, profileError: 'Name is too long.');
            return;
        }

        User::updateName(wwm_pdo(), $userId, $name);
        $user = User::findById(wwm_pdo(), $userId);
        wwm_log('profile updated user_id=' . $userId);

        $this->renderAccount($user, profileMessage: 'Profile saved.');
    }

    public function updatePassword(): void
    {
        $userId = Session::requireLogin();
        $user = User::findById(wwm_pdo(), $userId);

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            $this->renderAccount($user, passwordError: 'Invalid request.');
            return;
        }

        $current = (string)($_POST['current_password'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');

        if ($user === null || !Password::verify($current, (string)($user['password_hash'] ?? ''))) {
            $this->renderAccount($user, passwordError: 'Current password is incorrect.');
            return;
        }

        if (strlen($password) < 8) {
            $this->renderAccount($user, passwordError: 'New password must be at least 8 characters.');
            return;
        }

        if ($password !== $confirm) {
            $this->renderAccount($user, passwordError: 'Passwords do not match.');
            return;
        }

        User::updatePassword(wwm_pdo(), $userId, $password);
        wwm_log('password changed user_id=' . $userId);

        $this->renderAccount($user, passwordMessage: 'Password updated.');
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function renderAccount(
        ?array $user,
        ?string $profileMessage = null,
        ?string $profileError = null,
        ?string $passwordMessage = null,
        ?string $passwordError = null
    ): void {
        wwm_render('account', [
            'pageTitle' => 'Account',
            'user' => $user,
            'profileMessage' => $profileMessage,
            'profileError' => $profileError,
            'passwordMessage' => $passwordMessage,
            'passwordError' => $passwordError,
        ]);
    }
}
