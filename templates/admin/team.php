<?php
use Wwm\Services\AdminAccess;
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Super admin</p>
    <h1 class="page-title page-title-sm">Administrators</h1>
    <p class="field-hint">Delegate access: students, courses, broadcasts, or full super admin.</p>
  </div>
  <a href="/admin/admins/new" class="btn btn-primary btn-sm">Add administrator</a>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom:20px">
  <p class="field-hint" style="margin:0">
    Accounts in <code>admin_emails</code> (server config) always have full super admin access and are not listed below unless they also have DB flags.
  </p>
</div>

<div class="admin-card">
  <table class="admin-table admin-table-compact">
    <thead>
      <tr>
        <th>User</th>
        <th>Permissions</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (($admins ?? []) === []): ?>
        <tr><td colspan="3" class="field-hint">No delegated administrators yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($admins as $row): ?>
        <?php
          $id = (int)($row['id'] ?? 0);
          $protected = AdminAccess::isProtectedAccount($row);
          $labels = AdminAccess::permissionLabels($row);
        ?>
        <tr>
          <td>
            <strong><?= wwm_escape((string)($row['name'] ?: $row['email'])) ?></strong><br>
            <span class="field-hint"><?= wwm_escape((string)$row['email']) ?></span>
          </td>
          <td>
            <?php foreach ($labels as $label): ?>
              <span class="badge badge-draft" style="margin:0 6px 4px 0"><?= wwm_escape($label) ?></span>
            <?php endforeach; ?>
          </td>
          <td class="admin-table-actions">
            <?php if ($protected): ?>
              <span class="field-hint">Config</span>
            <?php else: ?>
              <a href="/admin/admins/<?= $id ?>" class="btn btn-ghost btn-sm">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
