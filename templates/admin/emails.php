<?php
/** @var list<array<string, mixed>> $templates */
/** @var list<string> $customized */
/** @var bool $mailEnabled */
/** @var string $fromEmail */
/** @var bool $webhooksEnabled */
/** @var array<string, array{url: string, token_label: string, endpoint: string}|null> $templateWebhooks */
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
<?php if (($saveError ?? '') === 'save'): ?>
  <div class="alert alert-error">Could not save the template. Open it from this list with <strong>Edit</strong> and try again. If the HTML is very large, the server may reject the upload — shorten the template or raise <code>post_max_size</code> on hosting.</div>
<?php endif; ?>

<div class="admin-card">
  <h2>Templates</h2>
  <table class="admin-table">
    <thead>
      <tr>
        <th>Template</th>
        <th>Category</th>
        <th>AVO webhook</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($templates as $template): ?>
        <?php
          $templateId = (string)$template['id'];
          $webhook = $templateWebhooks[$templateId] ?? null;
        ?>
        <tr>
          <td>
            <strong><?= wwm_escape((string)$template['label']) ?></strong>
            <div class="field-hint"><code><?= wwm_escape($templateId) ?></code></div>
            <div class="field-hint"><?= wwm_escape((string)$template['description']) ?></div>
          </td>
          <td><?= wwm_escape((string)$template['category']) ?></td>
          <td>
            <?php if ($webhook !== null): ?>
              <div class="field-hint"><code><?= wwm_escape($webhook['token_label']) ?></code></div>
              <pre class="email-webhook-sample email-webhook-sample-compact" id="webhook-<?= wwm_escape($templateId) ?>"><?= wwm_escape($webhook['url']) ?></pre>
              <button type="button" class="btn btn-ghost btn-sm email-webhook-copy" data-copy-target="webhook-<?= wwm_escape($templateId) ?>">Copy URL</button>
            <?php else: ?>
              <span class="field-hint">Cabinet only</span>
            <?php endif; ?>
          </td>
          <td class="admin-table-actions">
            <a href="/admin/emails/<?= wwm_escape($templateId) ?>/edit" class="btn btn-primary btn-sm">Edit</a>
            <a href="/admin/emails/<?= wwm_escape($templateId) ?>" class="btn btn-ghost btn-sm">Preview</a>
            <?php if (in_array($templateId, $customized ?? [], true)): ?>
              <span class="badge badge-paid">Custom</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
