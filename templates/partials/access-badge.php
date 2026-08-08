<?php
$accessLabel = (string)($accessLabel ?? '');
$demoExpiresAt = isset($demoExpiresAt) && is_string($demoExpiresAt) && $demoExpiresAt !== ''
    ? $demoExpiresAt
    : null;
$isDemo = stripos($accessLabel, 'demo') !== false;
?>
<?php if ($accessLabel !== ''): ?>
  <div class="access-badge-block">
    <span class="badge<?= $isDemo ? ' badge-demo' : '' ?>"><?= wwm_escape($accessLabel) ?></span>
    <?php if ($isDemo): ?>
      <p class="demo-access-until">
        <?php if ($demoExpiresAt !== null): ?>
          <time datetime="<?= wwm_escape($demoExpiresAt) ?>" data-demo-until="<?= wwm_escape($demoExpiresAt) ?>">
            Available until …
          </time>
        <?php else: ?>
          <span>No expiration date set</span>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
<?php endif; ?>
