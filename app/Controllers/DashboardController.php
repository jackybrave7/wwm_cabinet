<?php
declare(strict_types=1);

namespace Wwm\Controllers;

use Wwm\Auth\Session;
use Wwm\Models\User;
use Wwm\Services\AccessChecker;
use Wwm\Services\CourseCatalog;

final class DashboardController
{
    public function index(): void
    {
        $userId = Session::requireLogin();
        $user = User::findById(wwm_pdo(), $userId);
        $catalog = new CourseCatalog();
        $checker = new AccessChecker(wwm_pdo());
        $courses = $checker->coursesForDashboard($userId, $catalog);

        wwm_render('dashboard', [
            'pageTitle' => 'My courses',
            'user' => $user,
            'courses' => $courses,
        ]);
    }
}
