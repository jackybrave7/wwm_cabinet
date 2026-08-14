<?php
/** @var array<string, mixed> $template */
/** @var array{subject: string, text: string, html: ?string, customized: bool} $draft */
/** @var list<string> $variables */
/** @var array{url: string, token_label: string, endpoint: string}|null $webhook */
/** @var bool $webhooksEnabled */
$templateId = (string)($template['id'] ?? '');
$hasHtml = !empty($template['has_html']) || !empty($draft['html']);
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Edit email</p>
    <h1 class="page-title page-title-sm"><?= wwm_escape((string)($template['label'] ?? 'Template')) ?></h1>
    <p class="field-hint">
      <code><?= wwm_escape($templateId) ?></code>
      <?php if (!empty($draft['customized'])): ?>
        · <span class="badge badge-paid">Customized</span>
      <?php else: ?>
        · default template with placeholders
      <?php endif; ?>
    </p>
  </div>
  <div class="top-actions">
    <a href="/admin/emails/<?= wwm_escape($templateId) ?>" class="btn btn-ghost btn-sm">Preview</a>
    <a href="/admin/emails" class="btn btn-ghost btn-sm">← All templates</a>
  </div>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<?php if ($templateId === ''): ?>
  <div class="alert alert-error">Template id is missing. <a href="/admin/emails">Back to templates</a></div>
<?php else: ?>

<form method="post" action="/admin/emails/<?= wwm_escape($templateId) ?>/save" class="email-editor-form" id="email-editor-form" data-template-id="<?= wwm_escape($templateId) ?>">
  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

  <div class="admin-card">
    <h2>Subject</h2>
    <input type="text" name="subject" class="admin-input" value="<?= wwm_escape((string)$draft['subject']) ?>" required>
  </div>

  <div class="admin-card">
    <div class="email-editor-toolbar-row">
      <h2>Content</h2>
      <div class="email-editor-tabs" role="tablist">
        <?php if ($hasHtml): ?>
          <button type="button" class="email-editor-tab is-active" data-tab="visual">Visual</button>
          <button type="button" class="email-editor-tab" data-tab="html">HTML</button>
        <?php endif; ?>
        <button type="button" class="email-editor-tab<?= $hasHtml ? '' : ' is-active' ?>" data-tab="text">Plain text</button>
        <button type="button" class="email-editor-tab" data-tab="preview">Preview</button>
      </div>
    </div>

    <?php if ($hasHtml): ?>
      <div class="email-editor-panel is-active" data-panel="visual">
        <div class="email-visual-toolbar">
          <button type="button" data-cmd="bold"><strong>B</strong></button>
          <button type="button" data-cmd="italic"><em>I</em></button>
          <button type="button" data-cmd="underline"><u>U</u></button>
          <button type="button" data-cmd="insertUnorderedList">• List</button>
          <button type="button" data-cmd="createLink">Link</button>
          <button type="button" data-cmd="insertImage">Image</button>
          <button type="button" data-cmd="formatBlock" data-value="h1">H1</button>
          <button type="button" data-cmd="formatBlock" data-value="h2">H2</button>
          <button type="button" data-cmd="formatBlock" data-value="p">P</button>
        </div>
        <iframe class="email-visual-frame" id="email-visual-frame" title="Visual email editor"></iframe>
        <p class="field-hint" style="margin-top:10px">Visual and HTML tabs show <code>{{placeholders}}</code>. Open <strong>Preview</strong> to see sample data.</p>
      </div>

      <div class="email-editor-panel" data-panel="html">
        <div class="email-html-toolbar">
          <button type="button" class="btn btn-ghost btn-sm" id="email-html-format">Format HTML</button>
        </div>
        <div class="email-html-shell" id="email-html-shell">
          <pre class="email-html-highlight" id="email-html-highlight" aria-hidden="true"><code></code></pre>
          <textarea name="body_html" id="email-html-input" class="email-html-input" rows="24" spellcheck="false"><?= wwm_escape((string)($draft['html'] ?? '')) ?></textarea>
        </div>
      </div>
    <?php else: ?>
      <input type="hidden" name="body_html" id="email-html-input" value="">
    <?php endif; ?>

    <div class="email-editor-panel<?= $hasHtml ? '' : ' is-active' ?>" data-panel="text">
      <textarea name="body_text" id="email-text-input" class="email-text-input" rows="16" spellcheck="false"><?= wwm_escape((string)$draft['text']) ?></textarea>
    </div>

    <div class="email-editor-panel" data-panel="preview">
      <iframe class="email-preview-frame" id="email-preview-frame" title="Email preview"></iframe>
      <pre class="email-preview-text" id="email-preview-text"></pre>
    </div>
  </div>

  <div class="admin-card">
    <h2>Variables</h2>
    <p class="field-hint" style="margin-bottom:12px">Use these placeholders in subject, HTML, and plain text. They are replaced when the email is sent.</p>
    <div class="email-variable-list">
      <?php foreach ($variables as $variable): ?>
        <button type="button" class="email-variable-chip" data-variable="<?= wwm_escape($variable) ?>"><?= wwm_escape($variable) ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <?php require __DIR__ . '/partials/email-webhook.php'; ?>

  <div class="email-editor-actions">
    <button type="submit" class="btn btn-primary">Save template</button>
    <button type="button" class="btn btn-ghost" id="email-preview-refresh">Refresh preview</button>
  </div>
</form>

<?php if (!empty($draft['customized'])): ?>
  <form method="post" action="/admin/emails/<?= wwm_escape($templateId) ?>/reset" class="email-reset-form" onsubmit="return confirm('Reset this template to the built-in default?');">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <button type="submit" class="btn btn-ghost btn-sm">Reset to default</button>
  </form>
<?php endif; ?>

<script>
window.__emailEditor = {
  hasHtml: <?= $hasHtml ? 'true' : 'false' ?>,
  initialHtml: <?= json_encode((string)($draft['html'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  initialText: <?= json_encode((string)$draft['text'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  previewVars: <?= json_encode(\Wwm\Services\EmailTemplateCatalog::sampleContext($templateId), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  templateId: <?= json_encode($templateId, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<?php endif; ?>
