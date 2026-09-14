<?php
$statusLabels = [
    'draft' => 'Draft',
    'scheduled' => 'Scheduled',
    'sending' => 'Sending',
    'sent' => 'Sent',
    'cancelled' => 'Cancelled',
];
$engagementById = is_array($engagementById ?? null) ? $engagementById : [];

$formatWhen = static function (string $iso): string {
    $iso = trim($iso);
    if ($iso === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->format('Y-m-d H:i') . ' UTC';
    } catch (Throwable) {
        return $iso;
    }
};
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
  <div class="admin-table-wrap">
    <table class="admin-table admin-table-compact">
      <thead>
        <tr>
          <th>Title / subject</th>
          <th>Status</th>
          <th>Delivery</th>
          <th>Opens</th>
          <th>Clicks</th>
          <th>Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (($broadcasts ?? []) === []): ?>
          <tr><td colspan="7" class="field-hint">No broadcasts yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($broadcasts as $row): ?>
          <?php
            $id = (int)$row['id'];
            $status = (string)($row['status'] ?? 'draft');
            $label = $statusLabels[$status] ?? $status;
            $eng = $engagementById[$id] ?? [
                'tracked_sent' => 0,
                'unique_opens' => 0,
                'total_opens' => 0,
                'unique_clickers' => 0,
                'total_clicks' => 0,
            ];
            $sent = (int)($row['sent_count'] ?? 0);
            $failed = (int)($row['failed_count'] ?? 0);
            $skipped = (int)($row['skipped_unsub_count'] ?? 0);
            $total = (int)($row['recipients_total'] ?? 0);
            $tracked = (int)$eng['tracked_sent'];
            $openRate = $tracked > 0 ? round(100 * (int)$eng['unique_opens'] / $tracked, 1) : null;
            $clickRate = $tracked > 0 ? round(100 * (int)$eng['unique_clickers'] / $tracked, 1) : null;
          ?>
          <tr>
            <td>
              <strong><?= wwm_escape((string)($row['title'] !== '' ? $row['title'] : $row['subject'])) ?></strong><br>
              <span class="field-hint"><?= wwm_escape((string)$row['subject']) ?></span>
            </td>
            <td><span class="badge badge-draft"><?= wwm_escape($label) ?></span></td>
            <td>
              <?php if ($total > 0 || $sent > 0 || $status === 'sending'): ?>
                <strong><?= $sent ?></strong> / <?= $total ?> sent
                <?php if ($failed > 0): ?>
                  <br><span class="field-hint"><?= $failed ?> failed</span>
                <?php endif; ?>
                <?php if ($skipped > 0): ?>
                  <br><span class="field-hint"><?= $skipped ?> skipped (unsub)</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="field-hint">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($tracked > 0): ?>
                <strong><?= (int)$eng['unique_opens'] ?></strong>
                <?php if ($openRate !== null): ?>
                  <span class="field-hint">(<?= wwm_escape((string)$openRate) ?>%)</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="field-hint">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($tracked > 0): ?>
                <strong><?= (int)$eng['unique_clickers'] ?></strong>
                <?php if ($clickRate !== null): ?>
                  <span class="field-hint">(<?= wwm_escape((string)$clickRate) ?>%)</span>
                <?php endif; ?>
                <?php if ((int)$eng['total_clicks'] > (int)$eng['unique_clickers']): ?>
                  <br><span class="field-hint"><?= (int)$eng['total_clicks'] ?> total</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="field-hint">—</span>
              <?php endif; ?>
            </td>
            <td class="field-hint"><?= wwm_escape($formatWhen((string)($row['updated_at'] ?? ''))) ?></td>
            <td class="admin-table-actions">
              <a href="/admin/broadcasts/<?= $id ?>" class="btn btn-ghost btn-sm">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="field-hint" style="margin:12px 0 0">Open % and click % are based on tracked sends (HTML opens use a pixel; some clients block it).</p>
</div>
