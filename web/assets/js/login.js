(async function () {
  'use strict';
  const btn = document.getElementById('passkeyLogin');
  const div = document.getElementById('passkeyDivider');
  const err = document.getElementById('passkeyError');
  if (!btn || !window.Passkey) return;

  const showErr = (m) => { err.textContent = m; err.classList.remove('d-none'); };

  const platform = await Passkey.platformAvailable();
  if (Passkey.supported) {
    btn.classList.remove('d-none');
    div.classList.remove('d-none');
    if (!platform) btn.querySelector('span').textContent = 'Prihlásiť sa pomocou passkey';
  }

  // Automatická ponuka uložených odtlačkov priamo v poli "meno"
  if (await Passkey.conditionalAvailable()) {
    Passkey.login({ conditional: true }).catch((e) => showErr(e.message));
  }

  btn.addEventListener('click', async () => {
    err.classList.add('d-none');
    btn.disabled = true;
    btn.classList.add('loading');
    try {
      await Passkey.login();
    } catch (e) {
      showErr(e.message);
    } finally {
      btn.disabled = false;
      btn.classList.remove('loading');
    }
  });

  // Ak sa už predtým prihlasoval odtlačkom, ponúkneme ho hneď
  let used = false;
  try { used = localStorage.getItem('esp32dth-passkey') === '1'; } catch (e) {}
  if (used && platform && !document.querySelector('.alert-danger:not(.d-none)')) {
    btn.classList.add('pulse');
  }
})();
