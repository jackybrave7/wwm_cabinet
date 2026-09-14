(function () {
  const form = document.getElementById('broadcast-editor-form');
  const audienceSelect = document.getElementById('broadcast-audience-select');
  const countEl = document.getElementById('broadcast-audience-count');
  const filterPanel = document.getElementById('broadcast-audience-filter');
  if (!form || !audienceSelect || !countEl) {
    return;
  }

  let timer = null;

  function filterFields() {
    return form.querySelectorAll('[data-broadcast-audience-field], [name="bf_q"]');
  }

  function syncFilterPanel() {
    if (!filterPanel) {
      return;
    }
    if (audienceSelect.value === 'filtered') {
      filterPanel.open = true;
    }
  }

  function buildQuery() {
    const params = new URLSearchParams();
    params.set('audience', audienceSelect.value);
    filterFields().forEach((field) => {
      if (!field.name) {
        return;
      }
      if (field.type === 'checkbox') {
        if (field.checked) {
          params.set(field.name, field.value);
        }
        return;
      }
      const value = String(field.value || '').trim();
      if (value !== '') {
        params.set(field.name, value);
      }
    });
    return params.toString();
  }

  function refreshCount() {
    const qs = buildQuery();
    fetch('/admin/broadcasts/audience-preview?' + qs, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((res) => {
        if (!res.ok) {
          throw new Error('preview failed');
        }
        return res.json();
      })
      .then((data) => {
        if (typeof data.count === 'number') {
          countEl.textContent = String(data.count);
        }
      })
      .catch(() => {});
  }

  function scheduleRefresh() {
    window.clearTimeout(timer);
    timer = window.setTimeout(refreshCount, 250);
  }

  audienceSelect.addEventListener('change', () => {
    syncFilterPanel();
    scheduleRefresh();
  });

  filterFields().forEach((el) => {
    el.addEventListener('change', scheduleRefresh);
    el.addEventListener('input', scheduleRefresh);
  });

  syncFilterPanel();
  refreshCount();
})();
