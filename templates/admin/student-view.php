<?php
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
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Student profile</p>
    <h1 class="page-title page-title-sm"><?= wwm_escape((string)($student['name'] ?: $student['email'])) ?></h1>
    <p class="field-hint"><?= wwm_escape((string)$student['email']) ?> · joined <?= wwm_escape($formatDate((string)($student['created_at'] ?? ''))) ?></p>
  </div>
  <a href="/admin/students" class="btn btn-ghost">← All students</a>
</div>

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
</div>

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
    <p class="field-hint">This student has no active course access.</p>
  </div>
<?php endif; ?>
