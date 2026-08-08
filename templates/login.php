<section class="auth-card">
  <h1 class="page-title">Sign in</h1>
  <p class="lede">Access your video courses and lesson materials.</p>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= wwm_escape($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($message)): ?>
    <div class="alert alert-success"><?= wwm_escape($message) ?></div>
  <?php endif; ?>

  <form method="post" action="/login" class="form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <input type="hidden" name="next" value="<?= wwm_escape($next ?? '/') ?>">
    <label class="field">
      <span>Email</span>
      <input type="email" name="email" required autocomplete="email" value="<?= wwm_escape($email ?? '') ?>"<?= empty($email) ? ' autofocus' : '' ?>>
    </label>
    <label class="field">
      <span>Password</span>
      <input type="password" name="password" required autocomplete="current-password" value="<?= wwm_escape($password ?? '') ?>"<?= !empty($email) ? ' autofocus' : '' ?>>
    </label>
    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
  </form>
  <p class="form-footer"><a href="/forgot">Forgot password?</a></p>

  <?php if (!empty(wwm_config()['magic_link_login'])): ?>
  <div class="auth-divider" aria-hidden="true"><span>or</span></div>

  <form method="post" action="/auth/magic/request" class="form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
    <input type="hidden" name="next" value="<?= wwm_escape($next ?? '/') ?>">
    <label class="field">
      <span>Email for sign-in link</span>
      <input type="email" name="email" required autocomplete="email" value="<?= wwm_escape($email ?? '') ?>">
    </label>
    <button type="submit" class="btn btn-ghost btn-block">Email me a sign-in link</button>
  </form>
  <p class="form-hint">We will send a one-time link. No password needed.</p>
  <?php endif; ?>

  <?php if (str_contains((string)(wwm_config()['base_url'] ?? ''), 'localhost')): ?>
    <p class="form-hint">Local dev: <code>demo@wwm.test</code> / <code>demo-demo-demo</code> (admin) · <code>student@example.com</code> / <code>password</code></p>
  <?php endif; ?>
</section>
