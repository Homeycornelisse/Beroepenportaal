(function () {
  'use strict';

  var cfg = window.BPPluginGuard || {};
  var ajaxUrl = typeof cfg.ajaxUrl === 'string' ? cfg.ajaxUrl : '';
  var nonce = typeof cfg.nonce === 'string' ? cfg.nonce : '';
  var confirmText = typeof cfg.confirmText === 'string' ? cfg.confirmText : 'Voer je accountwachtwoord in.';
  var invalidPasswordText = typeof cfg.invalidPasswordText === 'string' ? cfg.invalidPasswordText : 'Wachtwoord is onjuist.';
  var protectedPrefixes = Array.isArray(cfg.protectedPrefixes) ? cfg.protectedPrefixes : ['bp-addon-', 'beroepen-portaal-core'];

  if (!ajaxUrl || !nonce) return;

  function isProtectedPlugin(pluginBase) {
    if (!pluginBase) return false;
    var value = String(pluginBase).replace(/^[/\\]+/, '');
    for (var i = 0; i < protectedPrefixes.length; i++) {
      var prefix = String(protectedPrefixes[i] || '');
      if (!prefix) continue;
      if (prefix.slice(-1) === '/') {
        if (value.indexOf(prefix) === 0) return true;
      } else if (value.indexOf(prefix + '-') === 0 || value.indexOf(prefix + '/') === 0 || value === prefix) {
        return true;
      } else if (value.indexOf(prefix) === 0) {
        return true;
      }
    }
    return false;
  }

  function getPluginsFromUrl(url) {
    var out = [];
    try {
      var u = new URL(url, window.location.href);
      var p = u.searchParams.get('plugin');
      if (p) out.push(p);
      var checked = u.searchParams.getAll('checked[]');
      checked.forEach(function (c) { if (c) out.push(c); });
    } catch (e) {
      return [];
    }
    return out;
  }

  function getBulkAction(form) {
    if (!form) return '';
    var a1 = form.querySelector('select[name="action"]');
    var a2 = form.querySelector('select[name="action2"]');
    var v1 = a1 ? String(a1.value || '') : '';
    var v2 = a2 ? String(a2.value || '') : '';
    if (v1 && v1 !== '-1') return v1;
    if (v2 && v2 !== '-1') return v2;
    return '';
  }

  function getCheckedPlugins(form) {
    if (!form) return [];
    var out = [];
    var checks = form.querySelectorAll('input[name="checked[]"]:checked');
    checks.forEach(function (c) {
      var v = String(c.value || '');
      if (v) out.push(v);
    });
    return out;
  }

  function addGuardTokenToUrl(url, token) {
    var u = new URL(url, window.location.href);
    u.searchParams.set('bp_guard_token', token);
    return u.toString();
  }

  function ensureGuardTokenField(form, token) {
    var field = form.querySelector('input[name="bp_guard_token"]');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = 'bp_guard_token';
      form.appendChild(field);
    }
    field.value = token;
  }

  var passwordModal = null;

  function ensurePasswordModal() {
    if (passwordModal) return passwordModal;
    passwordModal = document.createElement('div');
    passwordModal.style.cssText = [
      'position:fixed',
      'inset:0',
      'background:rgba(2,8,23,.60)',
      'z-index:1000000',
      'display:none',
      'align-items:center',
      'justify-content:center',
      'padding:16px'
    ].join(';');
    passwordModal.innerHTML =
      '<div style="max-width:460px;width:100%;background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:16px;">' +
        '<h3 style="margin:0 0 8px;color:#0f2f67;">Wachtwoordbevestiging</h3>' +
        '<p style="margin:0 0 10px;color:#475569;font-size:14px;">' + confirmText + '</p>' +
        '<div style="display:flex;gap:8px;margin-bottom:10px;">' +
          '<input id="bp-plugin-guard-password" type="password" autocomplete="current-password" style="flex:1;min-width:0;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;">' +
          '<button type="button" id="bp-plugin-guard-toggle" style="padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;color:#0f2f67;cursor:pointer;">Toon</button>' +
        '</div>' +
        '<div id="bp-plugin-guard-error" style="min-height:18px;color:#b91c1c;font-size:13px;margin-bottom:10px;"></div>' +
        '<div style="display:flex;justify-content:flex-end;gap:8px;">' +
          '<button type="button" id="bp-plugin-guard-cancel" style="padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#0f2f67;cursor:pointer;">Annuleren</button>' +
          '<button type="button" id="bp-plugin-guard-confirm" style="padding:10px 12px;border:1px solid #0f5cc0;border-radius:10px;background:#0f5cc0;color:#fff;font-weight:700;cursor:pointer;">Bevestigen</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(passwordModal);

    var input = passwordModal.querySelector('#bp-plugin-guard-password');
    var toggle = passwordModal.querySelector('#bp-plugin-guard-toggle');
    if (toggle && input) {
      toggle.addEventListener('click', function () {
        var isPwd = input.getAttribute('type') === 'password';
        input.setAttribute('type', isPwd ? 'text' : 'password');
        toggle.textContent = isPwd ? 'Verberg' : 'Toon';
      });
    }
    return passwordModal;
  }

  function askPassword() {
    return new Promise(function (resolve) {
      var modal = ensurePasswordModal();
      var input = modal.querySelector('#bp-plugin-guard-password');
      var toggle = modal.querySelector('#bp-plugin-guard-toggle');
      var err = modal.querySelector('#bp-plugin-guard-error');
      var cancel = modal.querySelector('#bp-plugin-guard-cancel');
      var confirm = modal.querySelector('#bp-plugin-guard-confirm');

      function close(value) {
        if (err) err.textContent = '';
        if (input) {
          input.value = '';
          input.setAttribute('type', 'password');
        }
        if (toggle) toggle.textContent = 'Toon';
        modal.style.display = 'none';
        resolve(value);
      }

      if (cancel) {
        cancel.onclick = function () { close(''); };
      }
      if (confirm) {
        confirm.onclick = function () {
          var pw = input ? String(input.value || '') : '';
          if (!pw) {
            if (err) err.textContent = 'Vul je wachtwoord in.';
            return;
          }
          close(pw);
        };
      }
      if (input) {
        input.onkeydown = function (e) {
          if (e.key === 'Enter' && confirm) confirm.click();
          if (e.key === 'Escape' && cancel) cancel.click();
        };
      }

      modal.style.display = 'flex';
      if (input) input.focus();
    });
  }

  async function verifyPassword(password) {
    var body = new URLSearchParams();
    body.set('action', 'bp_core_verify_admin_password');
    body.set('nonce', nonce);
    body.set('password', password || '');
    var res = await fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });
    var json = null;
    try { json = await res.json(); } catch (e) { json = null; }
    if (!res.ok || !json || !json.success || !json.data || !json.data.guard_token) {
      throw new Error((json && json.data && json.data.message) ? String(json.data.message) : invalidPasswordText);
    }
    return String(json.data.guard_token);
  }

  async function getGuardTokenWithPrompt() {
    var password = await askPassword();
    if (!password) return '';
    try {
      return await verifyPassword(password);
    } catch (e) {
      window.alert(e && e.message ? e.message : invalidPasswordText);
      return '';
    }
  }

  document.addEventListener('click', function (ev) {
    var link = ev.target && ev.target.closest ? ev.target.closest('a[href*="plugins.php?action="]') : null;
    if (!link) return;
    var href = link.getAttribute('href') || '';
    if (!href) return;

    var plugins = getPluginsFromUrl(href);
    var hasProtected = plugins.some(isProtectedPlugin);
    if (!hasProtected) return;

    var isSensitiveAction = href.indexOf('action=deactivate') !== -1 || href.indexOf('action=delete-selected') !== -1;
    if (!isSensitiveAction) return;

    ev.preventDefault();
    (async function () {
      var token = await getGuardTokenWithPrompt();
      if (!token) return;
      window.location.href = addGuardTokenToUrl(href, token);
    })();
  }, true);

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.matches || !form.matches('form#bulk-action-form, form#posts-filter')) return;
    if (form.dataset.bpGuardSubmitting === '1') return;

    var action = getBulkAction(form);
    if (action !== 'deactivate-selected' && action !== 'delete-selected') return;
    var plugins = getCheckedPlugins(form);
    if (!plugins.length) return;
    var hasProtected = plugins.some(isProtectedPlugin);
    if (!hasProtected) return;

    ev.preventDefault();
    (async function () {
      var token = await getGuardTokenWithPrompt();
      if (!token) return;
      ensureGuardTokenField(form, token);
      form.dataset.bpGuardSubmitting = '1';
      form.submit();
    })();
  }, true);
})();
