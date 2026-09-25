<?php
use Wwm\Services\StudentAttribution;

$formatDate = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('M j, Y H:i', $ts) : '—';
};
$accessAvoLines = static function (array $view, bool $paidColumn) use ($formatDate): array {
    if (($view['label'] ?? 'None') === 'None') {
        return [];
    }
    $lines = [];
    if ($paidColumn && !empty($view['avo_paid_at'])) {
        $lines[] = 'Paid in AVO: ' . $formatDate((string)$view['avo_paid_at']);
    }
    if (!empty($view['avo_ordered_at'])) {
        $ordered = (string)$view['avo_ordered_at'];
        $paid = (string)($view['avo_paid_at'] ?? '');
        if (!$paidColumn || $paid === '' || $ordered !== $paid) {
            $lines[] = ($paidColumn ? 'Order in AVO: ' : 'Ordered in AVO: ') . $formatDate($ordered);
        }
    }
    if ($lines === [] && !empty($view['granted_at'])) {
        $lines[] = 'Granted in cabinet: ' . $formatDate((string)$view['granted_at']);
    }

    return $lines;
};
$avoRegisteredAt = trim((string)($student['avo_contact_registered_at'] ?? ''));
$avoFirstOrderAt = trim((string)($student['avo_first_order_at'] ?? ''));
$id = (int)($student['id'] ?? 0);
$pct = $total_lessons > 0 ? min(100, (int)round($total_opened / $total_lessons * 100)) : 0;
$badgeClass = $access_label === 'Paid' ? 'badge-paid' : ($access_label === 'Demo' ? 'badge-demo' : 'badge-draft');
$periods = is_array($access_periods ?? null) ? $access_periods : [];
$accessCourses = is_array($access_courses ?? null) ? $access_courses : [];
$location = StudentAttribution::lastLoginLocationLabel($student);
if ($location === '—') {
    $location = StudentAttribution::signupLocationLabel($student);
}
$signupLocation = StudentAttribution::signupLocationLabel($student);
$channel = StudentAttribution::channelLabel($student);
$channelDetail = StudentAttribution::channelDetail($student);
$utmFields = StudentAttribution::utmFields($student);
$courseBlocksList = is_array($course_blocks ?? null) ? $course_blocks : [];
$progressCourseCount = count($courseBlocksList);
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Student profile</p>
    <h1 class="page-title page-title-sm"><?= wwm_escape((string)($student['name'] ?: $student['email'])) ?></h1>
    <p class="field-hint"><?= wwm_escape((string)$student['email']) ?> · registered <?= wwm_escape($formatDate(\Wwm\Models\User::registeredAtForDisplay($student))) ?><?php if (\Wwm\Models\User::isAvoBulkImport($student)): ?> <span class="field-hint">(AVO import)</span><?php endif; ?></p>
  </div>
  <div class="admin-topbar-actions">
    <form method="post" action="/admin/students/<?= $id ?>/send-login-credentials" class="inline-form"
          data-confirm="Send sign-in details to <?= wwm_escape((string)$student['email']) ?>? A new password will be set and included in the email.">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <input type="hidden" name="reset_password" value="1">
      <button type="submit" class="btn btn-primary btn-sm">Send sign-in details</button>
    </form>
    <a href="/admin/students" class="btn btn-ghost">← All students</a>
  </div>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-stats">
  <div class="admin-stat-card">
    <span class="admin-stat-label">Lessons opened</span>
    <strong class="admin-stat-value"><?= (int)$total_opened ?> <span class="admin-stat-note" style="display:inline;font-size:1rem">/ <?= (int)$total_lessons ?></span></strong>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Last activity</span>
    <strong class="admin-stat-value" style="font-size:1.35rem"><?= wwm_escape($formatDate($last_activity)) ?></strong>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Access</span>
    <strong class="admin-stat-value" style="font-size:1.35rem"><span class="badge <?= $badgeClass ?>"><?= wwm_escape($access_label) ?></span></strong>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Location</span>
    <strong class="admin-stat-value" style="font-size:1.1rem"><?= wwm_escape($location) ?></strong>
    <?php if ($signupLocation !== '—' && $signupLocation !== $location): ?>
      <span class="admin-stat-note">At signup: <?= wwm_escape($signupLocation) ?></span>
    <?php endif; ?>
  </div>
  <div class="admin-stat-card">
    <span class="admin-stat-label">Marketing channel</span>
    <strong class="admin-stat-value" style="font-size:1.1rem"><?= wwm_escape($channel) ?></strong>
    <?php if ($channelDetail !== null): ?>
      <span class="admin-stat-note"><?= wwm_escape($channelDetail) ?></span>
    <?php endif; ?>
  </div>
