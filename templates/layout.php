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
  <?php
    $previewStudentId = \Wwm\Auth\Session::studentPreviewId();
    $previewStudent = $previewStudentId !== null ? \Wwm\Models\User::findById(wwm_pdo(), $previewStudentId) : null;
  ?>
  <?php if ($previewStudentId === null): ?>
  <?= wwm_analytics_head_html() ?>
  <?php endif; ?>
</head>
<body>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand wwm-logo" href="/"><span class="wwm-logo-text">World Watercolor</span> <em>Masters</em></a>
      <?php if (!empty($user)): ?>
        <div class="header-menu">
          <input type="checkbox" id="site-nav-toggle" class="site-nav-toggle">
          <label for="site-nav-toggle" class="header-menu-toggle" aria-label="Open menu">
            <span class="header-menu-toggle-box" aria-hidden="true">
              <span></span><span></span><span></span>
            </span>
          </label>
          <nav class="header-nav" aria-label="Account">
            <a href="/">My courses</a>
            <?php if ($previewStudentId === null): ?>
            <a href="/account">Account</a>
            <?php endif; ?>
            <?php
              $signedInId = \Wwm\Auth\Session::userId();
              $signedIn = $signedInId !== null ? \Wwm\Models\User::findById(wwm_pdo(), $signedInId) : null;
            ?>
            <?php if (is_array($signedIn) && \Wwm\Models\User::isAdmin($signedIn)): ?>
              <a href="/admin/dashboard">Admin</a>
            <?php endif; ?>
            <span class="user-email"><?= wwm_escape((string)($user['email'] ?? '')) ?></span>
            <?php if ($previewStudentId !== null): ?>
              <a href="/admin/student-preview/exit" class="btn btn-ghost">Back to admin</a>
            <?php else: ?>
              <a href="/logout" class="btn btn-ghost">Sign out</a>
            <?php endif; ?>
          </nav>
        </div>
      <?php endif; ?>
    </div>
  </header>
  <?php if (is_array($previewStudent)): ?>
    <div class="cabinet-preview-bar">
      <div class="wrap cabinet-preview-bar-inner">
        <p>Viewing <?= wwm_escape((string)($previewStudent['name'] ?: $previewStudent['email'])) ?>’s cabinet. Lesson checks and video position are not saved.</p>
        <a class="btn btn-ghost btn-sm" href="/admin/student-preview/exit">Back to student card</a>
      </div>
    </div>
  <?php endif; ?>
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
  <?php if ($previewStudentId === null): ?>
  <?= wwm_analytics_body_html() ?>
  <?php endif; ?>
  <script src="<?= wwm_escape(wwm_asset_url('js/password-toggle.js')) ?>" defer></script>
  <script src="<?= wwm_escape(wwm_asset_url('js/cabinet.js')) ?>" defer></script>
</body>
</html>
