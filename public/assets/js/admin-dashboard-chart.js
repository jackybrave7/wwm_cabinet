(function () {
  var root = document.querySelector('[data-admin-chart-tooltip]');
  if (!root) {
    return;
  }

  var tip = root.querySelector('.admin-chart-tooltip');
  if (!tip) {
    return;
  }

  var cols = root.querySelectorAll('.admin-chart-col');
  var offset = 14;

  function formatNum(n) {
    var x = Number(n) || 0;
    try {
      return x.toLocaleString('en-US');
    } catch (e) {
      return String(x);
    }
  }

  function show(col, clientX, clientY) {
    var label = col.getAttribute('data-chart-label') || '';
    var visits = col.getAttribute('data-chart-visits') || '0';
    var demo = col.getAttribute('data-chart-demo') || '0';
    var paid = col.getAttribute('data-chart-paid') || '0';

    tip.innerHTML =
      '<strong class="admin-chart-tooltip-title"></strong>' +
      '<div class="admin-chart-tooltip-row admin-chart-tooltip-row--visits"><span>Visits</span><span></span></div>' +
      '<div class="admin-chart-tooltip-row admin-chart-tooltip-row--demo"><span>Demo signups</span><span></span></div>' +
      '<div class="admin-chart-tooltip-row admin-chart-tooltip-row--paid"><span>Purchases</span><span></span></div>';

    tip.querySelector('.admin-chart-tooltip-title').textContent = label;
    var rows = tip.querySelectorAll('.admin-chart-tooltip-row span:last-child');
    rows[0].textContent = formatNum(visits);
    rows[1].textContent = formatNum(demo);
    rows[2].textContent = formatNum(paid);

    tip.hidden = false;
    tip.setAttribute('aria-hidden', 'false');
    position(clientX, clientY);
  }

  function hide() {
    tip.hidden = true;
    tip.setAttribute('aria-hidden', 'true');
  }

  function position(clientX, clientY) {
    var pad = 8;
    var w = tip.offsetWidth;
    var h = tip.offsetHeight;
    var x = clientX + offset;
    var y = clientY + offset;

    if (x + w + pad > window.innerWidth) {
      x = clientX - w - offset;
    }
    if (y + h + pad > window.innerHeight) {
      y = clientY - h - offset;
    }
    if (x < pad) {
      x = pad;
    }
    if (y < pad) {
      y = pad;
    }

    tip.style.left = x + 'px';
    tip.style.top = y + 'px';
  }

  cols.forEach(function (col) {
    col.addEventListener('mouseenter', function (e) {
      show(col, e.clientX, e.clientY);
    });
    col.addEventListener('mousemove', function (e) {
      if (!tip.hidden) {
        position(e.clientX, e.clientY);
      }
    });
    col.addEventListener('mouseleave', hide);
  });

  root.addEventListener('scroll', hide, { passive: true });
})();
