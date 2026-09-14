<?php
/** @var array $snapshot */
/** @var array $periodTotals */
/** @var array $chart */
/** @var array $trafficPeriod */
/** @var array $trafficLifetime */
/** @var array $periodConversions */
/** @var array $lifetimeConversions */

function wwm_dash_pct(?float $pct): string
{
    if ($pct === null) {
        return '—';
    }

    return number_format($pct, 2, '.', '') . '%';
}

$chartMax = max(1, (int)($chart['max'] ?? 1));
$trafficOk = !empty($trafficPeriod['ok']);
$visitsPeriod = (int)($trafficPeriod['visits_total'] ?? 0);
$visitsLifetime = (int)($trafficLifetime['visits_total'] ?? 0);
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Administrator</p>
    <h1 class="page-title page-title-sm">Dashboard</h1>
    <p class="field-hint">Cabinet enrollments and Yandex Metrika<?php if ($metrikaCounterId > 0): ?> (counter <?= (int)$metrikaCounterId ?><?php if (!empty($metrikaVisitHosts)): ?>, visits: <?= wwm_escape((string)$metrikaVisitHosts) ?><?php endif; ?>)<?php endif; ?>.</p>
  </div>
</div>

<form method="get" action="/admin/dashboard" class="admin-dashboard-filters admin-card admin-card--compact">
  <label class="admin-filter-field">
    <span>Period</span>
    <select name="period" class="input">
      <option value="7d" <?= ($period ?? '') === '7d' ? 'selected' : '' ?>>Last 7 days</option>
      <option value="30d" <?= ($period ?? '30d') === '30d' ? 'selected' : '' ?>>Last 30 days</option>
      <option value="90d" <?= ($period ?? '') === '90d' ? 'selected' : '' ?>>Last 90 days</option>
      <option value="365d" <?= ($period ?? '') === '365d' ? 'selected' : '' ?>>Last 12 months</option>
      <option value="all" <?= ($period ?? '') === 'all' ? 'selected' : '' ?>>All time</option>
    </select>
  </label>
  <label class="admin-filter-field">
    <span>Chart grouping</span>
    <select name="group" class="input">
      <option value="day" <?= ($group ?? 'day') === 'day' ? 'selected' : '' ?>>By day</option>
      <option value="week" <?= ($group ?? '') === 'week' ? 'selected' : '' ?>>By week</option>
      <option value="month" <?= ($group ?? '') === 'month' ? 'selected' : '' ?>>By month</option>
    </select>
  </label>
  <button type="submit" class="btn btn-primary btn-sm">Apply</button>
  <span class="admin-filter-range field-hint"><?= wwm_escape($fromLabel ?? '') ?> — <?= wwm_escape($toLabel ?? '') ?></span>
</form>

<?php if (!$metrikaConfigured): ?>
  <div class="alert alert-warning" style="margin-top:14px">
    Traffic and conversion rates need Yandex Metrika API access.
    Add an OAuth token in <a href="/admin/settings">Analytics</a>
    (counter ID is taken from your Metrika snippet if omitted).
  </div>
<?php elseif (!$trafficOk && !empty($trafficPeriod['error'])): ?>
  <div class="alert alert-warning" style="margin-top:14px">
    Could not load Metrika data for this period. Check the OAuth token and that the token has access to counter <?= (int)$metrikaCounterId ?>.
  </div>
<?php endif; ?>

<h2 class="admin-section-title">Overall</h2>
<div class="admin-stats admin-stats--4">
  <div class="admin-stat-card">
    <span class="admin-stat-label">Students</span>
    <strong class="admin-stat-value"><?= (int)($snapshot['students_total'] ?? 0) ?></strong>
    <span class="admin-stat-note">accounts (non-admin)</span>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Full access</span>
    <strong class="admin-stat-value"><?= (int)($snapshot['paid_students_total'] ?? 0) ?></strong>
    <span class="admin-stat-note">active paid enrollments</span>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Demo active</span>
    <strong class="admin-stat-value"><?= (int)($snapshot['demo_active_total'] ?? 0) ?></strong>
    <span class="admin-stat-note">time-limited access now</span>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Visits (Metrika)</span>
    <strong class="admin-stat-value"><?= $visitsLifetime > 0 ? number_format($visitsLifetime) : '—' ?></strong>
    <span class="admin-stat-note">since 2018 · conv. demo <?= wwm_dash_pct($lifetimeConversions['visit_to_demo_pct'] ?? null) ?> · paid <?= wwm_dash_pct($lifetimeConversions['visit_to_paid_pct'] ?? null) ?></span>
  </div>
