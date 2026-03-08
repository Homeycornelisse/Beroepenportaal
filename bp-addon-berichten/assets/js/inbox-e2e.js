(function () {
  'use strict';

  const cfgNode = document.getElementById('bp-e2e-config');
  if (!cfgNode) return;

  let cfg = {};
  try {
    cfg = JSON.parse(cfgNode.textContent || '{}');
  } catch (e) {
    return;
  }

  const me = Number(cfg.userId || 0);
  if (!me || !window.crypto || !window.crypto.subtle) return;

  const STORAGE_BUNDLE_KEY = `bp_e2e_bundle_${me}`;
  const STORAGE_META_KEY = `bp_e2e_meta_${me}`;
  const STORAGE_IOS_DISMISS_KEY = `bp_ios_install_banner_dismissed_${me}`;
  const STORAGE_LOCKED_KEY = `bp_e2e_locked_${me}_${window.location.pathname}`;
  const INACTIVITY_MS = 5 * 60 * 1000;
  const LOCK_ENABLED = cfg.lockEnabled !== false;
  const PBKDF2_ITERATIONS = 210000;
  const PBKDF2_HASH = 'SHA-256';
  const ENCRYPTION_VERSION = 2;

  const senderPublicKeys = cfg.publicKeys || {};
  const senderPublicKeyFingerprints = cfg.publicKeyFingerprints || {};

  const form = document.querySelector('.bp-chat-compose form');
  const textarea = form ? form.querySelector('textarea[name="inhoud"]') : null;
  const hiddenPlain = form ? form.querySelector('input[name="onderwerp"]') : null;
  const hiddenRecipient = form ? form.querySelector('input[name="naar_id"]') : null;
  const sendBtn = document.getElementById('bp-send-btn');
  const recipientWarn = document.getElementById('bp-e2e-recipient-warning');
  const rotateBtn = document.getElementById('bp-e2e-rotate');
  const exportBtn = document.getElementById('bp-e2e-export');
  const importInput = document.getElementById('bp-e2e-import');
  const contactQrImg = document.getElementById('bp-contact-qr');
  const installBtn = document.getElementById('bp-install-app');
  const iosHelpResetBtn = document.getElementById('bp-ios-install-help-reset');
  const manualUnlockBtn = document.getElementById('bp-e2e-unlock-btn');

  let deferredInstallPrompt = null;
  let runtimePrivateKey = null;
  let runtimeKeyring = [];
  let runtimeUnlocked = false;
  let inactivityTimer = null;
  let lastActivityAt = 0;
  let lockOverlay = null;
  let lockOverlayInfo = null;
  let secretModal = null;
  let unlockBusy = false;
  let lockedNodes = [];
  let hiddenNodes = [];

  function loadJSON(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return fallback;
      return JSON.parse(raw);
    } catch (e) {
      return fallback;
    }
  }

  function saveJSON(key, value) {
    localStorage.setItem(key, JSON.stringify(value));
  }

  function toB64(buf) {
    const bytes = new Uint8Array(buf);
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin);
  }

  function fromB64(str) {
    const bin = atob(str);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return bytes.buffer;
  }

  function wipeRuntimeKeys() {
    runtimePrivateKey = null;
    runtimeKeyring = [];
    runtimeUnlocked = false;
    if (inactivityTimer) {
      clearTimeout(inactivityTimer);
      inactivityTimer = null;
    }
    lastActivityAt = 0;
    if (manualUnlockBtn) manualUnlockBtn.textContent = 'Ontgrendel berichten';
  }

  function setPersistedLockedState(locked) {
    try {
      if (!window.sessionStorage) return;
      if (locked) {
        window.sessionStorage.setItem(STORAGE_LOCKED_KEY, '1');
      } else {
        window.sessionStorage.removeItem(STORAGE_LOCKED_KEY);
      }
    } catch (e) {}
  }

  function hasPersistedLockedState() {
    try {
      return !!(window.sessionStorage && window.sessionStorage.getItem(STORAGE_LOCKED_KEY) === '1');
    } catch (e) {
      return false;
    }
  }

  function redactMessages() {
    lockedNodes = [];
    const nodes = document.querySelectorAll('.bp-msg-body');
    nodes.forEach((node) => {
      const previous = node.textContent || '';
      lockedNodes.push({ node, previous });
      node.textContent = '[Vergrendeld]';
    });
    hiddenNodes = [];
    const areas = document.querySelectorAll('.bp-chat-log, .bp-chat-compose, .bp-inbox-list, .bp-inbox-head');
    areas.forEach((el) => {
      hiddenNodes.push({
        el,
        visibility: el.style.visibility || '',
        pointerEvents: el.style.pointerEvents || '',
        userSelect: el.style.userSelect || ''
      });
      el.style.visibility = 'hidden';
      el.style.pointerEvents = 'none';
      el.style.userSelect = 'none';
    });
    document.body.classList.add('bp-e2e-locked');
  }

  function restoreRedactedMessages() {
    lockedNodes.forEach((entry) => {
      if (!entry || !entry.node) return;
      const isE2E = entry.node.getAttribute('data-e2e') === '1';
      if (isE2E) return;
      entry.node.textContent = entry.previous || '';
    });
    lockedNodes = [];
    hiddenNodes.forEach((h) => {
      if (!h || !h.el) return;
      h.el.style.visibility = h.visibility;
      h.el.style.pointerEvents = h.pointerEvents;
      h.el.style.userSelect = h.userSelect;
    });
    hiddenNodes = [];
    document.body.classList.remove('bp-e2e-locked');
  }

  function lockDueToInactivity() {
    if (!LOCK_ENABLED) return;
    wipeRuntimeKeys();
    setPersistedLockedState(true);
    redactMessages();
    showLockOverlay();
  }

  function armInactivityTimer() {
    if (!LOCK_ENABLED) return;
    if (inactivityTimer) clearTimeout(inactivityTimer);
    if (!runtimeUnlocked || !lastActivityAt) return;
    const idleMs = Date.now() - lastActivityAt;
    const remaining = INACTIVITY_MS - idleMs;
    if (remaining <= 0) {
      lockDueToInactivity();
      return;
    }
    inactivityTimer = setTimeout(function () {
      armInactivityTimer();
    }, remaining);
  }

  function resetInactivityTimer() {
    if (!LOCK_ENABLED) return;
    if (!runtimeUnlocked) return;
    lastActivityAt = Date.now();
    armInactivityTimer();
  }

  function bindActivityWatchers() {
    if (!LOCK_ENABLED) return;
    ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach((evt) => {
      window.addEventListener(evt, function () {
        if (!runtimeUnlocked) return;
        resetInactivityTimer();
      }, { passive: true });
    });
  }

  function ensureLockOverlay() {
    if (lockOverlay) return lockOverlay;
    const overlay = document.createElement('div');
    overlay.style.cssText = [
      'position:fixed',
      'inset:0',
      'display:none',
      'align-items:center',
      'justify-content:center',
      'padding:16px',
      'background:#0b1220',
      'z-index:1000001'
    ].join(';');

    overlay.innerHTML =
      '<div style="max-width:460px;width:100%;background:#ffffff;border:1px solid #dbe4f0;border-radius:16px;padding:20px;box-shadow:0 12px 28px rgba(2,6,23,.35);text-align:left;">' +
        '<h3 style="margin:0 0 8px 0;color:#0f2f67;font-size:30px;line-height:1.1;font-weight:800;">Scherm vergrendeld</h3>' +
        '<p id="bp-chat-lock-overlay-info" style="margin:0 0 16px 0;color:#334155;font-size:19px;line-height:1.35;">Vanwege inactiviteit is gevoelige informatie afgeschermd.</p>' +
        '<button type="button" id="bp-chat-lock-overlay-btn" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border:1px solid #0f5cc0;border-radius:10px;background:#0f5cc0;color:#ffffff;font-weight:700;font-size:20px;line-height:1;cursor:pointer;">Ontgrendelen</button>' +
      '</div>';

    document.body.appendChild(overlay);
    lockOverlay = overlay;
    lockOverlayInfo = overlay.querySelector('#bp-chat-lock-overlay-info');
    const unlockBtn = overlay.querySelector('#bp-chat-lock-overlay-btn');
    if (unlockBtn) {
      unlockBtn.addEventListener('click', async function () {
        const ok = await runUnlockFlow();
        if (!ok) {
          if (lockOverlayInfo) lockOverlayInfo.textContent = 'Ontgrendelen mislukt. Probeer het opnieuw.';
          return;
        }
        if (lockOverlayInfo) lockOverlayInfo.textContent = 'Chat weer ontgrendeld.';
      });
    }
    return overlay;
  }

  function showLockOverlay() {
    const overlay = ensureLockOverlay();
    if (!overlay) return;
    overlay.style.display = 'flex';
    if (lockOverlayInfo) {
      lockOverlayInfo.textContent = 'Je was 5 minuten inactief. Voer je sleutelwachtwoord opnieuw in.';
    }
  }

  function hideLockOverlay() {
    if (!lockOverlay) return;
    lockOverlay.style.display = 'none';
  }

  async function askPassword(opts) {
    const title = String((opts && opts.title) || 'Wachtwoord invoeren');
    const message = String((opts && opts.message) || '');
    if (secretModal && secretModal.parentNode) {
      secretModal.parentNode.removeChild(secretModal);
      secretModal = null;
    }

    return new Promise((resolve) => {
      const wrap = document.createElement('div');
      wrap.style.cssText = [
        'position:fixed',
        'inset:0',
        'z-index:999999',
        'display:flex',
        'align-items:center',
        'justify-content:center',
        'padding:16px',
        'background:rgba(2,6,23,.78)',
        'backdrop-filter:blur(4px)'
      ].join(';');

      wrap.innerHTML =
        '<div style="width:100%;max-width:440px;background:#fff;border:1px solid #dbe4f0;border-radius:16px;padding:18px;box-shadow:0 10px 24px rgba(2,6,23,.30);">' +
          '<h3 style="margin:0 0 8px 0;color:#0f2f67;font-size:22px;font-weight:800;">' + title + '</h3>' +
          (message ? '<p style="margin:0 0 12px 0;color:#334155;font-size:14px;line-height:1.45;">' + message + '</p>' : '') +
          '<label style="display:block;margin:0 0 6px 0;font-size:12px;color:#475569;font-weight:700;">Wachtwoord</label>' +
          '<div style="display:flex;gap:8px;align-items:center;">' +
            '<input id="bp-secret-input" type="password" inputmode="text" autocomplete="current-password" autocapitalize="off" autocorrect="off" spellcheck="false" style="flex:1;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:14px;-webkit-text-security:disc;">' +
            '<button id="bp-secret-toggle" type="button" style="border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:9px 10px;cursor:pointer;font-weight:600;color:#1e293b;">Toon</button>' +
          '</div>' +
          '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">' +
            '<button id="bp-secret-cancel" type="button" style="border:1px solid #cbd5e1;background:#fff;color:#1e293b;border-radius:10px;padding:9px 12px;cursor:pointer;font-weight:600;">Annuleren</button>' +
            '<button id="bp-secret-ok" type="button" style="border:1px solid #0f5cc0;background:#0f5cc0;color:#111827;border-radius:10px;padding:9px 12px;cursor:pointer;font-weight:700;box-shadow:0 2px 6px rgba(15,92,192,.28);">Doorgaan</button>' +
          '</div>' +
        '</div>';

      if (!document.body) {
        const fallback = window.prompt((title ? `${title}\n` : '') + message, '');
        resolve(fallback ? String(fallback) : null);
        return;
      }
      document.body.appendChild(wrap);
      secretModal = wrap;

      const input = wrap.querySelector('#bp-secret-input');
      const toggle = wrap.querySelector('#bp-secret-toggle');
      const cancel = wrap.querySelector('#bp-secret-cancel');
      const ok = wrap.querySelector('#bp-secret-ok');

      function done(value) {
        if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
        if (secretModal === wrap) secretModal = null;
        resolve(value);
      }

      if (toggle && input) {
        input.type = 'password';
        input.style.webkitTextSecurity = 'disc';
        input.dataset.showing = '0';
        toggle.addEventListener('click', function () {
          const show = input.dataset.showing !== '1';
          input.type = show ? 'text' : 'password';
          input.style.webkitTextSecurity = show ? 'none' : 'disc';
          input.dataset.showing = show ? '1' : '0';
          toggle.textContent = show ? 'Verberg' : 'Toon';
        });
        input.addEventListener('blur', function () {
          // Veiligheidsnet: bij blur altijd maskeren.
          input.type = 'password';
          input.style.webkitTextSecurity = 'disc';
          input.dataset.showing = '0';
          if (toggle) toggle.textContent = 'Toon';
        });
      }

      if (cancel) cancel.addEventListener('click', function () { done(null); });
      if (ok && input) {
        ok.addEventListener('click', function () {
          const value = String(input.value || '');
          done(value ? value : null);
        });
      }
      if (input) {
        input.focus();
        input.addEventListener('keydown', function (ev) {
          if (ev.key === 'Enter') {
            ev.preventDefault();
            const value = String(input.value || '');
            done(value ? value : null);
          } else if (ev.key === 'Escape') {
            ev.preventDefault();
            done(null);
          }
        });
      }
    });
  }

  async function verifyAccountPassword(password) {
    const ajaxUrl = String(cfg.ajaxUrl || '');
    const nonce = String(cfg.verifyAccountNonce || '');
    if (!ajaxUrl || !nonce) {
      throw new Error('Wachtwoordcontrole niet beschikbaar.');
    }
    const body = new URLSearchParams();
    body.set('action', 'bp_core_verify_account_password');
    body.set('nonce', nonce);
    body.set('password', String(password || ''));

    const res = await fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });

    let json = null;
    try { json = await res.json(); } catch (e) { json = null; }
    if (!res.ok || !json || !json.success) {
      throw new Error((json && json.data && json.data.message) ? String(json.data.message) : 'Wachtwoord is onjuist.');
    }
    return true;
  }

  async function importPublicJwk(jwk) {
    return crypto.subtle.importKey('jwk', jwk, { name: 'RSA-OAEP', hash: 'SHA-256' }, true, ['encrypt']);
  }

  async function importPrivateJwk(jwk) {
    return crypto.subtle.importKey('jwk', jwk, { name: 'RSA-OAEP', hash: 'SHA-256' }, true, ['decrypt']);
  }

  function publicFromPrivateJwk(privateJwk) {
    if (!privateJwk || !privateJwk.n || !privateJwk.e) return null;
    return {
      kty: 'RSA',
      n: privateJwk.n,
      e: privateJwk.e,
      alg: 'RSA-OAEP-256',
      ext: true,
      key_ops: ['encrypt'],
    };
  }

  async function deriveMasterKey(password, saltB64) {
    try {
      if (typeof password !== 'string' || password.length < 8) {
        throw new Error('INVALID_PASSWORD');
      }
      if (typeof saltB64 !== 'string' || saltB64 === '') {
        throw new Error('INVALID_SALT');
      }
      const salt = new Uint8Array(fromB64(saltB64));
      if (!salt || salt.byteLength < 16) {
        throw new Error('INVALID_SALT_BYTES');
      }
      const passBytes = new TextEncoder().encode(password);
      const base = await crypto.subtle.importKey('raw', passBytes, 'PBKDF2', false, ['deriveKey']);
      return await crypto.subtle.deriveKey(
        { name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: PBKDF2_HASH },
        base,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
      );
    } catch (e) {
      throw new Error('MASTER_KEY_DERIVE_FAILED');
    }
  }

  async function encryptJwkWithPassword(jwkObject, password) {
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const saltB64 = toB64(salt.buffer);
    const masterKey = await deriveMasterKey(password, saltB64);
    const plain = new TextEncoder().encode(JSON.stringify(jwkObject));
    const cipher = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, masterKey, plain);
    return {
      salt: saltB64,
      iv: toB64(iv.buffer),
      cipher: toB64(cipher),
      iterations: PBKDF2_ITERATIONS,
      hash: PBKDF2_HASH,
    };
  }

  async function decryptJwkWithPassword(blob, password) {
    try {
      if (!blob || typeof blob !== 'object') throw new Error('INVALID_BLOB');
      if (Number(blob.iterations || PBKDF2_ITERATIONS) !== PBKDF2_ITERATIONS) throw new Error('ITERATIONS_MISMATCH');
      if (String(blob.hash || '') !== PBKDF2_HASH) throw new Error('HASH_MISMATCH');
      if (!blob.salt || !blob.iv || !blob.cipher) throw new Error('INVALID_BLOB_FIELDS');
      const masterKey = await deriveMasterKey(password, blob.salt);
      const iv = new Uint8Array(fromB64(blob.iv));
      const cipher = fromB64(blob.cipher);
      const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, masterKey, cipher);
      const decoded = new TextDecoder().decode(plain);
      const jwk = JSON.parse(decoded);
      if (!jwk || typeof jwk !== 'object' || !jwk.n || !jwk.e || !jwk.d) {
        throw new Error('INVALID_JWK');
      }
      return jwk;
    } catch (e) {
      throw new Error('DECRYPT_FAILED');
    }
  }

  function getStoredBundle() {
    return loadJSON(STORAGE_BUNDLE_KEY, null);
  }

  function saveStoredBundle(bundle) {
    saveJSON(STORAGE_BUNDLE_KEY, bundle);
  }

  async function uploadPublicKey(publicJwk) {
    const body = new URLSearchParams();
    body.set('action', 'bp_e2e_public_key');
    body.set('bp_e2e_nonce', cfg.nonce || '');
    body.set('public_key', JSON.stringify(publicJwk));

    await fetch(cfg.adminPost || '/wp-admin/admin-post.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });
  }

  async function generateEncryptedBundle(password, oldKeyringEncrypted) {
    const pair = await crypto.subtle.generateKey(
      {
        name: 'RSA-OAEP',
        modulusLength: 2048,
        publicExponent: new Uint8Array([1, 0, 1]),
        hash: 'SHA-256',
      },
      true,
      ['encrypt', 'decrypt']
    );
    const privateJwk = await crypto.subtle.exportKey('jwk', pair.privateKey);
    const publicJwk = await crypto.subtle.exportKey('jwk', pair.publicKey);
    const encryptedCurrent = await encryptJwkWithPassword(privateJwk, password);

    const meta = loadJSON(STORAGE_META_KEY, {});
    meta.lastRotatedAt = Date.now();
    meta.createdAt = meta.createdAt || Date.now();
    saveJSON(STORAGE_META_KEY, meta);

    const bundle = {
      version: ENCRYPTION_VERSION,
      current: encryptedCurrent,
      keyring: Array.isArray(oldKeyringEncrypted) ? oldKeyringEncrypted : [],
    };
    saveStoredBundle(bundle);
    senderPublicKeys[String(me)] = publicJwk;
    await uploadPublicKey(publicJwk);
    runtimePrivateKey = await importPrivateJwk(privateJwk);
    runtimeKeyring = [];
    runtimeUnlocked = true;
    resetInactivityTimer();
  }

  async function migratePlainStorageIfNeeded() {
    const legacy = loadJSON(`bp_e2e_priv_jwk_${me}`, null);
    if (!legacy || !legacy.n || !legacy.e) return;
    const pass = await askPassword({
      title: 'E2E sleutel beveiligen',
      message: 'Voer je accountwachtwoord in om je sleutel lokaal te beveiligen.'
    });
    if (!pass || pass.length < 8) {
      alert('Wachtwoord te kort. Minimaal 8 tekens.');
      return;
    }
    try {
      await verifyAccountPassword(pass);
    } catch (e) {
      alert(e && e.message ? e.message : 'Wachtwoord is onjuist.');
      return;
    }

    const encryptedCurrent = await encryptJwkWithPassword(legacy, pass);
    const bundle = { version: ENCRYPTION_VERSION, current: encryptedCurrent, keyring: [] };
    saveStoredBundle(bundle);
    localStorage.removeItem(`bp_e2e_priv_jwk_${me}`);
    senderPublicKeys[String(me)] = publicFromPrivateJwk(legacy);
    await uploadPublicKey(senderPublicKeys[String(me)]);
  }

  async function unlockVault(forcePrompt) {
    if (runtimeUnlocked && runtimePrivateKey) {
      resetInactivityTimer();
      return true;
    }

    let bundle = getStoredBundle();
    if (!bundle || !bundle.current) {
      const firstPass = await askPassword({
        title: 'E2E sleutel instellen',
        message: 'Voer je accountwachtwoord in. Dit wordt gebruikt om je lokale sleutel te ontgrendelen.'
      });
      if (!firstPass || firstPass.length < 8) {
        alert('Geen geldig wachtwoord. Minimaal 8 tekens nodig.');
        return false;
      }
      try {
        await verifyAccountPassword(firstPass);
      } catch (e) {
        alert(e && e.message ? e.message : 'Wachtwoord is onjuist.');
        return false;
      }
      await generateEncryptedBundle(firstPass, []);
      return true;
    }

    if (!forcePrompt) return false;

    const pass = await askPassword({
      title: 'Berichten ontgrendelen',
      message: 'Voer je accountwachtwoord in om je berichten te ontgrendelen.'
    });
    if (!pass) return false;
    try {
      await verifyAccountPassword(pass);
    } catch (e) {
      alert(e && e.message ? e.message : 'Wachtwoord is onjuist.');
      return false;
    }

    try {
      const currentJwk = await decryptJwkWithPassword(bundle.current, pass);
      runtimePrivateKey = await importPrivateJwk(currentJwk);
      runtimeKeyring = [];

      const keyring = Array.isArray(bundle.keyring) ? bundle.keyring : [];
      for (const item of keyring) {
        if (!item || !item.encrypted) continue;
        try {
          const jwk = await decryptJwkWithPassword(item.encrypted, pass);
          const key = await importPrivateJwk(jwk);
          runtimeKeyring.push(key);
        } catch (e) {}
      }

      runtimeUnlocked = true;
      setPersistedLockedState(false);
      if (manualUnlockBtn) manualUnlockBtn.textContent = 'Berichten ontgrendeld';
      hideLockOverlay();
      restoreRedactedMessages();
      resetInactivityTimer();
      return true;
    } catch (e) {
      const resetOk = window.confirm('Ontgrendelen mislukt. Wil je een nieuwe lokale sleutel instellen met dit wachtwoord? Oude versleutelde berichten kunnen dan mogelijk niet meer leesbaar zijn.');
      if (resetOk) {
        try {
          await generateEncryptedBundle(pass, []);
          setPersistedLockedState(false);
          if (manualUnlockBtn) manualUnlockBtn.textContent = 'Berichten ontgrendeld';
          return true;
        } catch (e2) {
          alert('Nieuwe sleutel aanmaken is mislukt.');
        }
      } else {
        alert('Ontgrendelen mislukt. Controleer je wachtwoord.');
      }
      showLockOverlay();
      return false;
    }
  }

  async function runUnlockFlow() {
    if (unlockBusy) return false;
    unlockBusy = true;
    try {
      const ok = await unlockVault(true);
      if (!ok) return false;
      hideLockOverlay();
      restoreRedactedMessages();
      await decryptVisibleMessages();
      return true;
    } finally {
      unlockBusy = false;
    }
  }

  async function rotateKeys() {
    const ok = await unlockVault(true);
    if (!ok) return false;

    const pass = await askPassword({
      title: 'Sleutel-rotatie',
      message: 'Voer je accountwachtwoord opnieuw in om de sleutel te roteren.'
    });
    if (!pass) return false;
    try {
      await verifyAccountPassword(pass);
    } catch (e) {
      alert(e && e.message ? e.message : 'Wachtwoord is onjuist.');
      return false;
    }

    const bundle = getStoredBundle();
    let oldEncrypted = [];
    if (bundle && bundle.current) {
      oldEncrypted = [{ encrypted: bundle.current, ts: Date.now() }];
      if (Array.isArray(bundle.keyring)) oldEncrypted = oldEncrypted.concat(bundle.keyring).slice(0, 5);
    }

    const previousPrivateKey = runtimePrivateKey;
    const previousKeyring = Array.isArray(runtimeKeyring) ? runtimeKeyring.slice() : [];
    if (previousPrivateKey) {
      try {
        const oldJwk = await crypto.subtle.exportKey('jwk', previousPrivateKey);
        const encryptedOld = await encryptJwkWithPassword(oldJwk, pass);
        oldEncrypted.unshift({ encrypted: encryptedOld, ts: Date.now() });
      } catch (e) {}
    }

    await generateEncryptedBundle(pass, oldEncrypted);
    runtimeKeyring = [];
    if (previousPrivateKey) runtimeKeyring.push(previousPrivateKey);
    previousKeyring.forEach((k) => {
      if (k) runtimeKeyring.push(k);
    });
    await decryptVisibleMessages();
    alert('Sleutel geroteerd.');
    return true;
  }

  async function maybeAutoRotate() {
    if (!runtimeUnlocked) return;
    const rotationDays = Math.max(7, Math.min(365, Number(cfg.rotationDays || 90)));
    const meta = loadJSON(STORAGE_META_KEY, {});
    const last = Number(meta.lastRotatedAt || meta.createdAt || 0);
    if (!last) return;
    const maxAgeMs = rotationDays * 24 * 60 * 60 * 1000;
    if (Date.now() - last < maxAgeMs) return;

    const run = confirm('Je sleutel is oud. Nu automatisch roteren?');
    if (!run) return;
    await rotateKeys();
  }

  async function encryptFor(recipientId, plaintext) {
    const recipientJwk = senderPublicKeys[String(recipientId)];
    const myJwk = senderPublicKeys[String(me)];
    const recipientFp = String(senderPublicKeyFingerprints[String(recipientId)] || '');
    const myFp = String(senderPublicKeyFingerprints[String(me)] || '');
    if (!recipientJwk || !myJwk) throw new Error('Publieke sleutel ontbreekt.');
    if (!recipientFp || !myFp) throw new Error('Sleutelvingerafdruk ontbreekt.');
    if (!runtimePrivateKey) throw new Error('Sleutel niet ontgrendeld.');

    const aes = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const pt = new TextEncoder().encode(plaintext);
    const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aes, pt);
    const rawAes = await crypto.subtle.exportKey('raw', aes);

    const recPub = await importPublicJwk(recipientJwk);
    const myPub = await importPublicJwk(myJwk);
    const wrappedRecipient = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, recPub, rawAes);
    const wrappedSender = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, myPub, rawAes);

    const payload = {
      v: 1,
      alg: 'RSA-OAEP-256/A256GCM',
      iv: toB64(iv.buffer),
      ct: toB64(ct),
      keys: {},
      ts: Date.now(),
      keyfps: {},
    };
    payload.keys[String(recipientId)] = toB64(wrappedRecipient);
    payload.keys[String(me)] = toB64(wrappedSender);
    payload.keyfps[String(recipientId)] = recipientFp.toLowerCase();
    payload.keyfps[String(me)] = myFp.toLowerCase();
    return 'e2e:v1:' + btoa(JSON.stringify(payload));
  }

  async function decryptWithRuntimeKey(privateKey, payloadText) {
    const json = JSON.parse(atob(payloadText.substring(7)));
    const wrapped = json.keys && json.keys[String(me)];
    if (!wrapped) return null;
    const aesRaw = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, privateKey, fromB64(wrapped));
    const aes = await crypto.subtle.importKey('raw', aesRaw, { name: 'AES-GCM' }, false, ['decrypt']);
    const pt = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: new Uint8Array(fromB64(json.iv)) },
      aes,
      fromB64(json.ct)
    );
    return new TextDecoder().decode(pt);
  }

  async function decryptPayload(payloadText) {
    if (!payloadText || payloadText.indexOf('e2e:v1:') !== 0) return payloadText;
    if (!runtimeUnlocked || !runtimePrivateKey) return '[Vergrendeld]';

    try {
      const plain = await decryptWithRuntimeKey(runtimePrivateKey, payloadText);
      if (plain !== null) return plain;
    } catch (e) {}

    for (const key of runtimeKeyring) {
      try {
        const plain = await decryptWithRuntimeKey(key, payloadText);
        if (plain !== null) return plain;
      } catch (e) {}
    }
    return '[Versleuteld bericht]';
  }

  async function decryptVisibleMessages() {
    const nodes = document.querySelectorAll('.bp-msg-body[data-e2e="1"]');
    for (const node of nodes) {
      const src = node.getAttribute('data-raw') || '';
      if (!src) continue;
      try {
        node.textContent = await decryptPayload(src);
      } catch (e) {
        node.textContent = '[Kan niet ontsleutelen]';
      }
    }
  }

  function updateRecipientState() {
    let hasKey = false;
    if (hiddenRecipient) {
      const rid = Number(hiddenRecipient.value || 0);
      hasKey =
        hiddenRecipient.getAttribute('data-has-key') === '1' &&
        !!senderPublicKeys[String(rid)] &&
        !!senderPublicKeyFingerprints[String(rid)];
    }
    if (recipientWarn) recipientWarn.style.display = hasKey ? 'none' : 'block';
    if (sendBtn && hiddenRecipient) sendBtn.disabled = false;
  }

  function initContactQr() {
    if (!contactQrImg) return;
    const code = String(cfg.contactCode || '').trim();
    if (!code) {
      contactQrImg.alt = 'Contactcode niet beschikbaar';
      return;
    }
    const payload = JSON.stringify({ type: 'bp_contact_code', code: code, site: window.location.origin });
    const sources = [];
    if (cfg.qrProxy) sources.push(String(cfg.qrProxy));
    sources.push('https://quickchart.io/qr?size=150&margin=0&text=' + encodeURIComponent(payload));
    sources.push('https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=' + encodeURIComponent(payload));
    sources.push('https://chart.googleapis.com/chart?cht=qr&chs=150x150&chl=' + encodeURIComponent(payload));

    let idx = 0;
    contactQrImg.referrerPolicy = 'no-referrer';
    contactQrImg.loading = 'lazy';
    contactQrImg.onerror = function () {
      idx += 1;
      if (idx < sources.length) {
        contactQrImg.src = sources[idx];
      } else {
        contactQrImg.alt = 'QR niet beschikbaar';
      }
    };
    contactQrImg.src = sources[0];
  }

  function isIosSafari() {
    const ua = window.navigator.userAgent || '';
    const iOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const safari = /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
    return iOS && safari;
  }

  function isStandaloneMode() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  }

  function initialsFromName(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    const a = parts[0].charAt(0).toUpperCase();
    const b = parts.length > 1 ? parts[1].charAt(0).toUpperCase() : '';
    return (a + b).substring(0, 2);
  }

  function buildAvatarFallbackDataUrl(label) {
    const txt = initialsFromName(label);
    const svg =
      '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">' +
      '<rect width="64" height="64" rx="32" fill="#e2e8f0"/>' +
      '<text x="32" y="37" text-anchor="middle" font-size="22" font-family="Arial, sans-serif" fill="#334155">' + txt + '</text>' +
      '</svg>';
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }

  function setupAvatarFallbacks() {
    const avatars = document.querySelectorAll('.bp-contact-avatar, .bp-msg-avatar');
    avatars.forEach((img) => {
      if (!(img instanceof HTMLImageElement)) return;
      const label = img.getAttribute('alt') || img.getAttribute('data-name') || '';
      const fallback = buildAvatarFallbackDataUrl(label);
      img.addEventListener('error', function onErr() {
        img.removeEventListener('error', onErr);
        img.src = fallback;
      });
      if (!img.getAttribute('src')) {
        img.src = fallback;
      }
    });
  }

  function sanitizeComposerPayload() {
    if (!textarea) return;
    const v = String(textarea.value || '').trim();
    if (v.indexOf('e2e:v1:') === 0) {
      textarea.value = '';
    }
  }

  function showIosInstallBanner() {
    if (!isIosSafari()) return;
    if (isStandaloneMode()) return;
    if (localStorage.getItem(STORAGE_IOS_DISMISS_KEY) === '1') return;

    const wrap = document.querySelector('.bp-inbox-wrap');
    if (!wrap) return;
    const box = document.createElement('div');
    box.className = 'bp-notice-box';
    box.style.display = 'flex';
    box.style.alignItems = 'flex-start';
    box.style.justifyContent = 'space-between';
    box.style.gap = '10px';
    box.innerHTML =
      '<div><strong>iPhone app installeren</strong><br>Open Safari deelmenu en kies <em>Zet op beginscherm</em>.</div>' +
      '<button type="button" class="bp-mini-btn">Sluiten</button>';
    const closeBtn = box.querySelector('button');
    closeBtn.addEventListener('click', function () {
      localStorage.setItem(STORAGE_IOS_DISMISS_KEY, '1');
      box.remove();
    });
    wrap.insertBefore(box, wrap.firstElementChild ? wrap.firstElementChild.nextSibling : null);
  }

  if (rotateBtn) {
    rotateBtn.addEventListener('click', async function () {
      const ok = await rotateKeys();
      if (ok) await decryptVisibleMessages();
    });
  }

  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      const bundle = getStoredBundle();
      if (!bundle) return alert('Geen sleutelbundle gevonden.');
      const payload = { exportedAt: Date.now(), bundle: bundle };
      const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `bp-e2e-backup-${me}.json`;
      document.body.appendChild(a);
      a.click();
      a.remove();
    });
  }

  if (importInput) {
    importInput.addEventListener('change', async function () {
      const file = importInput.files && importInput.files[0];
      if (!file) return;
      try {
        const text = await file.text();
        const parsed = JSON.parse(text);
        const bundle = parsed && parsed.bundle ? parsed.bundle : parsed;
        if (!bundle || !bundle.current) throw new Error('bad');
        saveStoredBundle(bundle);
        wipeRuntimeKeys();
        alert('Backup geladen. Ontgrendel je sleutel opnieuw.');
      } catch (e) {
        alert('Import mislukt. Dit bestand is ongeldig.');
      } finally {
        importInput.value = '';
      }
    });
  }

  if (iosHelpResetBtn) {
    iosHelpResetBtn.addEventListener('click', function () {
      localStorage.removeItem(STORAGE_IOS_DISMISS_KEY);
      showIosInstallBanner();
    });
  }

  if (manualUnlockBtn) {
    manualUnlockBtn.addEventListener('click', function () {
      runUnlockFlow();
    });
  }

  document.addEventListener('click', function (ev) {
    const target = ev.target && ev.target.closest ? ev.target.closest('#bp-chat-lock-overlay-btn, #bp-e2e-unlock-btn') : null;
    if (!target) return;
    ev.preventDefault();
    runUnlockFlow();
  });

  if (form && textarea) {
    form.addEventListener('submit', async function (ev) {
      const recipientId = hiddenRecipient ? Number(hiddenRecipient.value || 0) : 0;
      const plain = (textarea.value || '').trim();
      if (!recipientId || !plain) return;
      ev.preventDefault();
      const recipientHasKey =
        !!senderPublicKeys[String(recipientId)] &&
        !!senderPublicKeyFingerprints[String(recipientId)];

      // Fallback: zonder ontvanger-sleutel versturen we plain tekst;
      // server slaat dit dan at-rest versleuteld op.
      if (!recipientHasKey) {
        if (hiddenPlain) hiddenPlain.value = '';
        form.submit();
        return;
      }

      const unlocked = await unlockVault(true);
      if (!unlocked) return alert('Ontgrendelen mislukt. Bericht niet verstuurd.');
      try {
        const cipher = await encryptFor(recipientId, plain);
        textarea.value = cipher;
        if (hiddenPlain) hiddenPlain.value = '';
        form.submit();
      } catch (e) {
        alert('Versleutelen lukt niet. Even opnieuw proberen.');
      }
    });
  }

  updateRecipientState();
  bindActivityWatchers();

  (function injectLockStyles() {
    if (!document.head) return;
    const style = document.createElement('style');
    style.textContent = '.bp-e2e-locked .bp-chat-log,.bp-e2e-locked .bp-inbox-list{filter:blur(18px) saturate(.7);pointer-events:none;user-select:none;}';
    document.head.appendChild(style);
  })();

  (async function init() {
    try {
      showIosInstallBanner();
      initContactQr();
      setupAvatarFallbacks();
      sanitizeComposerPayload();
      await migratePlainStorageIfNeeded();

      if ('serviceWorker' in navigator && cfg.swUrl) {
        try {
          await navigator.serviceWorker.register(cfg.swUrl, { scope: cfg.swScope || '/' });
        } catch (e) {}
      }
      window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredInstallPrompt = e;
        if (installBtn) installBtn.style.display = 'inline-flex';
      });
      if (installBtn) {
        installBtn.addEventListener('click', async function () {
          if (!deferredInstallPrompt) return;
          deferredInstallPrompt.prompt();
          await deferredInstallPrompt.userChoice;
          deferredInstallPrompt = null;
          installBtn.style.display = 'none';
        });
      }

      const hasEncryptedMessages = !!document.querySelector('.bp-msg-body[data-e2e="1"]');
      if (LOCK_ENABLED) ensureLockOverlay();
      if (hasEncryptedMessages) {
        // Geen directe vergrendeling bij openen of paginawissel.
        // Alleen na echte inactiviteit of handmatig ontgrendelen.
        if (manualUnlockBtn) {
          manualUnlockBtn.textContent = 'Ontgrendel berichten';
        }
        if (LOCK_ENABLED && hasPersistedLockedState()) {
          redactMessages();
          showLockOverlay();
        }
      }
      await maybeAutoRotate();
    } catch (e) {
      redactMessages();
    }
  })();
})();
