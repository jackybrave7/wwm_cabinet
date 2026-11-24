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
$formatWhen = static function (?string $iso): string {
    $iso = trim((string)$iso);
    if ($iso === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($iso))->format('Y-m-d H:i') . ' UTC';
    } catch (Throwable) {
        return $iso;
    }
};
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Broadcast #<?= $id ?></p>
    <h1 class="page-title page-title-sm"><?= wwm_escape((string)($b['title'] !== '' ? $b['title'] : $b['subject'])) ?></h1>
    <p class="field-hint">Status: <span class="badge <?= wwm_escape(EmailBroadcast::statusBadgeClass($status)) ?>"><?= wwm_escape(EmailBroadcast::statusLabel($status)) ?></span>
      <?php if ($status === 'scheduled' && !empty($b['scheduled_at'])): ?>
        · <?= wwm_escape($formatWhen((string)$b['scheduled_at'])) ?>
      <?php endif; ?>
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($canEdit): ?>
      <a href="/admin/broadcasts/<?= $id ?>/edit" class="btn btn-primary btn-sm">Edit</a>
    <?php endif; ?>
    <form method="post" action="/admin/broadcasts/<?= $id ?>/copy" class="inline-form" data-confirm-title="Copy broadcast?" data-confirm="Create a new draft from this broadcast? Nothing will be sent." data-confirm-ok="Copy">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <button type="submit" class="btn btn-ghost btn-sm">Copy</button>
    </form>
    <?php if ($canEdit): ?>
    <form method="post" action="/admin/broadcasts/<?= $id ?>/delete" class="inline-form" data-confirm-danger data-confirm-title="Delete broadcast?" data-confirm="Delete this unsent broadcast? This cannot be undone." data-confirm-ok="Delete">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <button type="submit" class="btn btn-ghost btn-sm">Delete</button>
    </form>
    <?php endif; ?>
    <a href="/admin/broadcasts" class="btn btn-ghost btn-sm">← All</a>
  </div>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-student-profile admin-broadcast-detail">

<div class="admin-card" style="margin-bottom:16px">
  <dl class="admin-kv">
    <dt>Subject</dt><dd><?= wwm_escape((string)$b['subject']) ?></dd>
    <dt>Audience</dt><dd><?= wwm_escape(EmailBroadcast::audienceLabel($b)) ?></dd>
    <dt>From</dt><dd><?= wwm_escape((string)($broadcastFrom ?? '')) ?><?php if (empty($broadcastOwnMailbox)): ?> <span class="field-hint">(same mailbox as course-access mail)</span><?php endif; ?></dd>
    <dt>Recipients</dt>
    <dd>
      <?php if ($status === 'scheduled'): ?>
        <strong><?= (int)($audienceSize ?? 0) ?></strong> planned
      <?php else: ?>
        <?= (int)($b['sent_count'] ?? 0) ?> sent,
        <?= (int)($b['failed_count'] ?? 0) ?> failed,
        <?= (int)($b['skipped_unsub_count'] ?? 0) ?> skipped (unsubscribed),
        <?php if ($status === 'sending'): ?>
          <?= (int)($pendingCount ?? 0) ?> waiting,
        <?php endif; ?>
        <?= (int)($b['recipients_total'] ?? 0) ?> total
      <?php endif; ?>
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

<?php endif; ?>

<?php if ($status === 'sending'): ?>
  <div class="admin-card" style="margin-bottom:16px">
    <p class="field-hint">Sending in progress. <?= (int)($pendingCount ?? 0) ?> still waiting. Cron (<code>scripts/run-broadcasts.php</code>) sends the queue, about 200 letters every few minutes. Refresh only reloads these numbers.</p>
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
    try {
        $htmlPreview = preg_replace('#\scontenteditable\s*=\s*("true"|"false"|true|false)#i', '', $htmlPreview) ?? $htmlPreview;
        $htmlPreview = \Wwm\Services\BroadcastEmailLayout::forDelivery(
            \Wwm\Services\BroadcastHtmlSanitizer::sanitize($htmlPreview),
            (string)($b['subject'] ?? '')
        );
    } catch (Throwable $e) {
        $htmlPreview = '';
    }
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
      <iframe class="email-preview-frame" title="Broadcast visual preview" id="broadcast-view-html-frame" srcdoc="<?= wwm_iframe_srcdoc($htmlPreview) ?>"></iframe>
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

