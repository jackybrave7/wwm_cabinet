<?php
$a = $automation ?? [];
$id = (int)($a['id'] ?? 0);
$def = json_decode((string)($a['definition_json'] ?? '{}'), true);
$nodes = is_array($def['nodes'] ?? null) ? $def['nodes'] : [];
$edges = is_array($def['edges'] ?? null) ? $def['edges'] : [];
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Automation</p>
    <h1 class="page-title page-title-sm"><?= wwm_escape((string)($a['title'] ?? 'Flow')) ?></h1>
    <p class="field-hint"><code><?= wwm_escape((string)($a['slug'] ?? '')) ?></code></p>
  </div>
  <a href="/admin/automations" class="btn btn-ghost btn-sm">← All flows</a>
</div>

<?php if (empty($a['is_active'])): ?>
  <div class="alert alert-warning">
    This flow is <strong>off</strong>. No students are enrolled and no steps run until you check <strong>Active</strong> below and save. AVO webhooks and demo email behave as today.
  </div>
<?php endif; ?>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php
$errors = [
    'csrf' => 'Session expired. Try again.',
    'invalid_json' => 'Definition JSON is not valid.',
    'invalid_definition' => 'Definition must include start node, nodes, and edges.',
    'upload' => 'Could not read uploaded file.',
    'import' => 'AVO export could not be imported.',
];
$err = (string)($error ?? '');
if ($err !== '' && isset($errors[$err])): ?>
  <div class="alert alert-error"><?= wwm_escape($errors[$err]) ?></div>
<?php endif; ?>

<?php
$nodeStats = is_array($nodeStats ?? null) ? $nodeStats : [];
$stepEventTotal = (int)($stepEventTotal ?? 0);
$nodesById = $nodes;
?>
<div class="admin-card" style="margin-bottom:16px">
  <h2 class="admin-team-section-title">Step statistics</h2>
  <p class="field-hint">How many times each node was reached (unique students and total passes). Logged only while the flow is active.</p>
  <?php if ($stepEventTotal === 0): ?>
    <p class="field-hint">No events yet.</p>
  <?php else: ?>
    <div class="admin-table-wrap admin-table-wrap--profile" style="margin-top:12px">
      <table class="admin-table admin-table-compact admin-table--profile">
        <thead>
          <tr>
            <th>Node</th>
            <th>Type</th>
            <th class="col-num">Students</th>
            <th class="col-num">Passes</th>
            <th class="col-date">Last</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($nodeStats as $stat): ?>
            <?php
              $nid = (string)$stat['node_id'];
              $nodeMeta = $nodesById[$nid] ?? null;
              $label = is_array($nodeMeta) ? (string)($nodeMeta['label'] ?? $nid) : $nid;
            ?>
            <tr>
              <td class="col-subject">
                <strong><?= wwm_escape($nid) ?></strong><br>
                <span class="field-hint"><?= wwm_escape($label) ?></span>
              </td>
              <td><?= wwm_escape((string)$stat['node_type']) ?></td>
              <td class="col-num"><?= (int)$stat['unique_users'] ?></td>
              <td class="col-num"><?= (int)$stat['hits'] ?></td>
              <td class="col-date"><span class="field-hint"><?= wwm_escape((string)($stat['last_at'] ?? '—')) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="field-hint" style="margin-top:8px">Total logged events: <?= $stepEventTotal ?></p>
  <?php endif; ?>
</div>

<div class="admin-card" style="margin-bottom:16px">
  <h2 class="admin-team-section-title">Flow overview</h2>
  <p class="field-hint"><?= wwm_escape((string)($a['description'] ?? '')) ?></p>
  <ol class="automation-step-list">
    <?php
    $order = [];
    $seen = [];
    $walk = static function (string $nodeId, array &$order, array &$seen) use ($def, &$walk): void {
        if ($nodeId === '' || isset($seen[$nodeId])) {
            return;
        }
        $seen[$nodeId] = true;
        $order[] = $nodeId;
        foreach ($def['edges'] ?? [] as $edge) {
            if (!is_array($edge) || (string)($edge['from'] ?? '') !== $nodeId) {
                continue;
            }
            $walk((string)($edge['to'] ?? ''), $order, $seen);
        }
    };
    $walk('start', $order, $seen);
    foreach ($order as $nodeId):
        $node = $nodes[$nodeId] ?? null;
        if (!is_array($node)) {
            continue;
        }
        $label = (string)($node['label'] ?? $nodeId);
        $type = (string)($node['type'] ?? '');
    ?>
      <li><strong><?= wwm_escape($nodeId) ?></strong> — <?= wwm_escape($type) ?>: <?= wwm_escape($label) ?></li>
    <?php endforeach; ?>
  </ol>
</div>

<form method="post" action="/admin/automations/<?= $id ?>" class="admin-card">
  <h2 class="admin-team-section-title">Settings</h2>
  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

  <label class="field">
    <span class="field-label">Title</span>
    <input type="text" name="title" value="<?= wwm_escape((string)($a['title'] ?? '')) ?>" required>
  </label>

  <label class="field">
    <span class="field-label">Description</span>
    <textarea name="description" rows="2"><?= wwm_escape((string)($a['description'] ?? '')) ?></textarea>
  </label>

  <label class="field">
    <span class="field-label">Course slug</span>
    <input type="text" name="course_slug" value="<?= wwm_escape((string)($a['course_slug'] ?? '')) ?>" pattern="[a-z0-9\-]+" required>
  </label>

  <label class="field field-checkbox">
    <input type="checkbox" name="is_active" value="1"<?= !empty($a['is_active']) ? ' checked' : '' ?>>
    <span><strong>Active</strong> — when enabled, new demo grants start this flow and cron sends scheduled emails. Leave unchecked until you are ready to replace the AVO business process.</span>
  </label>

  <label class="field">
    <span class="field-label">Definition (JSON)</span>
    <span class="field-hint">Nodes, edges, delays (seconds), templates, conditions. Saved as the live business process.</span>
    <textarea name="definition_json" rows="28" class="admin-code-textarea" spellcheck="false"><?= wwm_textarea_raw((string)($definitionPretty ?? '')) ?></textarea>
  </label>

  <div class="admin-form-footer">
    <button type="submit" class="btn btn-primary">Save</button>
  </div>
</form>

<form method="post" action="/admin/automations/<?= $id ?>/import-avo" enctype="multipart/form-data" class="admin-card" style="margin-top:16px">
  <h2 class="admin-team-section-title">Import AVO export</h2>
  <p class="field-hint">Upload JSON from AVO business process export. Maps to the canonical Elke demo funnel; then edit JSON here.</p>
  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
  <label class="field">
    <span class="field-label">AVO JSON file</span>
    <input type="file" name="avo_export" accept=".json,application/json" required>
  </label>
  <button type="submit" class="btn btn-ghost">Import</button>
</form>
