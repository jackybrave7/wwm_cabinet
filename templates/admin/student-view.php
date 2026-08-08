<?php
use Wwm\Services\StudentAttribution;

$formatDate = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('M j, Y H:i', $ts) : '—';
};
$id = (int)($student['id'] ?? 0);
$pct = $total_lessons > 0 ? min(100, (int)round($total_opened / $total_lessons * 100)) : 0;
$badgeClass = $access_label === 'Paid' ? 'badge-paid' : ($access_label === 'Demo' ? 'badge-demo' : 'badge-draft');
$periods = is_array($access_periods ?? null) ? $access_periods : [];
$accessCourses = is_array($access_courses ?? null) ? $access_courses : [];
$location = StudentAttribution::locationLabel($student);
$channel = StudentAttribution::channelLabel($student);
$channelDetail = StudentAttribution::channelDetail($student);
$lastLocation = StudentAttribution::locationLabel($student, false);
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Student profile</p>
    <h1 class="page-title page-title-sm"><?= wwm_escape((string)($student['name'] ?: $student['email'])) ?></h1>
    <p class="field-hint"><?= wwm_escape((string)$student['email']) ?> · joined <?= wwm_escape($formatDate((string)($student['created_at'] ?? ''))) ?></p>
  </div>
  <a href="/admin/students" class="btn btn-ghost">← All students</a>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-stats">
  <div class="admin-stat-card">
    <span class="admin-stat-label">Lessons opened</span>
    <strong class="admin-stat-value"><?= (int)$total_opened ?> <span class="admin-stat-note" style="display:inline;font-size:1rem">/ <?= (int)$total_lessons ?></span></strong>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Last activity</span>
    <strong class="admin-stat-value" style="font-size:1.35rem"><?= wwm_escape($formatDate($last_activity)) ?></strong>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Access</span>
    <strong class="admin-stat-value" style="font-size:1.35rem"><span class="badge <?= $badgeClass ?>"><?= wwm_escape($access_label) ?></span></strong>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Location</span>
    <strong class="admin-stat-value" style="font-size:1.1rem"><?= wwm_escape($location) ?></strong>
    <?php if ($lastLocation !== $location && $lastLocation !== '—'): ?>
      <span class="admin-stat-note">Last login: <?= wwm_escape($lastLocation) ?></span>
    <?php endif; ?>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Marketing channel</span>
    <strong class="admin-stat-value" style="font-size:1.1rem"><?= wwm_escape($channel) ?></strong>
    <?php if ($channelDetail !== null): ?>
      <span class="admin-stat-note"><?= wwm_escape($channelDetail) ?></span>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($avo_enabled)): ?>
<div class="admin-card" style="margin-bottom:24px">
  <h2>AVO sync</h2>
  <p class="field-hint" style="margin-bottom:12px">
    Contact ID: <?= $avo_contact_id !== null ? (int)$avo_contact_id : 'not found' ?>
    · logged in: <?= !empty($avo_logged_in_tagged) ? 'local ✓' : 'local —' ?>
    <?= $avo_has_logged_in_tag === true ? '· AVO ✓' : ($avo_has_logged_in_tag === false ? '· AVO —' : '') ?>
    · demo opened: <?= !empty($avo_demo_opened_tagged) ? 'local ✓' : 'local —' ?>
    <?= $avo_has_demo_opened_tag === true ? '· AVO ✓' : ($avo_has_demo_opened_tag === false ? '· AVO —' : '') ?>
  </p>
  <form method="post" action="/admin/students/<?= $id ?>/avo-sync" class="inline-form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <button type="submit" class="btn btn-ghost btn-sm">Resync AVO tags</button>
  </form>
</div>
<?php endif; ?>

