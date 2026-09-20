<?php
/** @var string $adminNav */
use Wwm\Auth\Session;
use Wwm\Models\User;
use Wwm\Services\AdminAccess;

$navUser = $user ?? null;
if (!is_array($navUser)) {
    $navId = Session::userId();
    $navUser = $navId !== null ? User::findById(wwm_pdo(), $navId) : null;
}
$canDashboard = is_array($navUser) && AdminAccess::hasAdminPanelAccess($navUser);
$canCourses = is_array($navUser) && AdminAccess::canManageCourses($navUser);
$canStudents = is_array($navUser) && AdminAccess::canManageStudents($navUser);
$canEmails = is_array($navUser) && AdminAccess::canManageEmails($navUser);
$canBroadcasts = is_array($navUser) && AdminAccess::canManageBroadcasts($navUser);
$canSettings = is_array($navUser) && AdminAccess::canManageSettings($navUser);
$canAdmins = is_array($navUser) && AdminAccess::canManageAdmins($navUser);
?>
<div class="admin-sidebar-wrap">
  <input type="checkbox" id="admin-nav-toggle" class="admin-nav-toggle" aria-hidden="true">
  <aside class="admin-sidebar">
    <div class="admin-sidebar-panel">
      <div class="admin-sidebar-head">
        <a class="brand wwm-logo" href="<?= wwm_escape(AdminAccess::defaultAdminPath(is_array($navUser) ? $navUser : [])) ?>">World Watercolor <em>Masters</em></a>
        <label for="admin-nav-toggle" class="header-menu-toggle admin-menu-toggle" aria-label="Open admin menu">
          <span class="header-menu-toggle-box" aria-hidden="true"><span></span><span></span><span></span></span>
        </label>
      </div>
      <nav class="admin-nav" aria-label="Admin">
        <?php if ($canDashboard): ?>
          <a href="/admin/dashboard" class="<?= ($adminNav ?? '') === 'dashboard' ? 'is-active' : '' ?>">Dashboard</a>
        <?php endif; ?>
        <?php if ($canCourses): ?>
          <a href="/admin/courses" class="<?= ($adminNav ?? '') === 'courses' ? 'is-active' : '' ?>">Courses</a>
        <?php endif; ?>
        <?php if ($canStudents): ?>
          <a href="/admin/students" class="<?= ($adminNav ?? '') === 'students' ? 'is-active' : '' ?>">Students</a>
        <?php endif; ?>
        <?php if ($canAdmins): ?>
          <a href="/admin/admins" class="<?= ($adminNav ?? '') === 'admins' ? 'is-active' : '' ?>">Administrators</a>
        <?php endif; ?>
        <?php if ($canEmails): ?>
          <a href="/admin/emails" class="<?= ($adminNav ?? '') === 'emails' ? 'is-active' : '' ?>">Emails</a>
          <a href="/admin/automations" class="<?= ($adminNav ?? '') === 'automations' ? 'is-active' : '' ?>">Automations</a>
        <?php endif; ?>
        <?php if ($canBroadcasts): ?>
          <a href="/admin/broadcasts" class="<?= ($adminNav ?? '') === 'broadcasts' ? 'is-active' : '' ?>">Broadcasts</a>
        <?php endif; ?>
        <?php if ($canSettings): ?>
          <a href="/admin/settings" class="<?= ($adminNav ?? '') === 'settings' ? 'is-active' : '' ?>">Analytics</a>
        <?php endif; ?>
        <?php if ($canDashboard): ?>
          <a href="/admin/guide" class="<?= ($adminNav ?? '') === 'guide' ? 'is-active' : '' ?>">Инструкция</a>
        <?php endif; ?>
        <a href="/">← Student view</a>
      </nav>
    </div>
  </aside>
</div>
