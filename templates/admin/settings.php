<div class="admin-topbar">
  <div>
    <p class="badge badge-admin">Administrator</p>
    <h1 class="page-title page-title-sm">Analytics</h1>
    <p class="field-hint">Tracking codes for the student cabinet (login, courses, lessons). Not injected into admin pages.</p>
  </div>
</div>

<?php if (!empty($message)): ?>
  <div class="alert alert-success"><?= wwm_escape((string)$message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-error"><?= wwm_escape((string)$error) ?></div>
<?php endif; ?>

<div class="admin-card" style="max-width:900px">
  <form method="post" action="/admin/settings" class="form">
    <input type="hidden" name="csrf" value="<?= wwm_escape(wwm_csrf_token()) ?>">

    <h2>Head</h2>
    <p class="field-hint" style="margin-bottom:12px">
      Paste counter scripts (Yandex Metrika, Google Analytics, GTM) — inserted before <code>&lt;/head&gt;</code>.
    </p>
    <label class="field">
      <span>Code in &lt;head&gt;</span>
      <textarea name="analytics_head" rows="12" class="input" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace;font-size:13px"><?= wwm_escape((string)($analytics_head ?? '')) ?></textarea>
    </label>

    <h2 style="margin-top:28px">Body</h2>
    <p class="field-hint" style="margin-bottom:12px">
      Optional: <code>&lt;noscript&gt;</code> fallbacks and tags that must load at the end of the page — inserted before <code>&lt;/body&gt;</code>.
    </p>
    <label class="field">
      <span>Code before &lt;/body&gt;</span>
      <textarea name="analytics_body" rows="8" class="input" spellcheck="false" style="font-family:ui-monospace,Consolas,monospace;font-size:13px"><?= wwm_escape((string)($analytics_body ?? '')) ?></textarea>
    </label>

    <div class="top-actions" style="margin-top:20px">
      <button type="submit" class="btn btn-primary">Save</button>
    </div>
  </form>
</div>

<div class="admin-card" style="max-width:900px;margin-top:24px">
  <h2>Example: Yandex Metrika</h2>
  <pre class="email-webhook-sample" style="white-space:pre-wrap"><?= wwm_escape(<<<'HTML'
<!-- Yandex.Metrika counter -->
<script type="text/javascript">
   (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
   m[i].l=1*new Date();
   for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
   k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
   (window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

   ym(00000000, "init", {
        clickmap:true,
        trackLinks:true,
        accurateTrackBounce:true,
        webvisor:true
   });
</script>
HTML) ?></pre>
  <p class="field-hint" style="margin-top:12px">Replace <code>00000000</code> with your counter ID. Put the <code>&lt;noscript&gt;</code> block in the Body field if needed.</p>
</div>
