<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\User;
use Wwm\Services\AdminAccess;
use Wwm\Services\AdminInviteMail;

final class AdminTeamController
{
    public function index(): void
    {
        $userId = Session::requireSuperAdmin();
        $user = User::findById(wwm_pdo(), $userId);
        $admins = User::listDelegatedAdmins(wwm_pdo());

        wwm_render_admin('team', [
            'pageTitle' => 'Administrators — Admin',
            'user' => $user,
            'adminNav' => 'admins',
            'admins' => $admins,
            'message' => match (true) {
                isset($_GET['created']) => 'Administrator added.',
                isset($_GET['updated']) => 'Permissions saved.',
                isset($_GET['revoked']) => 'Administrator access removed.',
                default => null,
            },
            'error' => match ((string)($_GET['error'] ?? '')) {
                'csrf' => 'Session expired. Please try again.',
                'protected' => 'This account is managed in server config and cannot be edited here.',
                'self' => 'You cannot remove your own administrator access.',
                'exists' => 'Could not save administrator.',
                default => null,
            },
        ]);
    }

    public function createForm(): void
    {
        $userId = Session::requireSuperAdmin();
        $user = User::findById(wwm_pdo(), $userId);

        wwm_render_admin('team-form', [
            'pageTitle' => 'Add administrator — Admin',
            'user' => $user,
            'adminNav' => 'admins',
            'target' => null,
            'formAction' => '/admin/admins',
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/admins?error=csrf');
        }

        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $permissions = $this->permissionsFromPost();

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->renderFormError(null, 'Please enter a valid email.');
            return;
        }
        if (!$permissions['super'] && !$permissions['students'] && !$permissions['courses']) {
            $this->renderFormError(null, 'Select at least one permission.');
            return;
        }

        $pdo = wwm_pdo();
        $existing = User::findByEmail($pdo, $email);
        if ($existing !== null && AdminAccess::isProtectedAccount($existing)) {
            wwm_redirect('/admin/admins?error=protected');
        }

        $plainForEmail = null;
        if ($existing === null) {
            if (strlen($password) < 8) {
                $password = trim((string)(wwm_config()['demo_default_password'] ?? ''));
            }
            if (strlen($password) < 8) {
                $this->renderFormError(null, 'Set a password (min. 8 characters) for a new account.');
                return;
            }
            $plainForEmail = $password;
            $userId = User::create($pdo, $email, $password, $name);
        } else {
            $userId = (int)$existing['id'];
            if ($name !== '') {
                User::updateName($pdo, $userId, $name);
            }
            if ($password !== '') {
                if (strlen($password) < 8) {
                    $this->renderFormError($existing, 'Password must be at least 8 characters.');
                    return;
                }
                User::updatePassword($pdo, $userId, $password);
                $plainForEmail = $password;
            }
        }

        User::setAdminPermissions($pdo, $userId, $permissions);
        AdminInviteMail::send($pdo, $userId, $permissions, $plainForEmail);
        wwm_log('admin granted user_id=' . $userId . ' email=' . $email);
        wwm_redirect('/admin/admins?created=1');
    }

    public function edit(int $id): void
    {
        $userId = Session::requireSuperAdmin();
        $user = User::findById(wwm_pdo(), $userId);
        $target = User::findById(wwm_pdo(), $id);
        if ($target === null) {
            wwm_redirect('/admin/admins');
        }
        if (AdminAccess::isProtectedAccount($target)) {
            wwm_redirect('/admin/admins?error=protected');
        }

        wwm_render_admin('team-form', [
            'pageTitle' => 'Edit administrator — Admin',
            'user' => $user,
            'adminNav' => 'admins',
            'target' => $target,
            'formAction' => '/admin/admins/' . $id,
            'error' => null,
        ]);
    }

    public function update(int $id): void
    {
        Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/admins?error=csrf');
        }

        $pdo = wwm_pdo();
        $target = User::findById($pdo, $id);
        if ($target === null) {
            wwm_redirect('/admin/admins');
        }
        if (AdminAccess::isProtectedAccount($target)) {
            wwm_redirect('/admin/admins?error=protected');
        }

        $wasAdmin = AdminAccess::hasAdminPanelAccess($target);
        $name = trim((string)($_POST['name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $permissions = $this->permissionsFromPost();

        if (!$permissions['super'] && !$permissions['students'] && !$permissions['courses']) {
            $this->renderFormError($target, 'Select at least one permission.');
            return;
        }

        if ($name !== '') {
            User::updateName($pdo, $id, $name);
        }
        if ($password !== '') {
            if (strlen($password) < 8) {
                $this->renderFormError($target, 'Password must be at least 8 characters.');
                return;
            }
            User::updatePassword($pdo, $id, $password);
        }

        User::setAdminPermissions($pdo, $id, $permissions);
        $plainForEmail = $password !== '' ? $password : null;
        if (!$wasAdmin || $plainForEmail !== null) {
            AdminInviteMail::send($pdo, $id, $permissions, $plainForEmail);
        }
        wwm_log('admin permissions updated user_id=' . $id);
        wwm_redirect('/admin/admins?updated=1');
    }

    public function revoke(int $id): void
    {
        $actorId = Session::requireSuperAdmin();
        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/admins?error=csrf');
        }

        if ($id === $actorId) {
            wwm_redirect('/admin/admins?error=self');
        }

        $pdo = wwm_pdo();
        $target = User::findById($pdo, $id);
        if ($target === null) {
            wwm_redirect('/admin/admins');
        }
        if (AdminAccess::isProtectedAccount($target)) {
            wwm_redirect('/admin/admins?error=protected');
        }

        User::revokeAdmin($pdo, $id);
        wwm_log('admin revoked user_id=' . $id);
        wwm_redirect('/admin/admins?revoked=1');
    }

    /**
     * @return array{super: bool, students: bool, courses: bool, broadcasts: bool}
     */
    private function permissionsFromPost(): array
    {
        $super = !empty($_POST['perm_super']);
        return [
            'super' => $super,
            'students' => $super || !empty($_POST['perm_students']),
            'courses' => $super || !empty($_POST['perm_courses']),
            'broadcasts' => $super || !empty($_POST['perm_broadcasts']),
        ];
    }

    /**
     * @param array<string, mixed>|null $target
     */
    private function renderFormError(?array $target, string $error): void
    {
        $userId = Session::requireSuperAdmin();
        $user = User::findById(wwm_pdo(), $userId);

        wwm_render_admin('team-form', [
            'pageTitle' => $target === null ? 'Add administrator — Admin' : 'Edit administrator — Admin',
            'user' => $user,
            'adminNav' => 'admins',
            'target' => $target,
            'formAction' => $target === null ? '/admin/admins' : '/admin/admins/' . (int)$target['id'],
            'error' => $error,
        ]);
    }
}
