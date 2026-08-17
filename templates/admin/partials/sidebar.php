<?php
/** @var string $adminNav */
?>
<div class="admin-sidebar-wrap">
  <input type="checkbox" id="admin-nav-toggle" class="admin-nav-toggle" aria-hidden="true">
  <aside class="admin-sidebar">
    <div class="admin-sidebar-head">
      <a class="brand wwm-logo" href="/admin/courses">World Watercolor <em>Masters</em></a>
      <label for="admin-nav-toggle" class="admin-menu-toggle" aria-label="Open admin menu">
        <span class="admin-menu-toggle-box" aria-hidden="true"><span></span><span></span><span></span></span>
      </label>
    </div>
    <nav class="admin-nav" aria-label="Admin">
      <a href="/admin/courses" class="<?= ($adminNav ?? '') === 'courses' ? 'is-active' : '' ?>">Courses</a>
      <a href="/admin/students" class="<?= ($adminNav ?? '') === 'students' ? 'is-active' : '' ?>">Students</a>
      <a href="/admin/emails" class="<?= ($adminNav ?? '') === 'emails' ? 'is-active' : '' ?>">Emails</a>
      <a href="/admin/settings" class="<?= ($adminNav ?? '') === 'settings' ? 'is-active' : '' ?>">Analytics</a>
      <a href="/">← Student view</a>
    </nav>
  </aside>
</div>
