<section class="empty-state">
  <h1 class="page-title"><?= wwm_escape((string)($course['title'] ?? 'Course')) ?></h1>
  <?php if (!empty($upgrade)): ?>
    <p>This lesson is not included in your demo access.</p>
    <p>Purchase the full course to unlock all lessons.</p>
  <?php else: ?>
    <p>You do not have access to this course yet.</p>
    <p>Start with a free demo or purchase on the course page.</p>
  <?php endif; ?>
  <?php if (!empty($course['buy_url'])): ?>
    <a class="btn btn-primary" href="<?= wwm_escape((string)$course['buy_url']) ?>" target="_blank" rel="noopener">Get access</a>
  <?php else: ?>
    <a class="btn btn-primary" href="https://worldwatercolormasters.art" target="_blank" rel="noopener">Browse courses</a>
  <?php endif; ?>
  <p class="form-footer"><a href="/">← Back to my courses</a></p>
</section>
