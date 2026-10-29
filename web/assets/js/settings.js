(async function () {
  'use strict';
  const add = document.getElementById('passkeyAdd');
  const nameInp = document.getElementById('passkeyName');
  const msg = document.getElementById('passkeyMsg');
  const detect = document.getElementById('bioDetect');
  if (!add || !window.Passkey) return;

  const show = (text, type) => { msg.className = 'alert alert-' + type; msg.textContent = text; };

  // Automatické zistenie údajov zo systému
  nameInp.value = await Passkey.deviceName();
  const platform = await Passkey.platformAvailable();
  if (!Passkey.supported) {
    detect.innerHTML = '<i class="bi bi-x-circle text-danger"></i> <span>Tento prehliadač prihlásenie odtlačkom nepodporuje.</span>';
  } else if (platform) {
    detect.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> <span>Zariadenie <b></b> podporuje odtlačok prsta / tvár / PIN.</span>';
    detect.querySelector('b').textContent = nameInp.value;
    add.disabled = false;
  } else {
    detect.innerHTML = '<i class="bi bi-info-circle text-warning"></i> <span>Na tomto zariadení nie je dostupný snímač ani zámok obrazovky. Môžeš použiť bezpečnostný kľúč alebo telefón.</span>';
    add.disabled = false;
  }
  if (location.search.includes('welcome') && platform) {
    document.getElementById('passkeys').scrollIntoView({ behavior: 'smooth' });
    add.classList.add('pulse');
  }

  add.addEventListener('click', async () => {
    add.disabled = true;
    try {
      const r = await Passkey.register(nameInp.value.trim());
      show(r.message || 'Hotovo', 'success');
      try { localStorage.setItem('esp32dth-passkey', '1'); } catch (e) {}
      setTimeout(() => location.reload(), 900);
    } catch (e) {
      show(e.message, 'danger');
      add.disabled = false;
    }
  });

  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-passkey-del]');
    if (!b) return;
    if (!confirm('Odstrániť tento odtlačok? Na danom zariadení sa potom prihlásiš len heslom.')) return;
    const r = await Passkey.remove(Number(b.dataset.passkeyDel));
    if (r.ok) { b.closest('li').remove(); App.toast('Odstránené'); } else App.toast(r.error || 'Chyba', 'danger');
  });
})();
