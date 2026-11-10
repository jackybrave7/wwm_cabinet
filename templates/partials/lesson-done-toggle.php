<?php
/** @var int $toggleLessonNum */
/** @var string $toggleCourseSlug */
/** @var bool $toggleDone */
$toggleHint = !empty($toggleDone)
    ? 'Marked as done. Click to clear.'
    : 'Mark as done if you have completed this part of the lesson';
?>
<button
  type="button"
  class="lesson-done-toggle<?= !empty($toggleDone) ? ' is-done' : '' ?>"
  data-lesson-num="<?= (int)$toggleLessonNum ?>"
  data-url="/c/<?= wwm_escape((string)$toggleCourseSlug) ?>/<?= (int)$toggleLessonNum ?>/done"
  aria-pressed="<?= !empty($toggleDone) ? 'true' : 'false' ?>"
  aria-label="<?= wwm_escape($toggleHint) ?>"
  title="<?= wwm_escape($toggleHint) ?>"
>
  <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
    <path d="M5 12.5 9.2 16.7 19 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>
</button>
