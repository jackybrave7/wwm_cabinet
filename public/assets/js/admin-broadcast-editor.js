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
  const imageDialog = document.getElementById('broadcast-image-dialog');
  const imageDialogUrl = document.getElementById('broadcast-image-dialog-url');
  const imageDialogAlt = document.getElementById('broadcast-image-dialog-alt');
  const imageDialogWidth = document.getElementById('broadcast-image-dialog-width');
  const imageDialogAlign = document.getElementById('broadcast-image-dialog-align');
  const imageDialogMarginTop = document.getElementById('broadcast-image-dialog-margin-top');
  const imageDialogMarginBottom = document.getElementById('broadcast-image-dialog-margin-bottom');
  const imageDialogPreview = document.getElementById('broadcast-image-dialog-preview');
  const imageDialogPreviewHint = document.getElementById('broadcast-image-dialog-preview-hint');
  const imageDialogError = document.getElementById('broadcast-image-dialog-error');
  const imageDialogInsert = document.getElementById('broadcast-image-dialog-insert');
  const imageDialogPickFile = document.getElementById('broadcast-image-dialog-pick-file');
  const formatRadios = form.querySelectorAll('[data-broadcast-format]');
  const htmlOnlyTabs = form.querySelectorAll('.broadcast-html-only');

  let contentMode = config.contentMode === 'html' ? 'html' : 'plain';
  let activeTab = contentMode === 'html' ? 'visual' : 'text';
  let imageInsertTarget = 'visual';

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
        if (doc.head && !doc.querySelector('base')) {
          const base = doc.createElement('base');
          base.href = window.location.origin + '/';
          doc.head.appendChild(base);
        }
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

  function normalizeImageUrl(url) {
    const trimmed = String(url || '').trim();
    if (trimmed === '') {
      return '';
    }
    if (trimmed.startsWith('//')) {
      return window.location.protocol + trimmed;
    }
    if (trimmed.startsWith('/')) {
      return window.location.origin + trimmed;
    }
    return trimmed;
  }

  function resolveUploadUrl(data) {
    if (data && data.url && /^https?:\/\//i.test(data.url)) {
      return data.url;
    }
    if (data && data.path) {
      return window.location.origin + data.path;
    }
    return data && data.url ? normalizeImageUrl(data.url) : '';
  }

  function readImageOptionsFromDialog() {
    return {
      url: normalizeImageUrl(imageDialogUrl ? imageDialogUrl.value : ''),
      alt: imageDialogAlt ? imageDialogAlt.value.trim() : '',
      width: imageDialogWidth ? imageDialogWidth.value : '600px',
      align: imageDialogAlign ? imageDialogAlign.value : 'center',
      marginTop: imageDialogMarginTop ? imageDialogMarginTop.value : '16',
      marginBottom: imageDialogMarginBottom ? imageDialogMarginBottom.value : '16',
    };
  }

  function buildImageStyle(opts) {
    const width = opts.width || '600px';
    let imgStyle = 'display:block;max-width:100%;height:auto;border:0;';
    if (width === '100%') {
      imgStyle += 'width:100%;';
    } else {
      imgStyle += 'width:' + width + ';';
    }
    if (opts.align === 'center') {
      imgStyle += 'margin-left:auto;margin-right:auto;';
    } else if (opts.align === 'right') {
      imgStyle += 'margin-left:auto;margin-right:0;';
    } else {
      imgStyle += 'margin-left:0;margin-right:auto;';
    }
    return imgStyle;
  }

  function buildImageWrapperStyle(opts) {
    const mt = parseInt(opts.marginTop, 10) || 0;
    const mb = parseInt(opts.marginBottom, 10) || 0;
    return 'text-align:' + opts.align + ';margin:' + mt + 'px 0 ' + mb + 'px 0;';
  }

  function buildImageHtmlSnippet(opts) {
    const safeUrl = String(opts.url).replace(/"/g, '&quot;');
    const safeAlt = String(opts.alt).replace(/"/g, '&quot;');
    const imgStyle = buildImageStyle(opts);
    const wrapStyle = buildImageWrapperStyle(opts);
    return (
      '<div style="' + wrapStyle + '">' +
      '<img src="' + safeUrl + '" alt="' + safeAlt + '" style="' + imgStyle + '">' +
      '</div>'
    );
  }

  function insertImageInVisual(opts) {
    const doc = visualDocument();
    if (!doc || !visualFrame) {
      return;
    }
    const img = doc.createElement('img');
    img.src = opts.url;
    img.alt = opts.alt || '';
    img.setAttribute('style', buildImageStyle(opts));

    const wrap = doc.createElement('div');
    wrap.setAttribute('style', buildImageWrapperStyle(opts));
    wrap.appendChild(img);

    visualFrame.contentWindow.focus();
    const sel = doc.getSelection();
    if (sel && sel.rangeCount > 0) {
      const range = sel.getRangeAt(0);
      range.collapse(false);
      range.insertNode(wrap);
      range.setStartAfter(wrap);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
    } else if (doc.body) {
      doc.body.appendChild(wrap);
    }
    syncVisualToHtml();
  }

  function insertImageWithOptions(opts) {
    if (imageInsertTarget === 'html') {
      if (htmlInput) {
        insertAtCursor(htmlInput, buildImageHtmlSnippet(opts));
        syncHtmlHighlight();
      }
      return;
    }
    insertImageInVisual(opts);
  }

  function setImageDialogError(message) {
    if (!imageDialogError) {
      return;
    }
    if (message) {
      imageDialogError.textContent = message;
      imageDialogError.hidden = false;
    } else {
      imageDialogError.textContent = '';
      imageDialogError.hidden = true;
    }
  }

  function updateImageDialogPreview() {
    if (!imageDialogPreview) {
      return;
    }
    const url = normalizeImageUrl(imageDialogUrl ? imageDialogUrl.value : '');
    if (!url) {
      imageDialogPreview.removeAttribute('src');
      if (imageDialogPreviewHint) {
        imageDialogPreviewHint.hidden = false;
      }
      return;
    }
    imageDialogPreview.onload = () => {
      if (imageDialogPreviewHint) {
        imageDialogPreviewHint.hidden = true;
      }
      setImageDialogError('');
    };
    imageDialogPreview.onerror = () => {
      if (imageDialogPreviewHint) {
        imageDialogPreviewHint.hidden = false;
      }
    };
    imageDialogPreview.src = url;
  }

  function openImageDialog(url, target) {
    if (!imageDialog) {
      return;
    }
    imageInsertTarget = target || (activeTab === 'html' ? 'html' : 'visual');
    if (imageDialogUrl) {
      imageDialogUrl.value = url || '';
    }
    if (imageDialogAlt) {
      imageDialogAlt.value = '';
    }
    setImageDialogError('');
    updateImageDialogPreview();
    imageDialog.hidden = false;
    if (imageDialogUrl) {
      imageDialogUrl.focus();
    }
  }

  function closeImageDialog() {
    if (imageDialog) {
      imageDialog.hidden = true;
    }
    setImageDialogError('');
  }

  function validateImageUrl(url) {
    if (!url) {
      return 'Enter an image URL.';
    }
    if (!/^https:\/\//i.test(url)) {
      return 'Use a full https:// URL so email clients can load the image.';
    }
    return '';
  }

  function verifyImageLoads(url) {
    return new Promise((resolve, reject) => {
      const probe = new Image();
      probe.onload = () => resolve(url);
      probe.onerror = () => reject(new Error('Could not load this image. Check the URL or upload again.'));
      probe.src = url;
    });
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
    if (!response.ok || (!data.url && !data.path)) {
      window.alert(data.error || 'Image upload failed.');
      return null;
    }
    return resolveUploadUrl(data);
  }

  async function handleImageFile(file) {
    if (!file) {
      return;
    }
    const url = await uploadImageFile(file);
    if (!url) {
      return;
    }
    openImageDialog(url, imageInsertTarget || (activeTab === 'html' ? 'html' : 'visual'));
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
        imageInsertTarget = 'visual';
        if (imageUploadInput) {
          imageUploadInput.click();
        }
        return;
      }
      if (cmd === 'insertImageUrl') {
        openImageDialog('', 'visual');
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
      openImageDialog('', 'html');
    });
  }

  if (imageDialogPickFile && imageUploadInput) {
    imageDialogPickFile.addEventListener('click', () => {
      imageUploadInput.click();
    });
  }

  if (imageDialogUrl) {
    imageDialogUrl.addEventListener('input', updateImageDialogPreview);
  }

  imageDialog &&
    imageDialog.querySelectorAll('[data-image-dialog-close]').forEach((el) => {
      el.addEventListener('click', closeImageDialog);
    });

  if (imageDialogInsert) {
    imageDialogInsert.addEventListener('click', async () => {
      const opts = readImageOptionsFromDialog();
      const validationError = validateImageUrl(opts.url);
      if (validationError) {
        setImageDialogError(validationError);
        return;
      }
      imageDialogInsert.disabled = true;
      try {
        await verifyImageLoads(opts.url);
        insertImageWithOptions(opts);
        closeImageDialog();
      } catch (err) {
        setImageDialogError(err && err.message ? err.message : 'Could not load image.');
      } finally {
        imageDialogInsert.disabled = false;
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
