<?php
use Wwm\Models\User;
use Wwm\Services\AdminStudentListFilter;
use Wwm\Services\StudentAttribution;

$listFilter = $listFilter ?? AdminStudentListFilter::fromRequest();
$filterCourses = is_array($filterCourses ?? null) ? $filterCourses : [];
$filterQuery = $listFilter->queryParams();

$formatDate = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('M j, Y', $ts) : '—';
};
$formatDateTime = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('M j, Y H:i', $ts) : '—';
};
$sortHref = static function (string $column) use ($listFilter, $filterQuery): string {
    $query = $filterQuery;
    $query['sort'] = $column;
    $query['dir'] = $listFilter->sortDirForLink($column);
    unset($query['page']);

    return '/admin/students?' . http_build_query($query);
};
$sortIndicator = static function (string $column) use ($listFilter): string {
    if (!$listFilter->isSortedBy($column)) {
        return '';
    }

    return $listFilter->dir === 'asc' ? ' ↑' : ' ↓';
};
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Administrator</p>
    <h1 class="page-title page-title-sm">Students</h1>
  </div>
  <div class="top-actions">
    <form class="admin-toolbar" method="get" action="/admin/students">
      <input type="search" name="q" class="admin-search" placeholder="Search by name or email…" value="<?= wwm_escape($search ?? '') ?>" aria-label="Search students">
      <?php foreach ($filterQuery as $key => $value): ?>
        <?php if ($key === 'q') { continue; } ?>
        <input type="hidden" name="<?= wwm_escape($key) ?>" value="<?= wwm_escape($value) ?>">
      <?php endforeach; ?>
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
    <a href="/admin/students?<?= wwm_escape(http_build_query(array_merge($filterQuery, ['filters' => '1']))) ?>" class="btn btn-ghost btn-sm<?= $listFilter->isActive() ? ' is-active-filter' : '' ?>">Filter<?= $listFilter->isActive() ? ' · on' : '' ?></a>
    <?php if (!empty($avo_enabled)): ?>
      <form method="post" action="/admin/students/avo-sync-names" class="inline-form" onsubmit="return confirm('Update student names from AVO for all <?= (int)($totalStudents ?? 0) ?> students? This may take a minute.');">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Sync names from AVO</button>
      </form>
      <form method="post" action="/admin/students/avo-sync-utm" class="inline-form" onsubmit="return confirm('Pull UTM / marketing channels from AVO for all <?= (int)($totalStudents ?? 0) ?> students? This may take several minutes.');">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Sync UTM from AVO</button>
      </form>
    <?php endif; ?>
    <a href="/admin/students/new" class="btn btn-primary btn-sm">Add student</a>
  </div>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-stats">
  <div class="admin-stat-card">
    <span class="admin-stat-label"><?= $listFilter->isActive() ? 'Matching students' : 'Total students' ?></span>
    <strong class="admin-stat-value"><?= (int)$totalStudents ?></strong>
  </div>
</div>

