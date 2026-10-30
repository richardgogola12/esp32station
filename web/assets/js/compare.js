(function () {
  'use strict';
  const form = document.getElementById('cmpForm');
  if (!form) return;
  const modeInput = form.querySelector('[name=mode]');

  // Prepínanie režimu
  document.getElementById('modeSeg').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-mode]');
    if (!b) return;
    modeInput.value = b.dataset.mode;
    if (b.dataset.mode === 'custom') {
      document.querySelectorAll('#modeSeg button').forEach((x) => x.classList.toggle('active', x === b));
      document.getElementById('customBox').classList.remove('d-none');
      document.getElementById('dateBox').classList.add('d-none');
    } else form.submit();
  });
  document.getElementById('cd').addEventListener('change', () => form.submit());

  // Posun o obdobie dozadu / dopredu
  document.querySelectorAll('[data-shift]').forEach((b) => b.addEventListener('click', () => {
    const inp = document.getElementById('cdate');
    const d = new Date(inp.value + 'T12:00:00');
    const k = Number(b.dataset.shift);
    if (modeInput.value === 'week') d.setDate(d.getDate() + 7 * k);
    else if (modeInput.value === 'month') d.setMonth(d.getMonth() + k);
    else d.setDate(d.getDate() + k);
    inp.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    form.submit();
  }));

  const cfg = document.getElementById('cmpConfig');
  if (!cfg) return;
  Charts.applyDefaults();
  const COL_A = '#6366f1', COL_B = '#94a3b8';
  let data = null, metric = 't', chart = null;
  const days = ['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne'];

  function fmtOffset(sec) {
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
    const hm = `${String(h % 24).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    switch (data.mode) {
      case 'day': return hm;
      case 'week': return days[Math.floor(h / 24) % 7] + ' ' + hm;
      case 'month': return (Math.floor(h / 24) + 1) + '. deň ' + hm;
      default: return h < 48 ? `+${h} h` : `+${Math.floor(h / 24)} d`;
    }
  }

  function diff(a, b, unit, dec) {
    if (a === null || b === null || a === undefined || b === undefined) return '<span class="text-secondary">–</span>';
    const d = a - b;
    if (Math.abs(d) < (dec ? 0.05 : 0.5)) return '<span class="delta same">= rovnako</span>';
    return `<span class="delta ${d > 0 ? 'up' : 'down'}"><i class="bi bi-arrow-${d > 0 ? 'up' : 'down'}"></i> ${d > 0 ? '+' : ''}${App.num(d, dec)} ${unit}</span>`;
  }

  function stats() {
    const sa = data.a.stats, sb = data.b.stats;
    document.querySelectorAll('#cmpStats [data-metric]').forEach((box) => {
      const m = box.dataset.metric, unit = m === 't' ? '°C' : '%', dec = m === 't' ? 1 : 0;
      const g = (s, k) => (s ? s[m + k] : null);
      const row = (label, k) => `<div class="cmp-row"><span>${label}</span>
        <b class="a">${App.num(g(sa, k), dec)}</b><b class="b">${App.num(g(sb, k), dec)}</b>${diff(g(sa, k), g(sb, k), unit, dec)}</div>`;
      box.querySelector('.cmp-grid').innerHTML =
        `<div class="cmp-row head"><span></span><b class="a">A</b><b class="b">B</b><span>Rozdiel</span></div>` +
        row('Priemer', 'avg') + row('Minimum', 'min') + row('Maximum', 'max');
    });
    const ins = document.getElementById('cmpInsight');
    if (sa && sb) {
      const dt = sa.tavg - sb.tavg, dh = sa.havg - sb.havg;
      const tt = Math.abs(dt) < 0.1 ? 'rovnako teplé' : `v priemere o ${App.num(Math.abs(dt))} °C ${dt > 0 ? 'teplejšie' : 'chladnejšie'}`;
      const hh = Math.abs(dh) < 1 ? 'vlhkosť bola rovnaká' : `vlhkosť bola o ${App.num(Math.abs(dh), 0)} % ${dh > 0 ? 'vyššia' : 'nižšia'}`;
      ins.textContent = `💡 ${data.a.label} bolo ${tt} ako ${data.b.label.toLowerCase()} a ${hh}.`;
    } else ins.textContent = 'V jednom z období chýbajú merania.';
  }

  function draw() {
    const unit = metric === 't' ? '°C' : '%';
    const ds = (k, color, dash) => ({
      label: (k === 'a' ? 'A · ' : 'B · ') + data[k].label,
      data: data[k].points.map((p) => ({ x: p.o, y: p[metric] })),
      borderColor: color, borderDash: dash ? [6, 5] : [], borderWidth: k === 'a' ? 2.6 : 2,
      backgroundColor: k === 'a' ? (c) => c.chart.chartArea ? Charts.gradient(c.chart.ctx, c.chart.chartArea, COL_A) : 'transparent' : 'transparent',
      fill: k === 'a', spanGaps: true
    });
    const opts = Charts.lineOptions(unit);
    opts.scales.x = { type: 'linear', grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 20, callback: (v) => fmtOffset(v) } };
    opts.plugins.tooltip.callbacks.title = (items) => items.length ? fmtOffset(items[0].parsed.x) : '';
    if (chart) chart.destroy();
    Charts.applyDefaults();
    chart = new Chart(document.getElementById('cmpChart'), { type: 'line', data: { datasets: [ds('b', COL_B, true), ds('a', COL_A, false)] }, options: opts });
  }

  document.getElementById('cmpMetric').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-metric]');
    if (!b) return;
    document.querySelectorAll('#cmpMetric button').forEach((x) => x.classList.toggle('active', x === b));
    metric = b.dataset.metric;
    draw();
  });
  document.addEventListener('themechange', () => data && draw());

  App.get('api/data.php?action=compare&' + cfg.dataset.query).then((j) => {
    if (!j.ok) return App.toast(j.error || 'Chyba', 'danger');
    data = j;
    stats();
    if (window.Chart) draw();
  });
})();