</div>

<div class="admin-student-profile">

<details class="admin-card admin-expander">
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>UTM attribution</h2>
      <?php if ($utmFields !== []): ?>
        <span class="field-hint"><?= count($utmFields) ?> field<?= count($utmFields) === 1 ? '' : 's' ?></span>
      <?php endif; ?>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
  <?php if ($utmFields !== []): ?>
    <table class="admin-table admin-table-compact">
      <tbody>
        <?php foreach ($utmFields as $key => $value): ?>
          <tr>
            <th style="width:180px"><?= wwm_escape($key) ?></th>
            <td><?= wwm_escape($value) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="field-hint">No UTM data yet. Use Resync below or wait for the next AVO webhook with advertising fields.</p>
  <?php endif; ?>
  </div>
</details>

<?php if (!empty($avo_enabled)): ?>
<details class="admin-card admin-expander">
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>AVO sync</h2>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
  <p class="field-hint" style="margin-bottom:8px">
    <strong>Registered in AVO:</strong>
    <?= $avoRegisteredAt !== '' ? wwm_escape($formatDate($avoRegisteredAt)) : '—' ?>
  </p>
  <?php if ($avoFirstOrderAt !== ''): ?>
  <p class="field-hint" style="margin-bottom:8px">
    <strong>First order in AVO:</strong> <?= wwm_escape($formatDate($avoFirstOrderAt)) ?>
  </p>
  <?php endif; ?>
  <p class="field-hint" style="margin-bottom:12px">
    Contact ID: <?= $avo_contact_id !== null ? (int)$avo_contact_id : 'not found' ?>
    · logged in: <?= !empty($avo_logged_in_tagged) ? 'local ✓' : 'local —' ?>
    <?= $avo_has_logged_in_tag === true ? '· AVO ✓' : ($avo_has_logged_in_tag === false ? '· AVO —' : '') ?>
    · demo opened: <?= !empty($avo_demo_opened_tagged) ? 'local ✓' : 'local —' ?>
    <?= $avo_has_demo_opened_tag === true ? '· AVO ✓' : ($avo_has_demo_opened_tag === false ? '· AVO —' : '') ?>
  </p>
  <?php if ($avoRegisteredAt === ''): ?>
    <p class="field-hint" style="margin-bottom:12px">Use Resync to pull registration date from AVO. Re-run CSV import to backfill order and payment dates for legacy students.</p>
  <?php endif; ?>
  <form method="post" action="/admin/students/<?= $id ?>/avo-sync" class="inline-form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <button type="submit" class="btn btn-ghost btn-sm">Resync AVO tags &amp; UTM</button>
  </form>
  </div>
</details>
<?php endif; ?>

<?php
$payments = is_array($payments ?? null) ? $payments : [];
$emailMessages = is_array($email_messages ?? null) ? $email_messages : [];
?>
<details class="admin-card admin-expander">
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>Payments</h2>
      <span class="field-hint"><?= $payments === [] ? 'No payments logged yet' : count($payments) . ' payment' . (count($payments) === 1 ? '' : 's') ?></span>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
  <?php if ($payments === []): ?>
    <p class="field-hint">Paid orders from the AVO payment webhook appear here (amount, order id, UTM at purchase).</p>
  <?php else: ?>
    <div class="admin-table-wrap admin-table-wrap--profile">
      <table class="admin-table admin-table-compact admin-table--profile">
        <thead>
          <tr>
            <th class="col-date">Paid</th>
            <th>Course</th>
            <th class="col-status">Amount</th>
            <th>AVO order</th>
            <th>UTM / campaign</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $pay): ?>
            <?php
              $paidAt = (string)($pay['paid_at'] ?? $pay['ordered_at'] ?? $pay['created_at'] ?? '');
              $utmParts = array_filter([
                  trim((string)($pay['utm_source'] ?? '')),
                  trim((string)($pay['utm_medium'] ?? '')),
                  trim((string)($pay['utm_campaign'] ?? '')),
              ]);
            ?>
            <tr>
              <td class="col-date"><?= $paidAt !== '' ? wwm_escape($formatDate($paidAt)) : '—' ?></td>
              <td><code><?= wwm_escape((string)($pay['course_slug'] ?? '')) ?></code></td>
              <td class="col-status payment-amount-cell">
                <?php
                  $amountLines = \Wwm\Models\Payment::amountDisplayLines($pay);
                ?>
                <span class="payment-amount-primary"><?= wwm_escape($amountLines['primary']) ?></span>
                <?php if ($amountLines['secondary'] !== null): ?>
                  <span class="payment-amount-secondary field-hint">
                    <?= wwm_escape($amountLines['secondary']) ?>
                    <?php if ($amountLines['estimated']): ?>
                      <span class="payment-amount-est" title="USD estimated from RUB using fallback rate">~</span>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="admin-cell-muted"><?= wwm_escape((string)($pay['avo_account_id'] ?? '')) ?></td>
              <td class="col-subject"><?= $utmParts !== [] ? wwm_escape(implode(' · ', $utmParts)) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  </div>
