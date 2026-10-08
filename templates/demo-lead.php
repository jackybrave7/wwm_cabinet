<section class="auth-card demo-lead-card">
  <p class="demo-lead-kicker"><?= wwm_escape((string)($copy['kicker'] ?? '')) ?></p>
  <h1 class="page-title"><?= wwm_escape((string)($copy['title'] ?? '')) ?></h1>
  <p class="lede"><?= wwm_escape((string)($copy['text'] ?? '')) ?></p>

  <?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= wwm_escape((string)($copy['success'] ?? '')) ?></div>
    <?php
      $thanksUrl = (string)($copy['thanks_url'] ?? \Wwm\Services\DemoLead::thanksUrl());
      $thanksParts = parse_url($thanksUrl);
      if (is_array($thanksParts)) {
          $q = [];
          if (!empty($thanksParts['query'])) {
              parse_str((string)$thanksParts['query'], $q);
          }
          $q['wwm_lead'] = '1';
          $q['course'] = (string)($course['slug'] ?? '');
          $thanksUrl = ($thanksParts['scheme'] ?? 'https') . '://'
              . ($thanksParts['host'] ?? 'worldwatercolormasters.art')
              . ($thanksParts['path'] ?? '/thanks')
              . '?' . http_build_query($q);
      }
    ?>
    <iframe
      class="demo-lead-thanks-frame"
      src="<?= wwm_escape($thanksUrl) ?>"
      width="1"
      height="1"
      tabindex="-1"
      aria-hidden="true"
      title=""
    ></iframe>
  <?php else: ?>
    <?php if (!empty($error)): ?>
      <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
    <?php endif; ?>
    <form method="post" action="/demo/<?= wwm_escape((string)($course['slug'] ?? '')) ?>" class="form">
      <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">
      <label class="field">
        <span><?= wwm_escape((string)($copy['email_placeholder'] ?? 'Email')) ?></span>
        <input type="email" name="email" required autocomplete="email" maxlength="254" value="<?= wwm_escape((string)($email ?? '')) ?>">
      </label>
      <label class="field">
        <span><?= wwm_escape((string)($copy['name_placeholder'] ?? 'Your name')) ?></span>
        <input type="text" name="name" autocomplete="name" maxlength="80" value="<?= wwm_escape((string)($name ?? '')) ?>">
      </label>
      <div class="demo-lead-hp" aria-hidden="true">
        <label>Company <input type="text" name="company" tabindex="-1" autocomplete="off"></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block"><?= wwm_escape((string)($copy['button'] ?? 'Send me the demo')) ?></button>
    </form>
  <?php endif; ?>

  <p class="form-hint"><?= wwm_escape((string)($copy['note'] ?? '')) ?></p>
</section>
