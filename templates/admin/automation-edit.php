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
    <form method="post" action="/admin/automations/<?= $id ?>/duplicate" class="inline-form" data-confirm="Создать копию этого процесса? Копия будет выключена.">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <button type="submit" class="btn btn-ghost btn-sm">Copy</button>
    </form>
    <?php if ($isArchivedFlow): ?>
      <form method="post" action="/admin/automations/<?= $id ?>/unarchive" class="inline-form" data-confirm="Восстановить процесс из архива?">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Restore</button>
      </form>
    <?php else: ?>
      <form method="post" action="/admin/automations/<?= $id ?>/archive" class="inline-form" data-confirm="Отправить в архив? Процесс будет выключен.">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-ghost btn-sm">Archive</button>
      </form>
    <?php endif; ?>
    <?php if ($canDeleteFlow): ?>
      <form method="post" action="/admin/automations/<?= $id ?>/delete" class="inline-form" data-confirm-danger data-confirm="Удалить процесс безвозвратно вместе с историей запусков?">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    <?php endif; ?>
    <a href="/admin/guide?section=automations" class="btn btn-ghost btn-sm">Инструкция</a>
    <a href="/admin/automations" class="btn btn-ghost btn-sm">← All flows</a>
  </div>
</div>

<?php if ($isArchivedFlow): ?>
  <div class="alert alert-warning">
    Процесс в <strong>архиве</strong> и не участвует в зачислении. Восстановите из архива или отредактируйте копию.
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
      <?= $flowIsActive ? 'Процесс включён' : 'Процесс выключен' ?>
    </p>
    <p class="automation-active-panel__hint field-hint" id="automation-active-panel-hint">
      <?php if ($isArchivedFlow): ?>
        В архиве зачисление и шаги не выполняются.
      <?php elseif ($flowIsActive): ?>
        Срабатывает выбранный тип входа (демо, оплата, ручной запуск). Не забудьте нажать <strong>Сохранить</strong> после изменений.
      <?php else: ?>
        Зачисления и шаги не идут, пока не включите и не сохраните. Для демо-воронки отключите дублирующий BP в AVO.
      <?php endif; ?>
    </p>
  </div>
  <label class="automation-active-switch" title="Включить или выключить процесс">
    <span class="automation-active-switch__label" aria-hidden="true"><?= $flowIsActive ? 'Вкл' : 'Выкл' ?></span>
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
    on: 'Срабатывает выбранный тип входа (демо, оплата, ручной запуск). Не забудьте нажать Сохранить после изменений.',
    off: 'Зачисления и шаги не идут, пока не включите и не сохраните. Для демо-воронки отключите дублирующий BP в AVO.',
  };
  function sync() {
    const on = input.checked;
    panel.classList.toggle('is-on', on);
    panel.classList.toggle('is-off', !on);
    if (title) title.textContent = on ? 'Процесс включён' : 'Процесс выключен';
    if (hint) hint.textContent = on ? hints.on : hints.off;
    if (switchLabel) switchLabel.textContent = on ? 'Вкл' : 'Выкл';
  }
  input.addEventListener('change', sync);
})();
</script>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php
$errors = [
    'csrf' => 'Session expired. Try again.',
    'invalid_json' => 'Definition JSON is not valid.',
    'invalid_definition' => 'Схема не прошла проверку: связи, параметры блоков или логика веток. Сохраните снова из редактора — перед отправкой покажется список замечаний.',
    'upload' => 'Could not read uploaded file.',
    'import' => 'AVO export could not be imported.',
    'filter_required' => 'Для режима «По фильтру» задайте хотя бы одно условие отбора.',
    'bulk_none' => 'По фильтру никого не найдено.',
    'bulk_limit' => 'Слишком большая аудитория — сузьте фильтр или разбейте на части.',
    'bulk_inactive' => 'Включите процесс (переключатель вверху) и сохраните перед запуском аудитории.',
    'bulk_archived' => 'Процесс в архиве — восстановите или используйте другой.',
    'enroll_email' => 'Укажите email ученика.',
    'enroll_not_found' => 'Ученик с таким email не найден.',
    'enroll_failed' => 'Не удалось зачислить (процесс выключен или в архиве).',
    'course_required' => 'Для выбранного типа входа нужен course slug.',
];
$err = (string)($error ?? '');
if ($err !== '' && isset($errors[$err])): ?>
  <div class="alert alert-error"><?= wwm_escape($errors[$err]) ?></div>
