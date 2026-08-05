<section class="empty-state">
  <h1 class="page-title"><?= (int)($code ?? 500) ?></h1>
  <p><?= wwm_escape($message ?? 'Something went wrong.') ?></p>
  <p class="form-footer"><a href="/">← Home</a></p>
</section>
