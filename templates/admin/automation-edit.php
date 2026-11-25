<?php
$a = $automation ?? [];
$id = (int)($a['id'] ?? 0);
$def = is_array($flowDefinition ?? null) ? $flowDefinition : json_decode((string)($a['definition_json'] ?? '{}'), true);
if (!is_array($def)) {
    $def = [];
}
$flowEditorConfig = is_array($flowEditorConfig ?? null) ? $flowEditorConfig : [];
$nodes = is_array($def['nodes'] ?? null) ? $def['nodes'] : [];
$edges = is_array($def['edges'] ?? null) ? $def['edges'] : [];
$courseSlugFlow = (string)($a['course_slug'] ?? 'elke-en');
$flowTitle = (string)($a['title'] ?? 'Flow');
$isArchivedFlow = !empty($isArchivedFlow);
$flowActiveRuns = (int)($flowActiveRuns ?? 0);
$canDeleteFlow = empty($a['is_active']) && $flowActiveRuns === 0;
$entryModeLabels = is_array($entryModeLabels ?? null) ? $entryModeLabels : [];
$courseSlugList = is_array($courseSlugs ?? null) ? $courseSlugs : [];
$courseSlugValues = [];
foreach ($courseSlugList as $courseOpt) {
    if (is_array($courseOpt)) {
        $courseSlugValues[] = (string)($courseOpt['value'] ?? '');
    } else {
        $courseSlugValues[] = (string)$courseOpt;
    }
}
$currentEntryMode = \Wwm\Models\EmailAutomation::normalizeEntryMode((string)($a['entry_mode'] ?? ''));
$currentCourseSlug = (string)($a['course_slug'] ?? '');
$entryNeedsCourse = \Wwm\Models\EmailAutomation::entryModeRequiresCourseSlug($currentEntryMode);
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Automation</p>
    <h1 class="page-title page-title-sm"><?= wwm_escape($flowTitle) ?></h1>
    <p class="field-hint"><code><?= wwm_escape((string)($a['slug'] ?? '')) ?></code></p>
  </div>
  <div class="admin-table-actions" style="justify-content:flex-end">
    <form method="post" action="/admin/automations/<?= $id ?>/duplicate" class="inline-form" data-confirm="Create a copy of this process? The copy will be off.">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <button type="submit" class="btn btn-ghost btn-sm">Copy</button>
    </form>
    <?php if ($isArchivedFlow): ?>
      <form method="post" action="/admin/automations/<?= $id ?>/unarchive" class="inline-form" data-confirm="Restore this process from the archive?">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Restore</button>
      </form>
    <?php else: ?>
      <form method="post" action="/admin/automations/<?= $id ?>/archive" class="inline-form" data-confirm="Archive this process? It will be turned off.">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Archive</button>
      </form>
    <?php endif; ?>
    <?php if ($canDeleteFlow): ?>
      <form method="post" action="/admin/automations/<?= $id ?>/delete" class="inline-form" data-confirm-danger data-confirm="Delete this process permanently, including its run history?">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    <?php endif; ?>
    <a href="/admin/guide?section=automations" class="btn btn-ghost btn-sm">Guide</a>
    <a href="/admin/automations" class="btn btn-ghost btn-sm">← All flows</a>
  </div>
</div>

<?php if ($isArchivedFlow): ?>
  <div class="alert alert-warning">
    This process is in the <strong>archive</strong> and does not enroll anyone. Restore it, or edit a copy.
  </div>
<?php endif; ?>

<?php
$flowIsActive = !empty($a['is_active']);
?>
<div
  class="automation-active-panel<?= $flowIsActive ? ' is-on' : ' is-off' ?><?= $isArchivedFlow ? ' is-archived' : '' ?>"
  id="automation-active-panel"
