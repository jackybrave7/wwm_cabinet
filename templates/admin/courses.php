<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Administrator</p>
    <h1 class="page-title page-title-sm">Courses</h1>
  </div>
  <a href="/admin/courses/new" class="btn btn-primary">+ Add course</a>
</div>

<div class="admin-card">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Course</th>
        <th>Sections</th>
        <th>Lessons</th>
        <th class="col-num">Full access</th>
        <th>Demo</th>
        <th>Status</th>
        <th></th>
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
          <td>
            <strong><?= wwm_escape((string)($course['title'] ?? $slug)) ?></strong><br>
            <span style="color:var(--mute);font-size:0.85rem">
              <?= wwm_escape($slug) ?>
              <?php if (!empty($course['avo_goods_id'])): ?>
                · id_goods <?= (int)$course['avo_goods_id'] ?>
              <?php endif; ?>
            </span>
          </td>
          <td><?= (int)$row['sections'] ?></td>
          <td><?= (int)$row['lessons'] ?></td>
          <td class="col-num"><strong><?= (int)$row['paid'] ?></strong></td>
          <td><?= $demoHours ?> h</td>
          <td>
            <?php if ($row['published']): ?>
              <span class="badge badge-paid" style="margin:0">Published</span>
            <?php else: ?>
              <span class="badge badge-draft" style="margin:0">Draft</span>
            <?php endif; ?>
          </td>
          <td><a href="/admin/courses/<?= wwm_escape($slug) ?>" class="btn btn-ghost btn-sm">Edit</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
