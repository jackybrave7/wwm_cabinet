(function () {
  const form = document.getElementById('students-automation-launch-form');
  const pick = document.getElementById('students-automation-pick');
  const audienceSelect = document.getElementById('students-automation-audience');
  const countEl = document.getElementById('students-automation-count');
  const filterPanel = document.getElementById('broadcast-audience-filter');
  const courseSelect = document.getElementById('students-automation-course');
  if (!form || !pick || !audienceSelect || !countEl) {
    return;
  }

  let timer = null;

  form.addEventListener('submit', function (e) {
    const id = pick.value;
    if (!id) {
      e.preventDefault();
      return;
    }
    form.action = '/admin/automations/' + id + '/enroll-audience';
  });

  pick.addEventListener('change', function () {
    const opt = pick.options[pick.selectedIndex];
    const slug = opt ? opt.getAttribute('data-course') : '';
    if (courseSelect && slug && courseSelect.querySelector('option[value="' + slug + '"]')) {
      courseSelect.value = slug;
    }
  });

  function filterFields() {
    return form.querySelectorAll('[data-broadcast-audience-field], [name="bf_q"]');
  }

  function syncFilterPanel() {
    if (!filterPanel) {
      return;
    }
    filterPanel.open = audienceSelect.value === 'filtered';
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
    fetch('/admin/students/automation-audience-preview?' + buildQuery(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((res) => (res.ok ? res.json() : Promise.reject()))
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
