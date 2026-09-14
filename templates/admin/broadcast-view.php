<?php
use Wwm\Models\EmailBroadcast;

$b = $broadcast ?? [];
$id = (int)($b['id'] ?? 0);
$status = (string)($b['status'] ?? '');
$canEdit = in_array($status, ['draft', 'scheduled'], true);
$canCancel = in_array($status, ['draft', 'scheduled', 'sending'], true);
$eng = is_array($engagement ?? null) ? $engagement : [];
$trackedSent = (int)($eng['tracked_sent'] ?? 0);
$uniqueOpens = (int)($eng['unique_opens'] ?? 0);
$totalOpens = (int)($eng['total_opens'] ?? 0);
$uniqueClickers = (int)($eng['unique_clickers'] ?? 0);
$totalClicks = (int)($eng['total_clicks'] ?? 0);
$openRate = $trackedSent > 0 ? round(100 * $uniqueOpens / $trackedSent, 1) : 0.0;
$clickRate = $trackedSent > 0 ? round(100 * $uniqueClickers / $trackedSent, 1) : 0.0;
$linkStats = is_array($linkStats ?? null) ? $linkStats : [];
$recipientEngagement = is_array($recipientEngagement ?? null) ? $recipientEngagement : [];
$hasHtml = trim((string)($b['body_html'] ?? '')) !== '';
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

<?php if ($trackedSent > 0 || in_array($status, ['sending', 'sent'], true)): ?>
  <div class="admin-stats" style="margin-bottom:16px">
    <div class="admin-stat-card">
      <span class="admin-stat-label">Unique opens</span>
      <strong class="admin-stat-value"><?= $uniqueOpens ?></strong>
      <span class="field-hint"><?= wwm_escape((string)$openRate) ?>% of sent<?= $totalOpens > $uniqueOpens ? ' · ' . $totalOpens . ' total' : '' ?></span>
    </div>
    <div class="admin-stat-card">
      <span class="admin-stat-label">Recipients who clicked</span>
      <strong class="admin-stat-value"><?= $uniqueClickers ?></strong>
      <span class="field-hint"><?= wwm_escape((string)$clickRate) ?>% · <?= $totalClicks ?> click<?= $totalClicks === 1 ? '' : 's' ?></span>
    </div>
    <div class="admin-stat-card">
      <span class="admin-stat-label">Tracked sends</span>
      <strong class="admin-stat-value"><?= $trackedSent ?></strong>
      <span class="field-hint">Opens need HTML (pixel). Plain-only: clicks only.</span>
    </div>
  </div>

  <?php if ($linkStats !== []): ?>
    <div class="admin-card" style="margin-bottom:16px">
      <h2 class="admin-team-section-title">Links</h2>
      <div class="admin-table-wrap">
        <table class="admin-table admin-table-compact">
          <thead>
            <tr>
              <th>URL</th>
              <th>Unique clickers</th>
              <th>Total clicks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($linkStats as $link): ?>
              <tr>
                <td>
                  <a href="<?= wwm_escape((string)$link['target_url']) ?>" target="_blank" rel="noopener"><?= wwm_escape((string)($link['link_label'] ?: $link['target_url'])) ?></a>
                  <div class="field-hint"><?= wwm_escape((string)$link['target_url']) ?></div>
                </td>
                <td><?= (int)$link['unique_clickers'] ?></td>
                <td><?= (int)$link['total_clicks'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($recipientEngagement !== []): ?>
    <details class="admin-card admin-expander" style="margin-bottom:16px" open>
      <summary class="admin-expander-summary">
        <span class="admin-expander-summary-text">
          <h2>Per recipient</h2>
          <span class="field-hint">Delivery, opens, clicks (first <?= count($recipientEngagement) ?> rows)</span>
        </span>
        <span class="admin-expander-chevron" aria-hidden="true">▼</span>
      </summary>
      <div class="admin-expander-body">
        <div class="admin-table-wrap">
          <table class="admin-table admin-table-compact">
            <thead>
              <tr>
                <th>Email</th>
                <th>Delivery</th>
                <th>Opened</th>
                <th>Clicks</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recipientEngagement as $row): ?>
                <tr>
                  <td><?= wwm_escape((string)($row['email'] ?? '')) ?></td>
                  <td><span class="field-hint"><?= wwm_escape((string)($row['delivery_status'] ?? '')) ?></span></td>
                  <td>
                    <?php if (!empty($row['opened_at'])): ?>
                      <span class="badge badge-demo" style="margin:0">Yes</span>
                      <span class="field-hint"><?= wwm_escape((string)$row['opened_at']) ?><?= (int)($row['open_count'] ?? 0) > 1 ? ' · ' . (int)$row['open_count'] . '×' : '' ?></span>
                    <?php elseif (!$hasHtml): ?>
                      <span class="field-hint">n/a (plain)</span>
                    <?php else: ?>
                      <span class="field-hint">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= (int)($row['click_count'] ?? 0) > 0 ? (int)$row['click_count'] : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="field-hint" style="margin-top:12px">Same tracking as transactional emails: pixel opens may be blocked; link redirects are more reliable. Unsubscribe links are not tracked.</p>
      </div>
    </details>
  <?php endif; ?>