<details class="admin-card admin-expander admin-filter-panel" id="student-filters"<?= ($listFilter->isActive() || (string)($_GET['filters'] ?? '') === '1') ? ' open' : '' ?>>
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>Filter students</h2>
      <span class="field-hint">Access, course, registration, activity, location, UTM</span>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
    <form method="get" action="/admin/students" class="admin-filter-form">
      <input type="hidden" name="filters" value="1">
      <input type="hidden" name="q" value="<?= wwm_escape($search ?? '') ?>">
      <input type="hidden" name="sort" value="<?= wwm_escape($listFilter->sort) ?>">
      <input type="hidden" name="dir" value="<?= wwm_escape($listFilter->dir) ?>">
      <div class="admin-filter-grid">
        <label class="field">
          <span>Access</span>
          <select name="access">
            <?php foreach (AdminStudentListFilter::ACCESS_OPTIONS as $value => $label): ?>
              <option value="<?= wwm_escape($value) ?>"<?= $listFilter->access === $value ? ' selected' : '' ?>><?= wwm_escape($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Course</span>
          <select name="course">
            <option value="">Any course</option>
            <?php foreach ($filterCourses as $course): ?>
              <?php $slug = (string)($course['slug'] ?? ''); ?>
              <option value="<?= wwm_escape($slug) ?>"<?= $listFilter->courseSlug === $slug ? ' selected' : '' ?>><?= wwm_escape((string)($course['title'] ?? $slug)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Course access type</span>
          <select name="course_access">
            <option value="any"<?= ($listFilter->courseAccess === '' || $listFilter->courseAccess === 'any') ? ' selected' : '' ?>>Any grant</option>
            <option value="paid"<?= $listFilter->courseAccess === 'paid' ? ' selected' : '' ?>>Paid (active)</option>
            <option value="demo"<?= $listFilter->courseAccess === 'demo' ? ' selected' : '' ?>>Demo (active)</option>
          </select>
        </label>
        <label class="field">
          <span>Registered from</span>
          <input type="date" name="registered_from" value="<?= wwm_escape($listFilter->registeredFrom) ?>">
        </label>
        <label class="field">
          <span>Registered to</span>
          <input type="date" name="registered_to" value="<?= wwm_escape($listFilter->registeredTo) ?>">
        </label>
        <label class="field">
          <span>Lesson activity</span>
          <select name="activity">
            <?php foreach (AdminStudentListFilter::ACTIVITY_OPTIONS as $value => $label): ?>
              <option value="<?= wwm_escape($value) ?>"<?= $listFilter->activity === $value ? ' selected' : '' ?>><?= wwm_escape($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Country</span>
          <input type="text" name="country" value="<?= wwm_escape($listFilter->country) ?>" placeholder="e.g. Germany" autocomplete="off">
        </label>
        <label class="field field-checkbox">
          <span>UTM</span>
          <label class="checkbox-inline">
            <input type="checkbox" name="has_utm" value="1"<?= $listFilter->hasUtm === '1' ? ' checked' : '' ?>>
            Has any UTM data
          </label>
        </label>
        <label class="field">
          <span>UTM source</span>
          <input type="text" name="utm_source" value="<?= wwm_escape($listFilter->utmSource) ?>" placeholder="contains…" autocomplete="off">
        </label>
        <label class="field">
          <span>UTM medium</span>
          <input type="text" name="utm_medium" value="<?= wwm_escape($listFilter->utmMedium) ?>" placeholder="contains…" autocomplete="off">
        </label>
        <label class="field">
          <span>UTM campaign</span>
          <input type="text" name="utm_campaign" value="<?= wwm_escape($listFilter->utmCampaign) ?>" placeholder="contains…" autocomplete="off">
        </label>
      </div>
      <div class="admin-filter-actions">
        <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
        <a href="/admin/students" class="btn btn-ghost btn-sm">Clear all</a>
      </div>
    </form>
  </div>
</details>

<div class="admin-card">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Student</th>
        <th class="admin-sort-th"><a href="<?= wwm_escape($sortHref('location')) ?>">Location<?= $sortIndicator('location') ?></a></th>
        <th class="admin-sort-th"><a href="<?= wwm_escape($sortHref('registered')) ?>">Registered<?= $sortIndicator('registered') ?></a></th>
        <th class="admin-sort-th"><a href="<?= wwm_escape($sortHref('access')) ?>">Access<?= $sortIndicator('access') ?></a></th>
        <th class="admin-sort-th"><a href="<?= wwm_escape($sortHref('progress')) ?>">Progress<?= $sortIndicator('progress') ?></a></th>
        <th class="admin-sort-th"><a href="<?= wwm_escape($sortHref('activity')) ?>">Last activity<?= $sortIndicator('activity') ?></a></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($students === []): ?>
        <tr><td colspan="6" style="color:var(--mute)">No students found.</td></tr>
      <?php endif; ?>
      <?php foreach ($students as $row): ?>
        <?php
          $u = $row['user'];
          $id = (int)($u['id'] ?? 0);
          $opened = (int)$row['opened'];
          $total = (int)$row['total'];
          $pct = $total > 0 ? min(100, (int)round($opened / $total * 100)) : 0;
          $label = (string)$row['access_label'];
          $badgeClass = $label === 'Paid' ? 'badge-paid' : ($label === 'Demo' ? 'badge-demo' : 'badge-draft');
          $location = StudentAttribution::locationLabel($u);
        ?>
        <tr>
          <td>
            <?php
              $displayName = (string)($u['name'] ?: $u['email']);
              $profileUrl = '/admin/students/' . $id;
            ?>
            <a href="<?= wwm_escape($profileUrl) ?>" class="admin-student-link"><strong><?= wwm_escape($displayName) ?></strong></a><br>
            <span style="color:var(--mute);font-size:0.85rem"><?= wwm_escape((string)$u['email']) ?></span>
          </td>
          <td class="admin-meta-cell"><?= wwm_escape($location) ?></td>
          <td class="admin-meta-cell"><?= wwm_escape($formatDateTime(User::registeredAtForDisplay($u))) ?></td>
          <td><span class="badge <?= $badgeClass ?>" style="margin:0"><?= wwm_escape($label) ?></span></td>
          <td class="progress-cell">
            <span class="progress-label"><?= $opened ?> / <?= $total ?></span>
            <div class="progress-bar"><span class="progress-bar-fill" style="width:<?= $pct ?>%"></span></div>
            <?php if ($row['courses'] !== []): ?>
              <span class="progress-meta">
                <?php foreach ($row['courses'] as $i => $c): ?>
                  <?= $i > 0 ? ' · ' : '' ?><?= wwm_escape($c['slug']) ?>: <?= (int)$c['opened'] ?>/<?= (int)$c['total'] ?>
                <?php endforeach; ?>
              </span>
            <?php endif; ?>
          </td>
          <td><?= wwm_escape($formatDate($row['last_activity'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (($totalPages ?? 1) > 1): ?>
    <?php
      $currentPage = (int)($page ?? 1);
      $query = $listFilter->queryParams();
      $pageUrl = static function (int $p) use ($query): string {
          $query['page'] = $p;
          return '/admin/students?' . http_build_query($query);
      };
    ?>
    <div class="admin-pagination">
      <?php if ($currentPage > 1): ?>
        <a href="<?= wwm_escape($pageUrl($currentPage - 1)) ?>" class="btn btn-ghost btn-sm">← Prev</a>
      <?php endif; ?>
      <span class="field-hint">Page <?= $currentPage ?> of <?= (int)$totalPages ?></span>
      <?php if ($currentPage < (int)$totalPages): ?>
        <a href="<?= wwm_escape($pageUrl($currentPage + 1)) ?>" class="btn btn-ghost btn-sm">Next →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
