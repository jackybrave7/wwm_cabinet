/**
 * WWM Cabinet — HTML prototype interactions (demo only).
 */
(function () {
  document.querySelectorAll('[data-tabs]').forEach((root) => {
    const buttons = root.querySelectorAll('[data-tab]');
    const panels = root.querySelectorAll('[data-panel]');
    buttons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-tab');
        buttons.forEach((b) => b.classList.toggle('is-active', b === btn));
        panels.forEach((p) => p.classList.toggle('is-active', p.getAttribute('data-panel') === id));
      });
    });
  });

  document.querySelectorAll('[data-modal-open]').forEach((trigger) => {
    const id = trigger.getAttribute('data-modal-open');
    const modal = document.getElementById(id);
    if (!modal) return;
    trigger.addEventListener('click', () => modal.classList.add('is-open'));
    modal.querySelectorAll('[data-modal-close]').forEach((el) => {
      el.addEventListener('click', () => modal.classList.remove('is-open'));
    });
  });

  document.querySelectorAll('.editor-toolbar [data-cmd]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const cmd = btn.getAttribute('data-cmd');
      const val = btn.getAttribute('data-value') || null;
      document.execCommand(cmd, false, val);
      const editor = document.querySelector('.editor-surface[contenteditable]');
      editor?.focus();
    });
  });

  document.querySelectorAll('[data-video-provider]').forEach((select) => {
    const form = select.closest('form');
    const urlInput = form?.querySelector('[name="video_url"]');
    const hint = form?.querySelector('[data-video-hint]');
    const samples = {
      kinescope: 'https://kinescope.io/embed/xxxxxxxx',
      vimeo: 'https://player.vimeo.com/video/123456789',
      youtube: 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    };
    const update = () => {
      const p = select.value;
      if (urlInput && samples[p]) urlInput.placeholder = samples[p];
      if (hint) {
        hint.textContent =
          p === 'kinescope'
            ? 'Paste Kinescope embed URL. Whitelist: my.worldwatercolormasters.art'
            : p === 'vimeo'
              ? 'Vimeo player URL or video ID'
              : 'YouTube embed URL';
      }
    };
    select.addEventListener('change', update);
    update();
  });

  document.querySelectorAll('.demo-toggle input').forEach((input) => {
    const row = input.closest('.lesson-row');
    const sync = () => row?.classList.toggle('is-demo', input.checked);
    input.addEventListener('change', sync);
    sync();
  });

  const courseLayout = document.querySelector('[data-course-layout]');
  if (courseLayout) {
    const navItems = [...courseLayout.querySelectorAll('.lesson-nav-item[data-lesson-id]')];
    const panels = [...courseLayout.querySelectorAll('[data-lesson-panel]')];

    const showLesson = (id) => {
      navItems.forEach((item) => {
        item.classList.toggle('is-active', item.dataset.lessonId === id);
      });
      panels.forEach((panel) => {
        panel.hidden = panel.dataset.lessonPanel !== id;
      });
    };

    navItems.forEach((item) => {
      item.addEventListener('click', (event) => {
        event.preventDefault();
        showLesson(item.dataset.lessonId);
      });
    });

    const first = navItems.find((item) => !item.classList.contains('is-locked'));
    if (first?.dataset.lessonId) {
      showLesson(first.dataset.lessonId);
    }
  }
})();
