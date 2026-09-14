<?php
$done = !empty($done);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= wwm_escape($pageTitle ?? 'Unsubscribe') ?></title>
  <link rel="stylesheet" href="<?= wwm_escape(wwm_asset_url('css/cabinet.css')) ?>">
</head>
<body class="auth-page">
  <main class="auth-card" style="max-width:480px;margin:48px auto;padding:32px">
    <?php if ($done): ?>
      <h1 class="page-title page-title-sm">You’re unsubscribed</h1>
      <p>We won’t send marketing broadcasts to <strong><?= wwm_escape((string)($email ?? '')) ?></strong>. Transactional emails (login, course access) may still be sent when needed.</p>
    <?php else: ?>
      <h1 class="page-title page-title-sm">Unsubscribe</h1>
      <p>Stop marketing emails to <strong><?= wwm_escape((string)($email ?? '')) ?></strong>?</p>
      <?php if (!empty($_GET['error']) && $_GET['error'] === 'csrf'): ?>
        <div class="alert alert-error">Session expired. Please try again.</div>
      <?php endif; ?>
      <form method="post" action="/email/unsubscribe">
        <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
        <input type="hidden" name="t" value="<?= wwm_escape((string)($token ?? '')) ?>">
        <button type="submit" class="btn btn-primary" style="margin-top:16px">Confirm unsubscribe</button>
      </form>
    <?php endif; ?>
    <p style="margin-top:24px"><a href="<?= wwm_escape(wwm_base_url()) ?>/">Back to cabinet</a></p>
  </main>
</body>
</html>
