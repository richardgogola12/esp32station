/* Prihlásenie odtlačkom prsta (WebAuthn / passkeys) */
(function () {
  'use strict';

  const enc = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const dec = (s) => {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    return Uint8Array.from(atob(s), (c) => c.charCodeAt(0)).buffer;
  };

  const Passkey = window.Passkey = {
    supported: !!(window.PublicKeyCredential && navigator.credentials),

    /** Zistí, či má zariadenie biometriu / zámok obrazovky použiteľný na prihlásenie. */
    async platformAvailable() {
      if (!this.supported) return false;
      try { return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(); } catch (e) { return false; }
    },

    async conditionalAvailable() {
      if (!this.supported || !PublicKeyCredential.isConditionalMediationAvailable) return false;
      try { return await PublicKeyCredential.isConditionalMediationAvailable(); } catch (e) { return false; }
    },

    /** Automaticky zistí názov zariadenia (systém + prehliadač). */
    async deviceName() {
      const ua = navigator.userAgent;
      let os = 'Zariadenie', model = '';
      if (navigator.userAgentData?.getHighEntropyValues) {
        try {
          const h = await navigator.userAgentData.getHighEntropyValues(['model', 'platform', 'platformVersion']);
          model = (h.model || '').trim();
          os = h.platform || os;
        } catch (e) {}
      }
      if (os === 'Zariadenie' || !os) {
        if (/Android/i.test(ua)) os = 'Android';
        else if (/iPhone/i.test(ua)) os = 'iPhone';
        else if (/iPad/i.test(ua)) os = 'iPad';
        else if (/Mac OS X/i.test(ua)) os = 'Mac';
        else if (/Windows/i.test(ua)) os = 'Windows';
        else if (/Linux/i.test(ua)) os = 'Linux';
      }
      if (!model && /Android/i.test(ua)) {
        const m = ua.match(/Android [\d.]+; ([^;)]+)/);
        if (m && m[1] !== 'K') model = m[1].trim();
      }
      let br = 'Prehliadač';
      if (/Edg\//.test(ua)) br = 'Edge';
      else if (/SamsungBrowser/.test(ua)) br = 'Samsung Internet';
      else if (/OPR\//.test(ua)) br = 'Opera';
      else if (/Firefox\//.test(ua)) br = 'Firefox';
      else if (/Chrome\//.test(ua)) br = 'Chrome';
      else if (/Safari\//.test(ua)) br = 'Safari';
      return [model || os, br].join(' · ');
    },

    async register(name) {
      const o = await App.post('api/webauthn.php?action=register_options');
      if (!o.ok) throw new Error(o.error || 'Nepodarilo sa pripraviť registráciu');
      const pk = o.publicKey;
      pk.challenge = dec(pk.challenge);
      pk.user.id = dec(pk.user.id);
      pk.excludeCredentials = (pk.excludeCredentials || []).map((c) => ({ ...c, id: dec(c.id) }));
      let cred;
      try {
        cred = await navigator.credentials.create({ publicKey: pk });
      } catch (e) {
        if (e.name === 'InvalidStateError') throw new Error('Toto zariadenie už je zaregistrované.');
        if (e.name === 'NotAllowedError') throw new Error('Operácia bola zrušená alebo vypršal čas.');
        throw e;
      }
      const r = await App.post('api/webauthn.php?action=register_verify', {
        id: cred.id,
        rawId: enc(cred.rawId),
        clientDataJSON: enc(cred.response.clientDataJSON),
        attestationObject: enc(cred.response.attestationObject),
        name
      });
      if (!r.ok) throw new Error(r.error || 'Registrácia zlyhala');
      return r;
    },

    _abort: null,

    async login({ conditional = false } = {}) {
      if (this._abort) { this._abort.abort(); this._abort = null; }
      const o = await App.post('api/webauthn.php?action=login_options');
      if (!o.ok) throw new Error(o.error || 'Nepodarilo sa pripraviť prihlásenie');
      const pk = o.publicKey;
      pk.challenge = dec(pk.challenge);
      pk.allowCredentials = (pk.allowCredentials || []).map((c) => ({ ...c, id: dec(c.id) }));
      const req = { publicKey: pk };
      if (conditional) {
        this._abort = new AbortController();
        req.mediation = 'conditional';
        req.signal = this._abort.signal;
      }
      let cred;
      try {
        cred = await navigator.credentials.get(req);
      } catch (e) {
        if (e.name === 'AbortError') return null;
        if (e.name === 'NotAllowedError') throw new Error('Prihlásenie bolo zrušené.');
        throw e;
      }
      if (!cred) return null;
      const next = document.querySelector('[data-next]')?.dataset.next || '';
      const r = await App.post('api/webauthn.php?action=login_verify' + (next ? '&next=' + encodeURIComponent(next) : ''), {
        id: cred.id,
        rawId: enc(cred.rawId),
        clientDataJSON: enc(cred.response.clientDataJSON),
        authenticatorData: enc(cred.response.authenticatorData),
        signature: enc(cred.response.signature),
        userHandle: cred.response.userHandle ? enc(cred.response.userHandle) : ''
      });
      if (!r.ok) throw new Error(r.error || 'Prihlásenie zlyhalo');
      try { localStorage.setItem('esp32dth-passkey', '1'); } catch (e) {}
      location.href = r.redirect;
      return r;
    },

    async remove(id) {
      return App.post('api/webauthn.php?action=delete', { id });
    }
  };
})();
