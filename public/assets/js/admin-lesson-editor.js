document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('lesson-form');
  const editor = document.getElementById('lesson-html-editor');
  const htmlField = document.getElementById('lesson-html-body');
  const modal = document.getElementById('video-modal');
  const modalTitle = document.getElementById('video-modal-title');
  const modalInsertBtn = document.getElementById('video-modal-insert');
  const modalProvider = document.getElementById('video-modal-provider');
  const modalUrl = document.getElementById('video-modal-url');
  let savedRange = null;
  let editingVideoBlock = null;

  const escapeAttr = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;');

  const escapeText = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;');

  const shortUrlLabel = (url) => (url.length > 52 ? `${url.slice(0, 49)}…` : url);

  const detectProvider = (url) => {
    if (/kinescope\.io/i.test(url)) {
      return 'kinescope';
    }
    if (/vimeo\.com/i.test(url)) {
      return 'vimeo';
    }
    if (/youtube|youtu\.be/i.test(url)) {
      return 'youtube';
    }
    return 'kinescope';
  };

  const normalizeEmbedUrl = (rawUrl, provider) => {
    let url = rawUrl.trim();
    if (!url) {
      return '';
    }
    if (!/^https?:\/\//i.test(url)) {
      url = `https://${url.replace(/^\/+/, '')}`;
    }

    if (provider === 'kinescope' || /kinescope\.io/i.test(url)) {
      const embedMatch = url.match(/kinescope\.io\/embed\/([a-zA-Z0-9_-]+)/i);
      if (embedMatch) {
        return `https://kinescope.io/embed/${embedMatch[1]}`;
      }
      const shareMatch = url.match(/kinescope\.io\/([a-zA-Z0-9_-]+)/i);
      if (shareMatch && shareMatch[1].toLowerCase() !== 'embed') {
        return `https://kinescope.io/embed/${shareMatch[1]}`;
      }
    }

    if (provider === 'youtube' || /youtube|youtu\.be/i.test(url)) {
      const embedMatch = url.match(/youtube(?:-nocookie)?\.com\/embed\/([^?&/]+)/i);
      if (embedMatch) {
        return `https://www.youtube.com/embed/${embedMatch[1]}`;
      }
      const watchMatch = url.match(/[?&]v=([^&]+)/);
      if (watchMatch) {
        return `https://www.youtube.com/embed/${watchMatch[1]}`;
      }
      const shortMatch = url.match(/youtu\.be\/([^?&/]+)/);
      if (shortMatch) {
        return `https://www.youtube.com/embed/${shortMatch[1]}`;
      }
    }

    if (provider === 'vimeo' || /vimeo\.com/i.test(url)) {
      const playerMatch = url.match(/player\.vimeo\.com\/video\/(\d+)/i);
      if (playerMatch) {
        return `https://player.vimeo.com/video/${playerMatch[1]}`;
      }
      const idMatch = url.match(/vimeo\.com\/(?:video\/)?(\d+)/i);
      if (idMatch) {
        return `https://player.vimeo.com/video/${idMatch[1]}`;
      }
    }

    return url;
  };

  const buildVideoEmbed = (url) => (
    `<div class="video-embed" contenteditable="false" data-video-url="${escapeAttr(url)}">`
    + '<div class="video-embed-toolbar">'
    + `<span class="video-embed-url" title="${escapeAttr(url)}">${escapeText(shortUrlLabel(url))}</span>`
    + '<span class="video-embed-actions">'
    + '<button type="button" class="btn btn-ghost btn-sm" data-video-edit>Edit URL</button>'
    + '<button type="button" class="btn btn-ghost btn-sm" data-video-remove>Remove</button>'
    + '</span></div>'
    + '<div class="video-embed-preview">'
    + `<iframe src="${escapeAttr(url)}" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen loading="lazy"></iframe>`
    + '</div></div><p><br></p>'
  );

  const updateVideoEmbedBlock = (block, url) => {
    block.setAttribute('data-video-url', url);
    const urlLabel = block.querySelector('.video-embed-url');
    if (urlLabel) {
      urlLabel.textContent = shortUrlLabel(url);
      urlLabel.title = url;
    }
    const iframe = block.querySelector('.video-embed-preview iframe');
    if (iframe) {
      iframe.setAttribute('src', url);
    }
  };

  const serializeEditorHtml = () => {
    if (!editor) {
      return '';
    }
    const clone = editor.cloneNode(true);
    clone.querySelectorAll('.video-embed').forEach((block) => {
      const url = block.getAttribute('data-video-url') || '';
      if (!url) {
        block.remove();
        return;
      }
      const wrapper = document.createElement('div');
      wrapper.className = 'video-block';
      wrapper.innerHTML = `<iframe src="${escapeAttr(url)}" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen loading="lazy"></iframe>`;
      block.replaceWith(wrapper);
    });
    return clone.innerHTML;
  };

  const deserializeToEditor = (html) => {
    const template = document.createElement('template');
    template.innerHTML = html;
    template.content.querySelectorAll('.video-block').forEach((block) => {
      const iframe = block.querySelector('iframe');
      const url = iframe?.getAttribute('src') || '';
      if (!url) {
        block.remove();
        return;
      }
      const placeholder = document.createElement('template');
      placeholder.innerHTML = buildVideoEmbed(url);
      block.replaceWith(placeholder.content.firstElementChild);
    });
    return template.innerHTML;
  };

  const syncEditorToField = () => {
    if (htmlField) {
      htmlField.value = serializeEditorHtml();
    }
  };

  const syncFieldToEditor = () => {
    if (!editor || !htmlField) {
      return;
    }
    if (htmlField.value.trim() !== '') {
      editor.innerHTML = deserializeToEditor(htmlField.value);
    }
  };

  const saveSelection = () => {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || !editor) {
      savedRange = null;
      return;
    }
    const range = selection.getRangeAt(0);
    if (!editor.contains(range.commonAncestorContainer)) {
      savedRange = null;
      return;
    }
    savedRange = range.cloneRange();
  };

  const restoreSelection = () => {
    if (!savedRange || !editor) {
      return false;
    }
    const selection = window.getSelection();
    if (!selection) {
      return false;
    }
    selection.removeAllRanges();
    selection.addRange(savedRange);
    return true;
  };

  const insertHtmlAtCursor = (html) => {
    if (!editor) {
      return;
    }
    editor.focus();
    if (!restoreSelection()) {
      const selection = window.getSelection();
      if (!selection || selection.rangeCount === 0 || !editor.contains(selection.anchorNode)) {
        editor.insertAdjacentHTML('beforeend', html);
        savedRange = null;
        syncEditorToField();
        return;
      }
    }
    document.execCommand('insertHTML', false, html);
    savedRange = null;
    syncEditorToField();
  };

  const setModalMode = (mode) => {
    const isEdit = mode === 'edit';
    if (modalTitle) {
      modalTitle.textContent = isEdit ? 'Edit video' : 'Insert video';
    }
    if (modalInsertBtn) {
      modalInsertBtn.textContent = isEdit ? 'Update video' : 'Insert';
    }
  };

  const openModal = (mode = 'insert', block = null) => {
    if (!modal) {
      return;
    }
    editingVideoBlock = mode === 'edit' ? block : null;
    setModalMode(mode);
    if (modalUrl) {
      modalUrl.value = block?.getAttribute('data-video-url') || '';
    }
    if (modalProvider && modalUrl?.value) {
      modalProvider.value = detectProvider(modalUrl.value);
    }
    modal.classList.add('is-open');
    updateVideoHint();
    modalUrl?.focus();
  };

  const closeModal = () => {
    modal?.classList.remove('is-open');
    editingVideoBlock = null;
    setModalMode('insert');
    if (modalUrl) {
      modalUrl.value = '';
    }
  };

  syncFieldToEditor();

  if (editor) {
    editor.addEventListener('input', syncEditorToField);
    editor.addEventListener('blur', syncEditorToField);

    editor.addEventListener('click', (event) => {
      const removeBtn = event.target.closest('[data-video-remove]');
      const editBtn = event.target.closest('[data-video-edit]');
      const block = event.target.closest('.video-embed');
      if (!block) {
        return;
      }

      event.preventDefault();
      if (removeBtn) {
        block.remove();
        syncEditorToField();
        return;
      }
      if (editBtn) {
        openModal('edit', block);
      }
    });
  }

  if (form) {
    form.addEventListener('submit', () => {
      syncEditorToField();
    }, true);
  }

  document.querySelectorAll('button[type="submit"][form="lesson-form"], #lesson-form button[type="submit"]').forEach((button) => {
    button.addEventListener('mousedown', () => {
      saveSelection();
      syncEditorToField();
    });
    button.addEventListener('click', syncEditorToField);
  });

  const headingSelect = document.getElementById('editor-heading');
  if (headingSelect && editor) {
    headingSelect.addEventListener('change', () => {
      const value = headingSelect.value;
      if (!value) {
        return;
      }
      document.execCommand('formatBlock', false, value);
      headingSelect.selectedIndex = 0;
      editor.focus();
      syncEditorToField();
    });
  }

  document.querySelectorAll('.editor-toolbar [data-cmd]').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      const cmd = btn.getAttribute('data-cmd');
      if (!cmd) {
        return;
      }
      const val = btn.getAttribute('data-value');
      if (cmd === 'createLink') {
        const url = window.prompt('Link URL', val || 'https://');
        if (url) {
          document.execCommand(cmd, false, url);
        }
      } else {
        document.execCommand(cmd, false, val);
      }
      editor?.focus();
      syncEditorToField();
    });
  });

  document.querySelectorAll('[data-modal-open]').forEach((trigger) => {
    const id = trigger.getAttribute('data-modal-open');
    if (!id) {
      return;
    }
    trigger.addEventListener('mousedown', (event) => {
      event.preventDefault();
      saveSelection();
    });
    trigger.addEventListener('click', () => openModal('insert'));
  });

  document.querySelectorAll('[data-modal-close]').forEach((el) => {
    el.addEventListener('click', closeModal);
  });

  const samples = {
    kinescope: 'https://kinescope.io/r11d1T5NkGJnCcXmWBStkv',
    vimeo: 'https://player.vimeo.com/video/123456789',
    youtube: 'https://www.youtube.com/embed/xxxxxxxx',
  };

  const hints = {
    kinescope: 'Paste Kinescope share or embed URL',
    vimeo: 'Vimeo player URL or video ID',
    youtube: 'YouTube share or embed URL',
  };

  const updateVideoHint = () => {
    if (!modalProvider) {
      return;
    }
    const provider = modalProvider.value;
    const hint = document.querySelector('[data-video-hint]');
    if (modalUrl && samples[provider]) {
      modalUrl.placeholder = samples[provider];
    }
    if (hint && hints[provider]) {
      hint.textContent = hints[provider];
    }
  };

  modalProvider?.addEventListener('change', updateVideoHint);
  updateVideoHint();

  modalInsertBtn?.addEventListener('click', () => {
    if (!modalUrl || !editor) {
      return;
    }
    const provider = modalProvider?.value || 'kinescope';
    const url = normalizeEmbedUrl(modalUrl.value, provider);
    if (!url) {
      modalUrl.focus();
      return;
    }
    if (editingVideoBlock) {
      updateVideoEmbedBlock(editingVideoBlock, url);
    } else {
      insertHtmlAtCursor(buildVideoEmbed(url));
    }
    closeModal();
    editor.focus();
  });

  const materialsList = document.getElementById('materials-list');
  document.getElementById('add-material-row')?.addEventListener('click', () => {
    if (!materialsList) {
      return;
    }
    const row = document.createElement('div');
    row.className = 'material-row field-row';
    row.innerHTML = `
      <label class="field">
        <span>Title</span>
        <input type="text" name="material_title[]" placeholder="File name">
      </label>
      <label class="field">
        <span>URL</span>
        <input type="url" name="material_url[]" placeholder="https://...">
      </label>
    `;
    materialsList.appendChild(row);
  });
});
