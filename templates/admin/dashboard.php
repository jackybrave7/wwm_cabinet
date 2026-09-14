<?php
/** @var array $snapshot */
/** @var array $periodTotals */
/** @var array $chart */
function wwm_dash_pct(?float $pct): string
{
    if ($pct === null) {
        return '—';
    }

    return number_format($pct, 2, '.', '') . '%';
}

$chartMax = max(1, (int)($chart['max'] ?? 1));
$metrikaDeferred = !empty($metrikaDeferred);
$periodDemos = (int)($periodTotals['demo_grants'] ?? 0);
$periodPaid = (int)($periodTotals['paid_grants'] ?? 0);
$demoToPaidPct = $periodDemos > 0 ? round(100 * $periodPaid / $periodDemos, 2) : null;
?>
<div id="admin-dashboard" data-admin-dashboard data-period="<?= wwm_escape((string)($period ?? '7d')) ?>" data-group="<?= wwm_escape((string)($group ?? 'day')) ?>" data-date-from="<?= wwm_escape((string)($customFrom ?? '')) ?>" data-date-to="<?= wwm_escape((string)($customTo ?? '')) ?>" data-metrika-deferred="<?= $metrikaDeferred ? '1' : '0' ?>" data-chart-max="<?= (int)$chartMax ?>">
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Administrator</p>
    <h1 class="page-title page-title-sm">Dashboard</h1>
    <p class="field-hint">Cabinet enrollments and Yandex Metrika<?php if ($metrikaCounterId > 0): ?> (counter <?= (int)$metrikaCounterId ?><?php if (!empty($metrikaVisitHosts)): ?>, visits: <?= wwm_escape((string)$metrikaVisitHosts) ?><?php endif; ?>)<?php endif; ?>.</p>
  </div>
</div>

