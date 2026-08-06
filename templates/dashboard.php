<section class="dashboard">
  <div class="page-head">
    <h1 class="page-title">My courses</h1>
    <?php if (!empty($user['name'])): ?>
      <p class="lede">Welcome back, <?= wwm_escape($user['name']) ?>.</p>
    <?php else: ?>
      <p class="lede">Welcome back.</p>
    <?php endif; ?>
  </div>

  <?php if ($courses === []): ?>
    <div class="empty-state">
      <h2>No courses yet</h2>
      <p>When you register for a demo or purchase a course, it will appear here.</p>
      <a class="btn btn-primary" href="https://worldwatercolormasters.art" target="_blank" rel="noopener">Browse courses</a>
    </div>
  <?php else: ?>
    <div class="course-grid">
      <?php foreach ($courses as $course): ?>
        <?php $cover = wwm_course_cover_url((string)($course['cover_image'] ?? '')); ?>
        <a class="course-card" href="/c/<?= wwm_escape((string)$course['slug']) ?>">
          <?php if ($cover !== null): ?>
            <div
              class="course-card-cover"
              role="img"
              aria-label="<?= wwm_escape((string)$course['title']) ?>"
              style="background-image: url('<?= wwm_escape($cover) ?>')"
            ></div>
          <?php endif; ?>
          <div class="course-card-body">
            <p class="course-card-label">
              <?php if (!empty($course['access']['has_paid'])): ?>
                Full access
              <?php else: ?>
                Demo
              <?php endif; ?>
            </p>
            <h2><?= wwm_escape((string)$course['title']) ?></h2>
            <?php if (!empty($course['subtitle'])): ?>
              <p class="course-card-sub"><?= wwm_escape((string)$course['subtitle']) ?></p>
            <?php endif; ?>
            <span class="course-card-cta">Open course →</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
