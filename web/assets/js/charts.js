/* Spoločné pomôcky pre grafy */
(function () {
  'use strict';

  const Charts = window.Charts = {
    theme() {
      const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
      return {
        dark,
        text: dark ? 'rgba(226,232,240,.75)' : 'rgba(30,41,59,.7)',
        grid: dark ? 'rgba(148,163,184,.10)' : 'rgba(100,116,139,.12)',
        tooltipBg: dark ? 'rgba(15,23,42,.95)' : 'rgba(255,255,255,.97)',
        tooltipText: dark ? '#e2e8f0' : '#0f172a'
      };
    },

    applyDefaults() {
      if (!window.Chart) return;
      const t = this.theme();
      Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
      Chart.defaults.color = t.text;
      Chart.defaults.borderColor = t.grid;
    },

    gradient(ctx, area, color) {
      const g = ctx.createLinearGradient(0, area.top, 0, area.bottom);
      g.addColorStop(0, color + '55');
      g.addColorStop(1, color + '00');
      return g;
    },

    /** Minigraf bez knižnice – rýchly, aj pre veľa kariet. */
    spark(canvas, values, color) {
      if (!canvas) return;
      const dpr = window.devicePixelRatio || 1;
      const w = canvas.clientWidth || 300, h = canvas.clientHeight || 54;
      canvas.width = w * dpr; canvas.height = h * dpr;
      const ctx = canvas.getContext('2d');
      ctx.scale(dpr, dpr);
      ctx.clearRect(0, 0, w, h);
      const pts = (values || []).map((v, i) => [i, v]).filter((p) => p[1] !== null && p[1] !== undefined);
      if (pts.length < 2) {
        ctx.fillStyle = this.theme().text;
        ctx.font = '12px Inter, sans-serif';
        ctx.fillText('Zatiaľ málo dát pre graf', 4, h / 2 + 4);
        return;
      }
      const n = values.length - 1;
      const ys = pts.map((p) => p[1]);
      let min = Math.min(...ys), max = Math.max(...ys);
      if (max - min < 0.5) { min -= 0.5; max += 0.5; }
      const X = (i) => 3 + (i / n) * (w - 6);
      const Y = (v) => h - 5 - ((v - min) / (max - min)) * (h - 12);
      ctx.beginPath();
      pts.forEach((p, k) => (k ? ctx.lineTo(X(p[0]), Y(p[1])) : ctx.moveTo(X(p[0]), Y(p[1]))));
      ctx.lineWidth = 2.2; ctx.strokeStyle = color; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
      ctx.stroke();
      ctx.lineTo(X(pts[pts.length - 1][0]), h); ctx.lineTo(X(pts[0][0]), h); ctx.closePath();
      const g = ctx.createLinearGradient(0, 0, 0, h);
      g.addColorStop(0, color + '40'); g.addColorStop(1, color + '00');
      ctx.fillStyle = g; ctx.fill();
      const last = pts[pts.length - 1];
      ctx.beginPath(); ctx.arc(X(last[0]), Y(last[1]), 3.2, 0, Math.PI * 2); ctx.fillStyle = color; ctx.fill();
    },

    lineOptions(unit, agg) {
      const t = this.theme();
      const unitMap = { raw: 'minute', '15m': 'hour', hour: 'hour', day: 'day' };
      return {
        responsive: true, maintainAspectRatio: false, animation: { duration: 450 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 16 } },
          tooltip: {
            backgroundColor: t.tooltipBg, titleColor: t.tooltipText, bodyColor: t.tooltipText,
            borderColor: t.grid, borderWidth: 1, padding: 12, cornerRadius: 12, boxPadding: 4, usePointStyle: true,
            callbacks: { label: (c) => ` ${c.dataset.label}: ${App.num(c.parsed.y, unit === '%' ? 0 : 1)} ${unit}` }
          }
        },
        scales: {
          x: {
            type: 'time',
            time: { tooltipFormat: 'd.M.yyyy HH:mm', unit: agg ? unitMap[agg] : undefined, displayFormats: { minute: 'HH:mm', hour: 'd.M. HH:mm', day: 'd.M.' } },
            grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 18 }
          },
          y: { grid: { color: t.grid }, ticks: { callback: (v) => v + ' ' + unit }, grace: '8%' }
        },
        elements: { point: { radius: 0, hoverRadius: 5 }, line: { tension: 0.35, borderWidth: 2.4 } }
      };
    },

    toDate(s) { return new Date(String(s).replace(' ', 'T')); }
  };
})();
