(function () {
  'use strict';
  // Systémové „Zdieľať“ (mobil), inak kopírovanie
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-share]');
    if (!b) return;
    const url = b.dataset.share, title = b.dataset.title || 'Teplota a vlhkosť';
    if (navigator.share) {
      try { await navigator.share({ title, text: 'Pozri si aktuálnu teplotu a vlhkosť:', url }); } catch (err) {}
    } else {
      try { await navigator.clipboard.writeText(url); App.toast('Odkaz je skopírovaný'); } catch (err) { prompt('Skopíruj odkaz:', url); }
    }
  });
  document.querySelectorAll('form[data-confirm-submit]').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm(f.dataset.confirmSubmit)) e.preventDefault();
  }));
})();
