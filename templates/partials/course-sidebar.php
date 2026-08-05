<?php
/** @var array<string, mixed> $course */
/** @var array<int, array<string, mixed>> $lessonAccess */
/** @var int|null $currentLessonNum */

$slug = (string)($course['slug'] ?? '');
$sections = is_array($course['sections'] ?? null) ? $course['sections'] : null;
$lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];

$lessonByNum = [];
foreach ($lessons as $lessonItem) {
    if (!is_array($lessonItem)) {
        continue;
    }
    $num = (int)($lessonItem['num'] ?? 0);
    if ($num > 0) {
        $lessonByNum[$num] = $lessonItem;
    }
}

$renderLesson = static function (array $navLesson, int $displayNum) use ($slug, $lessonAccess, $currentLessonNum): void {
    $num = (int)($navLesson['num'] ?? 0);
    if ($num === 0) {
        return;
    }

    $la = $lessonAccess[$num] ?? ['can_view_lesson' => false];
    $locked = empty($la['can_view_lesson']);
    $active = $currentLessonNum !== null && $currentLessonNum === $num;
    $classes = 'lesson-nav-item';
    if ($active) {
        $classes .= ' is-active';
    }
    if ($locked) {
        $classes .= ' is-locked';
    }

    $title = wwm_escape((string)($navLesson['title'] ?? 'Lesson'));
    $duration = trim((string)($navLesson['duration'] ?? ''));
    $meta = $locked ? '🔒' : $duration;
    ?>
    <?php if ($locked): ?>
      <span class="<?= $classes ?>">
        <span class="lesson-nav-num"><?= $displayNum ?></span>
        <span><?= $title ?></span>
        <?php if ($meta !== ''): ?><span class="lesson-nav-meta"><?= wwm_escape($meta) ?></span><?php endif; ?>
      </span>
    <?php else: ?>
      <a class="<?= $classes ?>" href="/c/<?= wwm_escape($slug) ?>/<?= $num ?>">
        <span class="lesson-nav-num"><?= $displayNum ?></span>
        <span><?= $title ?></span>
        <?php if ($meta !== ''): ?><span class="lesson-nav-meta"><?= wwm_escape($meta) ?></span><?php endif; ?>
      </a>
    <?php endif; ?>
    <?php
};

$renderSectionLessons = static function (array $sectionLessons) use ($lessonByNum, $renderLesson): void {
    $displayNum = 1;
    foreach ($sectionLessons as $lessonRef) {
        if (is_array($lessonRef) && isset($lessonRef['num'])) {
            $renderLesson($lessonRef, $displayNum);
            $displayNum++;
            continue;
        }
        $lessonNum = is_int($lessonRef) ? $lessonRef : (int)$lessonRef;
        if (isset($lessonByNum[$lessonNum])) {
            $renderLesson($lessonByNum[$lessonNum], $displayNum);
            $displayNum++;
        }
    }
};

$sectionContainsCurrent = static function (array $sectionLessons, ?int $currentLessonNum): bool {
    if ($currentLessonNum === null) {
        return false;
    }
    foreach ($sectionLessons as $lessonRef) {
        if (is_array($lessonRef) && isset($lessonRef['num'])) {
            if ((int)$lessonRef['num'] === $currentLessonNum) {
                return true;
            }
            continue;
        }
        $lessonNum = is_int($lessonRef) ? $lessonRef : (int)$lessonRef;
        if ($lessonNum === $currentLessonNum) {
            return true;
        }
    }
    return false;
};
?>
<aside class="course-sidebar">
  <h2>Course content</h2>

  <?php if ($sections !== null && $sections !== []): ?>
    <?php foreach ($sections as $section): ?>
      <?php if (!is_array($section)) continue; ?>
      <?php
        $sectionLessons = is_array($section['lessons'] ?? null) ? $section['lessons'] : [];
        $sectionTitle = trim((string)($section['title'] ?? ''));
        $isOpen = $sectionContainsCurrent($sectionLessons, $currentLessonNum);
      ?>
      <?php if ($sectionTitle !== ''): ?>
        <details class="section-block section-collapsible"<?= $isOpen ? ' open' : '' ?>>
          <summary class="section-title"><?= wwm_escape($sectionTitle) ?></summary>
          <div class="section-lessons">
            <?php $renderSectionLessons($sectionLessons); ?>
          </div>
        </details>
      <?php else: ?>
        <div class="section-block">
          <div class="section-lessons">
            <?php $renderSectionLessons($sectionLessons); ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="section-block">
      <div class="section-lessons">
        <?php
          $displayNum = 1;
          foreach ($lessons as $lessonItem):
            if (!is_array($lessonItem)) {
                continue;
            }
            $renderLesson($lessonItem, $displayNum);
            $displayNum++;
          endforeach;
        ?>
      </div>
    </div>
  <?php endif; ?>
</aside>
