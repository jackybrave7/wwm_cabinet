<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\SiteSettings;
use Wwm\Models\User;

final class AdminSettingsController
{
    public function index(): void
    {
        $userId = Session::requireAdmin();
        $pdo = wwm_pdo();
        $user = User::findById($pdo, $userId);

        wwm_render_admin('settings', [
            'title' => 'Settings — Admin',
            'adminNav' => 'settings',
            'user' => $user,
            'analytics_head' => SiteSettings::analyticsHead($pdo),
            'analytics_body' => SiteSettings::analyticsBody($pdo),
            'message' => match ($_GET['saved'] ?? '') {
                '1' => 'Analytics codes saved.',
                default => null,
            },
            'error' => match ($_GET['error'] ?? '') {
                'csrf' => 'Session expired. Please try again.',
                default => null,
            },
        ]);
    }

    public function update(): void
    {
        Session::requireAdmin();

        if (!wwm_verify_csrf($_POST['csrf'] ?? null)) {
            wwm_redirect('/admin/settings?error=csrf');
        }

        $pdo = wwm_pdo();
        SiteSettings::set($pdo, SiteSettings::KEY_ANALYTICS_HEAD, trim((string)($_POST['analytics_head'] ?? '')));
        SiteSettings::set($pdo, SiteSettings::KEY_ANALYTICS_BODY, trim((string)($_POST['analytics_body'] ?? '')));

        wwm_redirect('/admin/settings?saved=1');
    }
}
