<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= wwm_escape($title ?? 'Admin — WWM') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Fraunces:ital,wght@0,400;0,700;0,900;1,400&display=swap" rel="stylesheet">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" href="/favicon.png" type="image/png">
  <link rel="stylesheet" href="<?= wwm_escape(wwm_asset_url('css/cabinet.css')) ?>">
  <link rel="stylesheet" href="<?= wwm_escape(wwm_asset_url('css/admin.css')) ?>">
</head>
<body class="admin-body">
  <div class="admin-shell">
    <?php require __DIR__ . '/partials/sidebar.php'; ?>
    <div class="admin-main">
      <?= $content ?>
    </div>
  </div>
  <script src="/assets/js/admin-tabs.js" defer></script>
  <script src="/assets/js/admin-lesson-sort.js" defer></script>
  <script src="/assets/js/admin-lesson-editor.js" defer></script>
</body>
</html>
