<?php
use Wwm\Services\StudentAttribution;

$formatDate = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('M j, Y', $ts) : '—';
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
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
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
    <span class="admin-stat-label">Total students</span>
    <strong class="admin-stat-value"><?= (int)$totalStudents ?></strong>
  </div>
</div>

<div class="admin-card">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Student</th>
        <th>Location</th>
        <th>Channel</th>
        <th>Access</th>
        <th>Progress</th>
        <th>Last activity</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($students === []): ?>
        <tr><td colspan="7" style="color:var(--mute)">No students found.</td></tr>
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
          $channel = StudentAttribution::channelLabel($u);
          $channelDetail = StudentAttribution::channelDetail($u);
        ?>
        <tr>
          <td>
            <strong><?= wwm_escape((string)($u['name'] ?: $u['email'])) ?></strong><br>
            <span style="color:var(--mute);font-size:0.85rem"><?= wwm_escape((string)$u['email']) ?></span>
          </td>
          <td class="admin-meta-cell"><?= wwm_escape($location) ?></td>
          <td class="admin-meta-cell">
            <?= wwm_escape($channel) ?>
            <?php if ($channelDetail !== null): ?>
              <span class="admin-meta-sub"><?= wwm_escape($channelDetail) ?></span>
            <?php endif; ?>
          </td>
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
          <td><a href="/admin/students/<?= $id ?>" class="btn btn-ghost btn-sm">View</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (($totalPages ?? 1) > 1): ?>
    <?php
      $currentPage = (int)($page ?? 1);
      $query = [];
      if (($search ?? '') !== '') {
          $query['q'] = $search;
      }
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