>
  <div class="automation-active-panel__main">
    <p class="automation-active-panel__title" id="automation-active-panel-title">
      <?= $flowIsActive ? 'Process is on' : 'Process is off' ?>
    </p>
    <p class="automation-active-panel__hint field-hint" id="automation-active-panel-hint">
      <?php if ($isArchivedFlow): ?>
        While it is archived, enrollment and steps do not run.
      <?php elseif ($flowIsActive): ?>
        The chosen entry type runs (demo, payment, manual enroll). Press <strong>Save</strong> after you change anything.
      <?php else: ?>
        Enrollment and steps stay off until you turn it on and save. For a demo funnel, turn off the matching business process in AVO.
      <?php endif; ?>
    </p>
  </div>
  <label class="automation-active-switch" title="Turn the process on or off">
    <span class="automation-active-switch__label" aria-hidden="true"><?= $flowIsActive ? 'On' : 'Off' ?></span>
    <input
      type="checkbox"
      name="is_active"
      value="1"
      form="automation-edit-form"
      id="automation-is-active"
      class="automation-active-switch__input"
      <?= $flowIsActive ? ' checked' : '' ?>
      <?= $isArchivedFlow ? ' disabled' : '' ?>
    >
    <span class="automation-active-switch__track" aria-hidden="true"></span>
  </label>
</div>
<script>
(function () {
  const panel = document.getElementById('automation-active-panel');
  const input = document.getElementById('automation-is-active');
  const title = document.getElementById('automation-active-panel-title');
  const hint = document.getElementById('automation-active-panel-hint');
  const switchLabel = panel && panel.querySelector('.automation-active-switch__label');
  if (!panel || !input || input.disabled) return;
  const hints = {
    on: 'The chosen entry type runs (demo, payment, manual enroll). Press Save after you change anything.',
    off: 'Enrollment and steps stay off until you turn it on and save. For a demo funnel, turn off the matching business process in AVO.',
  };
  function sync() {
    const on = input.checked;
    panel.classList.toggle('is-on', on);
    panel.classList.toggle('is-off', !on);
    if (title) title.textContent = on ? 'Process is on' : 'Process is off';
    if (hint) hint.textContent = on ? hints.on : hints.off;
    if (switchLabel) switchLabel.textContent = on ? 'On' : 'Off';
  }
  input.addEventListener('change', sync);
})();
</script>

<div class="admin-card automation-identity">
  <div class="automation-settings__grid">
    <label class="field">
      <span class="field-label">Name</span>
      <input type="text" name="title" form="automation-edit-form" value="<?= wwm_escape((string)($a['title'] ?? '')) ?>" required>
    </label>
    <label class="field">
      <span class="field-label">Description</span>
      <textarea name="description" form="automation-edit-form" rows="3"><?= wwm_escape((string)($a['description'] ?? '')) ?></textarea>
    </label>
  </div>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php
$errors = [
    'csrf' => 'Session expired. Try again.',
    'invalid_json' => 'Definition JSON is not valid.',
    'invalid_definition' => 'The flow failed the check: connections, block settings, or branch logic. Save again from the editor — the list of issues appears before it is sent.',
    'upload' => 'Could not read uploaded file.',
    'import' => 'AVO export could not be imported.',
    'filter_required' => 'For “Matching the filter”, set at least one filter.',
    'bulk_none' => 'Nobody matches this filter.',
    'bulk_limit' => 'The audience is too large — narrow the filter or split it.',
    'bulk_inactive' => 'Turn the process on (the switch at the top) and save before enrolling an audience.',
    'bulk_archived' => 'This process is archived — restore it, or use another one.',
    'enroll_email' => 'Enter the student’s email.',
    'enroll_not_found' => 'No student with that email.',
    'enroll_failed' => 'Could not enroll (the process is off or archived).',
    'cancel_failed' => 'Could not remove them: the run was not found or is no longer active.',
    'advance_failed' => 'Could not move them forward: the run is not active, or the step failed.',
    'run_not_active' => 'The run was not found or is no longer active.',
    'automation_inactive' => 'The process is off or archived — it cannot be advanced.',
    'invalid_branch' => 'That branch is not valid.',
    'invalid_node' => 'The run’s current block is not in the flow.',
    'course_required' => 'This entry type needs a course slug.',
];
$err = (string)($error ?? '');
if ($err !== '' && isset($errors[$err])): ?>
  <div class="alert alert-error"><?= wwm_escape($errors[$err]) ?></div>