</details>

<details class="admin-card admin-expander">
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>Emails</h2>
      <span class="field-hint"><?= $emailMessages === [] ? 'No messages yet' : count($emailMessages) . ' message' . (count($emailMessages) === 1 ? '' : 's') ?></span>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
  <?php if ($emailMessages === []): ?>
    <p class="field-hint">No cabinet emails logged for this student yet.</p>
  <?php else: ?>
    <div class="admin-table-wrap admin-table-wrap--profile">
      <table class="admin-table admin-table-compact admin-table--profile">
        <thead>
          <tr>
            <th class="col-date">Sent</th>
            <th class="col-type">Type</th>
            <th class="col-subject">Subject</th>
            <th class="col-status">Status</th>
            <th class="col-status">Opened</th>
            <th>Links</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($emailMessages as $mail): ?>
            <?php
              $links = is_array($mail['links'] ?? null) ? $mail['links'] : [];
              $openedAt = (string)($mail['opened_at'] ?? '');
              $openCount = (int)($mail['open_count'] ?? 0);
              $subject = (string)($mail['subject'] ?? '');
            ?>
            <tr>
              <td class="col-date"><?= wwm_escape($formatDate((string)($mail['sent_at'] ?? ''))) ?></td>
              <td class="col-type"><?= wwm_escape(\Wwm\Models\EmailMessage::typeLabel((string)($mail['email_type'] ?? ''))) ?></td>
              <td class="col-subject" title="<?= wwm_escape($subject) ?>"><?= wwm_escape($subject) ?></td>
              <td class="col-status">
                <?php if (($mail['status'] ?? '') === 'sent'): ?>
                  <span class="badge badge-paid" style="margin:0">Sent</span>
                <?php else: ?>
                  <span class="badge badge-draft" style="margin:0">Failed</span>
                <?php endif; ?>
              </td>
              <td class="col-status">
                <?php if ($openedAt !== ''): ?>
                  <div class="admin-cell-stack">
                    <span class="badge badge-demo" style="margin:0">Yes</span>
                    <span class="admin-cell-muted"><?= wwm_escape($formatDate($openedAt)) ?><?= $openCount > 1 ? ' · ' . $openCount . '×' : '' ?></span>
                  </div>
                <?php else: ?>
                  <span class="admin-cell-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($links === []): ?>
                  <span class="admin-cell-muted">—</span>
                <?php else: ?>
                  <div class="email-link-chips">
                    <?php foreach ($links as $link): ?>
                      <?php $clicked = !empty($link['clicked_at']); ?>
                      <div class="email-link-chip<?= $clicked ? ' is-clicked' : '' ?>">
                        <span class="email-link-chip__label"><?= wwm_escape((string)($link['link_label'] ?: 'Link')) ?></span>
                        <span class="email-link-chip__status">
                          <?php if ($clicked): ?>
                            Clicked <?= wwm_escape($formatDate((string)$link['clicked_at'])) ?><?= (int)($link['click_count'] ?? 0) > 1 ? ' · ' . (int)$link['click_count'] . '×' : '' ?>
                          <?php else: ?>
                            Not clicked
                          <?php endif; ?>
                        </span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="admin-profile-footnote">Open tracking uses a pixel and may be blocked by some mail clients. Link clicks are more reliable.</p>
  <?php endif; ?>
  </div>
</details>

