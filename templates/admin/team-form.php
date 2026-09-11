<?php
use Wwm\Services\AdminAccess;

$isEdit = is_array($target ?? null);
$t = $isEdit ? $target : [];
$permSuper = $isEdit ? !empty($t['admin_super']) : false;
$permStudents = $isEdit ? (!empty($t['admin_students']) || $permSuper) : true;
$permCourses = $isEdit ? (!empty($t['admin_courses']) || $permSuper) : false;
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Super admin</p>
    <h1 class="page-title page-title-sm"><?= $isEdit ? 'Edit administrator' : 'Add administrator' ?></h1>
  </div>
  <a href="/admin/admins" class="btn btn-ghost">← All administrators</a>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-card admin-team-card">
  <form method="post" action="<?= wwm_escape((string)$formAction) ?>" class="form admin-team-form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

    <div class="admin-team-section">
      <h2 class="admin-team-section-title">Account</h2>
      <?php if (!$isEdit): ?>
        <label class="field">
          <span>Email</span>
          <input type="email" name="email" required autocomplete="off" value="<?= wwm_escape((string)($_POST['email'] ?? '')) ?>">
        </label>
      <?php else: ?>
        <p class="admin-team-account-email"><?= wwm_escape((string)$t['email']) ?></p>
      <?php endif; ?>

      <label class="field">
        <span>Name <span class="field-hint">(optional)</span></span>
        <input type="text" name="name" autocomplete="off" value="<?= wwm_escape((string)($_POST['name'] ?? ($t['name'] ?? ''))) ?>">
      </label>

      <label class="field">
        <span>Password<?= $isEdit ? ' <span class="field-hint">(leave empty to keep)</span>' : ' <span class="field-hint">(min. 8 characters, or demo default)</span>' ?></span>
        <input type="password" name="password" autocomplete="new-password" minlength="8">
      </label>
    </div>

    <div class="admin-team-section">
      <h2 class="admin-team-section-title">Permissions</h2>
      <div class="admin-perm-panel">
        <label class="admin-perm-option">
          <input type="checkbox" name="perm_super" value="1"<?= $permSuper ? ' checked' : '' ?> data-admin-perm-super>
          <span class="admin-perm-option-text">
            <strong>Super administrator</strong>
            <span class="field-hint">Manage admins, emails, analytics, and all cabinet areas.</span>
          </span>
        </label>
        <label class="admin-perm-option">
          <input type="checkbox" name="perm_students" value="1"<?= $permStudents ? ' checked' : '' ?> data-admin-perm-students>
          <span class="admin-perm-option-text">
            <strong>Students</strong>
            <span class="field-hint">Add students, grant course access, view profiles.</span>
          </span>
        </label>
        <label class="admin-perm-option">
          <input type="checkbox" name="perm_courses" value="1"<?= $permCourses ? ' checked' : '' ?> data-admin-perm-courses>
          <span class="admin-perm-option-text">
            <strong>Courses</strong>
            <span class="field-hint">Edit course content and lessons.</span>
          </span>
        </label>
      </div>
    </div>

    <div class="admin-form-footer">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Add administrator' ?></button>
      <a href="/admin/admins" class="btn btn-ghost">Cancel</a>
    </div>
  </form>

  <?php if ($isEdit && !AdminAccess::isProtectedAccount($t)): ?>
    <div class="admin-form-revoke">
      <h2 class="admin-team-section-title">Remove access</h2>
      <p class="field-hint">This user will keep their student account but lose all administrator permissions.</p>
      <form method="post" action="/admin/admins/<?= (int)$t['id'] ?>/revoke" class="admin-revoke-form" onsubmit="return confirm('Remove administrator access for this user?');">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <button type="submit" class="btn btn-danger btn-sm">Remove administrator access</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var superBox = document.querySelector('[data-admin-perm-super]');
  var studentsBox = document.querySelector('[data-admin-perm-students]');
  var coursesBox = document.querySelector('[data-admin-perm-courses]');
  if (!superBox || !studentsBox || !coursesBox) {
    return;
  }
  var sync = function () {
    if (superBox.checked) {
      studentsBox.checked = true;
      coursesBox.checked = true;
      studentsBox.disabled = true;
      coursesBox.disabled = true;
    } else {
      studentsBox.disabled = false;
      coursesBox.disabled = false;
    }
  };
  superBox.addEventListener('change', sync);
  sync();
})();
</script>
