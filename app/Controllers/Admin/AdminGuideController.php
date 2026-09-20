<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\User;
use Wwm\Services\AdminGuide;

final class AdminGuideController
{
    public function index(): void
    {
        Session::requireAdminDashboard();
        $pdo = wwm_pdo();
        $userId = Session::userId();
        $user = $userId !== null ? User::findById($pdo, $userId) : null;
        if (!is_array($user)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $doc = AdminGuide::document();
        $sections = AdminGuide::sectionsForUser($user);
        $fragment = trim((string)($_GET['section'] ?? ''));
        if ($fragment !== '') {
            $allowed = array_map(static fn (array $s): string => (string)($s['id'] ?? ''), $sections);
            if (!in_array($fragment, $allowed, true)) {
                $fragment = '';
            }
        }

        wwm_render_admin('guide', [
            'title' => ($doc['meta']['title'] ?? 'Guide') . ' — Admin',
            'adminNav' => 'guide',
            'meta' => $doc['meta'],
            'sections' => $sections,
            'focusSection' => $fragment,
            'user' => $user,
        ]);
    }
}
