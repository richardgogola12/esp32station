/* ESP32 DHT Station – spoločný JavaScript */
(function () {
  'use strict';

  const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
  const App = window.App = {
    base: meta('app-base'),
    csrf: meta('csrf-token'),
    url(p) { return this.base + '/' + String(p).replace(/^\//, ''); },

    async get(path) {
      const r = await fetch(this.url(path), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (r.status === 401) { location.href = this.url('login.php'); throw new Error('Neprihlásený'); }
      return r.json();
    },

    async post(path, body) {
      const r = await fetch(this.url(path), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf, Accept: 'application/json' },
        body: JSON.stringify(body || {})
      });
      const j = await r.json().catch(() => ({ ok: false, error: 'Neplatná odpoveď servera' }));
      if (!r.ok && j.ok !== false) j.ok = false;
      return j;
    },

    toast(msg, type = 'success') {
      const stack = document.getElementById('toastStack') || document.body;
      const el = document.createElement('div');
      el.className = `alert alert-${type} alert-dismissible fade show glass-alert`;
      el.setAttribute('role', 'alert');
      el.textContent = msg;
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'btn-close'; b.setAttribute('data-bs-dismiss', 'alert');
      el.appendChild(b);
      stack.appendChild(el);
      setTimeout(() => el.classList.remove('show'), 4500);
      setTimeout(() => el.remove(), 5000);
    },

    num(v, d = 1) {
      if (v === null || v === undefined || isNaN(v)) return '–';
      return Number(v).toLocaleString('sk-SK', { minimumFractionDigits: d, maximumFractionDigits: d });
    },

    css(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
  };

  // Automatické skrytie flash správ
  document.querySelectorAll('#toastStack .alert').forEach((el) => {
    setTimeout(() => el.classList.remove('show'), 5000);
    setTimeout(() => el.remove(), 5500);
  });

  // Prepínač témy
  const tt = document.getElementById('themeToggle');
  const setIcon = () => {
    if (!tt) return;
    const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    tt.innerHTML = dark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
  };
  setIcon();
  tt?.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', next);
    try { localStorage.setItem('esp32dth-theme', next); } catch (e) {}
    setIcon();
    document.dispatchEvent(new CustomEvent('themechange'));
  });

  // Zobrazenie hesla
  document.querySelectorAll('[data-reveal]').forEach((b) => b.addEventListener('click', () => {
    const inp = document.querySelector(b.dataset.reveal);
    if (!inp) return;
    inp.type = inp.type === 'password' ? 'text' : 'password';
    b.innerHTML = inp.type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
  }));

  // Sila hesla
  const pw = document.getElementById('password'), meter = document.getElementById('pwMeter');
  if (pw && meter) pw.addEventListener('input', () => {
    const v = pw.value; let s = 0;
    if (v.length >= 8) s++; if (v.length >= 12) s++;
    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
    meter.dataset.level = String(Math.min(4, s));
  });

  // Kopírovanie do schránky
  document.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-copy]');
    if (!b) return;
    const src = document.querySelector(b.dataset.copy);
    if (!src) return;
    const text = src.innerText.trim();
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta);
      ta.select(); document.execCommand('copy'); ta.remove();
    }
    const old = b.innerHTML;
    b.innerHTML = '<i class="bi bi-check2"></i> Skopírované';
    setTimeout(() => (b.innerHTML = old), 1600);
  });

  // Android-only prvky
  if (!/Android/i.test(navigator.userAgent)) document.querySelectorAll('.d-android').forEach((e) => e.classList.add('d-none'));

  // PWA – service worker a inštalácia
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register(App.url('sw.js'), { scope: App.base + '/' }).catch(() => {}));
  }
  let deferred = null;
  const ib = document.getElementById('installBtn');
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; ib?.classList.remove('d-none'); });
  ib?.addEventListener('click', async () => {
    if (!deferred) return;
    deferred.prompt();
    await deferred.userChoice;
    deferred = null; ib.classList.add('d-none');
  });
  window.addEventListener('appinstalled', () => ib?.classList.add('d-none'));
})();