<?php
$dashPeriod = $period ?? '7d';
$dashGroup = $group ?? 'day';
$periodOptions = [
    'yesterday' => 'Вчера',
    '7d' => '7 days',
    '30d' => '30 days',
    '90d' => '90 days',
    '365d' => '12 months',
    'all' => 'All time',
    'custom' => 'Свой период',
];
$customFromVal = (string)($customFrom ?? '');
$customToVal = (string)($customTo ?? '');
?>
<form method="get" action="/admin/dashboard" class="admin-dashboard-toolbar admin-card" data-admin-dashboard-filters>
  <div class="admin-dashboard-toolbar__row">
    <fieldset class="admin-period-pills">
      <legend class="admin-dashboard-toolbar__legend">Period</legend>
      <div class="admin-period-pills__list">
        <?php foreach ($periodOptions as $value => $label): ?>
          <label class="admin-period-pill<?= $dashPeriod === $value ? ' is-active' : '' ?>">
            <input type="radio" name="period" value="<?= wwm_escape($value) ?>"<?= $dashPeriod === $value ? ' checked' : '' ?><?= $value === 'custom' ? ' data-period-custom' : '' ?>>
            <span><?= wwm_escape($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="admin-dashboard-custom-range<?= $dashPeriod === 'custom' ? '' : ' is-hidden' ?>" data-admin-custom-range>
      <label class="admin-dashboard-custom-field">
        <span class="admin-dashboard-toolbar__legend">From</span>
        <input type="date" name="from" class="input input--dashboard" value="<?= wwm_escape($customFromVal) ?>"<?= $dashPeriod !== 'custom' ? ' disabled' : '' ?>>
      </label>
      <label class="admin-dashboard-custom-field">
        <span class="admin-dashboard-toolbar__legend">To</span>
        <input type="date" name="to" class="input input--dashboard" value="<?= wwm_escape($customToVal) ?>"<?= $dashPeriod !== 'custom' ? ' disabled' : '' ?>>
      </label>
    </div>
    <label class="admin-dashboard-toolbar__group<?= $dashPeriod === 'yesterday' ? ' is-disabled' : '' ?>">
      <span class="admin-dashboard-toolbar__legend">Chart grouping</span>
      <select name="group" class="input input--dashboard"<?= $dashPeriod === 'yesterday' ? ' disabled' : '' ?>>
        <option value="day" <?= $dashGroup === 'day' ? 'selected' : '' ?>>By day</option>
        <option value="week" <?= $dashGroup === 'week' ? 'selected' : '' ?>>By week</option>
        <option value="month" <?= $dashGroup === 'month' ? 'selected' : '' ?>>By month</option>
      </select>
    </label>
    <button type="submit" class="btn btn-primary btn-dashboard-apply">Apply</button>
  </div>
  <p class="admin-dashboard-toolbar__range">
    <span class="admin-dashboard-toolbar__range-label">Range</span>
    <strong><?= wwm_escape($fromLabel ?? '') ?> — <?= wwm_escape($toLabel ?? '') ?></strong>
    <span class="field-hint">Europe/Moscow</span>
  </p>
</form>

<?php if (!$metrikaConfigured): ?>
  <div class="alert alert-warning" style="margin-top:14px">
    Traffic and conversion rates need Yandex Metrika API access.
    Add an OAuth token in <a href="/admin/settings">Analytics</a>
    (counter ID is taken from your Metrika snippet if omitted).
  </div>
<?php endif; ?>
<div class="alert alert-warning" id="admin-dashboard-metrika-error" style="margin-top:14px;display:none"></div>

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
    <strong class="admin-stat-value<?= $metrikaDeferred ? ' admin-stat-value--loading' : '' ?>" data-dash-lifetime-visits><?= $metrikaDeferred ? '…' : '—' ?></strong>
    <span class="admin-stat-note" data-dash-lifetime-conv><?= $metrikaDeferred ? 'since 2018 · loading Metrika…' : 'since 2018 · configure Metrika in Analytics' ?></span>
  </div>
</div>

<h2 class="admin-section-title">Selected period</h2>
<p class="field-hint" style="margin:-4px 0 10px">Purchases = live sales only (webhooks/admin), unique AVO order per day. Demo = new student registrations with demo access (same date as Students list).</p>
<div class="admin-stats admin-stats--4">
  <div class="admin-stat-card">
    <span class="admin-stat-label">Visits</span>
    <strong class="admin-stat-value admin-stat-value--loading" data-dash-period-visits><?= $metrikaDeferred ? '…' : '—' ?></strong>
    <span class="admin-stat-note">Yandex Metrika</span>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">New students</span>
    <strong class="admin-stat-value"><?= (int)($periodTotals['new_students'] ?? 0) ?></strong>
    <span class="admin-stat-note">registrations</span>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Demo signups</span>
    <strong class="admin-stat-value"><?= (int)($periodTotals['demo_grants'] ?? 0) ?></strong>
    <span class="admin-stat-note" data-dash-period-conv-demo><?= $metrikaDeferred ? 'visit → demo …' : 'visit → demo —' ?></span>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Purchases</span>
    <strong class="admin-stat-value"><?= (int)($periodTotals['paid_grants'] ?? 0) ?></strong>
    <span class="admin-stat-note" data-dash-period-conv-paid><?= $metrikaDeferred ? 'visit → paid …' : 'visit → paid —' ?> · demo → paid <?= wwm_dash_pct($demoToPaidPct) ?></span>
  </div>
</div>

<div class="admin-card admin-dashboard-chart-card">
  <div class="admin-dashboard-chart-head">
    <h2>Visits, demos &amp; purchases</h2>
    <div class="admin-chart-legend">
      <span class="admin-chart-legend-item"><i class="admin-chart-swatch admin-chart-swatch--visits"></i> Visits</span>
      <span class="admin-chart-legend-item"><i class="admin-chart-swatch admin-chart-swatch--demo"></i> Demo signups</span>
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
            <div class="admin-chart-bar admin-chart-bar--visits<?= $metrikaDeferred ? ' admin-chart-bar--pending' : '' ?>" style="height:<?= max(2, $vh) ?>px"></div>
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

</div>

<script src="<?= wwm_escape(wwm_asset_url('js/admin-dashboard-chart.js')) ?>" defer></script>
<script src="<?= wwm_escape(wwm_asset_url('js/admin-dashboard-metrika.js')) ?>" defer></script>
<script>
document.querySelectorAll('[data-admin-dashboard-filters]').forEach(function (form) {
  var customRange = form.querySelector('[data-admin-custom-range]');
  var fromInput = form.querySelector('input[name="from"]');
  var toInput = form.querySelector('input[name="to"]');

  function syncCustomRange() {
    var customSelected = form.querySelector('input[data-period-custom]:checked') !== null;
    if (customRange) {
      customRange.classList.toggle('is-hidden', !customSelected);
    }
    if (fromInput) {
      fromInput.disabled = !customSelected;
      fromInput.required = customSelected;
    }
    if (toInput) {
      toInput.disabled = !customSelected;
      toInput.required = customSelected;
    }
  }

  form.querySelectorAll('.admin-period-pill input').forEach(function (input) {
    input.addEventListener('change', function () {
      syncCustomRange();
      if (input.hasAttribute('data-period-custom')) {
        return;
      }
      form.requestSubmit();
    });
  });
  syncCustomRange();
  form.querySelector('select[name="group"]')?.addEventListener('change', function () { form.requestSubmit(); });
});
</script>
