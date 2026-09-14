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

        $period = (string)($_GET['period'] ?? '30d');
        $group = (string)($_GET['group'] ?? 'day');

        $pdo = wwm_pdo();
        $dashboard = new AdminDashboardStats($pdo, new AdminStats($pdo));
        $metrika = new YandexMetrikaReporting($pdo);

        $filters = $dashboard->resolveFilters($period, $group);
        $snapshot = $dashboard->snapshot();

        $periodTotals = $dashboard->periodTotals($filters['from'], $filters['to']);
        $trafficPeriod = $metrika->visitsForPeriod($filters['from'], $filters['to'], $filters['group']);
        $chart = $dashboard->chartSeries(
            $filters['from'],
            $filters['to'],
            $filters['group'],
            $trafficPeriod['buckets'] ?? [],
        );

        $metrikaLifetimeFrom = new \DateTimeImmutable('2018-01-01', new \DateTimeZone('UTC'));
        $trafficLifetime = $metrika->visitsTotal($metrikaLifetimeFrom, $filters['to']);

        $periodConversions = $dashboard->conversionRates(
            (int)($trafficPeriod['visits_total'] ?? 0),
            (int)$periodTotals['demo_grants'],
            (int)$periodTotals['paid_grants'],
        );

        $lifetimeGrants = $dashboard->lifetimeGrantTotals();
        $lifetimeConversions = $dashboard->conversionRates(
            (int)($trafficLifetime['visits_total'] ?? 0),
            (int)$lifetimeGrants['demo_grants'],
            (int)$lifetimeGrants['paid_grants'],
        );

        wwm_render_admin('dashboard', [
            'pageTitle' => 'Dashboard — Admin',
            'adminNav' => 'dashboard',
            'user' => $user,
            'period' => $filters['period'],
            'group' => $filters['group'],
            'fromLabel' => $filters['from']->format('Y-m-d'),
            'toLabel' => $filters['to']->format('Y-m-d'),
            'snapshot' => $snapshot,
            'periodTotals' => $periodTotals,
            'chart' => $chart,
            'trafficPeriod' => $trafficPeriod,
            'trafficLifetime' => $trafficLifetime,
            'periodConversions' => $periodConversions,
            'lifetimeConversions' => $lifetimeConversions,
            'metrikaConfigured' => $metrika->isConfigured(),
            'metrikaCounterId' => $metrika->counterId(),
        ]);
    }
}
