/**
 * Typeahead for student email/name (min 3 characters).
 */
(function () {
  const wraps = document.querySelectorAll('[data-student-suggest]');
  if (wraps.length === 0) {
    return;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  wraps.forEach((wrap) => {
    const input = wrap.querySelector('input[name="student_email"], input[data-student-suggest-input]');
    const list = wrap.querySelector('.admin-student-suggest__list');
    if (!input || !list) {
      return;
    }

    let timer = 0;
    let seq = 0;
    let items = [];
    let active = -1;
    let abort = null;

    function hide() {
      list.hidden = true;
      list.innerHTML = '';
      items = [];
      active = -1;
      input.removeAttribute('aria-activedescendant');
    }

    function highlight() {
      list.querySelectorAll('[data-index]').forEach((el) => {
        const on = Number(el.getAttribute('data-index')) === active;
        el.classList.toggle('is-active', on);
        if (on) {
          input.setAttribute('aria-activedescendant', el.id);
          el.scrollIntoView({ block: 'nearest' });
        }
      });
    }

    function choose(index) {
      const row = items[index];
      if (!row) {
        return;
      }
      input.value = row.email || '';
      hide();
      input.focus();
    }

    function render(rows, q) {
      items = rows;
      active = rows.length ? 0 : -1;
      if (rows.length === 0) {
        list.innerHTML = '<li class="admin-student-suggest__empty">Никого не найдено по «' + escapeHtml(q) + '»</li>';
        list.hidden = false;
        return;
      }
      const uid = 'student-suggest-' + String(Math.random()).slice(2);
      list.innerHTML = rows.map((row, i) => {
        const name = String(row.name || '').trim();
        const email = String(row.email || '').trim();
        const title = name !== '' ? name : email;
        const meta = name !== '' && email !== '' ? email : '';
        return '<li>'
          + '<button type="button" class="admin-student-suggest__item" id="' + uid + '-' + i + '" data-index="' + i + '">'
          + '<span class="admin-student-suggest__name">' + escapeHtml(title) + '</span>'
          + (meta ? '<span class="admin-student-suggest__email">' + escapeHtml(meta) + '</span>' : '')
          + '</button></li>';
      }).join('');
      list.hidden = false;
      highlight();
    }

    function search(q) {
      seq += 1;
      const mine = seq;
      if (abort) {
        abort.abort();
      }
      abort = new AbortController();
      fetch('/admin/students/search?q=' + encodeURIComponent(q), {
        credentials: 'same-origin',
        signal: abort.signal,
        headers: { Accept: 'application/json' },
      })
        .then((r) => {
          if (!r.ok) {
            throw new Error('http ' + r.status);
          }
          return r.json();
        })
        .then((data) => {
          if (mine !== seq) {
            return;
          }
          render(Array.isArray(data.items) ? data.items : [], q);
        })
        .catch((err) => {
          if (err && err.name === 'AbortError') {
            return;
          }
          if (mine === seq) {
            hide();
          }
        });
    }

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    list.setAttribute('role', 'listbox');

    input.addEventListener('input', () => {
      const q = String(input.value || '').trim();
      window.clearTimeout(timer);
      if (q.length < 3) {
        hide();
        input.setAttribute('aria-expanded', 'false');
        return;
      }
      timer = window.setTimeout(() => {
        input.setAttribute('aria-expanded', 'true');
        search(q);
      }, 200);
    });

    input.addEventListener('keydown', (e) => {
      if (list.hidden || items.length === 0) {
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        active = (active + 1) % items.length;
        highlight();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active = (active - 1 + items.length) % items.length;
        highlight();
      } else if (e.key === 'Enter' && active >= 0) {
        e.preventDefault();
        choose(active);
      } else if (e.key === 'Escape') {
        hide();
        input.setAttribute('aria-expanded', 'false');
      }
    });

    list.addEventListener('mousedown', (e) => {
      const btn = e.target.closest('[data-index]');
      if (!btn) {
        return;
      }
      e.preventDefault();
      choose(Number(btn.getAttribute('data-index')));
    });

    document.addEventListener('click', (e) => {
      if (!wrap.contains(e.target)) {
        hide();
        input.setAttribute('aria-expanded', 'false');
      }
    });
  });
})();
