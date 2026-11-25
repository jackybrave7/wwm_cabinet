<?php
$rows = is_array($automations ?? null) ? $automations : [];
$archivedView = !empty($archivedView);
$entryModeLabels = is_array($entryModeLabels ?? null) ? $entryModeLabels : [];
$courseSlugList = is_array($courseSlugs ?? null) ? $courseSlugs : [];
$courseTitleBySlug = [];
foreach ($courseSlugList as $courseOpt) {
    if (is_array($courseOpt)) {
        $slug = (string)($courseOpt['value'] ?? '');
        if ($slug !== '') {
            $courseTitleBySlug[$slug] = (string)($courseOpt['label'] ?? $slug);
        }
    }
}
$listErrors = [
    'csrf' => 'Your session expired. Try again.',
    'not_found' => 'Process not found.',
    'archive_failed' => 'Could not archive the process.',
    'unarchive_failed' => 'Could not restore the process from the archive.',
    'delete_blocked' => 'You can delete only an inactive process with no active runs. Turn Active off or wait for the runs to finish, or archive it.',
    'course_required' => 'This entry type needs a course.',
];
$listErr = (string)($_GET['error'] ?? '');
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Programming</p>
    <h1 class="page-title page-title-sm">Email automations</h1>
    <p class="field-hint">Business-process style drip flows inside the cabinet. New flows are <strong>draft (off)</strong> until you enable Active on each flow.</p>
  </div>
  <?php if (!$archivedView): ?>
  <form method="post" action="/admin/automations/run" data-confirm="Run every automation step that is due?">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <button type="submit" class="btn btn-ghost btn-sm">Run due steps</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if ($listErr !== '' && isset($listErrors[$listErr])): ?>
  <div class="alert alert-error"><?= wwm_escape($listErrors[$listErr]) ?></div>
<?php endif; ?>

<div class="admin-card" style="margin-bottom:16px;padding:12px 16px">
  <div class="admin-table-actions" style="margin:0">
    <a href="/admin/automations" class="btn btn-sm <?= !$archivedView ? 'btn-primary' : 'btn-ghost' ?>">Active and drafts</a>
    <a href="/admin/automations?view=archived" class="btn btn-sm <?= $archivedView ? 'btn-primary' : 'btn-ghost' ?>">Archive</a>
    <a href="/admin/guide?section=automations" class="btn btn-ghost btn-sm">Guide</a>
  </div>
</div>