<?php
$automationRuns = is_array($automation_runs ?? null) ? $automation_runs : [];
?>
<details class="admin-card admin-expander"<?= $automationRuns !== [] ? ' open' : '' ?>>
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>Automations</h2>
      <span class="field-hint"><?= $automationRuns === [] ? 'Not enrolled' : count($automationRuns) . ' run' . (count($automationRuns) === 1 ? '' : 's') ?></span>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
    <?php if ($automationRuns === []): ?>
      <p class="field-hint">This student is not in any email automation yet. Demo or paid access enrolls them only when a matching active process exists.</p>
    <?php else: ?>
      <div class="admin-table-wrap admin-table-wrap--profile">
        <table class="admin-table admin-table-compact admin-table--profile">
          <thead>
            <tr>
              <th>Process</th>
              <th>Status</th>
              <th>Node</th>
              <th class="col-date">Enrolled</th>
              <?php if (!empty($can_manage_automations)): ?>
                <th class="col-tight"></th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($automationRuns as $run): ?>
              <?php
                $runStatus = (string)($run['status'] ?? '');
                $badge = $runStatus === 'active' ? 'badge-paid' : ($runStatus === 'completed' ? 'badge-demo' : 'badge-draft');
                $statusLabel = $runStatus === 'active' ? 'Active' : ($runStatus === 'completed' ? 'Completed' : ($runStatus === 'cancelled' ? 'Removed' : $runStatus));
                $runId = (int)($run['id'] ?? 0);
              ?>
              <tr>
                <td>
                  <a href="/admin/automations/<?= (int)($run['automation_id'] ?? 0) ?>/edit"><?= wwm_escape((string)($run['title'] ?? $run['slug'] ?? '')) ?></a>
                  <br><span class="field-hint"><code><?= wwm_escape((string)($run['slug'] ?? '')) ?></code></span>
                </td>
                <td><span class="badge <?= $badge ?>"><?= wwm_escape($statusLabel) ?></span></td>
                <td><?= wwm_escape((string)($run['current_node_id'] ?? '—')) ?></td>
                <td class="col-date"><?= wwm_escape($formatDate(isset($run['enrolled_at']) ? (string)$run['enrolled_at'] : null)) ?></td>
                <?php if (!empty($can_manage_automations)): ?>
                  <td class="col-tight">
                    <?php if ($runStatus === 'active' && $runId > 0): ?>
                      <form method="post" action="/admin/students/<?= $id ?>/automation-runs/<?= $runId ?>/cancel" class="inline-form" data-confirm="Remove this student from the process? No further emails or delays will run.">
                        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                      </form>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</details>

<details class="admin-card admin-expander">
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>Course access</h2>
      <span class="field-hint"><?= count($accessCourses) ?> course<?= count($accessCourses) === 1 ? '' : 's' ?></span>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
  <div class="admin-table-wrap admin-table-wrap--profile">
  <table class="admin-table admin-table-compact admin-table--profile access-table">
    <thead>
      <tr>
        <th>Course</th>
        <th>Demo</th>
        <th>Full access</th>
        <th>Grant</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($accessCourses as $row): ?>
        <?php
          $c = $row['course'];
          $slug = (string)($c['slug'] ?? '');
          $demo = is_array($row['demo'] ?? null) ? $row['demo'] : ['active' => false, 'label' => 'None'];
          $paid = is_array($row['paid'] ?? null) ? $row['paid'] : ['active' => false, 'label' => 'None'];
          $published = !empty($row['published']);
        ?>
        <tr>
          <td>
            <strong><?= wwm_escape((string)($c['title'] ?? $slug)) ?></strong><br>
            <span class="field-hint"><?= wwm_escape($slug) ?><?= $published ? '' : ' · draft' ?></span>
          </td>
          <td>
            <span class="badge <?= $demo['active'] ? 'badge-demo' : 'badge-draft' ?>"><?= wwm_escape((string)$demo['label']) ?></span>
            <?php foreach ($accessAvoLines($demo, false) as $avoLine): ?>
              <br><span class="field-hint"><?= wwm_escape($avoLine) ?></span>
            <?php endforeach; ?>
            <?php if ($demo['active']): ?>
              <form method="post" action="/admin/students/<?= $id ?>/access/revoke" class="inline-form">
                <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                <input type="hidden" name="course_slug" value="<?= wwm_escape($slug) ?>">
                <input type="hidden" name="access_type" value="demo">
                <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $paid['active'] ? 'badge-paid' : 'badge-draft' ?>"><?= wwm_escape((string)$paid['label']) ?></span>
            <?php foreach ($accessAvoLines($paid, true) as $avoLine): ?>
              <br><span class="field-hint"><?= wwm_escape($avoLine) ?></span>
            <?php endforeach; ?>
            <?php if ($paid['active']): ?>
              <form method="post" action="/admin/students/<?= $id ?>/access/revoke" class="inline-form">
                <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                <input type="hidden" name="course_slug" value="<?= wwm_escape($slug) ?>">
                <input type="hidden" name="access_type" value="paid">
                <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="/admin/students/<?= $id ?>/access" class="access-grant-form">
              <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
              <input type="hidden" name="course_slug" value="<?= wwm_escape($slug) ?>">
              <select name="access_type" required aria-label="Access type">
                <option value="demo">Demo</option>
                <option value="paid">Full</option>
              </select>
              <select name="period" class="access-period-select" required aria-label="Duration">
                <?php foreach ($periods as $key => $label): ?>
                  <option value="<?= wwm_escape((string)$key) ?>"<?= $key === '30d' ? ' selected' : '' ?>><?= wwm_escape((string)$label) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="date" name="expires_date" class="access-expires-date" hidden aria-label="Access until" min="<?= wwm_escape(gmdate('Y-m-d')) ?>">
              <button type="submit" class="btn btn-primary btn-sm">Grant</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </div>
