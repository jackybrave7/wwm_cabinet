<?php
/** @var callable(string): string $helpImage */
$helpImage = static function (string $file): string {
    return wwm_asset_url('help/recover-access/' . $file);
};
?>
<article class="help-article">
  <p class="help-back"><a href="/forgot">← Back to reset password</a></p>
  <h1 class="page-title">How to recover your account access</h1>
  <p class="lede help-lede">
    Use this guide if you forgot your password, did not receive a reset email, or want to change your password after signing in.
    Your student cabinet is at <strong>my.worldwatercolormasters.art</strong> — sign in and password reset happen here, not on the checkout page where you paid for the course.
  </p>

  <nav class="help-toc" aria-label="Steps">
    <ol>
      <li><a href="#step-request">Request a reset link</a></li>
      <li><a href="#step-inbox">Find the email</a></li>
      <li><a href="#step-link">Open the link</a></li>
      <li><a href="#step-password">Set a new password</a></li>
      <li><a href="#step-account">Change password anytime (signed in)</a></li>
      <li><a href="#step-troubleshoot">Still stuck?</a></li>
    </ol>
  </nav>

  <section class="help-step" id="step-request">
    <h2>1. Request a reset link</h2>
    <p>Open <a href="/forgot">my.worldwatercolormasters.art/forgot</a>, enter the <strong>same email</strong> you used when you bought the course or requested demo access, then tap <strong>Send link</strong>.</p>
    <figure>
      <img src="<?= wwm_escape($helpImage('step-01-forgot-form.svg')) ?>" width="640" height="400" alt="Reset password form with email field and Send link button" loading="lazy" decoding="async">
    </figure>
    <p class="form-hint">The page always shows a success message — even if the email is not registered — so we do not reveal whether an account exists.</p>
  </section>

  <section class="help-step" id="step-inbox">
    <h2>2. Find the email</h2>
    <p>Look for a message from <strong>robot@worldwatercolormasters.art</strong> with subject <strong>Reset your WWM password</strong>. Delivery can take a few minutes.</p>
    <figure>
      <img src="<?= wwm_escape($helpImage('step-02-check-email.svg')) ?>" width="640" height="400" alt="Email inbox highlighting the password reset message" loading="lazy" decoding="async">
    </figure>
    <ul class="help-list">
      <li>Check <strong>Spam</strong>, <strong>Junk</strong>, and <strong>Promotions</strong> (common on AOL, Yahoo, Gmail).</li>
      <li>Add <code>robot@worldwatercolormasters.art</code> to your contacts, then request the link again.</li>
      <li>Search your mailbox for <strong>World Watercolor</strong> or <strong>WWM password</strong>.</li>
    </ul>
  </section>

  <section class="help-step" id="step-link">
    <h2>3. Open the reset link</h2>
    <p>Click the link in the email. It works for <strong>1 hour</strong> and can be used once. If it expired, go back to <a href="/forgot">/forgot</a> and send a new link.</p>
    <figure>
      <img src="<?= wwm_escape($helpImage('step-03-reset-link.svg')) ?>" width="640" height="400" alt="Password reset email showing the reset link button" loading="lazy" decoding="async">
    </figure>
  </section>

  <section class="help-step" id="step-password">
    <h2>4. Set a new password</h2>
    <p>Choose a password with at least <strong>8 characters</strong>. Enter it twice, then save. You will be redirected to sign in with the new password.</p>
    <figure>
      <img src="<?= wwm_escape($helpImage('step-04-new-password.svg')) ?>" width="640" height="400" alt="New password and confirm password fields" loading="lazy" decoding="async">
    </figure>
    <p><a href="/login" class="btn btn-primary">Go to sign in</a></p>
  </section>

  <section class="help-step" id="step-account">
    <h2>5. Change your password anytime (after sign-in)</h2>
    <p>When you are already signed in, open <a href="/account">Account</a> in the top menu and update your password there — no email required.</p>
    <figure>
      <img src="<?= wwm_escape($helpImage('step-05-account-password.svg')) ?>" width="640" height="400" alt="Account page with change password section" loading="lazy" decoding="async">
    </figure>
  </section>

  <section class="help-step help-step-troubleshoot" id="step-troubleshoot">
    <h2>6. Still stuck?</h2>
    <ul class="help-list">
      <li><strong>No reset email at all?</strong> You may not have a cabinet account yet (only an order in the shop). After a purchase we send access from the cabinet — if that email never arrived, write to <a href="mailto:support@worldwatercolormasters.art">support@worldwatercolormasters.art</a> with your purchase email and course name.</li>
      <li><strong>Wrong email?</strong> Try every address you might have used (work, personal, typo variants).</li>
      <li><strong>Link says invalid?</strong> Request a fresh link and open it in the same browser without VPN blockers if possible.</li>
    </ul>
    <p>Support: <a href="mailto:support@worldwatercolormasters.art">support@worldwatercolormasters.art</a></p>
  </section>
</article>
