(function () {
  const config = window.__emailEditor || {};
  const hasHtml = !!config.hasHtml;
  const previewVars = config.previewVars || {};
  const form = document.getElementById('email-editor-form');
  const visualFrame = document.getElementById('email-visual-frame');
  const htmlInput = document.getElementById('email-html-input');
  const textInput = document.getElementById('email-text-input');
  const previewFrame = document.getElementById('email-preview-frame');
  const previewText = document.getElementById('email-preview-text');
  const previewRefresh = document.getElementById('email-preview-refresh');
  let activeTab = hasHtml ? 'visual' : 'text';

  function previewVarPairs() {
    return Object.entries(previewVars)
      .filter(([, value]) => String(value).trim() !== '')
      .sort((a, b) => String(b[1]).length - String(a[1]).length);
  }

  function applyPreviewVars(html) {
    let out = html;
    previewVarPairs().forEach(([key, value]) => {
      out = out.split('{{' + key + '}}').join(String(value));
    });
    return out;
  }

  function revertPreviewVars(html) {
    let out = html;
    previewVarPairs().forEach(([key, value]) => {
      out = out.split(String(value)).join('{{' + key + '}}');
    });
    return out;
  }

  function setActiveTab(name) {
    activeTab = name;
    document.querySelectorAll('.email-editor-tab').forEach((tab) => {
      tab.classList.toggle('is-active', tab.getAttribute('data-tab') === name);
    });
    document.querySelectorAll('.email-editor-panel').forEach((panel) => {
      panel.classList.toggle('is-active', panel.getAttribute('data-panel') === name);
    });
    if (name === 'preview') {
      syncBeforePreview();
      renderPreview();
    }
  }

  function visualDocument() {
    return visualFrame && visualFrame.contentDocument ? visualFrame.contentDocument : null;
  }

  function enableVisualEditing() {
    const doc = visualDocument();
    if (!doc) return;
    doc.designMode = 'on';
    if (doc.body) {
      doc.body.contentEditable = 'true';
    }
  }

  function loadVisualFromHtml(html) {
    if (!visualFrame) return;
    visualFrame.onload = () => enableVisualEditing();
    visualFrame.srcdoc = applyPreviewVars(html || '<!DOCTYPE html><html><body><p></p></body></html>');
  }

  function htmlFromVisual() {
    const doc = visualDocument();
    if (!doc || !doc.documentElement) return '';
    return '<!DOCTYPE html>\n' + doc.documentElement.outerHTML;
  }

  function syncVisualToHtml() {
    if (!hasHtml || !htmlInput) return;
    htmlInput.value = revertPreviewVars(htmlFromVisual());
  }

  function syncHtmlToVisual() {
    if (!hasHtml || !htmlInput) return;
    loadVisualFromHtml(htmlInput.value);
  }

  function syncBeforePreview() {
    if (activeTab === 'visual') {
      syncVisualToHtml();
    } else if (activeTab === 'html') {
      syncHtmlToVisual();
    }
  }

  function renderPreview() {
    const rawHtml = htmlInput ? htmlInput.value.trim() : '';
    const html = rawHtml ? applyPreviewVars(rawHtml) : '';
    const text = textInput ? textInput.value : '';
    if (previewFrame) {
      previewFrame.style.display = html ? 'block' : 'none';
      if (html) {
        previewFrame.srcdoc = html;
      }
    }
    if (previewText) {
      previewText.style.display = html ? 'none' : 'block';
      previewText.textContent = text;
    }
  }

  document.querySelectorAll('.email-editor-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      const next = tab.getAttribute('data-tab');
      if (!next) return;
      if (activeTab === 'visual') {
        syncVisualToHtml();
      }
      if (next === 'visual') {
        syncHtmlToVisual();
      }
      setActiveTab(next);
    });
  });

  document.querySelectorAll('.email-visual-toolbar button').forEach((button) => {
    button.addEventListener('click', () => {
      const doc = visualDocument();
      if (!doc) return;
      const cmd = button.getAttribute('data-cmd');
      const value = button.getAttribute('data-value');
      visualFrame.contentWindow.focus();
      if (cmd === 'createLink') {
        const url = window.prompt('Link URL');
        if (url) {
          doc.execCommand('createLink', false, url);
        }
        return;
      }
      if (cmd === 'insertImage') {
        const url = window.prompt(
          'Image URL (you can use {{cover_url}} or {{logo_url}})',
          '{{cover_url}}'
        );
        if (!url) {
          return;
        }
        const alt = window.prompt('Alt text (optional)', 'Course cover') || '';
        const src = applyPreviewVars(url.trim());
        doc.execCommand('insertImage', false, src);
        const images = doc.getElementsByTagName('img');
        const image = images.length ? images[images.length - 1] : null;
        if (image) {
          image.setAttribute('alt', alt);
          image.setAttribute('style', 'display:block;max-width:100%;height:auto;margin:16px auto;border:0;');
        }
        syncVisualToHtml();
        return;
      }
      if (cmd === 'formatBlock' && value) {
        doc.execCommand(cmd, false, '<' + value + '>');
        return;
      }
      if (cmd) {
        doc.execCommand(cmd, false, null);
      }
    });
  });

  document.querySelectorAll('.email-variable-chip').forEach((chip) => {
    chip.addEventListener('click', () => {
      const variable = chip.getAttribute('data-variable') || '';
      if (!variable) return;
      const subjectInput = form ? form.querySelector('input[name="subject"]') : null;
      if (document.activeElement === subjectInput && subjectInput) {
        insertAtCursor(subjectInput, variable);
        return;
      }
      if (activeTab === 'text' && textInput) {
        insertAtCursor(textInput, variable);
        return;
      }
      if (activeTab === 'html' && htmlInput) {
        insertAtCursor(htmlInput, variable);
        return;
      }
      if (activeTab === 'visual') {
        const doc = visualDocument();
        if (doc) {
          visualFrame.contentWindow.focus();
          doc.execCommand('insertText', false, variable);
          syncVisualToHtml();
        }
      }
    });
  });

  function insertAtCursor(textarea, text) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const value = textarea.value;
    textarea.value = value.slice(0, start) + text + value.slice(end);
    textarea.selectionStart = textarea.selectionEnd = start + text.length;
    textarea.focus();
  }

  if (previewRefresh) {
    previewRefresh.addEventListener('click', () => {
      syncBeforePreview();
      setActiveTab('preview');
    });
  }

  if (form) {
    form.addEventListener('submit', () => {
      if (hasHtml) {
        syncVisualToHtml();
      }
    });
  }

  if (hasHtml) {
    loadVisualFromHtml(config.initialHtml || '');
  }
})();
