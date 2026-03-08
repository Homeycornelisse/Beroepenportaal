(function () {
  'use strict';

  var guards = Array.isArray(window.BPSensitiveGuards) ? window.BPSensitiveGuards : [];
  if (!guards.length) return;

  var lockAfterMs = 300000;
  guards.forEach(function (g) {
    var ms = Number(g && g.lockAfterMs ? g.lockAfterMs : 0);
    if (ms > 0) lockAfterMs = Math.min(lockAfterMs, ms);
  });

  var isLocked = false;
  var timer = null;
  var lastActivityAt = Date.now();
  var inFlightUnlock = false;
  var obscured = [];
  var redacted = [];
  var hiddenScopes = [];
  var overlay = null;
  var unlockModal = null;
  var lockStateKey = 'bp_sensitive_locked:' + window.location.pathname;

  function getGuardAuth() {
    var cfg = window.BPGuardAuth || {};
    return {
      ajaxUrl: typeof cfg.ajaxUrl === 'string' ? cfg.ajaxUrl : '',
      nonce: typeof cfg.nonce === 'string' ? cfg.nonce : '',
    };
  }

  function persistLockState(locked) {
    try {
      if (!window.sessionStorage) return;
      if (locked) {
        window.sessionStorage.setItem(lockStateKey, '1');
      } else {
        window.sessionStorage.removeItem(lockStateKey);
      }
    } catch (e) {}
  }

  function hasPersistedLockState() {
    try {
      return !!(window.sessionStorage && window.sessionStorage.getItem(lockStateKey) === '1');
    } catch (e) {
      return false;
    }
  }

  async function verifyAccountPassword(password) {
    var auth = getGuardAuth();
    if (!auth.ajaxUrl || !auth.nonce) {
      throw new Error('Verificatie niet beschikbaar.');
    }
    var body = new URLSearchParams();
    body.set('action', 'bp_core_verify_account_password');
    body.set('nonce', auth.nonce);
    body.set('password', password || '');

    var res = await fetch(auth.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });
    var json = null;
    try { json = await res.json(); } catch (e) { json = null; }
    if (!res.ok || !json || !json.success) {
      throw new Error((json && json.data && json.data.message) ? String(json.data.message) : 'Wachtwoord is onjuist.');
    }
    return true;
  }

  function ensureOverlay() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.style.cssText = [
      'position:fixed',
      'inset:0',
      'background:#0b1220',
      'z-index:999999',
      'display:none',
      'align-items:center',
      'justify-content:center',
      'padding:16px'
    ].join(';');

    overlay.innerHTML =
      '<div style="max-width:440px;width:100%;background:#fff;border-radius:14px;padding:16px;border:1px solid #dbe4f0;">' +
        '<h3 style="margin:0 0 8px 0;color:#0f2f67;">Scherm vergrendeld</h3>' +
        '<p style="margin:0 0 12px 0;color:#475569;font-size:14px;">Vanwege inactiviteit is gevoelige informatie afgeschermd.</p>' +
        '<button type="button" id="bp-sensitive-unlock-btn" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;border:1px solid #cfd9e8;background:#0f5cc0;color:#fff;font-weight:700;cursor:pointer;">Ontgrendelen</button>' +
      '</div>';

    document.body.appendChild(overlay);
    var btn = overlay.querySelector('#bp-sensitive-unlock-btn');
    if (btn) {
      btn.addEventListener('click', unlockFlow);
    }
    return overlay;
  }

  function ensureUnlockModal() {
    if (unlockModal) return unlockModal;
    unlockModal = document.createElement('div');
    unlockModal.style.cssText = [
      'position:fixed',
      'inset:0',
      'background:rgba(2,8,23,.60)',
      'z-index:1000000',
      'display:none',
      'align-items:center',
      'justify-content:center',
      'padding:16px'
    ].join(';');
    unlockModal.innerHTML =
      '<div style="max-width:460px;width:100%;background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:16px;">' +
        '<h3 style="margin:0 0 8px;color:#0f2f67;">Ontgrendelen</h3>' +
        '<p style="margin:0 0 10px;color:#475569;font-size:14px;">Vul je accountwachtwoord in.</p>' +
        '<label style="display:block;margin-bottom:10px;">' +
          '<span style="display:block;color:#334155;font-size:13px;margin-bottom:6px;">Wachtwoord</span>' +
          '<div style="display:flex;gap:8px;">' +
            '<input id="bp-sensitive-unlock-password" type="password" autocomplete="current-password" style="flex:1;min-width:0;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;">' +
            '<button type="button" id="bp-sensitive-toggle-password" aria-label="Toon wachtwoord" style="padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;color:#0f2f67;cursor:pointer;">Toon</button>' +
          '</div>' +
        '</label>' +
        '<div id="bp-sensitive-unlock-error" style="min-height:18px;color:#b91c1c;font-size:13px;margin-bottom:10px;"></div>' +
        '<div style="display:flex;justify-content:flex-end;gap:8px;">' +
          '<button type="button" id="bp-sensitive-unlock-cancel" style="padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#0f2f67;cursor:pointer;">Annuleren</button>' +
          '<button type="button" id="bp-sensitive-unlock-confirm" style="padding:10px 12px;border:1px solid #0f5cc0;border-radius:10px;background:#0f5cc0;color:#fff;font-weight:700;cursor:pointer;">Ontgrendelen</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(unlockModal);

    var input = unlockModal.querySelector('#bp-sensitive-unlock-password');
    var toggle = unlockModal.querySelector('#bp-sensitive-toggle-password');
    var cancel = unlockModal.querySelector('#bp-sensitive-unlock-cancel');
    var confirm = unlockModal.querySelector('#bp-sensitive-unlock-confirm');

    if (toggle && input) {
      toggle.addEventListener('click', function () {
        var isPwd = input.getAttribute('type') === 'password';
        input.setAttribute('type', isPwd ? 'text' : 'password');
        toggle.textContent = isPwd ? 'Verberg' : 'Toon';
      });
    }
    if (cancel) {
      cancel.addEventListener('click', function () { closeUnlockModal(); });
    }
    if (confirm) {
      confirm.addEventListener('click', function () { submitUnlockModal(); });
    }
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') submitUnlockModal();
      });
    }
    return unlockModal;
  }

  function setUnlockError(message) {
    var modal = ensureUnlockModal();
    var err = modal.querySelector('#bp-sensitive-unlock-error');
    if (err) err.textContent = message || '';
  }

  function closeUnlockModal() {
    var modal = ensureUnlockModal();
    var input = modal.querySelector('#bp-sensitive-unlock-password');
    if (input) {
      input.value = '';
      input.setAttribute('type', 'password');
    }
    var toggle = modal.querySelector('#bp-sensitive-toggle-password');
    if (toggle) toggle.textContent = 'Toon';
    setUnlockError('');
    modal.style.display = 'none';
  }

  function openUnlockModal() {
    var modal = ensureUnlockModal();
    modal.style.display = 'flex';
    var input = modal.querySelector('#bp-sensitive-unlock-password');
    if (input) input.focus();
  }

  async function submitUnlockModal() {
    if (inFlightUnlock) return;
    var modal = ensureUnlockModal();
    var input = modal.querySelector('#bp-sensitive-unlock-password');
    var confirm = modal.querySelector('#bp-sensitive-unlock-confirm');
    var password = input ? String(input.value || '') : '';
    if (!password) {
      setUnlockError('Vul je wachtwoord in.');
      return;
    }
    inFlightUnlock = true;
    if (confirm) {
      confirm.disabled = true;
      confirm.textContent = 'Controleren...';
    }
    try {
      await verifyAccountPassword(password);
      closeUnlockModal();
      unlockNow();
    } catch (e) {
      setUnlockError(e && e.message ? e.message : 'Wachtwoord is onjuist.');
    } finally {
      inFlightUnlock = false;
      if (confirm) {
        confirm.disabled = false;
        confirm.textContent = 'Ontgrendelen';
      }
    }
  }

  function getTargets() {
    var all = [];
    guards.forEach(function (g) {
      var scopeSel = (g && g.scopeSelector) ? String(g.scopeSelector) : '';
      if (!scopeSel) return;
      var scope = document.querySelector(scopeSel);
      if (!scope) return;
      var blurSel = (g && g.blurSelectors) ? String(g.blurSelectors) :
        'textarea,input[type="text"],input[type="search"],input[type="number"],input[type="date"],input[type="file"],button,a.bp-btn,.bp-note';
      var nodes = scope.querySelectorAll(blurSel);
      nodes.forEach(function (n) { all.push(n); });
    });
    return all;
  }

  function getRedactTargets() {
    var all = [];
    guards.forEach(function (g) {
      var scopeSel = (g && g.scopeSelector) ? String(g.scopeSelector) : '';
      if (!scopeSel) return;
      var scope = document.querySelector(scopeSel);
      if (!scope) return;
      var redactSel = (g && g.redactSelectors) ? String(g.redactSelectors) : '';
      if (!redactSel) return;
      var nodes = scope.querySelectorAll(redactSel);
      nodes.forEach(function (n) { all.push(n); });
    });
    return all;
  }

  function applyLockVisuals() {
    hiddenScopes = [];
    guards.forEach(function (g) {
      var scopeSel = (g && g.scopeSelector) ? String(g.scopeSelector) : '';
      if (!scopeSel) return;
      var scope = document.querySelector(scopeSel);
      if (!scope) return;
      hiddenScopes.push({
        el: scope,
        visibility: scope.style.visibility || '',
        pointer: scope.style.pointerEvents || '',
        userSelect: scope.style.userSelect || '',
        ariaHidden: scope.getAttribute('aria-hidden')
      });
      scope.style.visibility = 'hidden';
      scope.style.pointerEvents = 'none';
      scope.style.userSelect = 'none';
      scope.setAttribute('aria-hidden', 'true');
    });

    obscured = [];
    var targets = getTargets();
    targets.forEach(function (el) {
      var state = {
        el: el,
        disabled: !!el.disabled,
        readOnly: !!el.readOnly,
        pointer: el.style.pointerEvents || '',
        filter: el.style.filter || '',
        opacity: el.style.opacity || '',
      };
      if (typeof el.readOnly !== 'undefined') el.readOnly = true;
      if (typeof el.disabled !== 'undefined') el.disabled = true;
      el.style.pointerEvents = 'none';
      el.style.filter = 'blur(14px)';
      el.style.opacity = '0.15';
      obscured.push(state);
    });

    redacted = [];
    var redactTargets = getRedactTargets();
    redactTargets.forEach(function (el) {
      var state = {
        el: el,
        html: el.innerHTML,
        filter: el.style.filter || '',
        opacity: el.style.opacity || '',
      };
      el.innerHTML = '[Vergrendeld]';
      el.style.filter = 'blur(16px)';
      el.style.opacity = '0.12';
      redacted.push(state);
    });
  }

  function clearLockVisuals() {
    hiddenScopes.forEach(function (s) {
      if (!s || !s.el) return;
      s.el.style.visibility = s.visibility;
      s.el.style.pointerEvents = s.pointer;
      s.el.style.userSelect = s.userSelect;
      if (s.ariaHidden === null || typeof s.ariaHidden === 'undefined') {
        s.el.removeAttribute('aria-hidden');
      } else {
        s.el.setAttribute('aria-hidden', s.ariaHidden);
      }
    });
    hiddenScopes = [];

    obscured.forEach(function (s) {
      if (!s || !s.el) return;
      if (typeof s.el.readOnly !== 'undefined') s.el.readOnly = s.readOnly;
      if (typeof s.el.disabled !== 'undefined') s.el.disabled = s.disabled;
      s.el.style.pointerEvents = s.pointer;
      s.el.style.filter = s.filter;
      s.el.style.opacity = s.opacity;
    });
    obscured = [];

    redacted.forEach(function (s) {
      if (!s || !s.el) return;
      s.el.innerHTML = s.html;
      s.el.style.filter = s.filter;
      s.el.style.opacity = s.opacity;
    });
    redacted = [];
  }

  function lockNow() {
    isLocked = true;
    persistLockState(true);
    applyLockVisuals();
    ensureOverlay().style.display = 'flex';
  }

  function unlockNow() {
    isLocked = false;
    persistLockState(false);
    clearLockVisuals();
    if (overlay) overlay.style.display = 'none';
    lastActivityAt = Date.now();
    scheduleTimer();
  }

  function scheduleTimer() {
    if (timer) clearTimeout(timer);
    if (isLocked) return;
    var elapsed = Date.now() - lastActivityAt;
    var remaining = lockAfterMs - elapsed;
    if (remaining <= 0) {
      lockNow();
      return;
    }
    timer = setTimeout(function () {
      var nowElapsed = Date.now() - lastActivityAt;
      if (nowElapsed >= lockAfterMs) {
        lockNow();
      } else {
        scheduleTimer();
      }
    }, remaining);
  }

  function unlockFlow() {
    openUnlockModal();
  }

  function activity() {
    if (isLocked) return;
    lastActivityAt = Date.now();
    scheduleTimer();
  }

  ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach(function (evt) {
    window.addEventListener(evt, activity, { passive: true });
  });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) return;
    scheduleTimer();
  });

  if (hasPersistedLockState()) {
    lockNow();
  } else {
    scheduleTimer();
  }
})();
