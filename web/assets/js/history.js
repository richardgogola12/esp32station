(function () {
  'use strict';
  const cfgEl = document.getElementById('historyConfig');
  Charts.applyDefaults();

  // Rýchle rozsahy
  const pad = (n) => String(n).padStart(2, '0');
  const local = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  document.getElementById('quickRanges')?.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-range]');
    if (!b) return;
    const now = new Date();
    let from;
    switch (b.dataset.range) {
      case '1h': from = new Date(now - 3600e3); break;
      case '24h': from = new Date(now - 86400e3); break;
      case '7d': from = new Date(now - 7 * 86400e3); break;
      case '30d': from = new Date(now - 30 * 86400e3); break;
      case 'month': from = new Date(now.getFullYear(), now.getMonth(), 1); break;
      case 'year': from = new Date(now.getFullYear(), 0, 1); break;
    }
    document.getElementById('from').value = local(from);
    document.getElementById('to').value = local(now);
    document.getElementById('filterForm').submit();
  });

  // Okamžité použitie filtra po kliknutí na zariadenie
  document.querySelectorAll('.device-chips input').forEach((i) => i.addEventListener('change', () => {
    if (!document.querySelector('.device-chips input:checked')) i.checked = true;
  }));

  if (!cfgEl) return;
  let data = null;
  const charts = {};
  const aggNames = { raw: 'každé meranie', '15m': '15-minútové priemery', hour: 'hodinové priemery', day: 'denné priemery' };

  function draw() {
    if (!data) return;
    Charts.applyDefaults();
    const make = (id, key, unit) => {
      const canvas = document.getElementById(id);
      const single = data.series.length === 1;
      const datasets = [];
      data.series.forEach((s) => {
        const pts = s.points;
        datasets.push({
          label: s.name,
          data: pts.map((p) => ({ x: Charts.toDate(p.x), y: p[key] })),
          borderColor: s.color,
          backgroundColor: (c) => c.chart.chartArea ? Charts.gradient(c.chart.ctx, c.chart.chartArea, s.color) : 'transparent',
          fill: single && data.agg === 'raw',
          spanGaps: false
        });
        if (single && pts.length && pts[0][key + 'min'] !== undefined) {
          // pásmo min–max pri agregovaných dátach
          datasets.push({ label: 'Max', data: pts.map((p) => ({ x: Charts.toDate(p.x), y: p[key + 'max'] })), borderColor: 'transparent', backgroundColor: s.color + '22', pointRadius: 0, fill: '+1', order: 2 });
          datasets.push({ label: 'Min', data: pts.map((p) => ({ x: Charts.toDate(p.x), y: p[key + 'min'] })), borderColor: 'transparent', backgroundColor: 'transparent', pointRadius: 0, fill: false, order: 2 });
        }
      });
      const opts = Charts.lineOptions(unit, data.agg);
      opts.plugins.legend.labels.filter = (item) => item.text !== 'Min' && item.text !== 'Max';
      if (charts[id]) charts[id].destroy();
      charts[id] = new Chart(canvas, { type: 'line', data: { datasets }, options: opts });
    };
    make('tChart', 't', '°C');
    make('hChart', 'h', '%');
    const lbl = document.getElementById('aggLabel');
    if (lbl) lbl.textContent = aggNames[data.agg] || '';
  }

  App.get('api/data.php?action=series&' + cfgEl.dataset.query).then((j) => {
    if (!j.ok) return App.toast(j.error || 'Nepodarilo sa načítať dáta', 'danger');
    data = j;
    draw();
  });
  document.addEventListener('themechange', draw);
})();

// Prepínanie grafu teplota / vlhkosť
(function () {
  const seg = document.getElementById('chartSeg');
  if (!seg) return;
  seg.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-chart]');
    if (!b) return;
    seg.querySelectorAll('button').forEach((x) => x.classList.toggle('active', x === b));
    const t = b.dataset.chart === 't';
    document.getElementById('wrapT').classList.toggle('d-none', !t);
    document.getElementById('wrapH').classList.toggle('d-none', t);
    window.dispatchEvent(new Event('resize'));
  });
})();