</details>

<script>
document.querySelectorAll('.access-grant-form').forEach((form) => {
  const period = form.querySelector('.access-period-select');
  const date = form.querySelector('.access-expires-date');
  if (!period || !date) {
    return;
  }
  const sync = () => {
    const custom = period.value === 'custom';
    date.hidden = !custom;
    date.required = custom;
    if (!custom) {
      date.value = '';
    }
  };
  period.addEventListener('change', sync);
  sync();
});
</script>

<details class="admin-card admin-expander">
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>Course progress</h2>
      <span class="field-hint">
        <?php if ($progressCourseCount === 0): ?>
          No courses with progress yet
        <?php else: ?>
          <?= $progressCourseCount ?> course<?= $progressCourseCount === 1 ? '' : 's' ?>
          · <?= (int)$total_opened ?> / <?= (int)$total_lessons ?> lessons opened
        <?php endif; ?>
      </span>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
  <?php if ($courseBlocksList === []): ?>
    <p class="field-hint">This student has no active course access yet. Use the table above to grant demo or full access.</p>
  <?php else: ?>
    <div class="admin-expander-group">
    <?php foreach ($courseBlocksList as $block): ?>
  <?php
    $c = $block['course'];
    $slug = (string)($c['slug'] ?? '');
    $opened = (int)$block['opened'];
    $total = (int)$block['total'];
    $coursePct = $total > 0 ? min(100, (int)round($opened / $total * 100)) : 0;
  ?>
  <details class="admin-expander admin-expander--nested admin-expander--course">
    <summary class="admin-expander-summary">
      <div class="student-course-head">
        <div>
          <h2 style="margin:0;font-family:'Fraunces',Georgia,serif;font-size:1.25rem"><?= wwm_escape((string)($c['title'] ?? $slug)) ?></h2>
          <span class="field-hint"><?= wwm_escape($slug) ?> · <?= wwm_escape((string)$block['access']) ?></span>
        </div>
        <div class="progress-cell" style="min-width:200px">
          <span class="progress-label"><?= $opened ?> / <?= $total ?> lessons</span>
          <div class="progress-bar"><span class="progress-bar-fill" style="width:<?= $coursePct ?>%"></span></div>
        </div>
      </div>
      <span class="admin-expander-chevron" aria-hidden="true">▼</span>
    </summary>
    <div class="admin-expander-body">
    <table class="admin-table admin-table-compact">
      <thead>
        <tr>
          <th style="width:40px"></th>
          <th style="width:48px">#</th>
          <th>Lesson</th>
          <th>First opened</th>
          <th>Last opened</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($block['lessons'] as $lesson): ?>
          <tr>
            <td>
              <?php if ($lesson['opened']): ?>
                <span class="lesson-opened" title="Opened">✓</span>
              <?php else: ?>
                <span class="lesson-not-opened">—</span>
              <?php endif; ?>
            </td>
            <td><?= (int)$lesson['num'] ?></td>
            <td><?= wwm_escape((string)$lesson['title']) ?></td>
            <td><?= wwm_escape($formatDate($lesson['first_opened_at'])) ?></td>
            <td><?= wwm_escape($formatDate($lesson['last_opened_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</details>

</div>

<details class="admin-card admin-expander admin-danger-zone" style="margin-top:12px">
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>Danger zone</h2>
      <span class="field-hint">Delete student permanently</span>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
  <p class="field-hint">Permanently delete this student and all their access records. This cannot be undone.</p>
  <form method="post" action="/admin/students/<?= $id ?>/delete" class="inline-form" onsubmit="return confirm('Delete this student permanently?');">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <button type="submit" class="btn btn-danger btn-sm">Delete student</button>
  </form>
  </div>
</details>
