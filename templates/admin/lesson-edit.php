<?php
$slug = (string)($course['slug'] ?? '');
$num = (int)($lesson['num'] ?? 0);
$video = is_array($lesson['video'] ?? null) ? $lesson['video'] : [];
$embedUrl = (string)($video['embed_url'] ?? '');
$provider = (string)($video['provider'] ?? 'kinescope');
$materials = is_array($lesson['materials'] ?? null) ? $lesson['materials'] : [];
$sections = is_array($course['sections'] ?? null) ? $course['sections'] : [];
$htmlBody = (string)($lesson['html_body'] ?? '');
if ($htmlBody === '' && !empty($lesson['description'])) {
    $htmlBody = '<p>' . wwm_escape((string)$lesson['description']) . '</p>';
}
if ($htmlBody !== '' && $embedUrl !== '' && !str_contains($htmlBody, 'video-block')) {
    $legacySrc = wwm_sanitize_video_embed_url($embedUrl);
    if ($legacySrc !== null) {
        $safeSrc = htmlspecialchars($legacySrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $legacyVideo = '<div class="video-block" contenteditable="false"><iframe src="' . $safeSrc . '" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen loading="lazy"></iframe></div>';
        $htmlBody = $legacyVideo . $htmlBody;
    }
}
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin"><?= wwm_escape((string)($course['title'] ?? $slug)) ?> · Lesson <?= $num ?></p>
    <h1 class="page-title page-title-sm">Lesson editor</h1>
  </div>
  <div class="top-actions">
    <a href="/c/<?= wwm_escape($slug) ?>/<?= $num ?>" class="btn btn-ghost" target="_blank" rel="noopener">Preview</a>
    <button type="submit" form="lesson-form" class="btn btn-primary">Save lesson</button>
  </div>
</div>

<?php if (!empty($saved)): ?>
  <div class="alert alert-success">Lesson saved successfully.</div>
<?php endif; ?>
<?php if (($error ?? '') === 'csrf'): ?>
  <div class="alert alert-error">Session expired. Please try again.</div>
<?php elseif (($error ?? '') === 'save'): ?>
  <div class="alert alert-error">Failed to save lesson.</div>
<?php endif; ?>

<form id="lesson-form" method="post" action="/admin/courses/<?= wwm_escape($slug) ?>/lessons/<?= $num ?>">
  <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
  <textarea name="html_body" id="lesson-html-body" class="lesson-html-source" hidden><?= wwm_escape(wwm_sanitize_lesson_html($htmlBody)) ?></textarea>

  <div class="editor-aside">
    <div>
      <div class="admin-card editor-meta-card">
        <div class="field-row">
          <label class="field">
            <span>Lesson title</span>
            <input type="text" name="title" value="<?= wwm_escape((string)($lesson['title'] ?? '')) ?>" required>
          </label>
          <?php if ($sections !== []): ?>
            <label class="field">
              <span>Section</span>
              <select name="section_index">
                <?php foreach ($sections as $i => $section): ?>
                  <?php if (!is_array($section)) continue; ?>
                  <?php
                    $label = trim((string)($section['title'] ?? ''));
                    if ($label === '') {
                        $label = 'Other';
                    }
                  ?>
                  <option value="<?= (int)$i ?>"<?= ($sectionIndex ?? null) === (int)$i ? ' selected' : '' ?>><?= wwm_escape($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>
        </div>
        <div class="field-row">
          <label class="field">
            <span>Duration (display)</span>
            <input type="text" name="duration" value="<?= wwm_escape((string)($lesson['duration'] ?? '')) ?>" placeholder="e.g. 12:34">
          </label>
          <label class="field">
            <span>Lesson #</span>
            <input type="text" value="<?= $num ?>" disabled>
          </label>
        </div>
      </div>

      <div class="editor-wrap">
        <div class="editor-toolbar">
          <select id="editor-heading" aria-label="Heading">
            <option value="">Heading</option>
            <option value="h2">Heading 2</option>
            <option value="h3">Heading 3</option>
            <option value="p">Paragraph</option>
          </select>
          <button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
          <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
          <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
          <button type="button" data-cmd="insertUnorderedList" title="Bullet list">• List</button>
          <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
          <button type="button" data-cmd="createLink" data-value="https://" title="Link">Link</button>
          <button type="button" class="btn btn-ghost btn-sm editor-toolbar-video" data-modal-open="video-modal">+ Insert video</button>
        </div>
        <div class="editor-surface lesson-content" contenteditable="true" id="lesson-html-editor" aria-label="Lesson body"></div>
      </div>

      <p class="field-hint" style="margin-top:10px">Use <strong>+ Insert video</strong> to add videos anywhere in the lesson. Each block shows a preview with <strong>Edit URL</strong> and <strong>Remove</strong>. Move blocks by placing the cursor before/after them and typing, or cut and paste.</p>
    </div>

    <aside class="editor-sidebar">
      <div class="admin-card">
        <h2>Access</h2>
        <label class="checkbox-field">
          <input type="checkbox" name="demo" value="1"<?= !empty($lesson['demo']) ? ' checked' : '' ?>>
          <span>Available in <strong>demo</strong> mode</span>
        </label>
        <p class="field-hint" style="margin-top:10px">Demo duration is set at <a href="/admin/courses/<?= wwm_escape($slug) ?>">course level</a>.</p>
      </div>

      <div class="admin-card">
        <h2>Danger zone</h2>
        <p class="field-hint" style="margin-bottom:12px">Delete this lesson permanently. Student progress for this lesson number will remain in the database but the lesson will no longer be accessible.</p>
        <form method="post" action="/admin/courses/<?= wwm_escape($slug) ?>/lessons/<?= $num ?>/delete" onsubmit="return confirm('Delete lesson #<?= $num ?>? This cannot be undone.');">
          <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
          <button type="submit" class="btn btn-ghost" style="color:var(--accent-dark)">Delete lesson</button>
        </form>
      </div>

      <div class="admin-card">
        <h2>Materials</h2>
        <div id="materials-list" class="materials-list">
          <?php if ($materials === []): ?>
            <div class="material-row field-row">
              <label class="field">
                <span>Title</span>
                <input type="text" name="material_title[]" placeholder="Reference photos — Lesson 1.zip">
              </label>
              <label class="field">
                <span>URL</span>
                <input type="url" name="material_url[]" placeholder="https://...">
              </label>
            </div>
          <?php else: ?>
            <?php foreach ($materials as $mat): ?>
              <?php if (!is_array($mat)) continue; ?>
              <div class="material-row field-row">
                <label class="field">
                  <span>Title</span>
                  <input type="text" name="material_title[]" value="<?= wwm_escape((string)($mat['title'] ?? '')) ?>">
                </label>
                <label class="field">
                  <span>URL</span>
                  <input type="url" name="material_url[]" value="<?= wwm_escape((string)($mat['url'] ?? '')) ?>">
                </label>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <button type="button" class="btn btn-ghost btn-sm btn-block" id="add-material-row">+ Add file</button>
      </div>

      <p style="margin-top:12px"><a href="/admin/courses/<?= wwm_escape($slug) ?>">← Back to course</a></p>
    </aside>
  </div>
</form>

<div class="modal" id="video-modal">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-dialog">
    <h2 id="video-modal-title">Insert video</h2>
    <div class="form">
      <label class="field">
        <span>Video host</span>
        <select id="video-modal-provider" data-video-provider>
          <option value="kinescope"<?= $provider === 'kinescope' ? ' selected' : '' ?>>Kinescope</option>
          <option value="vimeo"<?= $provider === 'vimeo' ? ' selected' : '' ?>>Vimeo</option>
          <option value="youtube"<?= $provider === 'youtube' ? ' selected' : '' ?>>YouTube</option>
        </select>
      </label>
      <label class="field">
        <span>Embed URL</span>
        <input type="url" id="video-modal-url" placeholder="https://kinescope.io/embed/xxxxxxxx">
        <span class="field-hint" data-video-hint>Paste Kinescope share or embed URL</span>
      </label>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="button" class="btn btn-primary" id="video-modal-insert">Insert</button>
      </div>
    </div>
  </div>
</div>
