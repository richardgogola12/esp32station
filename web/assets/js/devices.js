(function () {
  'use strict';
  const modalEl = document.getElementById('deviceModal');
  const form = document.getElementById('deviceForm');
  if (!modalEl || !form) return;

  const $ = (sel) => form.querySelector(sel);
  let slugTouched = false;

  const slugify = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);

  function setIntervalSec(sec) {
    let unit = 1;
    if (sec % 3600 === 0) unit = 3600; else if (sec % 60 === 0) unit = 60;
    $('#fIntervalUnit').value = String(unit);
    $('#fIntervalValue').value = String(sec / unit);
    markPreset();
  }
  function currentInterval() { return Number($('#fIntervalValue').value || 0) * Number($('#fIntervalUnit').value); }
  function markPreset() {
    const s = currentInterval();
    document.querySelectorAll('#intervalPresets button').forEach((b) => b.classList.toggle('active', Number(b.dataset.sec) === s));
  }

  document.getElementById('intervalPresets').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-sec]');
    if (b) setIntervalSec(Number(b.dataset.sec));
  });
  $('#fIntervalValue').addEventListener('input', markPreset);
  $('#fIntervalUnit').addEventListener('change', markPreset);

  $('#fName').addEventListener('input', () => { if (!slugTouched) $('#fSlug').value = slugify($('#fName').value); });
  $('#fSlug').addEventListener('input', () => { slugTouched = true; $('#fSlug').value = $('#fSlug').value.toLowerCase().replace(/[^a-z0-9_-]/g, ''); });

  modalEl.addEventListener('show.bs.modal', (ev) => {
    const btn = ev.relatedTarget;
    const edit = btn && btn.dataset.mode === 'edit';
    const d = edit ? JSON.parse(btn.dataset.device) : null;
    form.reset();
    $('[name=action]').value = edit ? 'update' : 'create';
    $('[name=id]').value = edit ? d.id : 0;
    document.getElementById('deviceModalTitle').textContent = edit ? 'Upraviť zariadenie' : 'Nové zariadenie';
    slugTouched = edit;
    $('#fName').value = edit ? d.name : '';
    $('#fSlug').value = edit ? d.slug : '';
    $('#fLocation').value = edit ? d.location : '';
    $('#fDesc').value = edit ? d.description : '';
    $('#fTOff').value = edit ? d.temp_offset : 0;
    $('#fHOff').value = edit ? d.hum_offset : 0;
    $('#fActive').checked = edit ? d.is_active : true;
    const color = edit ? d.color : '#6366f1';
    form.querySelectorAll('[name=color]').forEach((r) => (r.checked = r.value === color));
    setIntervalSec(edit ? d.interval : 60);
  });
  modalEl.addEventListener('shown.bs.modal', () => $('#fName').focus());

  form.addEventListener('submit', (e) => {
    const s = currentInterval();
    if (s < 10 || s > 86400) {
      e.preventDefault();
      App.toast('Interval musí byť medzi 10 sekundami a 24 hodinami.', 'danger');
    }
  });

  // Modal s API kľúčom a kódom pre ESP32
  const espEl = document.getElementById('espModal');
  const ingest = espEl.dataset.ingest;
  const codeEl = document.getElementById('espCode');
  const keyEl = document.getElementById('espKey');
  const missing = document.getElementById('keyMissing');
  const fresh = document.getElementById('keyNew');
  const regenForm = document.getElementById('regenForm');

  function fill(slug, key) {
    document.getElementById('espSlug').textContent = '@' + slug;
    keyEl.textContent = key || '— (vygeneruj nový kľúč)';
    codeEl.textContent = `const char* API_URL       = "${ingest}";\nconst char* DEVICE_NAME   = "${slug}";\nconst char* API_KEY       = "${key || 'SEM_VLOZ_API_KLUC'}";`;
  }

  async function reveal(id, slug) {
    fill(slug, '');
    keyEl.textContent = 'načítavam…';
    const fd = new FormData();
    fd.append('_csrf', App.csrf); fd.append('action', 'reveal'); fd.append('id', id);
    try {
      const r = await fetch(App.url('devices.php'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const j = await r.json();
      if (j.ok) { fill(j.slug, j.key); missing.classList.add('d-none'); }
      else { fill(slug, ''); missing.textContent = j.error || 'Kľúč sa nepodarilo načítať.'; missing.classList.remove('d-none'); }
    } catch (e) {
      fill(slug, ''); missing.textContent = 'Kľúč sa nepodarilo načítať. Obnov stránku.'; missing.classList.remove('d-none');
    }
  }

  espEl.addEventListener('show.bs.modal', (ev) => {
    const b = ev.relatedTarget;
    missing.classList.add('d-none');
    if (b && b.dataset.id) {
      fresh.classList.add('d-none');
      regenForm.querySelector('[name=id]').value = b.dataset.id;
      reveal(b.dataset.id, b.dataset.slug);
    } else {
      fresh.classList.remove('d-none');
      regenForm.querySelector('[name=id]').value = espEl.dataset.autoId;
      fill(espEl.dataset.autoSlug, espEl.dataset.autoKey);
    }
  });
  regenForm.addEventListener('submit', (e) => {
    if (!confirm('Starý kľúč prestane platiť a ESP32 bude treba preprogramovať. Pokračovať?')) e.preventDefault();
  });
  if (espEl.dataset.autoKey) {
    bootstrap.Modal.getOrCreateInstance(espEl).show();
    espEl.addEventListener('hidden.bs.modal', () => { espEl.dataset.autoKey = ''; }, { once: true });
  }

  // Potvrdzovací dialóg
  const cm = document.getElementById('confirmModal');
  const texts = {
    delete: ['Zmazať zariadenie?', 'Zariadenie „%s“ aj všetky jeho merania budú natrvalo odstránené.', 'Zmazať', 'btn-danger'],
    clear: ['Vymazať merania?', 'Všetky merania zariadenia „%s“ budú natrvalo odstránené. Zariadenie zostane.', 'Vymazať', 'btn-danger'],
    regen: ['Vygenerovať nový API kľúč?', 'Starý kľúč zariadenia „%s“ prestane platiť a ESP32 bude potrebné preprogramovať.', 'Vygenerovať', 'btn-warning']
  };
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (!b) return;
    const [title, text, label, cls] = texts[b.dataset.confirm];
    cm.querySelector('[name=action]').value = b.dataset.confirm;
    cm.querySelector('[name=id]').value = b.dataset.id;
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmText').textContent = text.replace('%s', b.dataset.name);
    const btn = document.getElementById('confirmBtn');
    btn.textContent = label;
    btn.className = 'btn px-4 ' + cls;
    bootstrap.Modal.getOrCreateInstance(cm).show();
  });

  if (new URLSearchParams(location.search).has('new')) bootstrap.Modal.getOrCreateInstance(modalEl).show();
})();