<?php endif; ?>

<?php if ($status === 'sending'): ?>
  <div class="admin-card" style="margin-bottom:16px">
    <p class="field-hint">Sending in progress. This page processes a small batch on each load; keep refreshing or rely on cron (<code>scripts/run-broadcasts.php</code>).</p>
    <a href="/admin/broadcasts/<?= $id ?>" class="btn btn-ghost btn-sm">Refresh</a>
  </div>
<?php endif; ?>

<?php
$previewVars = [
    '{{name}}' => 'Sample Student',
    '{{email}}' => 'student@example.com',
    '{{unsubscribe_url}}' => wwm_base_url() . '/email/unsubscribe?t=preview',
    '{{base_url}}' => wwm_base_url(),
];
$bodyHtmlRaw = trim((string)($b['body_html'] ?? ''));
$textPreview = str_replace(array_keys($previewVars), array_values($previewVars), (string)$b['body_text']);
$htmlPreview = $bodyHtmlRaw !== ''
    ? str_replace(array_keys($previewVars), array_values($previewVars), $bodyHtmlRaw)
    : '';
if ($htmlPreview !== '') {
    $htmlPreview = preg_replace('#\scontenteditable\s*=\s*("true"|"false"|true|false)#i', '', $htmlPreview) ?? $htmlPreview;
}
?>
<div class="admin-card" style="margin-bottom:16px">
  <h2 class="admin-team-section-title">Message</h2>
  <p class="field-hint" style="margin:-8px 0 16px">Preview with sample placeholders (not the sent copy).</p>

  <?php if ($htmlPreview !== ''): ?>
    <div class="email-preview-tabs">
      <button type="button" class="email-preview-tab is-active" data-broadcast-preview-tab="visual">Visual</button>
      <button type="button" class="email-preview-tab" data-broadcast-preview-tab="text">Plain text</button>
    </div>
    <div class="email-preview-panel is-active" data-broadcast-preview-panel="visual">
      <textarea id="broadcast-view-html-source" hidden readonly><?= wwm_textarea_raw($htmlPreview) ?></textarea>
      <iframe class="email-preview-frame" title="Broadcast visual preview" id="broadcast-view-html-frame"></iframe>
    </div>
    <div class="email-preview-panel" data-broadcast-preview-panel="text">
      <pre class="email-preview-text"><?= wwm_escape($textPreview) ?></pre>
    </div>
    <details class="admin-expander" style="margin-top:16px">
      <summary class="admin-expander-summary">
        <span class="admin-expander-summary-text">
          <span class="field-hint">HTML source</span>
        </span>
        <span class="admin-expander-chevron" aria-hidden="true">▼</span>
      </summary>
      <div class="admin-expander-body">
        <pre class="admin-pre"><?= wwm_escape($bodyHtmlRaw) ?></pre>
      </div>
    </details>
  <?php else: ?>
    <pre class="email-preview-text"><?= wwm_escape($textPreview) ?></pre>
  <?php endif; ?>
</div>

<?php if ($htmlPreview !== ''): ?>
<script>
(function () {
  const source = document.getElementById('broadcast-view-html-source');
  const frame = document.getElementById('broadcast-view-html-frame');
  if (source && frame) {
    frame.srcdoc = source.value;
  }
  document.querySelectorAll('[data-broadcast-preview-tab]').forEach((tab) => {
    tab.addEventListener('click', () => {
      const name = tab.getAttribute('data-broadcast-preview-tab');
      document.querySelectorAll('[data-broadcast-preview-tab]').forEach((el) => el.classList.remove('is-active'));
      document.querySelectorAll('[data-broadcast-preview-panel]').forEach((el) => el.classList.remove('is-active'));
      tab.classList.add('is-active');
      const panel = document.querySelector('[data-broadcast-preview-panel="' + name + '"]');
      if (panel) {
        panel.classList.add('is-active');
      }
    });
  });
})();
</script>
<?php endif; ?>

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
