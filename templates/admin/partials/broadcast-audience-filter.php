<?php
/** @var \Wwm\Services\AdminStudentListFilter $listFilter */
/** @var list<array<string, mixed>> $filterCourses */
use Wwm\Services\AdminStudentListFilter;

$listFilter = $listFilter ?? AdminStudentListFilter::fromArray([]);
$filterCourses = is_array($filterCourses ?? null) ? $filterCourses : [];
$filterOpen = ($listFilter->isActive() || (string)($audience ?? '') === 'filtered');
?>
<details class="admin-card admin-expander admin-filter-panel broadcast-audience-filter" id="broadcast-audience-filter"<?= $filterOpen ? ' open' : '' ?>>
  <summary class="admin-expander-summary">
    <span class="admin-expander-summary-text">
      <h2>Student filter</h2>
      <span class="field-hint">Access, course, registration, activity, location, UTM — same as Students list</span>
    </span>
    <span class="admin-expander-chevron" aria-hidden="true">▼</span>
  </summary>
  <div class="admin-expander-body">
    <div class="admin-filter-grid">
      <label class="field">
        <span>Search name or email</span>
        <input type="search" name="bf_q" value="<?= wwm_escape($listFilter->search) ?>" placeholder="contains…" autocomplete="off">
      </label>
      <label class="field">
        <span>Access</span>
        <select name="bf_access" data-broadcast-audience-field>
          <?php foreach (AdminStudentListFilter::ACCESS_OPTIONS as $value => $label): ?>
            <option value="<?= wwm_escape($value) ?>"<?= $listFilter->access === $value ? ' selected' : '' ?>><?= wwm_escape($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span>Course</span>
        <select name="bf_course" data-broadcast-audience-field>
          <option value="">Any course</option>
          <?php foreach ($filterCourses as $course): ?>
            <?php $slug = (string)($course['slug'] ?? ''); ?>
            <option value="<?= wwm_escape($slug) ?>"<?= $listFilter->courseSlug === $slug ? ' selected' : '' ?>><?= wwm_escape((string)($course['title'] ?? $slug)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span>Course access type</span>
        <select name="bf_course_access" data-broadcast-audience-field>
          <option value="any"<?= ($listFilter->courseAccess === '' || $listFilter->courseAccess === 'any') ? ' selected' : '' ?>>Any grant</option>
          <option value="paid"<?= $listFilter->courseAccess === 'paid' ? ' selected' : '' ?>>Paid (active)</option>
          <option value="demo"<?= $listFilter->courseAccess === 'demo' ? ' selected' : '' ?>>Demo (active)</option>
        </select>
      </label>
      <label class="field">
        <span>Registered from</span>
        <input type="date" name="bf_registered_from" value="<?= wwm_escape($listFilter->registeredFrom) ?>" data-broadcast-audience-field>
      </label>
      <label class="field">
        <span>Registered to</span>
        <input type="date" name="bf_registered_to" value="<?= wwm_escape($listFilter->registeredTo) ?>" data-broadcast-audience-field>
      </label>
      <label class="field">
        <span>Lesson activity</span>
        <select name="bf_activity" data-broadcast-audience-field>
          <?php foreach (AdminStudentListFilter::ACTIVITY_OPTIONS as $value => $label): ?>
            <option value="<?= wwm_escape($value) ?>"<?= $listFilter->activity === $value ? ' selected' : '' ?>><?= wwm_escape($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span>Country</span>
        <input type="text" name="bf_country" value="<?= wwm_escape($listFilter->country) ?>" placeholder="e.g. Germany" autocomplete="off" data-broadcast-audience-field>
      </label>
      <label class="field field-checkbox">
        <span>UTM</span>
        <label class="checkbox-inline">
          <input type="checkbox" name="bf_has_utm" value="1"<?= $listFilter->hasUtm === '1' ? ' checked' : '' ?> data-broadcast-audience-field>
          Has any UTM data
        </label>
      </label>
      <label class="field">
        <span>UTM source</span>
        <input type="text" name="bf_utm_source" value="<?= wwm_escape($listFilter->utmSource) ?>" placeholder="contains…" autocomplete="off" data-broadcast-audience-field>
      </label>
      <label class="field">
        <span>UTM medium</span>
        <input type="text" name="bf_utm_medium" value="<?= wwm_escape($listFilter->utmMedium) ?>" placeholder="contains…" autocomplete="off" data-broadcast-audience-field>
      </label>
      <label class="field">
        <span>UTM campaign</span>
        <input type="text" name="bf_utm_campaign" value="<?= wwm_escape($listFilter->utmCampaign) ?>" placeholder="contains…" autocomplete="off" data-broadcast-audience-field>
      </label>
    </div>
    <p class="field-hint" style="margin:12px 0 0">When audience is <strong>Custom filter</strong>, only students matching these criteria receive the broadcast.</p>
  </div>
</details>