<?php endif; ?>

<?php
$flowRuns = is_array($flowRuns ?? null) ? $flowRuns : [];
$flowRunsTotal = (int)($flowRunsTotal ?? count($flowRuns));
$flowRunsPage = max(1, (int)($flowRunsPage ?? 1));
$flowRunsPages = max(1, (int)($flowRunsPages ?? 1));
$openRunsPanel = !empty($openRunsPanel);
$nodeLabel = static function (string $nodeId) use ($nodes): string {
    $node = $nodes[$nodeId] ?? null;
    if (is_array($node) && trim((string)($node['label'] ?? '')) !== '') {
        return (string)$node['label'];
    }
    return $nodeId !== '' ? $nodeId : '—';
};
$formatRunAt = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('d.m.Y H:i', $ts) : $iso;
};
$runsPageUrl = static function (int $p) use ($id): string {
    $qs = $_GET;
    $qs['runs_page'] = $p;
    return '/admin/automations/' . $id . '/edit?' . http_build_query($qs) . '#automation-runs';
};
$runsSummaryHint = $flowRunsTotal === 0
    ? 'Nobody yet'
    : ($flowActiveRuns . ' active · total ' . $flowRunsTotal
        . ($flowRunsPages > 1 ? ' · page ' . $flowRunsPage . '/' . $flowRunsPages : ''));
