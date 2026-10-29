// Nastaví tému ešte pred vykreslením stránky (bez bliknutia)
(function () {
  var t = null;
  try { t = localStorage.getItem('esp32dth-theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') {
    t = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }
  document.documentElement.setAttribute('data-bs-theme', t);
})();
