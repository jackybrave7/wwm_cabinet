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
    <?php if (!empty($webhook['includes_attribution'])): ?>
      <p class="field-hint" style="margin-bottom:12px">
        <strong>UTM / marketing:</strong> include <code>utm_*</code> and <code>advertising_channel_*</code> macros in the webhook
        (in the URL or POST body). The cabinet saves them on the student profile — no slow AVO API backfill needed.
        Always pass <code>{id_contact}</code>, <code>{id_account}</code>, and <code>{sum}</code> (paid) when AVO offers them.
      </p>
    <?php endif; ?>
    <pre class="email-webhook-sample" id="email-webhook-url"><?= wwm_escape($webhook['url']) ?></pre>
    <button type="button" class="btn btn-ghost btn-sm email-webhook-copy" data-copy-target="email-webhook-url">Copy URL</button>
    <?php if (!empty($webhook['post_sample'])): ?>
      <p class="field-hint" style="margin-top:16px;margin-bottom:8px">
        If AVO sends a <strong>POST</strong> webhook (form or JSON), use the same field names in the body:
      </p>
      <pre class="email-webhook-sample" id="email-webhook-body"><?= wwm_escape((string)$webhook['post_sample']) ?></pre>
      <button type="button" class="btn btn-ghost btn-sm email-webhook-copy" data-copy-target="email-webhook-body">Copy POST fields</button>
    <?php endif; ?>
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
