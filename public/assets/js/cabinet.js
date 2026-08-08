(function () {
  var nodes = document.querySelectorAll('time[data-demo-until]');
  if (!nodes.length) {
    return;
  }

  var formatter = new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });

  nodes.forEach(function (node) {
    var iso = node.getAttribute('data-demo-until');
    if (!iso) {
      return;
    }

    var date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
      return;
    }

    node.textContent = 'Available until ' + formatter.format(date);
  });
})();
