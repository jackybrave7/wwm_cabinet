<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Students</p>
    <h1 class="page-title page-title-sm">Add student</h1>
  </div>
  <a href="/admin/students" class="btn btn-ghost">← All students</a>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-card" style="max-width:560px">
  <form method="post" action="/admin/students" class="form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

    <label class="field">
      <span>Email</span>
      <input type="email" name="email" required autocomplete="off" value="<?= wwm_escape((string)($_POST['email'] ?? '')) ?>">
    </label>

    <label class="field">
      <span>Name <span class="field-hint">(optional)</span></span>
      <input type="text" name="name" autocomplete="off" value="<?= wwm_escape((string)($_POST['name'] ?? '')) ?>">
    </label>

    <label class="field">
      <span>Password <span class="field-hint">(leave empty for demo default)</span></span>
      <input type="text" name="password" autocomplete="new-password" value="">
    </label>

    <div class="top-actions" style="margin-top:20px">
      <button type="submit" class="btn btn-primary">Create student</button>
      <a href="/admin/students" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