</div>

<h2 class="admin-section-title">Selected period</h2>
<p class="field-hint" style="margin:-4px 0 10px">Dates in Europe/Moscow. Purchases = live sales only (webhooks/admin), unique AVO order per day — legacy CSV/AVO import excluded. Demo includes imports.</p>
<div class="admin-stats admin-stats--4">
  <div class="admin-stat-card">
    <span class="admin-stat-label">Visits</span>
    <strong class="admin-stat-value"><?= $trafficOk ? number_format($visitsPeriod) : '—' ?></strong>
    <span class="admin-stat-note">Yandex Metrika</span>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">New students</span>
    <strong class="admin-stat-value"><?= (int)($periodTotals['new_students'] ?? 0) ?></strong>
    <span class="admin-stat-note">registrations</span>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Demo grants</span>
    <strong class="admin-stat-value"><?= (int)($periodTotals['demo_grants'] ?? 0) ?></strong>
    <span class="admin-stat-note">visit → demo <?= wwm_dash_pct($periodConversions['visit_to_demo_pct'] ?? null) ?></span>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Purchases</span>
    <strong class="admin-stat-value"><?= (int)($periodTotals['paid_grants'] ?? 0) ?></strong>
    <span class="admin-stat-note">visit → paid <?= wwm_dash_pct($periodConversions['visit_to_paid_pct'] ?? null) ?> · demo → paid <?= wwm_dash_pct($periodConversions['demo_to_paid_pct'] ?? null) ?></span>
  </div>
</div>

<div class="admin-card admin-dashboard-chart-card">
  <div class="admin-dashboard-chart-head">
    <h2>Visits, demos &amp; purchases</h2>
    <div class="admin-chart-legend">
      <span class="admin-chart-legend-item"><i class="admin-chart-swatch admin-chart-swatch--visits"></i> Visits</span>
      <span class="admin-chart-legend-item"><i class="admin-chart-swatch admin-chart-swatch--demo"></i> Demo</span>
      <span class="admin-chart-legend-item"><i class="admin-chart-swatch admin-chart-swatch--paid"></i> Purchases</span>
    </div>
  </div>
  <div class="admin-chart-scroll" data-admin-chart-tooltip>
    <div class="admin-chart-bars" role="img" aria-label="Chart of visits, demo grants and purchases">
      <?php foreach ($chart['labels'] ?? [] as $i => $label): ?>
        <?php
          $v = (int)($chart['visits'][$i] ?? 0);
          $d = (int)($chart['demo'][$i] ?? 0);
          $p = (int)($chart['paid'][$i] ?? 0);
          $vh = (int)round(160 * $v / $chartMax);
          $dh = (int)round(160 * $d / $chartMax);
          $ph = (int)round(160 * $p / $chartMax);
        ?>
        <div class="admin-chart-col"
             data-chart-label="<?= wwm_escape((string)$label) ?>"
             data-chart-visits="<?= $v ?>"
             data-chart-demo="<?= $d ?>"
             data-chart-paid="<?= $p ?>">
          <div class="admin-chart-cluster">
            <div class="admin-chart-bar admin-chart-bar--visits" style="height:<?= max(2, $vh) ?>px"></div>
            <div class="admin-chart-bar admin-chart-bar--demo" style="height:<?= max(2, $dh) ?>px"></div>
            <div class="admin-chart-bar admin-chart-bar--paid" style="height:<?= max(2, $ph) ?>px"></div>
          </div>
          <span class="admin-chart-label"><?= wwm_escape((string)$label) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="admin-chart-tooltip" hidden aria-hidden="true"></div>
  </div>
</div>

<script src="<?= wwm_escape(wwm_asset_url('js/admin-dashboard-chart.js')) ?>" defer></script>
