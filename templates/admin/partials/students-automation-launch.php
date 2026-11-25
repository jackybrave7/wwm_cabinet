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
  <h2 class="admin-team-section-title" style="margin-top:0">Enroll in a process</h2>
  <p class="field-hint">
    Enrolls people at the start of an email automation. The audience is everyone who matches, not only the rows on this page.
    The process must be <strong>Active</strong>. At most <?= (int)$bulkLimit ?> people at a time.
  </p>

  <form
    method="post"
    id="students-automation-launch-form"
    action=""
    data-confirm-title="Start this process?"
    data-confirm-ok="Enroll"
    data-confirm-danger
    data-confirm="Enroll the selected audience at the start of the process? People already active in it are skipped."
  >
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <input type="hidden" name="redirect" value="students">

    <div class="admin-filter-grid" style="margin-bottom:12px">
      <label class="field">
        <span class="field-label">Process</span>
        <select name="_automation_pick" id="students-automation-pick" required>
          <option value="">— choose —</option>
          <?php foreach ($launchAutomations as $flow): ?>
            <?php $fid = (int)$flow['id']; ?>
            <option value="<?= $fid ?>" data-course="<?= wwm_escape((string)($flow['course_slug'] ?? '')) ?>"<?= empty($flow['is_active']) ? ' disabled' : '' ?>>
              <?= wwm_escape((string)$flow['title']) ?><?= empty($flow['is_active']) ? ' (off)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Audience</span>
        <select name="audience" id="students-automation-audience">
          <option value="filtered"<?= $launchAudience === 'filtered' ? ' selected' : '' ?>>Matching the filter below</option>
          <option value="with_access"<?= $launchAudience === 'with_access' ? ' selected' : '' ?>>Everyone with course access</option>
          <option value="all_students">All students</option>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Course context (letters / conditions)</span>
        <select name="enroll_course_slug" id="students-automation-course">
          <option value="">— from the process —</option>
          <?php foreach ($filterCourses as $course): ?>
            <?php $slug = (string)($course['slug'] ?? ''); ?>
            <option value="<?= wwm_escape($slug) ?>"><?= wwm_escape((string)($course['title'] ?? $slug)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <p class="field-hint">
      Matching this choice: <strong id="students-automation-count"><?= (int)$launchAudienceSize ?></strong> students
    </p>

    <?php
      $audience = $launchAudience;
      require __DIR__ . '/broadcast-audience-filter.php';
    ?>

    <button type="submit" class="btn btn-primary btn-sm">Enroll in process</button>
  </form>
</div>
