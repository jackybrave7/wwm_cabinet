<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Administrator</p>
    <h1 class="page-title page-title-sm">Courses</h1>
  </div>
  <a href="/admin/courses/new" class="btn btn-primary">+ Add course</a>
</div>

<div class="admin-card admin-card--flush-table">
  <div class="admin-table-wrap">
  <table class="admin-table admin-table--courses">
    <thead>
      <tr>
        <th class="col-course">Course</th>
        <th class="col-tight col-num">Sections</th>
        <th class="col-tight col-num">Lessons</th>
        <th class="col-tight col-num" title="Students with full access">Paid</th>
        <th class="col-tight">Demo</th>
        <th class="col-status">Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <?php
          $course = $row['course'];
          $slug = (string)($course['slug'] ?? '');
          $demoHours = (int)($course['demo_hours'] ?? 48);
        ?>
        <tr>
          <td class="col-course">
            <strong><a class="admin-table-primary-link" href="/admin/courses/<?= wwm_escape($slug) ?>"><?= wwm_escape((string)($course['title'] ?? $slug)) ?></a></strong><br>
            <span class="field-hint admin-course-meta">
              <?= wwm_escape($slug) ?>
              <?php if (!empty($course['avo_goods_id'])): ?>
                · id_goods <?= (int)$course['avo_goods_id'] ?>
              <?php endif; ?>
            </span>
          </td>
          <td class="col-tight col-num"><?= (int)$row['sections'] ?></td>
          <td class="col-tight col-num"><?= (int)$row['lessons'] ?></td>
          <td class="col-tight col-num"><strong><?= (int)$row['paid'] ?></strong></td>
          <td class="col-tight"><?= $demoHours ?> h</td>
          <td class="col-status">
            <?php if ($row['published']): ?>
              <span class="badge badge-paid admin-table-badge">Published</span>
            <?php else: ?>
              <span class="badge badge-draft admin-table-badge">Draft</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
