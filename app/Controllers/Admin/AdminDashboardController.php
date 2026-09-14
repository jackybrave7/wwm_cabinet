<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Models\User;
use Wwm\Services\AdminDashboardStats;
use Wwm\Services\AdminStats;
use Wwm\Services\YandexMetrikaReporting;

final class AdminDashboardController
{
    public function index(): void
    {
        $userId = Session::requireAdminDashboard();
        $user = User::findById(wwm_pdo(), $userId);

        $period = (string)($_GET['period'] ?? '7d');
        $group = (string)($_GET['group'] ?? 'day');
        $customFrom = isset($_GET['from']) ? (string)$_GET['from'] : null;
        $customTo = isset($_GET['to']) ? (string)$_GET['to'] : null;

        $pdo = wwm_pdo();
        $dashboard = new AdminDashboardStats($pdo, new AdminStats($pdo));
        $metrika = new YandexMetrikaReporting($pdo);

        $filters = $dashboard->resolveFilters($period, $group, $customFrom, $customTo);
        $snapshot = $dashboard->snapshot();

        $periodTotals = $dashboard->periodTotals($filters['from'], $filters['to']);
        $chart = $dashboard->chartSeries(
            $filters['from'],
            $filters['to'],
            $filters['group'],
            [],
        );

        wwm_render_admin('dashboard', [
            'pageTitle' => 'Dashboard — Admin',
            'adminNav' => 'dashboard',
            'user' => $user,
            'period' => $filters['period'],
            'group' => $filters['group'],
            'fromLabel' => $filters['from']->format('Y-m-d'),
            'toLabel' => $filters['to']->format('Y-m-d'),
            'customFrom' => $filters['period'] === 'custom' ? $filters['from']->format('Y-m-d') : ($customFrom ?? ''),
            'customTo' => $filters['period'] === 'custom' ? $filters['to']->format('Y-m-d') : ($customTo ?? ''),
            'snapshot' => $snapshot,
            'periodTotals' => $periodTotals,
            'chart' => $chart,
            'metrikaDeferred' => $metrika->isConfigured(),
            'metrikaConfigured' => $metrika->isConfigured(),
            'metrikaCounterId' => $metrika->counterId(),
            'metrikaVisitHosts' => $metrika->visitHostnamesLabel(),
        ]);
    }
}
