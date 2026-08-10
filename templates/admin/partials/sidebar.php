<?php
/** @var string $adminNav */
?>
<aside class="admin-sidebar">
  <a class="brand wwm-logo" href="/admin/courses">World Watercolor <em>Masters</em></a>
  <nav class="admin-nav">
    <a href="/admin/courses" class="<?= ($adminNav ?? '') === 'courses' ? 'is-active' : '' ?>">Courses</a>
    <a href="/admin/students" class="<?= ($adminNav ?? '') === 'students' ? 'is-active' : '' ?>">Students</a>
    <a href="/admin/emails" class="<?= ($adminNav ?? '') === 'emails' ? 'is-active' : '' ?>">Emails</a>
    <a href="/">← Student view</a>
  </nav>
</aside>
