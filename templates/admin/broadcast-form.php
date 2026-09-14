<?php
$b = is_array($broadcast ?? null) ? $broadcast : [];
$isEdit = !empty($b['id']);
$audience = (string)($b['audience'] ?? $_POST['audience'] ?? 'all_students');
$contentMode = (string)($contentMode ?? 'plain');
$bodyText = (string)($_POST['body_text'] ?? $b['body_text'] ?? '');
$bodyHtml = (string)($_POST['body_html'] ?? $b['body_html'] ?? '');
$listFilter = $listFilter ?? \Wwm\Services\AdminStudentListFilter::fromArray([]);
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
$variables = ['{{name}}', '{{email}}', '{{unsubscribe_url}}', '{{base_url}}'];
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
    Unsubscribe link and List-Unsubscribe headers are added automatically.
    Estimated recipients: <strong id="broadcast-audience-count"><?= (int)($audienceSize ?? 0) ?></strong>
    <span class="field-hint">(excludes admins and suppressed addresses)</span>
  </p>
</div>

<div class="admin-card">
  <form method="post" action="<?= wwm_escape((string)$formAction) ?>" class="form admin-team-form" id="broadcast-editor-form">
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
      <select name="audience" id="broadcast-audience-select">
        <option value="all_students"<?= $audience === 'all_students' ? ' selected' : '' ?>>All student accounts</option>
        <option value="with_access"<?= $audience === 'with_access' ? ' selected' : '' ?>>Students with at least one course access</option>
        <option value="filtered"<?= $audience === 'filtered' ? ' selected' : '' ?>>Custom student filter</option>
      </select>
    </label>

    <?php require __DIR__ . '/partials/broadcast-audience-filter.php'; ?>

    <div class="admin-card" style="margin:20px 0;padding:16px">
      <div class="email-editor-toolbar-row">
        <h2 style="margin:0">Message</h2>
        <div class="broadcast-format-toggle" role="group" aria-label="Message format">
          <label class="broadcast-format-option">
            <input type="radio" name="content_mode" value="plain"<?= $contentMode === 'plain' ? ' checked' : '' ?> data-broadcast-format>
            Plain text
          </label>
          <label class="broadcast-format-option">
            <input type="radio" name="content_mode" value="html"<?= $contentMode === 'html' ? ' checked' : '' ?> data-broadcast-format>
            HTML email
          </label>
        </div>
      </div>

      <div class="email-editor-tabs broadcast-editor-tabs" role="tablist">
        <button type="button" class="email-editor-tab is-active" data-tab="text">Plain text</button>
        <button type="button" class="email-editor-tab broadcast-html-only" data-tab="visual" hidden>Visual</button>
        <button type="button" class="email-editor-tab broadcast-html-only" data-tab="html" hidden>HTML</button>
        <button type="button" class="email-editor-tab broadcast-html-only" data-tab="preview" hidden>Preview</button>
      </div>

      <div class="email-editor-panel is-active" data-panel="text">
        <textarea name="body_text" id="broadcast-text-input" class="email-text-input" rows="14" spellcheck="false"><?= wwm_escape($bodyText) ?></textarea>
      </div>

      <div class="email-editor-panel" data-panel="visual">
        <div class="email-visual-toolbar">
          <button type="button" data-cmd="bold"><strong>B</strong></button>
          <button type="button" data-cmd="italic"><em>I</em></button>
          <button type="button" data-cmd="underline"><u>U</u></button>
          <button type="button" data-cmd="insertUnorderedList">• List</button>
          <button type="button" data-cmd="createLink">Link</button>
          <button type="button" data-cmd="formatBlock" data-value="p">P</button>
        </div>
        <iframe class="email-visual-frame" id="broadcast-visual-frame" title="Visual editor" height="420"></iframe>
      </div>

      <div class="email-editor-panel" data-panel="html">
        <div class="email-html-toolbar">
          <button type="button" class="btn btn-ghost btn-sm" id="broadcast-html-format">Format HTML</button>
        </div>
        <div class="email-html-shell" id="broadcast-html-shell">
          <pre class="email-html-highlight" id="broadcast-html-highlight" aria-hidden="true"><code></code></pre>
          <textarea name="body_html" id="broadcast-html-input" class="email-html-input" rows="18" spellcheck="false"><?= wwm_textarea_raw($bodyHtml) ?></textarea>
        </div>
      </div>

      <div class="email-editor-panel" data-panel="preview">
        <iframe class="email-preview-frame" id="broadcast-preview-frame" title="Preview" height="420"></iframe>
        <pre class="email-preview-text" id="broadcast-preview-text"></pre>
      </div>

      <div class="email-variable-list" style="margin-top:16px">
        <?php foreach ($variables as $variable): ?>
          <button type="button" class="email-variable-chip" data-variable="<?= wwm_escape($variable) ?>"><?= wwm_escape($variable) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

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

<script type="application/json" id="broadcast-html-seed"><?= wwm_json_for_script($bodyHtml) ?></script>
<script>
window.__broadcastEditor = {
  contentMode: <?= wwm_json_for_script($contentMode) ?>,
  previewVars: <?= wwm_json_for_script([
    'name' => 'Sample Student',
    'email' => 'student@example.com',
    'unsubscribe_url' => wwm_base_url() . '/email/unsubscribe?t=sample',
    'base_url' => wwm_base_url(),
  ]) ?>
};
</script>