?>
<details class="admin-card admin-expander" id="automation-runs" style="margin-bottom:16px"<?= $openRunsPanel ? ' open' : '' ?>>
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2 class="admin-team-section-title" style="margin:0">Students in this process</h2>
      <span class="field-hint"><?= wwm_escape($runsSummaryHint) ?></span>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
  <p class="field-hint">
    A person in the process shows up as a row here and in the <strong>Active runs</strong> count on the process list.
    A demo from the student card and the <code>/api/demo</code> webhook enroll every <strong>active</strong> process whose entry type is demo and whose course slug matches.
    Right after entry the student moves through blocks until the first pause. After that, cron takes the next steps every 5–15 minutes.
    <strong>Advance</strong> skips the current pause and moves on to the next pause or the end.
    If the next block is a branch, pick Yes / No, or Auto to follow the condition.
    <strong>Remove</strong> stops the active run (letters stop).
  </p>
  <?php if ($flowRuns === []): ?>
    <p class="field-hint">Nobody here yet. If a demo was granted by hand, enroll the email below (the process must be on).</p>
  <?php else: ?>
    <div class="admin-table-wrap admin-table-wrap--profile" style="margin-top:12px">
      <table class="admin-table admin-table-compact admin-table--profile">
        <thead>
          <tr>
            <th>Student</th>
            <th>Status</th>
            <th>On block</th>
            <th class="col-date">Enrolled</th>
            <th class="col-date">Next step</th>
            <th class="col-tight"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($flowRuns as $run): ?>
            <?php
              $runStatus = (string)($run['status'] ?? '');
              $badge = $runStatus === 'active' ? 'badge-paid' : ($runStatus === 'completed' ? 'badge-demo' : 'badge-draft');
              $statusLabel = $runStatus === 'active' ? 'In progress' : ($runStatus === 'completed' ? 'Finished' : ($runStatus === 'cancelled' ? 'Removed' : $runStatus));
              $runName = trim((string)($run['name'] ?? ''));
              $runEmail = (string)($run['email'] ?? '');
              $runId = (int)($run['id'] ?? 0);
              $runNodeId = (string)($run['current_node_id'] ?? '');
              $forkNodeId = ($runStatus === 'active' && is_array($def))
                  ? \Wwm\Services\EmailAutomationRunner::pendingForkNodeId($def, $runNodeId)
                  : null;
              $forkBranches = ($forkNodeId !== null)
                  ? \Wwm\Services\EmailAutomationRunner::forkBranches($def, $forkNodeId)
                  : [];
            ?>
            <tr>
              <td>
                <a href="/admin/students/<?= (int)($run['user_id'] ?? 0) ?>"><?= wwm_escape($runName !== '' ? $runName : $runEmail) ?></a>
                <?php if ($runName !== '' && $runEmail !== ''): ?>
                  <br><span class="field-hint"><?= wwm_escape($runEmail) ?></span>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $badge ?>"><?= wwm_escape($statusLabel) ?></span></td>
              <td><?= wwm_escape($nodeLabel($runNodeId)) ?></td>
              <td class="col-date"><?= wwm_escape($formatRunAt(isset($run['enrolled_at']) ? (string)$run['enrolled_at'] : null)) ?></td>
              <td class="col-date"><?= wwm_escape($formatRunAt(isset($run['next_run_at']) ? (string)$run['next_run_at'] : null)) ?></td>
              <td class="col-tight">
                <?php if ($runStatus === 'active' && $runId > 0): ?>
                  <form method="post" action="/admin/automations/<?= $id ?>/runs/<?= $runId ?>/advance" class="inline-form" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:6px" data-confirm="Move this student forward in the process?">
                    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                    <?php if ($forkBranches !== []): ?>
                      <select name="branch" style="max-width:140px" title="Branch at <?= wwm_escape($nodeLabel((string)$forkNodeId)) ?>">
                        <option value="auto">Auto</option>
                        <?php foreach ($forkBranches as $br): ?>
                          <?php
                            $brLabel = $br === 'yes' ? 'Yes' : ($br === 'no' ? 'No' : $br);
                          ?>
                          <option value="<?= wwm_escape($br) ?>"><?= wwm_escape($brLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input type="hidden" name="branch" value="auto">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-sm">Advance</button>
                  </form>
                  <form method="post" action="/admin/automations/<?= $id ?>/runs/<?= $runId ?>/cancel" class="inline-form" data-confirm="Remove this student from the process? Letters and pauses stop. You can enroll them again by hand (a payment process will not enroll them again).">
                    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($flowRunsPages > 1): ?>
      <div class="admin-table-actions" style="margin-top:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <span class="field-hint">Page <?= (int)$flowRunsPage ?> of <?= (int)$flowRunsPages ?> (20 per page)</span>
        <span style="display:flex;gap:8px">
          <?php if ($flowRunsPage > 1): ?>
            <a href="<?= wwm_escape($runsPageUrl($flowRunsPage - 1)) ?>" class="btn btn-ghost btn-sm">← Back</a>
          <?php endif; ?>
          <?php if ($flowRunsPage < $flowRunsPages): ?>
            <a href="<?= wwm_escape($runsPageUrl($flowRunsPage + 1)) ?>" class="btn btn-ghost btn-sm">Next →</a>
          <?php endif; ?>
        </span>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  <?php if (!$isArchivedFlow): ?>
  <form method="post" action="/admin/automations/<?= $id ?>/enroll" class="admin-filter-grid" style="margin-top:16px">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <label class="field admin-student-suggest" data-student-suggest>
      <span class="field-label">Enroll by email</span>
      <input type="text" name="student_email" required placeholder="Name or email, from 3 characters" autocomplete="off" spellcheck="false" inputmode="email">
      <ul class="admin-student-suggest__list" hidden></ul>
    </label>
    <div class="field" style="align-self:flex-end">
      <button type="submit" class="btn btn-ghost">Add at the start</button>
    </div>
  </form>
  <?php endif; ?>
  </div>
</details>

<form method="post" action="/admin/automations/<?= $id ?>" class="admin-card automation-flow-editor" id="automation-edit-form">
  <h2 class="admin-team-section-title">Visual flow editor</h2>
  <div id="automation-flow-shell" class="automation-flow-shell">
  <p class="field-hint">A connection goes from an output to an input. <strong>Disconnect</strong> — drag a line off an output or input into empty space and release. Or click the line, then “Remove connection” / Delete. The wheel zooms. Drag the canvas with the <strong>right mouse button</strong> on empty space. Numbers on the left of a block: top is who is on this step, bottom is who already passed it. Click a number to open the list.</p>
  <div class="automation-flow-toolbar">
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-undo" disabled title="Ctrl+Z">Undo</button>
    <span class="automation-flow-zoom-wrap" title="Ctrl + mouse wheel on the canvas">
      <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-zoom-out" aria-label="Zoom out">−</button>
      <input type="range" id="automation-flow-zoom-slider" class="automation-flow-zoom-slider" min="50" max="160" step="5" value="100" aria-label="Canvas zoom">
      <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-zoom-in" aria-label="Zoom in">+</button>
      <span id="automation-flow-zoom-label" class="automation-flow-zoom-label">100%</span>
    </span>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-zoom-reset" title="Reset zoom and center">Reset</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-goto-start">Go to start</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-layout">Arrange blocks</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-remove-connection" disabled>Remove connection</button>
    <button type="button" class="btn btn-primary btn-sm" id="automation-flow-save">Save</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-fullscreen">Full screen</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-sync-json">JSON from canvas</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-reload-json">Canvas from JSON</button>
  </div>
  <details class="automation-flow-json-drawer" id="automation-flow-json-drawer">
    <summary>Flow JSON</summary>
    <p class="field-hint">In full screen, edit JSON here. “Canvas from JSON” reads this field.</p>
    <textarea id="automation-flow-json-inline" class="admin-code-textarea automation-flow-json-inline" rows="14" spellcheck="false" aria-label="Definition JSON"></textarea>
  </details>
  <div
    id="automation-flow-app"
    data-course-slug="<?= wwm_escape($courseSlugFlow) ?>"
  >
    <div class="automation-flow-workspace">
      <div class="automation-flow-palette-wrap">
        <h3>Blocks</h3>
        <div id="automation-flow-palette" aria-label="Palette"></div>
      </div>
      <div id="drawflow" class="automation-flow-canvas"></div>
      <div class="automation-flow-props-wrap">
        <h3>Properties</h3>
        <div id="automation-flow-props">
          <p class="field-hint">Select a block on the canvas.</p>
        </div>
      </div>
    </div>
    <script type="application/json" id="automation-flow-config"><?= wwm_json_for_script($flowEditorConfig) ?></script>
    <script type="application/json" id="automation-flow-definition"><?= wwm_json_for_script($def) ?></script>
  </div>
  </div>

  <div class="automation-settings">
    <h2 class="admin-team-section-title">Settings</h2>
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

    <input type="hidden" name="entry_mode" id="automation-entry-mode" value="<?= wwm_escape($currentEntryMode) ?>">
    <input type="hidden" name="course_slug" id="automation-settings-course" value="<?= wwm_escape($currentCourseSlug) ?>">
    <p class="field-hint automation-settings__hint" id="automation-entry-settings-hint">Entry type and the process course are set on the <strong>Start</strong> block (properties on the right).</p>

    <details class="automation-flow-advanced">
      <summary>Definition (JSON) — advanced</summary>
      <label class="field">
        <span class="field-hint">Nodes, edges, delays (seconds), templates, conditions. Saving the form fills this in from the canvas above.</span>
        <textarea name="definition_json" rows="20" class="admin-code-textarea" spellcheck="false"><?= wwm_textarea_raw((string)($definitionPretty ?? '')) ?></textarea>
      </label>
    </details>

    <div class="admin-form-footer">
      <button type="submit" class="btn btn-primary">Save</button>
    </div>
  </div>
</form>

<form method="post" action="/admin/automations/<?= $id ?>/import-avo" enctype="multipart/form-data" class="admin-card automation-import-avo">
  <h2 class="admin-team-section-title">Import from AVO</h2>
  <p class="field-hint">JSON exported from an AVO business process. After upload the flow appears in the editor — check it and save.</p>
  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
  <div class="automation-import-avo__row">
    <label class="field">
      <span class="field-label">JSON file</span>
      <input type="file" name="avo_export" accept=".json,application/json" required>
    </label>
    <div class="automation-import-avo__actions">
      <button type="submit" class="btn btn-ghost">Import</button>
    </div>
  </div>
</form>