<div class="admin-card">
  <h2>Course access</h2>
  <table class="admin-table admin-table-compact access-table">
    <thead>
      <tr>
        <th>Course</th>
        <th>Demo</th>
        <th>Full access</th>
        <th>Grant</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($accessCourses as $row): ?>
        <?php
          $c = $row['course'];
          $slug = (string)($c['slug'] ?? '');
          $demo = is_array($row['demo'] ?? null) ? $row['demo'] : ['active' => false, 'label' => 'None'];
          $paid = is_array($row['paid'] ?? null) ? $row['paid'] : ['active' => false, 'label' => 'None'];
          $published = !empty($row['published']);
        ?>
        <tr>
          <td>
            <strong><?= wwm_escape((string)($c['title'] ?? $slug)) ?></strong><br>
            <span class="field-hint"><?= wwm_escape($slug) ?><?= $published ? '' : ' · draft' ?></span>
          </td>
          <td>
            <span class="badge <?= $demo['active'] ? 'badge-demo' : 'badge-draft' ?>"><?= wwm_escape((string)$demo['label']) ?></span>
            <?php if ($demo['active']): ?>
              <form method="post" action="/admin/students/<?= $id ?>/access/revoke" class="inline-form">
                <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                <input type="hidden" name="course_slug" value="<?= wwm_escape($slug) ?>">
                <input type="hidden" name="access_type" value="demo">
                <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $paid['active'] ? 'badge-paid' : 'badge-draft' ?>"><?= wwm_escape((string)$paid['label']) ?></span>
            <?php if ($paid['active']): ?>
              <form method="post" action="/admin/students/<?= $id ?>/access/revoke" class="inline-form">
                <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                <input type="hidden" name="course_slug" value="<?= wwm_escape($slug) ?>">
                <input type="hidden" name="access_type" value="paid">
                <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="/admin/students/<?= $id ?>/access" class="access-grant-form">
              <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
              <input type="hidden" name="course_slug" value="<?= wwm_escape($slug) ?>">
              <select name="access_type" required aria-label="Access type">
                <option value="demo">Demo</option>
                <option value="paid">Full</option>
              </select>
              <select name="period" class="access-period-select" required aria-label="Duration">
                <?php foreach ($periods as $key => $label): ?>
                  <option value="<?= wwm_escape((string)$key) ?>"<?= $key === '30d' ? ' selected' : '' ?>><?= wwm_escape((string)$label) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="date" name="expires_date" class="access-expires-date" hidden aria-label="Access until" min="<?= wwm_escape(gmdate('Y-m-d')) ?>">
              <button type="submit" class="btn btn-primary btn-sm">Grant</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelectorAll('.access-grant-form').forEach((form) => {
  const period = form.querySelector('.access-period-select');
  const date = form.querySelector('.access-expires-date');
  if (!period || !date) {
    return;
  }
  const sync = () => {
    const custom = period.value === 'custom';
    date.hidden = !custom;
    date.required = custom;
    if (!custom) {
      date.value = '';
    }
  };
  period.addEventListener('change', sync);
  sync();
});
</script>

<?php foreach ($course_blocks as $block): ?>
  <?php
    $c = $block['course'];
    $slug = (string)($c['slug'] ?? '');
    $opened = (int)$block['opened'];
    $total = (int)$block['total'];
    $coursePct = $total > 0 ? min(100, (int)round($opened / $total * 100)) : 0;
  ?>
  <div class="admin-card">
    <div class="student-course-head">
      <div>
        <h2 style="margin:0"><?= wwm_escape((string)($c['title'] ?? $slug)) ?></h2>
        <span class="field-hint"><?= wwm_escape($slug) ?> · <?= wwm_escape((string)$block['access']) ?></span>
      </div>
      <div class="progress-cell" style="min-width:200px">
        <span class="progress-label"><?= $opened ?> / <?= $total ?> lessons</span>
        <div class="progress-bar"><span class="progress-bar-fill" style="width:<?= $coursePct ?>%"></span></div>
      </div>
    </div>
    <table class="admin-table admin-table-compact">
      <thead>
        <tr>
          <th style="width:40px"></th>
          <th style="width:48px">#</th>
          <th>Lesson</th>
          <th>First opened</th>
          <th>Last opened</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($block['lessons'] as $lesson): ?>
          <tr>
            <td>
              <?php if ($lesson['opened']): ?>
                <span class="lesson-opened" title="Opened">✓</span>
              <?php else: ?>
                <span class="lesson-not-opened">—</span>
              <?php endif; ?>
            </td>
            <td><?= (int)$lesson['num'] ?></td>
            <td><?= wwm_escape((string)$lesson['title']) ?></td>
            <td><?= wwm_escape($formatDate($lesson['first_opened_at'])) ?></td>
            <td><?= wwm_escape($formatDate($lesson['last_opened_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

<?php if ($course_blocks === []): ?>
  <div class="admin-card">
    <p class="field-hint">This student has no active course access yet. Use the table above to grant demo or full access.</p>
  </div>
<?php endif; ?>

<div class="admin-card admin-danger-zone">
  <h2>Danger zone</h2>
  <p class="field-hint">Permanently delete this student and all their access records. This cannot be undone.</p>
  <form method="post" action="/admin/students/<?= $id ?>/delete" class="inline-form" onsubmit="return confirm('Delete this student permanently?');">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <button type="submit" class="btn btn-danger btn-sm">Delete student</button>
  </form>
</div>
