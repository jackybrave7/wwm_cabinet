<?php
declare(strict_types=1);

namespace Wwm\Controllers\Admin;

use Wwm\Auth\Session;
use Wwm\Services\AdminDashboardStats;
use Wwm\Services\AdminStats;
use Wwm\Services\YandexMetrikaReporting;

final class AdminDashboardApiController
{
    public function metrika(): void
    {
        Session::requireAdminDashboard();

        $period = (string)($_GET['period'] ?? '7d');
        $group = (string)($_GET['group'] ?? 'day');

        $pdo = wwm_pdo();
        $dashboard = new AdminDashboardStats($pdo, new AdminStats($pdo));
        $metrika = new YandexMetrikaReporting($pdo);

        $filters = $dashboard->resolveFilters($period, $group);
        $periodTotals = $dashboard->periodTotals($filters['from'], $filters['to']);

        $trafficPeriod = $metrika->visitsForPeriod($filters['from'], $filters['to'], $filters['group']);
        $metrikaLifetimeFrom = new \DateTimeImmutable('2018-01-01', new \DateTimeZone('UTC'));
        $trafficLifetime = $metrika->visitsTotal($metrikaLifetimeFrom, $filters['to']);

        $chart = $dashboard->chartSeries(
            $filters['from'],
            $filters['to'],
            $filters['group'],
            $trafficPeriod['buckets'] ?? [],
        );

        $lifetimeGrants = $dashboard->lifetimeGrantTotals();

        $periodVisits = (int)($trafficPeriod['visits_total'] ?? 0);
        $lifetimeVisits = (int)($trafficLifetime['visits_total'] ?? 0);

        wwm_json_response(200, [
            'ok' => !empty($trafficPeriod['ok']),
            'error' => $trafficPeriod['error'] ?? null,
            'period_visits' => $periodVisits,
            'lifetime_visits' => $lifetimeVisits,
            'lifetime_ok' => !empty($trafficLifetime['ok']),
            'visits_series' => $chart['visits'] ?? [],
            'chart_max' => (int)($chart['max'] ?? 1),
            'period_conversions' => $dashboard->conversionRates(
                $periodVisits,
                (int)$periodTotals['demo_grants'],
                (int)$periodTotals['paid_grants'],
            ),
            'lifetime_conversions' => $dashboard->conversionRates(
                $lifetimeVisits,
                (int)$lifetimeGrants['demo_grants'],
                (int)$lifetimeGrants['paid_grants'],
            ),
        ]);
    }
}
