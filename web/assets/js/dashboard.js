(function () {
  'use strict';
  const grid = document.getElementById('deviceGrid');
  Charts.applyDefaults();

  const colors = {};
  document.querySelectorAll('[data-device]').forEach((c) => {
    colors[c.dataset.device] = getComputedStyle(c).getPropertyValue('--dev-color').trim() || '#6366f1';
  });
  const TEMP = '#f97316', HUM = '#0ea5e9';
  let latest = [];

  const trendHtml = (v, unit) => {
    if (v === null || v === undefined) return '';
    if (Math.abs(v) < 0.1) return '<i class="bi bi-dash"></i> stabilné';
    const up = v > 0;
    return `<i class="bi bi-arrow-${up ? 'up' : 'down'}-right"></i> ${up ? '+' : ''}${App.num(v, unit === '%' ? 0 : 1)} ${unit} / h`;
  };

  function render(devs) {
    latest = devs;
    let tSum = 0, tN = 0, hSum = 0, hN = 0, on = 0, cnt = 0;
    devs.forEach((d) => {
      if (d.temperature !== null) { tSum += d.temperature; tN++; }
      if (d.humidity !== null) { hSum += d.humidity; hN++; }
      if (d.online) on++;
      cnt += d.today ? d.today.count : 0;
      const card = document.querySelector(`[data-device="${d.id}"]`);
      if (!card) return;
      const f = (k) => card.querySelector(`[data-f="${k}"]`);
      const setText = (k, v) => { const el = f(k); if (el && el.textContent !== v) { el.textContent = v; el.classList.add('flash'); setTimeout(() => el.classList.remove('flash'), 700); } };
      setText('t', App.num(d.temperature, 1));
      setText('h', App.num(d.humidity, 0));
      f('seen').textContent = d.last_seen_h;
      f('dew').textContent = App.num(d.dew_point, 1);
      f('comfort').textContent = d.comfort || '–';
      f('range').textContent = d.today ? `${App.num(d.today.tmin)}–${App.num(d.today.tmax)} °C` : '–';
      f('tt').innerHTML = trendHtml(d.trend_t, '°C');
      f('ht').innerHTML = trendHtml(d.trend_h, '%');
      const st = f('status');
      st.className = 'status-badge ' + (d.online ? 'online' : 'offline');
      st.textContent = d.online ? 'Online' : 'Offline';
      if (d.spark) Charts.spark(f('spark'), d.spark.t, colors[d.id] || TEMP);
    });
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('avgT', tN ? App.num(tSum / tN, 1) : '–');
    set('avgH', hN ? App.num(hSum / hN, 0) : '–');
    set('onlineCount', on);
    set('todayCount', cnt.toLocaleString('sk-SK'));
    set('lastRefresh', 'aktualizované ' + new Date().toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' }));
  }

  async function refresh() {
    const dot = document.getElementById('liveDot');
    try {
      const j = await App.get('api/data.php?action=latest');
      if (j.ok) render(j.devices);
      dot?.classList.remove('error');
    } catch (e) {
      dot?.classList.add('error');
    }
  }

  // 24 h graf
  let chart = null, metric = 't', seriesData = null;
  async function loadChart() {
    const canvas = document.getElementById('chart24');
    if (!canvas) return;
    const to = new Date(), from = new Date(to.getTime() - 86400000);
    const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    const j = await App.get(`api/data.php?action=series&agg=15m&from=${encodeURIComponent(fmt(from))}&to=${encodeURIComponent(fmt(to))}`);
    if (!j.ok) return;
    seriesData = j;
    drawChart();
  }

  function drawChart() {
    const canvas = document.getElementById('chart24');
    if (!canvas || !seriesData) return;
    const unit = metric === 't' ? '°C' : '%';
    const datasets = seriesData.series.map((s) => ({
      label: s.name,
      data: s.points.map((p) => ({ x: Charts.toDate(p.x), y: p[metric] })),
      borderColor: s.color,
      backgroundColor: (c) => c.chart.chartArea ? Charts.gradient(c.chart.ctx, c.chart.chartArea, s.color) : 'transparent',
      fill: seriesData.series.length === 1,
      spanGaps: 45 * 60 * 1000
    }));
    if (chart) chart.destroy();
    Charts.applyDefaults();
    chart = new Chart(canvas, { type: 'line', data: { datasets }, options: Charts.lineOptions(unit, 'hour') });
  }

  document.getElementById('metricSeg')?.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-metric]');
    if (!b) return;
    document.querySelectorAll('#metricSeg button').forEach((x) => x.classList.toggle('active', x === b));
    metric = b.dataset.metric;
    drawChart();
  });

  document.addEventListener('themechange', () => { drawChart(); render(latest); });
  window.addEventListener('resize', () => render(latest));

  // Banner pre odtlačok prsta
  const banner = document.getElementById('passkeyBanner');
  if (banner && banner.dataset.count === '0' && window.PublicKeyCredential) {
    let dismissed = false;
    try { dismissed = localStorage.getItem('esp32dth-pk-banner') === '1'; } catch (e) {}
    if (!dismissed) {
      PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().then((ok) => ok && banner.classList.remove('d-none')).catch(() => {});
    }
    document.getElementById('passkeyBannerClose')?.addEventListener('click', () => {
      banner.classList.add('d-none');
      try { localStorage.setItem('esp32dth-pk-banner', '1'); } catch (e) {}
    });
  }

  if (grid) {
    refresh();
    loadChart();
    setInterval(() => { if (!document.hidden) refresh(); }, 30000);
    setInterval(() => { if (!document.hidden) loadChart(); }, 5 * 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  }
})();
