<?php
declare(strict_types=1);

namespace Wwm\Services;

/**
 * Script dropped on the Tilda landing in place of the AVO form script.
 */
final class DemoLeadEmbed
{
    /**
     * @param array<string, string> $copy
     */
    public static function script(string $slug, string $postUrl, array $copy, bool $showButton, bool $openOnLoad): string
    {
        $cfg = json_encode([
            'slug' => $slug,
            'postUrl' => $postUrl,
            'copy' => $copy,
            'showButton' => $showButton,
            'openOnLoad' => $openOnLoad,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        if ($cfg === false) {
            $cfg = '{}';
        }

        return <<<JS
(function () {
  var cfg = {$cfg};
  var scriptEl = document.currentScript;
  if (!cfg || !cfg.slug || !cfg.postUrl) return;
  window.__wwmDemoLead = window.__wwmDemoLead || {};
  if (window.__wwmDemoLead[cfg.slug]) return;
  window.__wwmDemoLead[cfg.slug] = true;

  var copy = cfg.copy || {};
  var style = document.createElement('style');
  style.textContent = [
    '.wwm-demo-overlay{position:fixed;inset:0;z-index:1000000;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;padding:24px;}',
    '.wwm-demo-overlay.is-open{display:flex;}',
    '.wwm-demo-card{position:relative;width:min(500px,100%);background:#fffdf7;padding:28px 24px 18px;font-family:Manrope,system-ui,sans-serif;color:#1a110a;box-sizing:border-box;}',
    '.wwm-demo-card *{box-sizing:border-box;}',
    '.wwm-demo-bar{height:6px;background:#ffa000;margin:0 0 14px;}',
    '.wwm-demo-kicker{margin:0 0 8px;font-size:16px;}',
    '.wwm-demo-title{margin:0 0 10px;font-family:"Playfair Display",Georgia,serif;font-weight:700;font-size:28px;line-height:1.2;}',
    '.wwm-demo-text{margin:0 0 12px;font-size:16px;line-height:1.45;}',
    '.wwm-demo-card input[type=email],.wwm-demo-card input[type=text]{display:block;width:100%;margin:10px 0;padding:8px 5px;font:18px/1.3 Manrope,system-ui,sans-serif;border:1px solid #ccc;background:#fff;color:#1a110a;}',
    '.wwm-demo-submit{display:block;width:100%;margin:10px 0;padding:10px 8px;border:0;border-radius:8px;background:#e58a00;color:#fff;font:700 16px/1.2 Manrope,system-ui,sans-serif;cursor:pointer;}',
    '.wwm-demo-submit:hover{background:#c37500;}',
    '.wwm-demo-submit:disabled{opacity:.7;cursor:wait;}',
    '.wwm-demo-note{margin:0 0 8px;text-align:center;font-size:13px;color:#5b6b79;}',
    '.wwm-demo-error{margin:0 0 8px;color:#b81e16;font-size:14px;}',
    '.wwm-demo-success{margin:12px 0;font-size:16px;line-height:1.45;}',
    '.wwm-demo-close{position:absolute;right:-12px;top:-12px;width:40px;height:40px;border:0;border-radius:20px;background:#fff;box-shadow:0 0 3px #888;font-size:28px;line-height:1;cursor:pointer;color:#5b6b79;}',
    '.wwm-demo-hp{position:absolute;left:-9999px;height:0;overflow:hidden;}'
  ].join('');
  document.head.appendChild(style);

  var overlay = document.createElement('div');
  overlay.className = 'wwm-demo-overlay';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');

  var card = document.createElement('div');
  card.className = 'wwm-demo-card';
  overlay.appendChild(card);

  var closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.className = 'wwm-demo-close';
  closeBtn.setAttribute('aria-label', 'Close');
  closeBtn.textContent = '×';
  card.appendChild(closeBtn);

  var bar = document.createElement('div');
  bar.className = 'wwm-demo-bar';
  card.appendChild(bar);

  function addText(tag, className, text) {
    var node = document.createElement(tag);
    node.className = className;
    node.textContent = text || '';
    card.appendChild(node);
    return node;
  }

  addText('p', 'wwm-demo-kicker', copy.kicker);
  addText('h2', 'wwm-demo-title', copy.title);
  addText('p', 'wwm-demo-text', copy.text);

  var form = document.createElement('form');
  form.method = 'post';
  form.action = cfg.postUrl;
  form.noValidate = true;
  card.appendChild(form);

  var email = document.createElement('input');
  email.type = 'email';
  email.name = 'email';
  email.required = true;
  email.autocomplete = 'email';
  email.placeholder = copy.email_placeholder || 'Email';
  form.appendChild(email);

  var name = document.createElement('input');
  name.type = 'text';
  name.name = 'name';
  name.autocomplete = 'name';
  name.placeholder = copy.name_placeholder || 'Your name';
  form.appendChild(name);

  var hp = document.createElement('div');
  hp.className = 'wwm-demo-hp';
  hp.setAttribute('aria-hidden', 'true');
  var company = document.createElement('input');
  company.type = 'text';
  company.name = 'company';
  company.tabIndex = -1;
  company.autocomplete = 'off';
  hp.appendChild(company);
  form.appendChild(hp);

  var embedFlag = document.createElement('input');
  embedFlag.type = 'hidden';
  embedFlag.name = 'embed';
  embedFlag.value = '1';
  form.appendChild(embedFlag);

  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (key) {
    var params = new URLSearchParams(window.location.search);
    var value = params.get(key);
    if (!value) return;
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = key;
    hidden.value = value.slice(0, 255);
    form.appendChild(hidden);
  });

  var error = document.createElement('p');
  error.className = 'wwm-demo-error';
  error.hidden = true;
  form.appendChild(error);

  var submit = document.createElement('button');
  submit.type = 'submit';
  submit.className = 'wwm-demo-submit';
  submit.textContent = copy.button || 'Send me the demo';
  form.appendChild(submit);

  addText('p', 'wwm-demo-note', copy.note);

  function openForm() {
    overlay.classList.add('is-open');
    email.focus();
  }

  function closeForm() {
    overlay.classList.remove('is-open');
  }

  closeBtn.addEventListener('click', closeForm);
  overlay.addEventListener('click', function (event) {
    if (event.target === overlay) closeForm();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeForm();
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    error.hidden = true;
    submit.disabled = true;
    var body = new URLSearchParams(new FormData(form));
    fetch(cfg.postUrl, {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (res) {
      return res.json().catch(function () { return { ok: false, message: copy.error_generic }; });
    }).then(function (data) {
      if (data && data.ok) {
        form.hidden = true;
        var done = document.createElement('p');
        done.className = 'wwm-demo-success';
        done.textContent = (data && data.message) || copy.success || '';
        card.insertBefore(done, form);
        return;
      }
      error.textContent = (data && data.message) || copy.error_generic || '';
      error.hidden = false;
      submit.disabled = false;
    }).catch(function () {
      error.textContent = copy.error_generic || '';
      error.hidden = false;
      submit.disabled = false;
    });
  });

  function mount() {
    document.body.appendChild(overlay);
    if (cfg.showButton) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'wwm-demo-submit';
      btn.textContent = copy.button || 'Send me the demo';
      btn.addEventListener('click', openForm);
      if (scriptEl && scriptEl.parentNode) scriptEl.parentNode.insertBefore(btn, scriptEl);
      else document.body.appendChild(btn);
    }
    if (cfg.openOnLoad) openForm();
  }

  if (document.body) mount();
  else document.addEventListener('DOMContentLoaded', mount);

  document.addEventListener('click', function (event) {
    var node = event.target;
    if (!node || !node.closest) return;
    var hit = node.closest('[data-wwm-demo], a[href="#wwm-demo"], a[href\$="#wwm-demo"]');
    if (!hit) return;
    var attr = hit.getAttribute('data-wwm-demo');
    if (attr && attr !== cfg.slug && attr !== '1') return;
    event.preventDefault();
    openForm();
  });

  var previous = window.wwmOpenDemoLead;
  window.wwmOpenDemoLead = function (slug) {
    if (!slug || slug === cfg.slug) {
      openForm();
      return;
    }
    if (typeof previous === 'function') previous(slug);
  };
})();
JS;
    }
}
