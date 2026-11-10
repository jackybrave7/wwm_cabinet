<?php
/** @var array<string, mixed> $course */
/** @var array<int, array<string, mixed>> $lessonAccess */
/** @var int|null $currentLessonNum */

$slug = (string)($course['slug'] ?? '');
$sections = is_array($course['sections'] ?? null) ? $course['sections'] : null;
$lessons = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];
$completedLessons = is_array($completedLessons ?? null) ? $completedLessons : [];

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

$renderLesson = static function (array $navLesson, int $displayNum) use ($slug, $lessonAccess, $currentLessonNum, $completedLessons): void {
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
    $toggleDone = !empty($completedLessons[$num]);
    ?>
    <?php if ($locked): ?>
      <span class="<?= $classes ?>">
        <span class="lesson-nav-num"><?= $displayNum ?></span>
        <span class="lesson-nav-title"><?= $title ?></span>
        <?php if ($meta !== ''): ?><span class="lesson-nav-meta"><?= wwm_escape($meta) ?></span><?php endif; ?>
      </span>
    <?php else: ?>
      <div class="<?= $classes ?>">
        <a class="lesson-nav-open" href="/c/<?= wwm_escape($slug) ?>/<?= $num ?>">
          <span class="lesson-nav-num"><?= $displayNum ?></span>
          <span class="lesson-nav-title"><?= $title ?></span>
          <?php if ($meta !== ''): ?><span class="lesson-nav-meta"><?= wwm_escape($meta) ?></span><?php endif; ?>
        </a>
        <?php
          $toggleLessonNum = $num;
          $toggleCourseSlug = $slug;
          require __DIR__ . '/lesson-done-toggle.php';
        ?>
      </div>
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
  <h2 class="course-sidebar-label">Course content</h2>

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
<script>
(function () {
  var hintOff = 'Mark as done if you have completed this part of the lesson';
  var hintOn = 'Marked as done. Click to clear.';
  var csrf = <?= json_encode(wwm_csrf_token()) ?>;
  function paint(num, done) {
    document.querySelectorAll('.lesson-done-toggle[data-lesson-num="' + num + '"]').forEach(function (btn) {
      btn.classList.toggle('is-done', done);
      btn.setAttribute('aria-pressed', done ? 'true' : 'false');
      var hint = done ? hintOn : hintOff;
      btn.title = hint;
      btn.setAttribute('aria-label', hint);
    });
  }
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('.lesson-done-toggle');
    if (!btn || btn.getAttribute('data-pending') === '1') return;
    event.preventDefault();
    event.stopPropagation();
    var num = btn.getAttribute('data-lesson-num');
    var url = btn.getAttribute('data-url');
    var want = btn.getAttribute('aria-pressed') !== 'true';
    btn.setAttribute('data-pending', '1');
    paint(num, want);
    var body = new FormData();
    body.append('csrf', csrf);
    body.append('done', want ? '1' : '0');
    fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (res) {
        return res.json().then(function (data) {
          return { ok: res.ok && data && data.ok, done: !!(data && data.done) };
        });
      })
      .then(function (result) {
        paint(num, result.ok ? result.done : !want);
      })
      .catch(function () {
        paint(num, !want);
      })
      .then(function () {
        btn.setAttribute('data-pending', '0');
      });
  });
})();
</script>
