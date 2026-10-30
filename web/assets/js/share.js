/* Verejná stránka zdieľania – bez prihlásenia */
(function () {
  'use strict';
  const base = document.querySelector('meta[name="app-base"]')?.content || '';
  window.App = window.App || {
    num(v, d = 1) {
      if (v === null || v === undefined || isNaN(v)) return '–';
      return Number(v).toLocaleString('sk-SK', { minimumFractionDigits: d, maximumFractionDigits: d });
    }
  };
  const num = App.num;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // Hodiny
  const tick = () => {
    const n = new Date();
    document.getElementById('clockT').textContent = n.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
    document.getElementById('clockD').textContent = n.toLocaleDateString('sk-SK', { weekday: 'long', day: 'numeric', month: 'long' });
  };
  tick(); setInterval(tick, 10000);

  const grid = document.getElementById('shareGrid');
  if (!grid) return;
  const token = grid.dataset.token;
  const api = (q) => fetch(`${base}/api/public.php?t=${encodeURIComponent(token)}&${q}`, { headers: { Accept: 'application/json' } }).then((r) => r.json());
  const trend = (v, u) => v === null || v === undefined ? '' : Math.abs(v) < 0.1 ? '→ stabilne' : `${v > 0 ? '↗ +' : '↘ '}${num(v, u === '%' ? 0 : 1)} ${u}/h`;

  function card(d) {
    return `<div class="col-12 col-md-6 col-xl-4"><article class="device-card glass" style="--dev-color:#6366f1">
      <header class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0"><h2 class="device-name text-truncate">${esc(d.name)}</h2>
          ${d.location ? `<div class="dev-sub text-truncate"><i class="bi bi-geo-alt"></i> ${esc(d.location)}</div>` : ''}</div>
        <span class="status-badge ${d.online ? 'online' : 'offline'}">${d.online ? 'Online' : 'Offline'}</span>
      </header>
      <div class="readings">
        <div class="reading temp"><div class="reading-label"><i class="bi bi-thermometer-half"></i> Teplota</div>
          <div class="reading-value">${num(d.temperature)}<small>°C</small></div><div class="reading-trend">${trend(d.trend_t, '°C')}</div></div>
        <div class="reading hum"><div class="reading-label"><i class="bi bi-droplet-half"></i> Vlhkosť</div>
          <div class="reading-value">${num(d.humidity, 0)}<small>%</small></div><div class="reading-trend">${trend(d.trend_h, '%')}</div></div>
      </div>
      <canvas class="spark" height="48" data-spark="${d.id}"></canvas>
      <div class="dev-facts">
        <div><span>Dnes</span><b>${d.today ? num(d.today.tmin) + '–' + num(d.today.tmax) + ' °C' : '–'}</b></div>
        <div><span>Oproti včera</span><b>${d.vs_yesterday_t === null ? '–' : (d.vs_yesterday_t > 0 ? '+' : '') + num(d.vs_yesterday_t) + ' °C'}</b></div>
        <div><span>Pocitovo</span><b>${num(d.feels_like)} °C</b></div>
        <div><span>Rosný bod</span><b>${num(d.dew_point)} °C</b></div>
      </div>
      ${d.tip ? `<div class="dev-tip">${esc(d.tip.icon + ' ' + d.tip.text)}</div>` : ''}
      <div class="device-foot"><span class="chip">${esc(d.comfort || '–')}</span><span><i class="bi bi-clock"></i> ${esc(d.last_seen_h)}</span></div>
    </article></div>`;
  }

  let devs = [];
  async function loadLatest() {
    try {
      const j = await api('action=latest');
      if (!j.ok) { grid.innerHTML = `<div class="col-12"><div class="alert alert-warning">${esc(j.error)}</div></div>`; return; }
      devs = j.devices;
      grid.innerHTML = devs.map(card).join('') || '<div class="col-12 text-secondary">Žiadne zariadenia.</div>';
      devs.forEach((d) => d.spark && Charts.spark(grid.querySelector(`[data-spark="${d.id}"]`), d.spark.t, '#f97316'));
      document.getElementById('lastRefresh').textContent = 'aktualizované ' + new Date().toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
      document.getElementById('liveDot').classList.remove('error');
    } catch (e) {
      document.getElementById('liveDot').classList.add('error');
    }
  }

  let chart = null, metric = 't', days = Number(document.querySelector('#shRange button.active')?.dataset.days || 1), series = null;
  async function loadSeries() {
    const j = await api(`action=series&days=${days}`);
    if (!j.ok) return;
    series = j;
    draw();
  }
  const palette = ['#f97316', '#0ea5e9', '#22c55e', '#a855f7', '#ec4899', '#eab308'];
  function draw() {
    if (!series || !window.Chart) return;
    Charts.applyDefaults();
    const unit = metric === 't' ? '°C' : '%';
    const ds = series.series.map((s, i) => {
      const color = s.color || palette[i % palette.length];
      return {
        label: s.name, data: s.points.map((p) => ({ x: Charts.toDate(p.x), y: p[metric] })), borderColor: color,
        backgroundColor: (c) => c.chart.chartArea ? Charts.gradient(c.chart.ctx, c.chart.chartArea, color) : 'transparent',
        fill: series.series.length === 1
      };
    });
    if (chart) chart.destroy();
    chart = new Chart(document.getElementById('shChart'), { type: 'line', data: { datasets: ds }, options: Charts.lineOptions(unit, series.agg) });
  }
  const seg = (id, fn) => document.getElementById(id)?.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    document.querySelectorAll(`#${id} button`).forEach((x) => x.classList.toggle('active', x === b));
    fn(b);
  });
  seg('shMetric', (b) => { metric = b.dataset.metric; draw(); });
  seg('shRange', (b) => { days = Number(b.dataset.days); loadSeries(); });
  document.addEventListener('themechange', draw);

  loadLatest(); loadSeries();
  setInterval(() => { if (!document.hidden) loadLatest(); }, 60000);
  setInterval(() => { if (!document.hidden) loadSeries(); }, 5 * 60000);
})();
