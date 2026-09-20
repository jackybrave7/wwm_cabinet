/**
 * Admin-styled confirm / alert (replaces window.confirm / window.alert).
 */
(function () {
  let root = null;
  let titleEl = null;
  let messageEl = null;
  let okBtn = null;
  let cancelBtn = null;
  let pendingResolve = null;
  let pendingMode = 'confirm';

  function ensureDialog() {
    if (root) {
      return;
    }
    root = document.createElement('div');
    root.className = 'modal admin-dialog';
    root.id = 'admin-dialog';
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = (
      '<div class="modal-backdrop" data-admin-dialog-close></div>'
      + '<div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-dialog-title">'
      + '<h2 id="admin-dialog-title"></h2>'
      + '<p class="admin-dialog-message" id="admin-dialog-message"></p>'
      + '<div class="modal-actions">'
      + '<button type="button" class="btn btn-ghost" data-admin-dialog-cancel>Отмена</button>'
      + '<button type="button" class="btn btn-primary" data-admin-dialog-ok>OK</button>'
      + '</div></div>'
    );
    document.body.appendChild(root);
    titleEl = root.querySelector('#admin-dialog-title');
    messageEl = root.querySelector('#admin-dialog-message');
    okBtn = root.querySelector('[data-admin-dialog-ok]');
    cancelBtn = root.querySelector('[data-admin-dialog-cancel]');

    root.querySelector('[data-admin-dialog-close]')?.addEventListener('click', () => close(false));
    cancelBtn?.addEventListener('click', () => close(false));
    okBtn?.addEventListener('click', () => close(true));

    document.addEventListener('keydown', (e) => {
      if (!root.classList.contains('is-open')) {
        return;
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        close(false);
      }
    });
  }

  function close(result) {
    if (!root) {
      return;
    }
    root.classList.remove('is-open');
    root.setAttribute('aria-hidden', 'true');
    const resolve = pendingResolve;
    pendingResolve = null;
    if (resolve) {
      if (pendingMode === 'alert') {
        resolve();
      } else {
        resolve(!!result);
      }
    }
  }

  function open(opts) {
    ensureDialog();
    const o = opts || {};
    const mode = o.mode === 'alert' ? 'alert' : 'confirm';
    pendingMode = mode;

    if (titleEl) {
      titleEl.textContent = o.title || (mode === 'alert' ? 'Сообщение' : 'Подтвердите действие');
    }
    if (messageEl) {
      messageEl.textContent = o.message || '';
    }
    if (okBtn) {
      okBtn.textContent = o.confirmLabel || (mode === 'alert' ? 'Понятно' : 'Подтвердить');
      okBtn.className = 'btn ' + (o.danger ? 'btn-danger' : 'btn-primary');
    }
    if (cancelBtn) {
      cancelBtn.style.display = mode === 'alert' ? 'none' : '';
      cancelBtn.textContent = o.cancelLabel || 'Отмена';
    }

    root.classList.add('is-open');
    root.setAttribute('aria-hidden', 'false');
    window.setTimeout(() => okBtn?.focus(), 0);

    return new Promise((resolve) => {
      pendingResolve = resolve;
    });
  }

  window.wwmAdminConfirm = function (opts) {
    return open(Object.assign({ mode: 'confirm' }, opts || {}));
  };

  window.wwmAdminAlert = function (opts) {
    if (typeof opts === 'string') {
      return open({ mode: 'alert', message: opts });
    }
    return open(Object.assign({ mode: 'alert' }, opts || {}));
  };

  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    const msg = form.getAttribute('data-confirm');
    if (!msg || form.dataset.confirmBypass === '1') {
      return;
    }
    e.preventDefault();
    wwmAdminConfirm({
      title: form.getAttribute('data-confirm-title') || 'Подтвердите действие',
      message: msg,
      danger: form.hasAttribute('data-confirm-danger'),
      confirmLabel: form.getAttribute('data-confirm-ok') || 'Подтвердить',
    }).then((ok) => {
      if (!ok) {
        return;
      }
      form.dataset.confirmBypass = '1';
      form.requestSubmit();
      delete form.dataset.confirmBypass;
    });
  }, true);
})();
