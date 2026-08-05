<section class="auth-card">
  <h1 class="page-title">Sign in</h1>
  <p class="lede">Access your video courses and lesson materials.</p>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= wwm_escape($error) ?></div>
  <?php endif; ?>
  <form method="post" action="/login" class="form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <input type="hidden" name="next" value="<?= wwm_escape($next ?? '/') ?>">
    <label class="field">
      <span>Email</span>
      <input type="email" name="email" required autocomplete="email" autofocus>
    </label>
    <label class="field">
      <span>Password</span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
  </form>
  <p class="form-footer"><a href="/forgot">Forgot password?</a></p>
  <?php if (str_contains((string)(wwm_config()['base_url'] ?? ''), 'localhost')): ?>
    <p class="form-hint">Local dev: <code>demo@wwm.test</code> / <code>demo-demo-demo</code> (admin) · <code>student@example.com</code> / <code>password</code></p>
  <?php endif; ?>
</section>
