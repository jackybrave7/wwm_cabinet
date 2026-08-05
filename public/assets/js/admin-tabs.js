document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-tabs]');
  if (!root) return;

  const buttons = root.querySelectorAll('.tab-btn[data-tab]');
  const panels = root.querySelectorAll('.tab-panel[data-panel]');

  buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-tab');
      if (!id) return;
      buttons.forEach((b) => b.classList.toggle('is-active', b === btn));
      panels.forEach((p) => p.classList.toggle('is-active', p.getAttribute('data-panel') === id));
    });
  });
});
