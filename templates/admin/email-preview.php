<?php
/** @var array<string, mixed> $template */
/** @var array{subject: string, text: string, html: ?string} $message */
/** @var string $mailWebhookUrl */
$templateId = (string)($template['id'] ?? '');
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Email preview</p>
    <h1 class="page-title page-title-sm"><?= wwm_escape((string)($template['label'] ?? 'Template')) ?></h1>
    <p class="field-hint"><code><?= wwm_escape($templateId) ?></code> · <?= wwm_escape((string)($template['trigger'] ?? '')) ?></p>
  </div>
  <a href="/admin/emails" class="btn btn-ghost">← All templates</a>
</div>

<div class="admin-card">
  <h2>Subject</h2>
  <p class="email-preview-subject"><?= wwm_escape((string)$message['subject']) ?></p>
</div>

<?php if (!empty($template['webhook'])): ?>
  <div class="admin-card">
    <h2>AVO webhook</h2>
    <pre class="email-webhook-sample"><?= wwm_escape($mailWebhookUrl) ?>?token=YOUR_TOKEN&amp;template=<?= wwm_escape($templateId) ?>&amp;email={email}&amp;name={name}&amp;course=elke-en&amp;id_contact={id_contact}</pre>
  </div>
<?php endif; ?>

<div class="admin-card">
  <div class="email-preview-tabs">
    <?php if (!empty($message['html'])): ?>
      <button type="button" class="email-preview-tab is-active" data-tab="html">HTML</button>
    <?php endif; ?>
    <button type="button" class="email-preview-tab<?= empty($message['html']) ? ' is-active' : '' ?>" data-tab="text">Plain text</button>
  </div>

  <?php if (!empty($message['html'])): ?>
    <div class="email-preview-panel is-active" data-panel="html">
      <iframe class="email-preview-frame" title="HTML email preview" srcdoc="<?= wwm_escape((string)$message['html']) ?>"></iframe>
    </div>
  <?php endif; ?>

  <div class="email-preview-panel<?= empty($message['html']) ? ' is-active' : '' ?>" data-panel="text">
    <pre class="email-preview-text"><?= wwm_escape((string)$message['text']) ?></pre>
  </div>
</div>

<script>
document.querySelectorAll('.email-preview-tab').forEach((tab) => {
  tab.addEventListener('click', () => {
    const name = tab.getAttribute('data-tab');
    document.querySelectorAll('.email-preview-tab').forEach((el) => el.classList.remove('is-active'));
    document.querySelectorAll('.email-preview-panel').forEach((el) => el.classList.remove('is-active'));
    tab.classList.add('is-active');
    const panel = document.querySelector('.email-preview-panel[data-panel="' + name + '"]');
    if (panel) panel.classList.add('is-active');
  });
});
</script>
