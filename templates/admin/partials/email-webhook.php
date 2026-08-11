<?php
/** @var array{url: string, token_label: string, endpoint: string}|null $webhook */
/** @var bool $webhooksEnabled */
?>
<?php if (!empty($webhook)): ?>
  <div class="admin-card">
    <h2>AVO webhook</h2>
    <p class="field-hint" style="margin-bottom:12px">
      Copy this URL and paste it into the AVO block «Отправить вебхук» as-is.
      Keep AVO macros like <code>{email}</code> unencoded — do not use <code>%7Bemail%7D</code>.
      Token from config: <code><?= wwm_escape($webhook['token_label']) ?></code>
      · endpoint <code><?= wwm_escape($webhook['endpoint']) ?></code>
    </p>
    <pre class="email-webhook-sample" id="email-webhook-url"><?= wwm_escape($webhook['url']) ?></pre>
    <button type="button" class="btn btn-ghost btn-sm email-webhook-copy" data-copy-target="email-webhook-url">Copy URL</button>
  </div>
<?php elseif (!empty($webhooksEnabled)): ?>
  <div class="admin-card">
    <h2>AVO webhook</h2>
    <p class="field-hint">This email is not sent from an AVO webhook. It is triggered inside the cabinet (login page, forgot password, or admin test).</p>
  </div>
<?php else: ?>
  <div class="admin-card">
    <h2>AVO webhook</h2>
    <p class="field-hint">Webhooks are disabled in config (<code>webhooks.enabled</code>).</p>
  </div>
<?php endif; ?>
