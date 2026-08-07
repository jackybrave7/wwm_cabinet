<?php
$accessLabel = (string)($accessLabel ?? '');
$isDemo = stripos($accessLabel, 'demo') !== false;
$lessonNum = (int)($lesson['num'] ?? 0);
?>
<section class="lesson-page">
  <div class="page-head page-head-compact">
    <div class="page-title-row">
      <h1 class="page-title page-title-sm"><?= wwm_escape((string)$course['title']) ?></h1>
      <?php if ($accessLabel !== ''): ?>
        <span class="badge<?= $isDemo ? ' badge-demo' : '' ?>"><?= wwm_escape($accessLabel) ?></span>
      <?php endif; ?>
    </div>
    <?php if (!empty($course['subtitle'])): ?>
      <p class="lede"><?= wwm_escape((string)$course['subtitle']) ?></p>
    <?php endif; ?>
  </div>

  <div class="course-layout">
    <?php
      $currentLessonNum = $lessonNum;
      require __DIR__ . '/partials/course-sidebar.php';
    ?>

    <div class="course-main">
      <article class="lesson-content">
        <h2><?= wwm_escape((string)($lesson['title'] ?? 'Lesson')) ?></h2>

        <?php $bodyHtml = wwm_lesson_body_html($lesson); ?>
        <?php if ($bodyHtml !== ''): ?>
          <div class="lesson-body">
            <?= $bodyHtml ?>
          </div>
        <?php else: ?>
          <p class="field-hint">Lesson content is not available yet.</p>
        <?php endif; ?>

        <?php if (!empty($lesson['materials']) && is_array($lesson['materials'])): ?>
          <div class="materials-box">
            <h3>Materials</h3>
            <ul>
              <?php foreach ($lesson['materials'] as $mat): ?>
                <?php if (!is_array($mat)) continue; ?>
                <li>
                  <a href="<?= wwm_escape((string)($mat['url'] ?? '#')) ?>" target="_blank" rel="noopener">
                    <?= wwm_escape((string)($mat['title'] ?? 'Download')) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </article>

      <?php if (!empty($prevLesson) || !empty($nextLesson)): ?>
        <nav class="lesson-pager" aria-label="Lesson navigation">
          <div class="lesson-pager-side">
            <?php if (!empty($prevLesson)): ?>
              <?php $prevNum = (int)($prevLesson['num'] ?? 0); ?>
              <a href="/c/<?= wwm_escape((string)$course['slug']) ?>/<?= $prevNum ?>" class="btn btn-ghost">
                ← Previous lesson
              </a>
              <?php if (!empty($prevLocked)): ?>
                <span class="lesson-pager-hint">Locked in demo mode</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="lesson-pager-side lesson-pager-side-end">
            <?php if (!empty($nextLesson)): ?>
              <?php $nextNum = (int)($nextLesson['num'] ?? 0); ?>
              <a href="/c/<?= wwm_escape((string)$course['slug']) ?>/<?= $nextNum ?>" class="btn btn-ghost">
                Next lesson →
              </a>
              <?php if (!empty($nextLocked)): ?>
                <span class="lesson-pager-hint">Locked in demo mode</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
    </div>
  </div>
</section>
