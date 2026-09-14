(function () {
  const config = window.__broadcastEditor || {};
  const form = document.getElementById('broadcast-editor-form');
  if (!form) {
    return;
  }

  const previewVars = config.previewVars || {};
  const visualFrame = document.getElementById('broadcast-visual-frame');
  const htmlInput = document.getElementById('broadcast-html-input');
  const textInput = document.getElementById('broadcast-text-input');
  const previewFrame = document.getElementById('broadcast-preview-frame');
  const previewText = document.getElementById('broadcast-preview-text');
  const htmlHighlight = document.getElementById('broadcast-html-highlight');
  const htmlHighlightCode = htmlHighlight ? htmlHighlight.querySelector('code') : null;
  const htmlFormatButton = document.getElementById('broadcast-html-format');
  const htmlInsertImageButton = document.getElementById('broadcast-html-insert-image');
  const imageUploadInput = document.getElementById('broadcast-image-upload');
  const formatRadios = form.querySelectorAll('[data-broadcast-format]');
  const htmlOnlyTabs = form.querySelectorAll('.broadcast-html-only');
  const imageStyle = 'display:block;max-width:100%;height:auto;margin:16px auto;border:0;';

  let contentMode = config.contentMode === 'html' ? 'html' : 'plain';
  let activeTab = contentMode === 'html' ? 'visual' : 'text';

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function highlightHtml(code) {
    let out = escapeHtml(code);
    out = out.replace(/(\{\{[^}]+\}\})/g, '<span class="tok-placeholder">$1</span>');
    out = out.replace(/(&lt;\/?)([a-zA-Z][\w:-]*)/g, '$1<span class="tok-tag">$2</span>');
    return out;
  }

  function syncHtmlHighlight() {
    if (!htmlInput || !htmlHighlightCode) {
      return;
    }
    htmlHighlightCode.innerHTML = highlightHtml(htmlInput.value) + '\n';
    if (htmlHighlight) {
      htmlHighlight.scrollTop = htmlInput.scrollTop;
    }
  }

  function applyPreviewVars(html) {
    let out = html;
    Object.entries(previewVars).forEach(([key, value]) => {
      out = out.split('{{' + key + '}}').join(String(value));
    });
    return out;
  }

  function setActiveTab(name) {
    if (contentMode === 'plain' && name !== 'text') {
      name = 'text';
    }
    activeTab = name;
    form.querySelectorAll('.broadcast-editor-tabs .email-editor-tab').forEach((tab) => {
      const tabName = tab.getAttribute('data-tab');
      const show = contentMode === 'html' || tabName === 'text';
      tab.hidden = !show;
      tab.classList.toggle('is-active', tabName === name && show);
    });
    form.querySelectorAll('.email-editor-panel').forEach((panel) => {
      const panelName = panel.getAttribute('data-panel');
      const show = panelName === name && (contentMode === 'html' || panelName === 'text');
      panel.classList.toggle('is-active', show);
    });
    if (name === 'preview') {
      renderPreview();
    }
  }

  function updateFormatUi() {
    const isHtml = contentMode === 'html';
    htmlOnlyTabs.forEach((el) => {
      el.hidden = !isHtml;
    });
    if (isHtml) {
      const seed = htmlInput && htmlInput.value.trim() ? htmlInput.value : readHtmlSeed();
      if (htmlInput && seed && !htmlInput.value.trim()) {
        htmlInput.value = seed;
      }
      loadVisualFromHtml(seed || '<!DOCTYPE html><html><body><p></p></body></html>');
      syncHtmlHighlight();
      if (activeTab === 'text') {
        setActiveTab('visual');
      } else {
        setActiveTab(activeTab);
      }
    } else {
      setActiveTab('text');
    }
  }

  function readHtmlSeed() {
    const node = document.getElementById('broadcast-html-seed');
    if (!node) {
      return '';
    }
    try {
      const parsed = JSON.parse(node.textContent || '""');
      return typeof parsed === 'string' ? parsed : '';
    } catch (e) {
      return '';
    }
  }

  function visualDocument() {
    return visualFrame && visualFrame.contentDocument ? visualFrame.contentDocument : null;
  }

  function loadVisualFromHtml(html) {
    if (!visualFrame) {
      return;
    }
    const docHtml = html || '<!DOCTYPE html><html><body><p></p></body></html>';
    visualFrame.onload = () => {
      const doc = visualDocument();
      if (doc) {
        doc.designMode = 'on';
        if (doc.body) {
          doc.body.contentEditable = 'true';
        }
      }
    };
    visualFrame.srcdoc = docHtml;
  }

  function syncVisualToHtml() {
    const doc = visualDocument();
    if (!doc || !htmlInput) {
      return;
    }
    const html = '<!DOCTYPE html>\n' + doc.documentElement.outerHTML;
    htmlInput.value = html;
    syncHtmlHighlight();
  }

  function syncBeforePreview() {
    if (contentMode === 'html' && activeTab === 'visual') {
      syncVisualToHtml();
    }
  }

  function renderPreview() {
    syncBeforePreview();
    const html = htmlInput ? htmlInput.value.trim() : '';
    const text = textInput ? textInput.value : '';
    if (contentMode === 'html' && previewFrame && html) {
      previewFrame.srcdoc = applyPreviewVars(html);
      if (previewText) {
        previewText.hidden = true;
      }
    } else if (previewText) {
      previewText.hidden = false;
      previewText.textContent = applyPreviewVars(text);
      if (previewFrame) {
        previewFrame.srcdoc = '';
      }
    }
  }

  function insertAtCursor(textarea, text) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(end);
    textarea.selectionStart = textarea.selectionEnd = start + text.length;
    textarea.focus();
  }

  function promptImageUrl() {
    const url = window.prompt('Image URL (must start with https://)', 'https://');
    if (!url || !url.trim()) {
      return null;
    }
    const trimmed = url.trim();
    if (!/^https:\/\//i.test(trimmed)) {
      window.alert('Use a full https:// URL so email clients can load the image.');
      return null;
    }
    return trimmed;
  }

  function promptAltText() {
    return window.prompt('Alt text (optional)', '') || '';
  }

  function insertImageInVisual(url, alt) {
    const doc = visualDocument();
    if (!doc || !visualFrame) {
      return;
    }
    visualFrame.contentWindow.focus();
    doc.execCommand('insertImage', false, url);
    const images = doc.getElementsByTagName('img');
    const image = images.length ? images[images.length - 1] : null;
    if (image) {
      image.setAttribute('alt', alt);
      image.setAttribute('style', imageStyle);
    }
    syncVisualToHtml();
  }

  function insertImageHtmlSnippet(url, alt) {
    const safeAlt = String(alt).replace(/"/g, '&quot;');
    const snippet = '<img src="' + url + '" alt="' + safeAlt + '" style="' + imageStyle + '">';
    if (htmlInput) {
      insertAtCursor(htmlInput, snippet);
      syncHtmlHighlight();
    }
  }

  function insertImageUrl(url, alt) {
    if (activeTab === 'html') {
      insertImageHtmlSnippet(url, alt);
      return;
    }
    insertImageInVisual(url, alt);
  }

  async function uploadImageFile(file) {
    const csrf = form.querySelector('[name="csrf"]');
    if (!csrf) {
      return null;
    }
    const body = new FormData();
    body.append('csrf', csrf.value);
    body.append('image', file);
    const response = await fetch('/admin/broadcasts/upload-image', {
      method: 'POST',
      body,
      credentials: 'same-origin',
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.url) {
      window.alert(data.error || 'Image upload failed.');
      return null;
    }
    return data.url;
  }

  async function handleImageFile(file) {
    if (!file) {
      return;
    }
    const url = await uploadImageFile(file);
    if (!url) {
      return;
    }
    insertImageUrl(url, promptAltText());
  }

  formatRadios.forEach((radio) => {
    radio.addEventListener('change', () => {
      if (!radio.checked) {
        return;
      }
      contentMode = radio.value === 'html' ? 'html' : 'plain';
      updateFormatUi();
    });
  });

  form.querySelectorAll('.broadcast-editor-tabs .email-editor-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      setActiveTab(tab.getAttribute('data-tab') || 'text');
    });
  });

  form.querySelectorAll('.email-visual-toolbar button').forEach((btn) => {
    btn.addEventListener('click', () => {
      const doc = visualDocument();
      if (!doc) {
        return;
      }
      const cmd = btn.getAttribute('data-cmd');
      const value = btn.getAttribute('data-value');
      visualFrame.contentWindow.focus();
      if (cmd === 'createLink') {
        const url = window.prompt('Link URL');
        if (url) {
          doc.execCommand('createLink', false, url);
        }
        syncVisualToHtml();
        return;
      }
      if (cmd === 'insertImage') {
        if (imageUploadInput) {
          imageUploadInput.click();
        }
        return;
      }
      if (cmd === 'insertImageUrl') {
        const imageUrl = promptImageUrl();
        if (imageUrl) {
          insertImageInVisual(imageUrl, promptAltText());
        }
        return;
      }
      if (cmd === 'formatBlock' && value) {
        doc.execCommand(cmd, false, '<' + value + '>');
        return;
      }
      if (cmd) {
        doc.execCommand(cmd, false, null);
      }
      syncVisualToHtml();
    });
  });

  document.querySelectorAll('.email-variable-chip').forEach((chip) => {
    chip.addEventListener('click', () => {
      const variable = chip.getAttribute('data-variable') || '';
      if (!variable) {
        return;
      }
      const subjectInput = form.querySelector('input[name="subject"]');
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
        syncHtmlHighlight();
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

  if (htmlInput) {
    htmlInput.addEventListener('input', syncHtmlHighlight);
    htmlInput.addEventListener('scroll', () => {
      if (htmlHighlight) {
        htmlHighlight.scrollTop = htmlInput.scrollTop;
      }
    });
  }

  if (htmlFormatButton && htmlInput) {
    htmlFormatButton.addEventListener('click', () => {
      htmlInput.value = htmlInput.value.replace(/>\s*</g, '>\n<');
      syncHtmlHighlight();
    });
  }

  if (htmlInsertImageButton) {
    htmlInsertImageButton.addEventListener('click', () => {
      if (imageUploadInput) {
        imageUploadInput.click();
      }
    });
  }

  if (imageUploadInput) {
    imageUploadInput.addEventListener('change', () => {
      const file = imageUploadInput.files && imageUploadInput.files[0];
      imageUploadInput.value = '';
      handleImageFile(file);
    });
  }

  form.addEventListener('submit', () => {
    if (contentMode === 'html') {
      if (activeTab === 'visual') {
        syncVisualToHtml();
      }
    } else if (htmlInput) {
      htmlInput.value = '';
    }
  });

  updateFormatUi();
})();