<?php if (!$archivedView && $entryModeLabels !== []): ?>
<div class="admin-card" style="margin-bottom:16px">
  <h2 class="admin-team-section-title" style="margin-top:0">New process</h2>
  <form method="post" action="/admin/automations/create" class="automation-create-form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <label class="field">
      <span class="field-label">Name</span>
      <input type="text" name="title" required placeholder="For example: Post-purchase cross-sell">
    </label>
    <label class="field">
      <span class="field-label">Entry type</span>
      <select name="entry_mode" id="automation-create-entry-mode">
        <?php foreach ($entryModeLabels as $mode => $modeLabel): ?>
          <option value="<?= wwm_escape($mode) ?>"><?= wwm_escape($modeLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field" id="automation-create-course-wrap">
      <span class="field-label">Course</span>
      <?php if ($courseSlugList !== []): ?>
        <select name="course_slug" id="automation-create-course">
          <option value="">— not linked —</option>
          <?php foreach ($courseSlugList as $courseOpt): ?>
            <?php
              $slug = is_array($courseOpt) ? (string)($courseOpt['value'] ?? '') : (string)$courseOpt;
              $courseLabel = is_array($courseOpt) ? (string)($courseOpt['label'] ?? $slug) : (string)$courseOpt;
            ?>
            <option value="<?= wwm_escape($slug) ?>"><?= wwm_escape($courseLabel) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="text" name="course_slug" id="automation-create-course" pattern="[a-z0-9\-]*" placeholder="elke-en">
      <?php endif; ?>
    </label>
    <div class="automation-create-form__submit">
      <button type="submit" class="btn btn-primary">Create and open the editor</button>
    </div>
  </form>
  <p class="field-hint" style="margin-top:12px">
    The ready-made post-purchase cross-sell is already in the list below (<code>post-purchase-cross-sell</code>). It syncs from the repository on every request to the cabinet.
  </p>
  <script>
  (function () {
    const mode = document.getElementById('automation-create-entry-mode');
    const course = document.getElementById('automation-create-course');
    if (!mode || !course) return;
    const needs = { demo_grant: true, payment_course: true, demo_form: true, manual: false, payment_any: false };
    function sync() {
      course.required = !!needs[mode.value];
    }
    mode.addEventListener('change', sync);
    sync();
  })();
  </script>
</div>
<?php endif; ?>

<div class="admin-card">
  <div class="admin-table-wrap">
    <table class="admin-table admin-table-compact admin-table--automations">
      <thead>
        <tr>
          <th class="col-flow">Flow</th>
          <th class="col-entry">Entry</th>
          <th class="col-course">Course</th>
          <th class="col-status">Status</th>
          <th class="col-num">Active runs</th>
          <th class="admin-table-actions-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows === []): ?>
          <tr><td colspan="6" class="field-hint"><?= $archivedView ? 'The archive is empty.' : 'No processes yet. Run migrate.php or open any admin page (the flows are pulled in automatically).' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <?php
            $id = (int)$row['id'];
            $title = (string)$row['title'];
            $activeRuns = (int)($row['active_runs'] ?? 0);
            $isActive = !empty($row['is_active']);
            $canDelete = !$isActive && $activeRuns === 0;
            $entryMode = \Wwm\Models\EmailAutomation::normalizeEntryMode((string)($row['entry_mode'] ?? ''));
            $entryLabel = $entryModeLabels[$entryMode] ?? $entryMode;
            $courseSlugCell = trim((string)($row['course_slug'] ?? ''));
          ?>
          <tr>
            <td class="col-flow">
              <strong><?= wwm_escape($title) ?></strong><br>
              <span class="field-hint"><?= wwm_escape((string)$row['slug']) ?></span>
            </td>
            <td class="col-entry"><span class="field-hint"><?= wwm_escape($entryLabel) ?></span></td>
            <td class="col-course"><?php
              if ($courseSlugCell === '') {
                  echo '<span class="field-hint">—</span>';
              } else {
                  $courseListLabel = $courseTitleBySlug[$courseSlugCell] ?? $courseSlugCell;
                  echo wwm_escape($courseListLabel);
                  if ($courseListLabel !== $courseSlugCell && !str_contains($courseListLabel, $courseSlugCell)) {
                      echo '<br><span class="field-hint"><code>' . wwm_escape($courseSlugCell) . '</code></span>';
                  }
              }
            ?></td>
            <td class="col-status">
              <?php if ($archivedView): ?>
                <span class="badge badge-draft">Archived</span>
              <?php elseif ($isActive): ?>
                <span class="badge badge-paid">Active</span>
              <?php else: ?>
                <span class="badge badge-draft">Draft</span>
              <?php endif; ?>
            </td>
            <td class="col-num"><?= $activeRuns ?></td>
            <td class="admin-table-actions">
              <a href="/admin/automations/<?= $id ?>/edit" class="btn btn-ghost btn-sm">Edit</a>
              <form method="post" action="/admin/automations/<?= $id ?>/duplicate" class="inline-form" data-confirm="Create a copy of “<?= wwm_escape($title) ?>”? The copy will be off.">
                <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                <button type="submit" class="btn btn-ghost btn-sm">Copy</button>
              </form>
              <?php if ($archivedView): ?>
                <form method="post" action="/admin/automations/<?= $id ?>/unarchive" class="inline-form" data-confirm="Restore “<?= wwm_escape($title) ?>” from the archive?">
                  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">Restore</button>
                </form>
              <?php else: ?>
                <form method="post" action="/admin/automations/<?= $id ?>/archive" class="inline-form" data-confirm="Archive “<?= wwm_escape($title) ?>”? The process will be turned off, and new students will not enter it.">
                  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">Archive</button>
                </form>
              <?php endif; ?>
              <?php if ($canDelete): ?>
                <form method="post" action="/admin/automations/<?= $id ?>/delete" class="inline-form" data-confirm-danger data-confirm="Delete “<?= wwm_escape($title) ?>” permanently? Every run record in the database will be deleted too.">
                  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$archivedView): ?>
  <p class="field-hint" style="margin-top:16px">After a student enters, they move straight to the first pause. After that, cron runs <code>php scripts/run-email-automations.php</code> every 5–15 minutes.</p>
  <?php endif; ?>
</div>
