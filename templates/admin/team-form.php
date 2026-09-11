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

<div class="admin-card" style="max-width:640px">
  <form method="post" action="<?= wwm_escape((string)$formAction) ?>" class="form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

    <?php if (!$isEdit): ?>
      <label class="field">
        <span>Email</span>
        <input type="email" name="email" required autocomplete="off" value="<?= wwm_escape((string)($_POST['email'] ?? '')) ?>">
      </label>
    <?php else: ?>
      <p class="field-hint" style="margin-bottom:16px">Account: <strong><?= wwm_escape((string)$t['email']) ?></strong></p>
    <?php endif; ?>

    <label class="field">
      <span>Name <span class="field-hint">(optional)</span></span>
      <input type="text" name="name" autocomplete="off" value="<?= wwm_escape((string)($_POST['name'] ?? ($t['name'] ?? ''))) ?>">
    </label>

    <label class="field">
      <span>Password <?= $isEdit ? '<span class="field-hint">(leave empty to keep)</span>' : '<span class="field-hint">(min. 8 chars, or demo default for new users)</span>' ?></span>
      <input type="password" name="password" autocomplete="new-password" minlength="8"<?= $isEdit ? '' : '' ?>>
    </label>

    <fieldset class="admin-perm-fieldset">
      <legend>Permissions</legend>
      <label class="checkbox-inline admin-perm-option">
        <input type="checkbox" name="perm_super" value="1"<?= $permSuper ? ' checked' : '' ?> data-admin-perm-super>
        <span><strong>Super administrator</strong> — manage admins, emails, analytics, everything</span>
      </label>
      <label class="checkbox-inline admin-perm-option">
        <input type="checkbox" name="perm_students" value="1"<?= $permStudents ? ' checked' : '' ?> data-admin-perm-students>
        <span><strong>Students</strong> — add students, grant course access, view profiles</span>
      </label>
      <label class="checkbox-inline admin-perm-option">
        <input type="checkbox" name="perm_courses" value="1"<?= $permCourses ? ' checked' : '' ?> data-admin-perm-courses>
        <span><strong>Courses</strong> — edit course content and lessons</span>
      </label>
    </fieldset>

    <div class="top-actions" style="margin-top:20px">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save' : 'Add administrator' ?></button>
      <a href="/admin/admins" class="btn btn-ghost">Cancel</a>
    </div>
  </form>

  <?php if ($isEdit && !AdminAccess::isProtectedAccount($t)): ?>
    <form method="post" action="/admin/admins/<?= (int)$t['id'] ?>/revoke" class="inline-form" style="margin-top:28px;padding-top:20px;border-top:1px solid var(--line)" onsubmit="return confirm('Remove administrator access for this user?');">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <button type="submit" class="btn btn-danger btn-sm">Remove administrator access</button>
    </form>
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
