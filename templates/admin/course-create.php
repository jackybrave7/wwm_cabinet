<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Courses</p>
    <h1 class="page-title page-title-sm">Add course</h1>
  </div>
  <a href="/admin/courses" class="btn btn-ghost">← All courses</a>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-card" style="max-width:640px">
  <form method="post" action="/admin/courses" class="form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

    <label class="field">
      <span>Course title</span>
      <input type="text" name="title" required autocomplete="off" value="<?= wwm_escape((string)($_POST['title'] ?? '')) ?>">
    </label>

    <label class="field">
      <span>URL slug</span>
      <input type="text" name="slug" required pattern="[a-z0-9\-]+" autocomplete="off" placeholder="e.g. alvaro-en" value="<?= wwm_escape((string)($_POST['slug'] ?? '')) ?>">
      <span class="field-hint">Lowercase letters, numbers, and hyphens only. Used in URLs: /c/<strong>slug</strong>/1</span>
    </label>

    <label class="field">
      <span>Subtitle <span class="field-hint">(optional)</span></span>
      <input type="text" name="subtitle" autocomplete="off" value="<?= wwm_escape((string)($_POST['subtitle'] ?? '')) ?>">
    </label>

    <label class="field">
      <span>Landing / buy URL <span class="field-hint">(optional)</span></span>
      <input type="url" name="buy_url" autocomplete="off" placeholder="https://worldwatercolormasters.art/..." value="<?= wwm_escape((string)($_POST['buy_url'] ?? '')) ?>">
    </label>

    <label class="field" style="max-width:280px">
      <span>Demo duration (hours)</span>
      <input type="number" name="demo_hours" value="<?= (int)($_POST['demo_hours'] ?? 48) ?>" min="1" max="720">
    </label>

    <div class="top-actions" style="margin-top:20px">
      <button type="submit" class="btn btn-primary">Create course</button>
      <a href="/admin/courses" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