<?php if ($linkStats !== []): ?>
  <details class="admin-card admin-expander" style="margin-bottom:16px">
    <summary class="admin-expander-summary">
      <span class="admin-expander-summary-text">
        <h2>Links</h2>
        <span class="field-hint"><?= count($linkStats) ?> tracked link<?= count($linkStats) === 1 ? '' : 's' ?></span>
      </span>
      <span class="admin-expander-chevron" aria-hidden="true">▼</span>
    </summary>
    <div class="admin-expander-body">
      <div class="admin-table-wrap admin-table-wrap--profile">
        <table class="admin-table admin-table-compact admin-table--profile admin-table--broadcast-links">
          <thead>
            <tr>
              <th>URL</th>
              <th class="col-num">Unique clickers</th>
              <th class="col-num">Total clicks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($linkStats as $link): ?>
              <tr>
                <td class="col-subject">
                  <a href="<?= wwm_escape((string)$link['target_url']) ?>" target="_blank" rel="noopener"><?= wwm_escape((string)($link['link_label'] ?: $link['target_url'])) ?></a>
                  <div class="field-hint"><?= wwm_escape((string)$link['target_url']) ?></div>
                </td>
                <td class="col-num"><?= (int)$link['unique_clickers'] ?></td>
                <td class="col-num"><?= (int)$link['total_clicks'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </details>
<?php endif; ?>

<?php
$recipientTotal = (int)($recipientTotal ?? 0);
$recipientPage = max(1, (int)($recipientPage ?? 1));
$recipientPages = max(1, (int)($recipientPages ?? 1));
$recipientsOpen = !empty($recipientsOpen);
?>
<?php if ($recipientTotal > 0): ?>
  <details class="admin-card admin-expander" id="broadcast-recipients" style="margin-bottom:16px"<?= $recipientsOpen ? ' open' : '' ?>>
    <summary class="admin-expander-summary">
      <span class="admin-expander-summary-text">
        <h2>Per recipient</h2>
        <span class="field-hint">Delivery, opens, clicks<?php if ($recipientPages > 1): ?> · page <?= $recipientPage ?> of <?= $recipientPages ?><?php endif; ?> · <?= $recipientTotal ?> address<?= $recipientTotal === 1 ? '' : 'es' ?></span>
      </span>
      <span class="admin-expander-chevron" aria-hidden="true">▼</span>
    </summary>
    <div class="admin-expander-body">
      <div class="admin-table-wrap admin-table-wrap--profile">
        <table class="admin-table admin-table-compact admin-table--profile admin-table--broadcast-recipients">
          <thead>
            <tr>
              <th>Email</th>
              <th class="col-status">Delivery</th>
              <th class="col-date">Opened</th>
              <th class="col-num">Clicks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recipientEngagement as $row): ?>
              <tr>
                <td class="col-subject"><?= wwm_escape((string)($row['email'] ?? '')) ?></td>
                <?php $delivery = (string)($row['delivery_status'] ?? ''); ?>
                <td class="col-status"><span class="badge <?= wwm_escape(EmailBroadcast::statusBadgeClass($delivery)) ?>"><?= wwm_escape(EmailBroadcast::statusLabel($delivery)) ?></span></td>
                <td class="col-date">
                  <?php if (!empty($row['opened_at'])): ?>
                    <span class="badge badge-demo" style="margin:0">Yes</span>
                    <span class="field-hint"><?= wwm_escape($formatWhen((string)$row['opened_at'])) ?><?= (int)($row['open_count'] ?? 0) > 1 ? ' · ' . (int)$row['open_count'] . '×' : '' ?></span>
                  <?php elseif (!$hasHtml): ?>
                    <span class="field-hint">n/a (plain)</span>
                  <?php else: ?>
                    <span class="field-hint">—</span>
                  <?php endif; ?>
                </td>
                <td class="col-num"><?= (int)($row['click_count'] ?? 0) > 0 ? (int)$row['click_count'] : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($recipientPages > 1): ?>
        <?php
          $recipientPageUrl = static function (int $p) use ($id): string {
              return '/admin/broadcasts/' . $id . '?rpage=' . $p . '#broadcast-recipients';
          };
        ?>
        <div class="admin-pagination">
          <?php if ($recipientPage > 1): ?>
            <a href="<?= wwm_escape($recipientPageUrl($recipientPage - 1)) ?>" class="btn btn-ghost btn-sm">← Prev</a>
          <?php endif; ?>
          <span class="field-hint">Page <?= $recipientPage ?> of <?= $recipientPages ?> · 100 per page</span>
          <?php if ($recipientPage < $recipientPages): ?>
            <a href="<?= wwm_escape($recipientPageUrl($recipientPage + 1)) ?>" class="btn btn-ghost btn-sm">Next →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <p class="field-hint" style="margin-top:12px">Same tracking as transactional emails: pixel opens may be blocked; link redirects are more reliable. Unsubscribe links are not tracked.</p>
    </div>
  </details>
<?php endif; ?>

</div><!-- .admin-broadcast-detail -->

<?php if ($canCancel && $status !== 'sent'): ?>
  <div class="admin-card">
    <?php if (in_array($status, ['draft', 'scheduled'], true)): ?>
      <form method="post" action="/admin/broadcasts/<?= $id ?>/send" style="display:inline;margin-right:12px" onsubmit="return confirm('Send this broadcast to <?= (int)($audienceSize ?? 0) ?> people?');">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <input type="hidden" name="confirm_count" value="<?= (int)($audienceSize ?? 0) ?>">
        <input type="hidden" name="from" value="view">
        <button type="submit" class="btn btn-danger btn-sm">Send to <?= (int)($audienceSize ?? 0) ?> people</button>
      </form>
    <?php endif; ?>
    <form method="post" action="/admin/broadcasts/<?= $id ?>/cancel" style="display:inline" onsubmit="return confirm('Cancel this broadcast?');">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <button type="submit" class="btn btn-ghost btn-sm">Cancel</button>
    </form>
  </div>
<?php endif; ?>
