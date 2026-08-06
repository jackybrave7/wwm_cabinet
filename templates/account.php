<section class="auth-card">
  <h1 class="page-title">Account</h1>
  <p class="lede"><?= wwm_escape((string)($user['email'] ?? '')) ?></p>
  <?php if (!empty($message)): ?>
    <div class="alert alert-success"><?= wwm_escape($message) ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= wwm_escape($error) ?></div>
  <?php endif; ?>
  <h2 class="page-title page-title-sm">Change password</h2>
  <form method="post" action="/account" class="form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <label class="field">
      <span>Current password</span>
      <input type="password" name="current_password" required autocomplete="current-password">
    </label>
    <label class="field">
      <span>New password</span>
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
    </label>
    <label class="field">
      <span>Confirm new password</span>
      <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
    </label>
    <button type="submit" class="btn btn-primary btn-block">Save password</button>
  </form>
  <p class="form-footer"><a href="/">← My courses</a></p>
</section>
