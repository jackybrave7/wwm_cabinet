<?php
use Wwm\Models\EmailBroadcast;

$b = $broadcast ?? [];
$id = (int)($b['id'] ?? 0);
$status = (string)($b['status'] ?? '');
$canEdit = in_array($status, ['draft', 'scheduled'], true);
$canCancel = in_array($status, ['draft', 'scheduled', 'sending'], true);
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Broadcast #<?= $id ?></p>
    <h1 class="page-title page-title-sm"><?= wwm_escape((string)($b['title'] !== '' ? $b['title'] : $b['subject'])) ?></h1>
    <p class="field-hint">Status: <strong><?= wwm_escape($status) ?></strong>
      <?php if (!empty($b['scheduled_at'])): ?>
        · scheduled <?= wwm_escape((string)$b['scheduled_at']) ?> UTC
      <?php endif; ?>
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($canEdit): ?>
      <a href="/admin/broadcasts/<?= $id ?>/edit" class="btn btn-primary btn-sm">Edit</a>
    <?php endif; ?>
    <a href="/admin/broadcasts" class="btn btn-ghost btn-sm">← All</a>
  </div>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom:16px">
  <dl class="admin-kv">
    <dt>Subject</dt><dd><?= wwm_escape((string)$b['subject']) ?></dd>
    <dt>Audience</dt><dd><?= wwm_escape(EmailBroadcast::audienceLabel($b)) ?></dd>
    <dt>Recipients</dt>
    <dd>
      <?= (int)($b['sent_count'] ?? 0) ?> sent,
      <?= (int)($b['failed_count'] ?? 0) ?> failed,
      <?= (int)($b['skipped_unsub_count'] ?? 0) ?> skipped (unsubscribed),
      <?= (int)($b['recipients_total'] ?? 0) ?> total
    </dd>
  </dl>
</div>

<?php if ($status === 'sending'): ?>
  <div class="admin-card" style="margin-bottom:16px">
    <p class="field-hint">Sending in progress. This page processes a small batch on each load; keep refreshing or rely on cron (<code>scripts/run-broadcasts.php</code>).</p>
    <a href="/admin/broadcasts/<?= $id ?>" class="btn btn-ghost btn-sm">Refresh</a>
  </div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom:16px">
  <h2 class="admin-team-section-title">Plain text</h2>
  <pre class="admin-pre"><?= wwm_escape((string)$b['body_text']) ?></pre>
  <?php if (trim((string)($b['body_html'] ?? '')) !== ''): ?>
    <h2 class="admin-team-section-title" style="margin-top:20px">HTML</h2>
    <pre class="admin-pre"><?= wwm_escape((string)$b['body_html']) ?></pre>
  <?php endif; ?>
</div>

<?php if ($canCancel && $status !== 'sent'): ?>
  <div class="admin-card">
    <?php if (in_array($status, ['draft', 'scheduled'], true)): ?>
      <form method="post" action="/admin/broadcasts/<?= $id ?>/send" style="display:inline;margin-right:12px" onsubmit="return confirm('Send now?');">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-danger btn-sm">Send now</button>
      </form>
    <?php endif; ?>
    <form method="post" action="/admin/broadcasts/<?= $id ?>/cancel" style="display:inline" onsubmit="return confirm('Cancel this broadcast?');">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <button type="submit" class="btn btn-ghost btn-sm">Cancel</button>
    </form>
  </div>
<?php endif; ?>
