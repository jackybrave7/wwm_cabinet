document.querySelectorAll('.email-webhook-copy').forEach((button) => {
  button.addEventListener('click', async () => {
    const targetId = button.getAttribute('data-copy-target');
    if (!targetId) return;
    const node = document.getElementById(targetId);
    const text = node ? (node.textContent || '').trim() : '';
    if (!text) return;

    try {
      await navigator.clipboard.writeText(text);
      const original = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(() => {
        button.textContent = original;
      }, 1500);
    } catch (error) {
      window.prompt('Copy URL:', text);
    }
  });
});
