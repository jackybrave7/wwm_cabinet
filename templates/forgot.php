<section class="auth-card">
  <h1 class="page-title">Reset password</h1>
  <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= wwm_escape($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($message)): ?>
    <div class="alert alert-success"><?= wwm_escape($message) ?></div>
  <?php else: ?>
    <p class="lede">Enter your email and we will send a reset link.</p>
    <form method="post" action="/forgot" class="form">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <label class="field">
        <span>Email</span>
        <input type="email" name="email" required autocomplete="email" autofocus>
      </label>
      <button type="submit" class="btn btn-primary btn-block">Send link</button>
    </form>
  <?php endif; ?>
  <p class="form-footer"><a href="/login">Back to sign in</a></p>
</section>
