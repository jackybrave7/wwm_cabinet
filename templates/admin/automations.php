<?php
$rows = is_array($automations ?? null) ? $automations : [];
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Programming</p>
    <h1 class="page-title page-title-sm">Email automations</h1>
    <p class="field-hint">Business-process style drip flows inside the cabinet. New flows are <strong>draft (off)</strong> until you enable Active on each flow.</p>
  </div>
  <form method="post" action="/admin/automations/run" onsubmit="return confirm('Process all due automation steps now?');">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <button type="submit" class="btn btn-ghost btn-sm">Run due steps</button>
  </form>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$_GET['error']) ?></div>
<?php endif; ?>

<div class="admin-card">
  <div class="admin-table-wrap">
    <table class="admin-table admin-table-compact">
      <thead>
        <tr>
          <th>Flow</th>
          <th>Course</th>
          <th>Status</th>
          <th>Active runs</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows === []): ?>
          <tr><td colspan="5" class="field-hint">No automations. Run migrate.php after deploy to seed Elke demo funnel.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <?php $id = (int)$row['id']; ?>
          <tr>
            <td>
              <strong><?= wwm_escape((string)$row['title']) ?></strong><br>
              <span class="field-hint"><?= wwm_escape((string)$row['slug']) ?></span>
            </td>
            <td><code><?= wwm_escape((string)$row['course_slug']) ?></code></td>
            <td>
              <?php if (!empty($row['is_active'])): ?>
                <span class="badge badge-paid">Active</span>
              <?php else: ?>
                <span class="badge badge-draft">Draft</span>
              <?php endif; ?>
            </td>
            <td><?= (int)($row['active_runs'] ?? 0) ?></td>
            <td><a href="/admin/automations/<?= $id ?>" class="btn btn-ghost btn-sm">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="field-hint" style="margin-top:16px">Cron: <code>php scripts/run-email-automations.php</code> every 5–15 minutes.</p>
</div>
