<?php
$statusLabels = [
    'draft' => 'Draft',
    'scheduled' => 'Scheduled',
    'sending' => 'Sending',
    'sent' => 'Sent',
    'cancelled' => 'Cancelled',
];
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Broadcasts</p>
    <h1 class="page-title page-title-sm">Email broadcasts</h1>
    <p class="field-hint">Marketing emails to students with unsubscribe headers (List-Unsubscribe, one-click).</p>
  </div>
  <a href="/admin/broadcasts/new" class="btn btn-primary btn-sm">New broadcast</a>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>

<div class="admin-card">
  <table class="admin-table admin-table-compact">
    <thead>
      <tr>
        <th>Title / subject</th>
        <th>Status</th>
        <th>Recipients</th>
        <th>Updated</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (($broadcasts ?? []) === []): ?>
        <tr><td colspan="5" class="field-hint">No broadcasts yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($broadcasts as $row): ?>
        <?php
          $id = (int)$row['id'];
          $status = (string)($row['status'] ?? 'draft');
          $label = $statusLabels[$status] ?? $status;
        ?>
        <tr>
          <td>
            <strong><?= wwm_escape((string)($row['title'] !== '' ? $row['title'] : $row['subject'])) ?></strong><br>
            <span class="field-hint"><?= wwm_escape((string)$row['subject']) ?></span>
          </td>
          <td><span class="badge badge-draft"><?= wwm_escape($label) ?></span></td>
          <td>
            <?php if ((int)($row['recipients_total'] ?? 0) > 0): ?>
              <?= (int)$row['sent_count'] ?> / <?= (int)$row['recipients_total'] ?>
              <?php if ((int)$row['skipped_unsub_count'] > 0): ?>
                <span class="field-hint">(<?= (int)$row['skipped_unsub_count'] ?> unsub)</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="field-hint">—</span>
            <?php endif; ?>
          </td>
          <td class="field-hint"><?= wwm_escape((string)($row['updated_at'] ?? '')) ?></td>
          <td class="admin-table-actions">
            <a href="/admin/broadcasts/<?= $id ?>" class="btn btn-ghost btn-sm">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
