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
    'csrf' => 'Сессия истекла. Повторите действие.',
    'not_found' => 'Процесс не найден.',
    'archive_failed' => 'Не удалось отправить в архив.',
    'unarchive_failed' => 'Не удалось восстановить из архива.',
    'delete_blocked' => 'Удаление возможно только для выключенного процесса без активных запусков. Сначала снимите Active или дождитесь завершения runs, либо отправьте в архив.',
    'course_required' => 'Для выбранного типа входа укажите курс.',
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
  <form method="post" action="/admin/automations/run" data-confirm="Запустить все шаги automations, у которых подошло время?">
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
    <a href="/admin/automations" class="btn btn-sm <?= !$archivedView ? 'btn-primary' : 'btn-ghost' ?>">Активные и черновики</a>
    <a href="/admin/automations?view=archived" class="btn btn-sm <?= $archivedView ? 'btn-primary' : 'btn-ghost' ?>">Архив</a>
    <a href="/admin/guide?section=automations" class="btn btn-ghost btn-sm">Инструкция</a>
  </div>
</div>

<?php if (!$archivedView && $entryModeLabels !== []): ?>
<div class="admin-card" style="margin-bottom:16px">
  <h2 class="admin-team-section-title" style="margin-top:0">Новый процесс</h2>
  <form method="post" action="/admin/automations/create" class="automation-create-form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <label class="field">
      <span class="field-label">Название</span>
      <input type="text" name="title" required placeholder="Например: Post-purchase cross-sell">
    </label>
    <label class="field">
      <span class="field-label">Тип входа</span>
      <select name="entry_mode" id="automation-create-entry-mode">
        <?php foreach ($entryModeLabels as $mode => $modeLabel): ?>
          <option value="<?= wwm_escape($mode) ?>"><?= wwm_escape($modeLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field" id="automation-create-course-wrap">
      <span class="field-label">Курс</span>
      <?php if ($courseSlugList !== []): ?>
        <select name="course_slug" id="automation-create-course">
          <option value="">— не привязан —</option>
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
      <button type="submit" class="btn btn-primary">Создать и открыть редактор</button>
    </div>
  </form>
  <p class="field-hint" style="margin-top:12px">
    Готовый cross-sell после оплаты уже в списке ниже (<code>post-purchase-cross-sell</code>) — синхронизируется из репозитория при каждом запросе к кабинету.
  </p>
  <script>
  (function () {
    const mode = document.getElementById('automation-create-entry-mode');
    const course = document.getElementById('automation-create-course');
    if (!mode || !course) return;
    const needs = { demo_grant: true, payment_course: true, manual: false, payment_any: false };
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
          <tr><td colspan="6" class="field-hint"><?= $archivedView ? 'Архив пуст.' : 'Нет процессов. Выполните migrate.php или откройте любую страницу админки (схема подтянется автоматически).' ?></td></tr>
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
              <form method="post" action="/admin/automations/<?= $id ?>/duplicate" class="inline-form" data-confirm="Создать копию «<?= wwm_escape($title) ?>»? Копия будет выключена.">
                <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                <button type="submit" class="btn btn-ghost btn-sm">Copy</button>
              </form>
              <?php if ($archivedView): ?>
                <form method="post" action="/admin/automations/<?= $id ?>/unarchive" class="inline-form" data-confirm="Восстановить «<?= wwm_escape($title) ?>» из архива?">
                  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">Restore</button>
                </form>
              <?php else: ?>
                <form method="post" action="/admin/automations/<?= $id ?>/archive" class="inline-form" data-confirm="Отправить «<?= wwm_escape($title) ?>» в архив? Процесс будет выключен, новые ученики не попадут в цепочку.">
                  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
                  <button type="submit" class="btn btn-ghost btn-sm">Archive</button>
                </form>
              <?php endif; ?>
              <?php if ($canDelete): ?>
                <form method="post" action="/admin/automations/<?= $id ?>/delete" class="inline-form" data-confirm-danger data-confirm="Удалить «<?= wwm_escape($title) ?>» безвозвратно? Удалятся и все записи запусков в базе.">
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
  <p class="field-hint" style="margin-top:16px">После входа ученик сразу идёт до первой паузы. Дальше cron: <code>php scripts/run-email-automations.php</code> каждые 5–15 минут.</p>
  <?php endif; ?>
</div>
