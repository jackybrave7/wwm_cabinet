document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.lesson-sortable').forEach((list) => {
    let dragged = null;

    list.addEventListener('dragstart', (event) => {
      const handle = event.target.closest('.drag-handle');
      if (!handle) {
        event.preventDefault();
        return;
      }
      dragged = handle.closest('.lesson-row');
      if (!dragged) {
        return;
      }
      dragged.classList.add('is-dragging');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', dragged.dataset.lessonNum || '');
    });

    list.addEventListener('dragend', () => {
      if (dragged) {
        dragged.classList.remove('is-dragging');
      }
      dragged = null;
      list.classList.remove('is-drag-over');
    });

    list.addEventListener('dragover', (event) => {
      if (!dragged) {
        return;
      }
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      list.classList.add('is-drag-over');

      const target = event.target.closest('.lesson-row');
      if (!target || target === dragged || !list.contains(target)) {
        return;
      }

      const rect = target.getBoundingClientRect();
      const after = event.clientY > rect.top + rect.height / 2;
      list.insertBefore(dragged, after ? target.nextSibling : target);
    });

    list.addEventListener('dragleave', (event) => {
      if (!list.contains(event.relatedTarget)) {
        list.classList.remove('is-drag-over');
      }
    });

    list.addEventListener('drop', (event) => {
      event.preventDefault();
      list.classList.remove('is-drag-over');
    });
  });

  document.querySelectorAll('.demo-toggle input').forEach((input) => {
    const row = input.closest('.lesson-row');
    const sync = () => row?.classList.toggle('is-demo', input.checked);
    input.addEventListener('change', sync);
    sync();
  });
});
