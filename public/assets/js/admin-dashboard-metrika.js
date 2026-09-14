(function () {
  var root = document.querySelector('[data-admin-dashboard]');
  if (!root || root.getAttribute('data-metrika-deferred') !== '1') {
    return;
  }

  var period = root.getAttribute('data-period') || '7d';
  var group = root.getAttribute('data-group') || 'day';
  var url = '/admin/api/dashboard/metrika?period=' + encodeURIComponent(period)
    + '&group=' + encodeURIComponent(group);

  function formatNum(n) {
    var x = Number(n) || 0;
    try {
      return x.toLocaleString('en-US');
    } catch (e) {
      return String(x);
    }
  }

  function formatPct(pct) {
    if (pct === null || pct === undefined || pct === '') {
      return '—';
    }
    return Number(pct).toFixed(2) + '%';
  }

  function setText(sel, text) {
    var el = root.querySelector(sel) || document.querySelector(sel);
    if (el) {
      el.textContent = text;
      el.classList.remove('admin-stat-value--loading');
    }
  }

  function updateChart(visitsSeries, chartMax) {
    var cols = root.querySelectorAll('.admin-chart-col');
    var max = Math.max(1, Number(chartMax) || 1);

    cols.forEach(function (col, i) {
      var v = Number(visitsSeries[i]) || 0;
      var d = Number(col.getAttribute('data-chart-demo')) || 0;
      var p = Number(col.getAttribute('data-chart-paid')) || 0;
      col.setAttribute('data-chart-visits', String(v));
      max = Math.max(max, v, d, p);
    });

    max = Math.max(1, max);
    root.setAttribute('data-chart-max', String(max));

    cols.forEach(function (col) {
      var v = Number(col.getAttribute('data-chart-visits')) || 0;
      var d = Number(col.getAttribute('data-chart-demo')) || 0;
      var p = Number(col.getAttribute('data-chart-paid')) || 0;
      var visitBar = col.querySelector('.admin-chart-bar--visits');
      var demoBar = col.querySelector('.admin-chart-bar--demo');
      var paidBar = col.querySelector('.admin-chart-bar--paid');
      if (visitBar) {
        visitBar.classList.remove('admin-chart-bar--pending');
        visitBar.style.height = Math.max(2, Math.round(160 * v / max)) + 'px';
      }
      if (demoBar) {
        demoBar.style.height = Math.max(2, Math.round(160 * d / max)) + 'px';
      }
      if (paidBar) {
        paidBar.style.height = Math.max(2, Math.round(160 * p / max)) + 'px';
      }
    });
  }

  fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (res) {
      return res.json().then(function (body) {
        return { ok: res.ok, body: body };
      });
    })
    .then(function (result) {
      var data = result.body || {};
      var errBox = document.getElementById('admin-dashboard-metrika-error');

      if (!result.ok || !data.ok) {
        setText('[data-dash-period-visits]', '—');
        setText('[data-dash-lifetime-visits]', '—');
        setText('[data-dash-lifetime-conv]', 'since 2018 · Metrika unavailable');
        setText('[data-dash-period-conv-demo]', 'visit → demo —');
        var paidNote = root.querySelector('[data-dash-period-conv-paid]');
        if (paidNote) {
          var tail = paidNote.textContent.split('· demo → paid')[1] || '';
          paidNote.textContent = 'visit → paid — · demo → paid' + tail;
        }
        if (errBox) {
          errBox.style.display = 'block';
          errBox.textContent = 'Could not load Metrika data. Check OAuth token and counter access in Analytics settings.';
        }
        root.querySelectorAll('.admin-chart-bar--visits').forEach(function (bar) {
          bar.classList.remove('admin-chart-bar--pending');
        });
        return;
      }

      setText('[data-dash-period-visits]', formatNum(data.period_visits));
      if (data.lifetime_ok && data.lifetime_visits > 0) {
        setText('[data-dash-lifetime-visits]', formatNum(data.lifetime_visits));
      } else {
        setText('[data-dash-lifetime-visits]', '—');
      }

      var lc = data.lifetime_conversions || {};
      setText(
        '[data-dash-lifetime-conv]',
        'since 2018 · conv. demo ' + formatPct(lc.visit_to_demo_pct)
          + ' · paid ' + formatPct(lc.visit_to_paid_pct)
      );

      var pc = data.period_conversions || {};
      setText('[data-dash-period-conv-demo]', 'visit → demo ' + formatPct(pc.visit_to_demo_pct));
      var paidEl = root.querySelector('[data-dash-period-conv-paid]');
      if (paidEl) {
        var demoPart = paidEl.textContent.match(/· demo → paid .+$/);
        paidEl.textContent = 'visit → paid ' + formatPct(pc.visit_to_paid_pct)
          + (demoPart ? demoPart[0] : '');
      }

      updateChart(data.visits_series || [], data.chart_max);
    })
    .catch(function () {
      setText('[data-dash-period-visits]', '—');
      setText('[data-dash-lifetime-visits]', '—');
    });
})();
