<?php
/** @var list<array<string, mixed>> $templates */
/** @var bool $mailEnabled */
/** @var string $fromEmail */
/** @var bool $webhooksEnabled */
/** @var string $mailWebhookUrl */
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Administrator</p>
    <h1 class="page-title page-title-sm">Email templates</h1>
    <p class="field-hint">
      All transactional emails sent from the cabinet
      <?php if ($mailEnabled && $fromEmail !== ''): ?>
        via <?= wwm_escape($fromEmail) ?>
      <?php else: ?>
        (SMTP disabled in config)
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if (!$mailEnabled): ?>
  <div class="alert alert-error">Mail is disabled in config. Templates are shown for preview only.</div>
<?php endif; ?>

<div class="admin-card">
  <h2>Templates</h2>
  <table class="admin-table">
    <thead>
      <tr>
        <th>Template</th>
        <th>Category</th>
        <th>Trigger</th>
        <th>Format</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($templates as $template): ?>
        <tr>
          <td>
            <strong><?= wwm_escape((string)$template['label']) ?></strong>
            <div class="field-hint"><code><?= wwm_escape((string)$template['id']) ?></code></div>
            <div class="field-hint"><?= wwm_escape((string)$template['description']) ?></div>
          </td>
          <td><?= wwm_escape((string)$template['category']) ?></td>
          <td><?= wwm_escape((string)$template['trigger']) ?></td>
          <td><?= !empty($template['has_html']) ? 'HTML' : 'Plain text' ?></td>
          <td><a href="/admin/emails/<?= wwm_escape((string)$template['id']) ?>" class="btn btn-ghost btn-sm">Preview</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($webhooksEnabled): ?>
  <div class="admin-card">
    <h2>AVO webhook for reminders</h2>
    <p class="field-hint" style="margin-bottom:12px">
      Replace AVO email blocks with «Отправить вебхук». Same <code>token</code> as <code>/api/demo</code>.
    </p>
    <pre class="email-webhook-sample"><?= wwm_escape($mailWebhookUrl) ?>?token=YOUR_TOKEN&amp;template=reminder_demo_no_login&amp;email={email}&amp;name={name}&amp;course=elke-en&amp;id_contact={id_contact}</pre>
    <p class="field-hint" style="margin-top:12px">
      Templates: <code>reminder_demo_no_login</code>, <code>reminder_demo_no_lesson</code>, <code>reminder_demo_expiring</code>
    </p>
  </div>
<?php endif; ?>
