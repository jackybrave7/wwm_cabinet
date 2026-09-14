<?php
declare(strict_types=1);

namespace Wwm\Auth;

final class Session
{
    public static function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return is_int($id) ? $id : (is_numeric($id) ? (int)$id : null);
    }

    public static function login(int $userId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        session_write_close();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(): int
    {
        $id = self::userId();
        if ($id === null) {
            $next = $_SERVER['REQUEST_URI'] ?? '/';
            wwm_redirect('/login?next=' . rawurlencode($next));
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $id;
    }

    public static function requireAdmin(): int
    {
        $userId = self::requireLogin();
        $user = \Wwm\Models\User::findById(wwm_pdo(), $userId);
        if ($user === null || !\Wwm\Models\User::isAdmin($user)) {
            http_response_code(403);
            wwm_render('error', [
                'pageTitle' => 'Forbidden',
                'user' => $user,
                'code' => 403,
                'message' => 'Admin access required. Sign in with an administrator account.',
            ]);
            exit;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $userId;
    }

    public static function requireAdminStudents(): int
    {
        return self::requireAdminCapability(
            static fn (array $user): bool => \Wwm\Services\AdminAccess::canManageStudents($user),
            'You do not have permission to manage students.'
        );
    }

    public static function requireAdminCourses(): int
    {
        return self::requireAdminCapability(
            static fn (array $user): bool => \Wwm\Services\AdminAccess::canManageCourses($user),
            'You do not have permission to edit courses.'
        );
    }

    public static function requireSuperAdmin(): int
    {
        return self::requireAdminCapability(
            static fn (array $user): bool => \Wwm\Services\AdminAccess::canManageAdmins($user),
            'Super administrator access required.'
        );
    }

    public static function requireAdminBroadcasts(): int
    {
        return self::requireAdminCapability(
            static fn (array $user): bool => \Wwm\Services\AdminAccess::canManageBroadcasts($user),
            'You do not have permission to manage email broadcasts.'
        );
    }

    public static function requireAdminDashboard(): int
    {
        $userId = self::requireLogin();
        $user = \Wwm\Models\User::findById(wwm_pdo(), $userId);
        if ($user === null || !\Wwm\Services\AdminAccess::hasAdminPanelAccess($user)) {
            http_response_code(403);
            wwm_render('error', [
                'pageTitle' => 'Forbidden',
                'user' => $user,
                'code' => 403,
                'message' => 'Administrator access required.',
            ]);
            exit;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $userId;
    }

    /**
     * @param callable(array<string, mixed>): bool $check
     */
    private static function requireAdminCapability(callable $check, string $message): int
    {
        $userId = self::requireLogin();
        $user = \Wwm\Models\User::findById(wwm_pdo(), $userId);
        if ($user === null || !\Wwm\Services\AdminAccess::hasAdminPanelAccess($user) || !$check($user)) {
            http_response_code(403);
            wwm_render('error', [
                'pageTitle' => 'Forbidden',
                'user' => $user,
                'code' => 403,
                'message' => $message,
            ]);
            exit;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $userId;
    }
}