<?php endif; ?>

<?php
$flowRuns = is_array($flowRuns ?? null) ? $flowRuns : [];
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
?>
<div class="admin-card" style="margin-bottom:16px">
  <h2 class="admin-team-section-title">Ученики в процессе</h2>
  <p class="field-hint">
    Попадание в процесс — строка здесь и число <strong>Active runs</strong> в списке процессов.
    Демо из карточки ученика и вебхук <code>/api/demo</code> зачисляют во все <strong>включённые</strong> процессы с типом входа «демо» и тем же slug курса.
  </p>
  <?php if ($flowRuns === []): ?>
    <p class="field-hint">Пока никого нет. Если демо уже выдано вручную — зачислите email ниже (процесс должен быть включён).</p>
  <?php else: ?>
    <div class="admin-table-wrap admin-table-wrap--profile" style="margin-top:12px">
      <table class="admin-table admin-table-compact admin-table--profile">
        <thead>
          <tr>
            <th>Ученик</th>
            <th>Статус</th>
            <th>Сейчас на блоке</th>
            <th class="col-date">Зачислен</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($flowRuns as $run): ?>
            <?php
              $runStatus = (string)($run['status'] ?? '');
              $badge = $runStatus === 'active' ? 'badge-paid' : ($runStatus === 'completed' ? 'badge-demo' : 'badge-draft');
              $statusLabel = $runStatus === 'active' ? 'В процессе' : ($runStatus === 'completed' ? 'Завершён' : $runStatus);
              $runName = trim((string)($run['name'] ?? ''));
              $runEmail = (string)($run['email'] ?? '');
            ?>
            <tr>
              <td>
                <a href="/admin/students/<?= (int)($run['user_id'] ?? 0) ?>"><?= wwm_escape($runName !== '' ? $runName : $runEmail) ?></a>
                <?php if ($runName !== '' && $runEmail !== ''): ?>
                  <br><span class="field-hint"><?= wwm_escape($runEmail) ?></span>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $badge ?>"><?= wwm_escape($statusLabel) ?></span></td>
              <td><?= wwm_escape($nodeLabel((string)($run['current_node_id'] ?? ''))) ?></td>
              <td class="col-date"><?= wwm_escape($formatRunAt(isset($run['enrolled_at']) ? (string)$run['enrolled_at'] : null)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php if (!$isArchivedFlow): ?>
  <form method="post" action="/admin/automations/<?= $id ?>/enroll" class="admin-filter-grid" style="margin-top:16px">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <label class="field">
      <span class="field-label">Зачислить по email</span>
      <input type="email" name="student_email" required placeholder="student@example.com" autocomplete="off">
    </label>
    <div class="field" style="align-self:flex-end">
      <button type="submit" class="btn btn-ghost">Добавить в начало</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<form method="post" action="/admin/automations/<?= $id ?>" class="admin-card automation-flow-editor" id="automation-edit-form">
  <h2 class="admin-team-section-title">Визуальный редактор сценария</h2>
  <div id="automation-flow-shell" class="automation-flow-shell">
  <p class="field-hint">Связь: от выхода к входу. <strong>Отсоединить</strong> — потяните линию с выхода или входа в пустое место и отпустите. Или клик по линии → «Удалить связь» / Delete. Колёсико — масштаб. Сдвиг схемы — зажатая <strong>ПКМ</strong> на пустом поле. Цифры слева у блока: сверху — кто сейчас на шаге, снизу — кто уже прошёл. Клик по числу открывает список.</p>
  <div class="automation-flow-toolbar">
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-undo" disabled title="Ctrl+Z">Отменить</button>
    <span class="automation-flow-zoom-wrap" title="Ctrl + колёсико мыши на схеме">
      <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-zoom-out" aria-label="Уменьшить">−</button>
      <input type="range" id="automation-flow-zoom-slider" class="automation-flow-zoom-slider" min="50" max="160" step="5" value="100" aria-label="Масштаб схемы">
      <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-zoom-in" aria-label="Увеличить">+</button>
      <span id="automation-flow-zoom-label" class="automation-flow-zoom-label">100%</span>
    </span>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-zoom-reset" title="Сбросить масштаб и центр">Сброс</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-goto-start">К старту</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-layout">Разложить блоки</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-remove-connection" disabled>Удалить связь</button>
    <button type="button" class="btn btn-primary btn-sm" id="automation-flow-save">Сохранить</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-fullscreen">На весь экран</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-sync-json">JSON из схемы</button>
    <button type="button" class="btn btn-ghost btn-sm" id="automation-flow-reload-json">Схема из JSON</button>
  </div>
  <details class="automation-flow-json-drawer" id="automation-flow-json-drawer">
    <summary>JSON сценария</summary>
    <p class="field-hint">В полноэкранном режиме правьте JSON здесь; «Схема из JSON» читает это поле.</p>
    <textarea id="automation-flow-json-inline" class="admin-code-textarea automation-flow-json-inline" rows="14" spellcheck="false" aria-label="Definition JSON"></textarea>
  </details>
  <div
    id="automation-flow-app"
    data-course-slug="<?= wwm_escape($courseSlugFlow) ?>"
  >
    <div class="automation-flow-workspace">
      <div class="automation-flow-palette-wrap">
        <h3>Блоки</h3>
        <div id="automation-flow-palette" aria-label="Palette"></div>
      </div>
      <div id="drawflow" class="automation-flow-canvas"></div>
      <div class="automation-flow-props-wrap">
        <h3>Свойства</h3>
        <div id="automation-flow-props">
          <p class="field-hint">Выберите блок на схеме.</p>
        </div>
      </div>
    </div>
    <script type="application/json" id="automation-flow-config"><?= wwm_json_for_script($flowEditorConfig) ?></script>
    <script type="application/json" id="automation-flow-definition"><?= wwm_json_for_script($def) ?></script>
  </div>
  </div>

  <h2 class="admin-team-section-title" style="margin-top:24px">Settings</h2>
  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

  <label class="field">
    <span class="field-label">Title</span>
    <input type="text" name="title" value="<?= wwm_escape((string)($a['title'] ?? '')) ?>" required>
  </label>

  <label class="field">
    <span class="field-label">Description</span>
    <textarea name="description" rows="2"><?= wwm_escape((string)($a['description'] ?? '')) ?></textarea>
  </label>

  <input type="hidden" name="entry_mode" id="automation-entry-mode" value="<?= wwm_escape($currentEntryMode) ?>">
  <input type="hidden" name="course_slug" id="automation-settings-course" value="<?= wwm_escape($currentCourseSlug) ?>">
  <p class="field-hint" id="automation-entry-settings-hint">Тип входа и курс процесса настраиваются в блоке <strong>«Старт»</strong> на схеме (свойства справа).</p>

  <details class="automation-flow-advanced">
    <summary>Definition (JSON) — для опытных</summary>
    <label class="field">
      <span class="field-hint">Nodes, edges, delays (seconds), templates, conditions. При сохранении формы подставляется из схемы выше.</span>
      <textarea name="definition_json" rows="20" class="admin-code-textarea" spellcheck="false"><?= wwm_textarea_raw((string)($definitionPretty ?? '')) ?></textarea>
    </label>
  </details>

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
