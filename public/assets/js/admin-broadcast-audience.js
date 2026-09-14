(function () {
  const form = document.getElementById('broadcast-editor-form');
  const audienceSelect = document.getElementById('broadcast-audience-select');
  const countEl = document.getElementById('broadcast-audience-count');
  const filterPanel = document.getElementById('broadcast-audience-filter');
  if (!form || !audienceSelect || !countEl) {
    return;
  }

  let timer = null;

  function syncFilterPanel() {
    if (!filterPanel) {
      return;
    }
    const isFiltered = audienceSelect.value === 'filtered';
    if (isFiltered) {
      filterPanel.open = true;
    }
  }

  function buildQuery() {
    const params = new URLSearchParams();
    params.set('audience', audienceSelect.value);
    form.querySelectorAll('[data-broadcast-audience-field], [name="bf_q"]').forEach((field) => {
      if (!field.name) {
        return;
      }
      if (field.type === 'checkbox' && !field.checked) {
        return;
      }
      params.set(field.name, field.value);
    });
    const q = form.querySelector('[name="bf_q"]');
    if (q && q.value.trim() !== '') {
      params.set('bf_q', q.value.trim());
    }
    return params.toString();
  }

  function refreshCount() {
    const qs = buildQuery();
    fetch('/admin/broadcasts/audience-preview?' + qs, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((res) => res.json())
      .then((data) => {
        if (typeof data.count === 'number') {
          countEl.textContent = String(data.count);
        }
      })
      .catch(() => {});
  }

  function scheduleRefresh() {
    window.clearTimeout(timer);
    timer = window.setTimeout(refreshCount, 350);
  }

  audienceSelect.addEventListener('change', () => {
    syncFilterPanel();
    scheduleRefresh();
  });

  form.querySelectorAll('[data-broadcast-audience-field], [name="bf_q"]').forEach((el) => {
    el.addEventListener('change', scheduleRefresh);
    el.addEventListener('input', scheduleRefresh);
  });

  syncFilterPanel();
})();
