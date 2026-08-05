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
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
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
                'code' => 403,
                'message' => 'Admin access required.',
            ]);
            exit;
        }
        return $userId;
    }
}
