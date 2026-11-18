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
          applyCount(data.count);
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

  function applyCount(count) {
    countEl.textContent = String(count);
    form.dataset.audienceCount = String(count);
    const hidden = document.getElementById('broadcast-confirm-count');
    if (hidden) {
      hidden.value = String(count);
    }
    const send = form.querySelector('[data-broadcast-send]');
    if (send) {
      send.textContent = 'Send to ' + count + ' people';
    }
  }

  form.addEventListener('submit', (event) => {
    const submitter = event.submitter;
    if (!submitter || submitter.getAttribute('name') !== 'action') {
      return;
    }
    const count = Number(form.dataset.audienceCount || countEl.textContent || '0');
    if (submitter.value === 'send') {
      if (count < 1) {
        event.preventDefault();
        window.alert('There is nobody to send to.');
        return;
      }
      if (!window.confirm('Send this broadcast to ' + count + ' people?')) {
        event.preventDefault();
      }
      return;
    }
    if (submitter.value === 'schedule') {
      if (!window.confirm('Schedule this broadcast for ' + count + ' people?')) {
        event.preventDefault();
      }
    }
  });

  form.dataset.audienceCount = countEl.textContent || '0';
  syncFilterPanel();
  refreshCount();
})();
