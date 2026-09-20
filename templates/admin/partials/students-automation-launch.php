<?php
/** @var list<array<string, mixed>> $launchAutomations */
/** @var \Wwm\Services\AdminStudentListFilter $listFilter */
/** @var list<array<string, mixed>> $filterCourses */
use Wwm\Services\AdminStudentListFilter;
use Wwm\Services\BroadcastAudience;
use Wwm\Services\EmailAutomationEnrollment;

$launchAutomations = is_array($launchAutomations ?? null) ? $launchAutomations : [];
$listFilter = $listFilter ?? AdminStudentListFilter::fromArray([]);
$filterCourses = is_array($filterCourses ?? null) ? $filterCourses : [];
if ($launchAutomations === []) {
    return;
}

$pdo = wwm_pdo();
$launchAudience = 'filtered';
if (!$listFilter->isActive()) {
    $launchAudience = 'with_access';
}
$launchAudienceSize = BroadcastAudience::countForBroadcast($pdo, $launchAudience, $listFilter);
$bulkLimit = EmailAutomationEnrollment::BULK_ENROLL_LIMIT;
?>
<div class="admin-card students-automation-launch" id="students-automation-launch">
  <h2 class="admin-team-section-title" style="margin-top:0">Запуск в процесс</h2>
  <p class="field-hint">
    Зачисление в начало email-automation. Аудитория считается по всем подходящим ученикам, не только по строкам на этой странице.
    Процесс должен быть <strong>Active</strong>. Не больше <?= (int)$bulkLimit ?> человек за раз.
  </p>

  <form
    method="post"
    id="students-automation-launch-form"
    action=""
    data-confirm-title="Запустить процесс?"
    data-confirm-ok="Направить"
    data-confirm-danger
    data-confirm="Зачислить выбранную аудиторию в начало процесса? Уже активные в этом процессе будут пропущены."
  >
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <input type="hidden" name="redirect" value="students">

    <div class="admin-filter-grid" style="margin-bottom:12px">
      <label class="field">
        <span class="field-label">Процесс</span>
        <select name="_automation_pick" id="students-automation-pick" required>
          <option value="">— выберите —</option>
          <?php foreach ($launchAutomations as $flow): ?>
            <?php $fid = (int)$flow['id']; ?>
            <option value="<?= $fid ?>" data-course="<?= wwm_escape((string)($flow['course_slug'] ?? '')) ?>"<?= empty($flow['is_active']) ? ' disabled' : '' ?>>
              <?= wwm_escape((string)$flow['title']) ?><?= empty($flow['is_active']) ? ' (выключен)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Аудитория</span>
        <select name="audience" id="students-automation-audience">
          <option value="filtered"<?= $launchAudience === 'filtered' ? ' selected' : '' ?>>По фильтру ниже</option>
          <option value="with_access"<?= $launchAudience === 'with_access' ? ' selected' : '' ?>>Все с доступом к курсу</option>
          <option value="all_students">Все ученики</option>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Контекст курса (письма / условия)</span>
        <select name="enroll_course_slug" id="students-automation-course">
          <option value="">— из процесса —</option>
          <?php foreach ($filterCourses as $course): ?>
            <?php $slug = (string)($course['slug'] ?? ''); ?>
            <option value="<?= wwm_escape($slug) ?>"><?= wwm_escape((string)($course['title'] ?? $slug)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <p class="field-hint">
      Подходит под выбор: <strong id="students-automation-count"><?= (int)$launchAudienceSize ?></strong> учеников
    </p>

    <?php
      $audience = $launchAudience;
      require __DIR__ . '/broadcast-audience-filter.php';
    ?>

    <button type="submit" class="btn btn-primary btn-sm">Направить в процесс</button>
  </form>
</div>
