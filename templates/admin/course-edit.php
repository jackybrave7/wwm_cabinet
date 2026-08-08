<?php
$slug = (string)($course['slug'] ?? '');
$lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
$sections = is_array($course['sections'] ?? null) ? $course['sections'] : [];
$status = strtolower((string)($course['status'] ?? 'published'));
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin"><?= wwm_escape((string)($course['title'] ?? $slug)) ?></p>
    <h1 class="page-title page-title-sm">Course settings</h1>
  </div>
  <div class="top-actions">
    <a href="/c/<?= wwm_escape($slug) ?>/1" class="btn btn-ghost" target="_blank" rel="noopener">Preview</a>
    <form method="post" action="/admin/courses/<?= wwm_escape($slug) ?>/lessons" style="display:inline">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <button type="submit" class="btn btn-ghost">+ Add lesson</button>
    </form>
    <button type="submit" form="course-form" class="btn btn-primary">Save changes</button>
  </div>
</div>

<?php if (!empty($saved)): ?>
  <div class="alert alert-success">Course saved successfully.</div>
<?php endif; ?>
<?php if (($error ?? '') === 'csrf'): ?>
  <div class="alert alert-error">Session expired. Please try again.</div>
<?php elseif (($error ?? '') === 'save'): ?>
  <div class="alert alert-error">Failed to save course.</div>
<?php endif; ?>

<form id="course-form" method="post" action="/admin/courses/<?= wwm_escape($slug) ?>" class="form" style="margin-top:0">
  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

  <div data-tabs>
    <div class="tabs">
      <button type="button" class="tab-btn is-active" data-tab="general">General</button>
      <button type="button" class="tab-btn" data-tab="demo">Demo access</button>
      <button type="button" class="tab-btn" data-tab="structure">Sections &amp; lessons</button>
    </div>

    <div class="tab-panel is-active" data-panel="general">
      <div class="admin-card">
        <h2>General information</h2>
        <div class="field-row">
          <label class="field">
            <span>Course title</span>
            <input type="text" name="title" value="<?= wwm_escape((string)($course['title'] ?? '')) ?>" required>
          </label>
          <label class="field">
            <span>URL slug</span>
            <input type="text" value="<?= wwm_escape($slug) ?>" disabled>
          </label>
        </div>
        <label class="field">
          <span>Subtitle</span>
          <input type="text" name="subtitle" value="<?= wwm_escape((string)($course['subtitle'] ?? '')) ?>">
        </label>
        <div class="field-row">
          <label class="field">
            <span>AVO goods ID</span>
            <input type="number" name="avo_goods_id" value="<?= (int)($course['avo_goods_id'] ?? 0) ?>" min="0">
          </label>
          <label class="field">
            <span>AVO training ID</span>
            <input type="number" name="avo_training_id" value="<?= (int)($course['avo_training_id'] ?? 0) ?>" min="0">
          </label>
        </div>
        <div class="field-row">
          <label class="field">
            <span>Status</span>
            <select name="status">
              <option value="published"<?= $status !== 'draft' ? ' selected' : '' ?>>Published</option>
              <option value="draft"<?= $status === 'draft' ? ' selected' : '' ?>>Draft</option>
            </select>
          </label>
          <label class="field">
            <span>Cover image URL</span>
            <input type="url" name="cover_image" value="<?= wwm_escape((string)($course['cover_image'] ?? '')) ?>">
          </label>
        </div>
        <label class="field">
          <span>Landing / buy URL</span>
          <input type="url" name="buy_url" value="<?= wwm_escape((string)($course['buy_url'] ?? '')) ?>">
        </label>
      </div>
    </div>

    <div class="tab-panel" data-panel="demo">
      <div class="admin-card">
        <h2>Demo mode settings</h2>
        <p class="field-hint" style="margin-bottom:16px">Demo duration applies when granting demo access via seed or webhooks. Per-lesson demo flags control which lessons are visible in demo mode.</p>
        <label class="field" style="max-width:280px">
          <span>Demo duration (hours)</span>
          <input type="number" name="demo_hours" value="<?= (int)($course['demo_hours'] ?? 48) ?>" min="1" max="720">
        </label>
      </div>
    </div>

    <div class="tab-panel" data-panel="structure">
      <div class="admin-card">
        <h2>Sections &amp; lessons</h2>
        <p class="field-hint" style="margin-bottom:16px">Drag lessons by the ⋮⋮ handle to reorder within each section. Save changes to apply.</p>
        <?php if ($sections !== []): ?>
          <?php foreach ($sections as $sectionIndex => $section): ?>
            <?php if (!is_array($section)) continue; ?>
            <?php if (!empty($section['title'])): ?>
              <div class="section-admin-title"><?= wwm_escape((string)$section['title']) ?></div>
            <?php endif; ?>
            <div class="lesson-sortable">
              <?php
                $sectionLessonRefs = is_array($section['lessons'] ?? null) ? $section['lessons'] : [];
                foreach ($sectionLessonRefs as $ref):
                  $lessonNum = is_array($ref) ? (int)($ref['num'] ?? 0) : (int)$ref;
                  $lesson = null;
                  foreach ($lessons as $l) {
                      if (is_array($l) && (int)($l['num'] ?? 0) === $lessonNum) {
                          $lesson = $l;
                          break;
                      }
                  }
                  if ($lesson === null) {
                      continue;
                  }
                  $flatList = false;
                  require __DIR__ . '/partials/lesson-row.php';
                endforeach;
              ?>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="lesson-sortable">
            <?php foreach ($lessons as $lesson): ?>
              <?php
                if (!is_array($lesson)) {
                    continue;
                }
                $sectionIndex = 0;
                $flatList = true;
                require __DIR__ . '/partials/lesson-row.php';
              ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>

<p style="margin-top:8px"><a href="/admin/courses">← Back to courses</a></p>
