<?php
$name = trim((string)($user['name'] ?? ''));
$email = trim((string)($user['email'] ?? ''));
?>
<section class="account-page">
  <header class="account-head">
    <h1 class="page-title">Account</h1>
    <p class="account-sub">
      <?php if ($name !== ''): ?>
        <?= wwm_escape($name) ?> · <?= wwm_escape($email) ?>
      <?php else: ?>
        <?= wwm_escape($email) ?>
      <?php endif; ?>
    </p>
  </header>

  <div class="account-grid">
    <div class="account-panel">
      <h2 class="account-panel-title">Profile</h2>
      <p class="account-panel-lede">How we greet you on the dashboard.</p>
      <?php if (!empty($profileMessage)): ?>
        <div class="alert alert-success"><?= wwm_escape($profileMessage) ?></div>
      <?php endif; ?>
      <?php if (!empty($profileError)): ?>
        <div class="alert alert-error"><?= wwm_escape($profileError) ?></div>
      <?php endif; ?>
      <form method="post" action="/account/profile" class="form account-form">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <label class="field">
          <span>Name</span>
          <input type="text" name="name" value="<?= wwm_escape($name) ?>" required maxlength="120" autocomplete="name">
        </label>
        <label class="field field-readonly">
          <span>Email</span>
          <input type="email" value="<?= wwm_escape($email) ?>" readonly tabindex="-1">
        </label>
        <button type="submit" class="btn btn-primary">Save profile</button>
      </form>
    </div>

    <div class="account-panel">
      <h2 class="account-panel-title">Password</h2>
      <p class="account-panel-lede">Change your sign-in password.</p>
      <?php if (!empty($passwordMessage)): ?>
        <div class="alert alert-success"><?= wwm_escape($passwordMessage) ?></div>
      <?php endif; ?>
      <?php if (!empty($passwordError)): ?>
        <div class="alert alert-error"><?= wwm_escape($passwordError) ?></div>
      <?php endif; ?>
      <form method="post" action="/account/password" class="form account-form">
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
        <button type="submit" class="btn btn-primary">Save password</button>
      </form>
    </div>
  </div>

  <p class="account-back"><a href="/">← My courses</a></p>
</section>
