<?php
$meta = is_array($meta ?? null) ? $meta : [];
$sections = is_array($sections ?? null) ? $sections : [];
$revision = (string)($meta['revision'] ?? '');
$changelog = (string)($meta['changelog'] ?? '');
$focus = (string)($focusSection ?? '');
?>
<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Guide</p>
    <h1 class="page-title page-title-sm"><?= wwm_escape((string)($meta['title'] ?? 'Инструкция')) ?></h1>
    <?php if ($revision !== ''): ?>
      <p class="field-hint">Актуально на <?= wwm_escape($revision) ?><?php if ($changelog !== ''): ?> — <?= wwm_escape($changelog) ?><?php endif; ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="admin-guide-layout">
  <nav class="admin-card admin-guide-toc" aria-label="Содержание">
    <h2 class="admin-team-section-title">Содержание</h2>
    <ol class="admin-guide-toc-list">
      <?php foreach ($sections as $section): ?>
        <?php $sid = (string)($section['id'] ?? ''); if ($sid === '') { continue; } ?>
        <li>
          <a href="#<?= wwm_escape($sid) ?>" class="<?= $focus === $sid ? 'is-active' : '' ?>"><?= wwm_escape((string)($section['title'] ?? $sid)) ?></a>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="field-hint admin-guide-toc-note">При обновлении кабинета разработчики дополняют этот раздел — смотрите дату «Актуально на».</p>
  </nav>

  <div class="admin-guide-main">
    <?php foreach ($sections as $section): ?>
      <?php
        $sid = (string)($section['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $body = is_array($section['body'] ?? null) ? $section['body'] : [];
      ?>
      <section id="<?= wwm_escape($sid) ?>" class="admin-card admin-guide-section<?= $focus === $sid ? ' admin-guide-section--focus' : '' ?>">
        <h2 class="admin-team-section-title"><?= wwm_escape((string)($section['title'] ?? $sid)) ?></h2>
        <div class="admin-guide-prose">
          <?php require __DIR__ . '/partials/guide-body.php'; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</div>
