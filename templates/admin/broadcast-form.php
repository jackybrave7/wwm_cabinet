<?php
$b = is_array($broadcast ?? null) ? $broadcast : [];
$isEdit = !empty($b['id']);
$audience = (string)($b['audience'] ?? $_POST['audience'] ?? 'all_students');
$scheduledLocal = '';
if (!empty($b['scheduled_at'])) {
    try {
        $dt = new DateTimeImmutable((string)$b['scheduled_at']);
        $dt = $dt->setTimezone(new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
        $scheduledLocal = $dt->format('Y-m-d\TH:i');
    } catch (Throwable) {
        $scheduledLocal = '';
    }
}
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Broadcasts</p>
    <h1 class="page-title page-title-sm"><?= $isEdit ? 'Edit broadcast' : 'New broadcast' ?></h1>
  </div>
  <a href="/admin/broadcasts" class="btn btn-ghost">← All broadcasts</a>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom:16px">
  <p class="field-hint" style="margin:0">
    Placeholders: <code>{{name}}</code>, <code>{{email}}</code>, <code>{{unsubscribe_url}}</code>, <code>{{base_url}}</code>.
    Plain text is required; HTML is optional. Unsubscribe link and List-Unsubscribe headers are added automatically.
    Estimated audience: <strong><?= (int)($audienceSize ?? 0) ?></strong> students (excludes admins and suppressed addresses).
  </p>
</div>

<div class="admin-card">
  <form method="post" action="<?= wwm_escape((string)$formAction) ?>" class="form admin-team-form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

    <label class="field">
      <span>Internal title <span class="field-hint">(optional)</span></span>
      <input type="text" name="title" value="<?= wwm_escape((string)($_POST['title'] ?? $b['title'] ?? '')) ?>">
    </label>

    <label class="field">
      <span>Email subject</span>
      <input type="text" name="subject" required value="<?= wwm_escape((string)($_POST['subject'] ?? $b['subject'] ?? '')) ?>">
    </label>

    <label class="field">
      <span>Audience</span>
      <select name="audience">
        <option value="all_students"<?= $audience === 'all_students' ? ' selected' : '' ?>>All student accounts</option>
        <option value="with_access"<?= $audience === 'with_access' ? ' selected' : '' ?>>Students with at least one course access</option>
      </select>
    </label>

    <label class="field">
      <span>Plain-text body</span>
      <textarea name="body_text" rows="12" required class="admin-textarea"><?= wwm_escape((string)($_POST['body_text'] ?? $b['body_text'] ?? '')) ?></textarea>
    </label>

    <label class="field">
      <span>HTML body <span class="field-hint">(optional)</span></span>
      <textarea name="body_html" rows="14" class="admin-textarea admin-textarea-mono"><?= wwm_escape((string)($_POST['body_html'] ?? $b['body_html'] ?? '')) ?></textarea>
    </label>

    <label class="field">
      <span>Schedule send <span class="field-hint">(local time, optional)</span></span>
      <input type="datetime-local" name="scheduled_at" value="<?= wwm_escape((string)($_POST['scheduled_at'] ?? $scheduledLocal)) ?>">
    </label>

    <div class="admin-form-footer" style="flex-wrap:wrap;gap:8px">
      <button type="submit" name="action" value="save" class="btn btn-primary">Save draft</button>
      <button type="submit" name="action" value="schedule" class="btn btn-ghost">Schedule</button>
      <?php if ($isEdit): ?>
        <button type="submit" name="action" value="send" class="btn btn-danger" onclick="return confirm('Send this broadcast now to all recipients in the audience?');">Send now</button>
      <?php endif; ?>
      <a href="/admin/broadcasts" class="btn btn-ghost">Cancel</a>
    </div>
  </form>

  <?php if ($isEdit): ?>
    <form method="post" action="/admin/broadcasts/<?= (int)$b['id'] ?>/test" class="admin-form-footer" style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border-subtle, #e8e8e8)">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <button type="submit" class="btn btn-ghost btn-sm">Send test to my email</button>
    </form>
  <?php endif; ?>
</div>
