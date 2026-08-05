<?php
/** @var array<string, mixed> $lesson */
/** @var string $slug */
/** @var int $sectionIndex */

$num = (int)($lesson['num'] ?? 0);
$isDemo = !empty($lesson['demo']);
$inputName = $flatList ?? false
    ? 'lesson_order[]'
    : 'section_lessons[' . $sectionIndex . '][]';
?>
<div class="lesson-row<?= $isDemo ? ' is-demo' : '' ?>" data-lesson-num="<?= $num ?>">
  <span class="drag-handle" draggable="true" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>
  <div>
    <div class="lesson-row-title"><?= wwm_escape((string)($lesson['title'] ?? '')) ?></div>
    <div class="lesson-row-meta">Lesson #<?= $num ?></div>
  </div>
  <div class="demo-toggle">
    <label>
      <input type="checkbox" name="lesson_demo[<?= $num ?>]" value="1"<?= $isDemo ? ' checked' : '' ?>>
      Demo
    </label>
  </div>
  <a href="/admin/courses/<?= wwm_escape($slug) ?>/lessons/<?= $num ?>" class="btn btn-ghost btn-sm">Edit</a>
  <input type="hidden" name="<?= wwm_escape($inputName) ?>" value="<?= $num ?>">
</div>
