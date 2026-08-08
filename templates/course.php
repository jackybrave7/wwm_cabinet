<?php
$accessLabel = (string)($accessLabel ?? '');
?>
<section class="course-page">
  <div class="page-head page-head-compact">
    <div class="page-title-row">
      <h1 class="page-title page-title-sm"><?= wwm_escape((string)$course['title']) ?></h1>
      <?php if ($accessLabel !== ''): ?>
        <?php require __DIR__ . '/partials/access-badge.php'; ?>
      <?php endif; ?>
    </div>
    <?php if (!empty($course['subtitle'])): ?>
      <p class="lede"><?= wwm_escape((string)$course['subtitle']) ?></p>
    <?php endif; ?>
  </div>

  <div class="course-layout">
    <?php
      $currentLessonNum = null;
      require __DIR__ . '/partials/course-sidebar.php';
    ?>

    <div class="course-main">
      <div class="lesson-content">
        <p>Select a lesson from the sidebar to begin.</p>
      </div>
    </div>
  </div>
</section>
