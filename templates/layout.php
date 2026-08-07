<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= wwm_escape($title ?? 'World Watercolor Masters') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Fraunces:ital,wght@0,400;0,700;0,900;1,400&display=swap" rel="stylesheet">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="apple-touch-icon" href="/favicon.png">
  <link rel="stylesheet" href="<?= wwm_escape(wwm_asset_url('css/cabinet.css')) ?>">
</head>
<body>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand wwm-logo" href="/">World Watercolor <em>Masters</em></a>
      <?php if (!empty($user)): ?>
        <nav class="header-nav">
          <a href="/">My courses</a>
          <a href="/account">Account</a>
          <?php if (\Wwm\Models\User::isAdmin($user)): ?>
            <a href="/admin/courses">Admin</a>
          <?php endif; ?>
          <span class="user-email"><?= wwm_escape((string)($user['email'] ?? '')) ?></span>
          <a href="/logout" class="btn btn-ghost">Sign out</a>
        </nav>
      <?php endif; ?>
    </div>
  </header>
  <main class="site-main">
    <div class="wrap">
      <?= $content ?>
    </div>
  </main>
  <footer class="site-footer">
    <div class="wrap">
      <p>&copy; <?= date('Y') ?> Bratec Lis School · <a href="https://worldwatercolormasters.art" target="_blank" rel="noopener">worldwatercolormasters.art</a> · <a href="mailto:support@worldwatercolormasters.art">support@worldwatercolormasters.art</a></p>
    </div>
  </footer>
</body>
</html>
