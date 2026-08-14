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
  const htmlHighlight = document.getElementById('email-html-highlight');
  const htmlHighlightCode = htmlHighlight ? htmlHighlight.querySelector('code') : null;
  const htmlFormatButton = document.getElementById('email-html-format');
  let activeTab = hasHtml ? 'visual' : 'text';
  let htmlFormattedOnce = false;

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function highlightHtml(code) {
    let out = escapeHtml(code);
    out = out.replace(/(&lt;!--[\s\S]*?--&gt;)/g, '<span class="tok-comment">$1</span>');
    out = out.replace(/(\{\{[^}]+\}\})/g, '<span class="tok-placeholder">$1</span>');
    out = out.replace(/(&lt;!DOCTYPE[^&]*&gt;)/gi, '<span class="tok-doctype">$1</span>');
    out = out.replace(/(&lt;\/?)([a-zA-Z][\w:-]*)/g, '$1<span class="tok-tag">$2</span>');
    out = out.replace(/(\s)([a-zA-Z_:][\w:.-]*)(=)/g, '$1<span class="tok-attr">$2</span>=');
    out = out.replace(/(=)(&quot;[^&]*?&quot;|&#39;[^&]*?&#39;)/g, '$1<span class="tok-value">$2</span>');
    return out;
  }

  function formatHtml(source) {
    const html = String(source || '').replace(/\r\n/g, '\n').trim();
    if (!html) {
      return '';
    }

    const voidTags = new Set([
      'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ]);
    const lines = html.replace(/>\s*</g, '>\n<').split('\n');
    let depth = 0;
    const out = [];

    lines.forEach((rawLine) => {
      const line = rawLine.trim();
      if (!line) {
        return;
      }

      const isClosing = /^<\//.test(line);
      const isSelfClosing = /\/>$/.test(line);
      const tagMatch = line.match(/^<\/?([a-zA-Z][\w:-]*)/);
      const tagName = tagMatch ? tagMatch[1].toLowerCase() : '';
      const isOpening = tagMatch && !isClosing && !isSelfClosing && !voidTags.has(tagName);
      const hasInlineClose = isOpening && line.includes('</' + tagName + '>');

      if (isClosing) {
        depth = Math.max(0, depth - 1);
      }

      out.push('  '.repeat(depth) + line);

      if (isOpening && !hasInlineClose) {
        depth += 1;
      }
    });

    return out.join('\n');
  }

  function syncHtmlHighlight() {
    if (!htmlInput || !htmlHighlightCode) {
      return;
    }
    htmlHighlightCode.innerHTML = highlightHtml(htmlInput.value) + '\n';
    syncHtmlScroll();
  }

  function syncHtmlScroll() {
    if (!htmlInput || !htmlHighlight) {
      return;
    }
    htmlHighlight.scrollTop = htmlInput.scrollTop;
    htmlHighlight.scrollLeft = htmlInput.scrollLeft;
  }

  function formatHtmlInput() {
    if (!htmlInput) {
      return;
    }
    htmlInput.value = formatHtml(htmlInput.value);
    syncHtmlHighlight();
  }

  function prepareHtmlTab() {
    if (!htmlInput) {
      return;
    }
    if (activeTab === 'visual') {
      syncVisualToHtml();
    }
    if (!htmlFormattedOnce) {
      formatHtmlInput();
      htmlFormattedOnce = true;
    } else {
      syncHtmlHighlight();
    }
  }

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
    visualFrame.onload = () => {
      enableVisualEditing();
      prepareVisualDocument(visualDocument());
    };
    visualFrame.srcdoc = html || '<!DOCTYPE html><html><body><p></p></body></html>';
  }

  function prepareVisualDocument(doc) {
    if (!doc || !doc.head) {
      return;
    }
    if (doc.getElementById('wwm-visual-editor-style')) {
      return;
    }
    const style = doc.createElement('style');
    style.id = 'wwm-visual-editor-style';
    style.textContent = [
      'a[href*="{{"], img[src*="{{"] {',
      '  outline: 1px dashed #c8bdb3;',
      '  outline-offset: 2px;',
      '}',
      'img[src*="{{"] {',
      '  display: block;',
      '  box-sizing: border-box;',
      '  width: 100%;',
      '  max-width: 520px;',
      '  margin: 16px auto;',
      '  padding: 18px 16px;',
      '  background: #f3f0ea;',
      '  border: 2px dashed #c8bdb3;',
      '  border-radius: 8px;',
      '  object-fit: none;',
      '  min-height: 72px;',
      '}',
    ].join('\n');
    doc.head.appendChild(style);

    doc.querySelectorAll('img[src*="{{"]').forEach((img) => {
      const src = img.getAttribute('src') || '';
      img.setAttribute('alt', src);
      img.setAttribute('title', src);
    });
  }

  function htmlFromVisual() {
    const doc = visualDocument();
    if (!doc || !doc.documentElement) return '';
    return '<!DOCTYPE html>\n' + doc.documentElement.outerHTML;
  }

  function syncVisualToHtml() {
    if (!hasHtml || !htmlInput) return;
    htmlInput.value = htmlFromVisual();
    syncHtmlHighlight();
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
    const text = textInput ? applyPreviewVars(textInput.value) : '';
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
      if (next === 'html') {
        prepareHtmlTab();
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
        doc.execCommand('insertImage', false, url.trim());
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

  if (htmlInput) {
    htmlInput.addEventListener('input', syncHtmlHighlight);
    htmlInput.addEventListener('scroll', syncHtmlScroll);
  }

  if (htmlFormatButton) {
    htmlFormatButton.addEventListener('click', formatHtmlInput);
  }

  if (previewRefresh) {
    previewRefresh.addEventListener('click', () => {
      syncBeforePreview();
      setActiveTab('preview');
    });
  }

  if (form) {
    form.addEventListener('submit', (event) => {
      if (hasHtml) {
        syncVisualToHtml();
      }
      const templateIdInput = form.querySelector('input[name="template_id"]');
      const templateId = templateIdInput ? String(templateIdInput.value || '').trim() : '';
      if (!templateId) {
        event.preventDefault();
        window.alert('Template id is missing. Reload the page and try again.');
      }
    });
  }

  if (hasHtml) {
    loadVisualFromHtml(config.initialHtml || '');
    syncHtmlHighlight();
  }
})();
