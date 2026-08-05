<section class="auth-card">
  <h1 class="page-title">New password</h1>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= wwm_escape($error) ?></div>
  <?php endif; ?>
  <form method="post" action="/reset" class="form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <input type="hidden" name="token" value="<?= wwm_escape($token ?? '') ?>">
    <label class="field">
      <span>New password</span>
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
    </label>
    <label class="field">
      <span>Confirm password</span>
      <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
    </label>
    <button type="submit" class="btn btn-primary btn-block">Save password</button>
  </form>
</section>
