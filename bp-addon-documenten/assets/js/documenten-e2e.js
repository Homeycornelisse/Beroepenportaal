(function () {
  'use strict';

  const app = document.getElementById('bp-docs-app');
  if (!app) return;

  const cfg = window.BPDocsCfg || {};
  if (!cfg.ajaxUrl || !cfg.nonce || !window.crypto || !window.crypto.subtle) return;

  const me = Number(cfg.userId || 0);
  if (!me) return;

  const E2E_PREFIX = 'e2e:v1:';
  const STORAGE_BUNDLE_KEY = `bp_docs_e2e_bundle_${me}`;
  const PBKDF2_ITERATIONS = 210000;
  const PBKDF2_HASH = 'SHA-256';

  const clientSelect = document.getElementById('bp-docs-client-select');
  const sortSelect = document.getElementById('bp-docs-sort');
  const foldersNode = document.getElementById('bp-docs-folders');
  const tableBody = document.getElementById('bp-docs-table-body');
  const unlockBtn = document.getElementById('bp-docs-unlock');
  const uploadBtn = document.getElementById('bp-docs-upload-btn');
  const uploadInput = document.getElementById('bp-docs-upload');
  const newFolderBtn = document.getElementById('bp-docs-new-folder');
  const alertNode = document.getElementById('bp-docs-alert');

  let state = null;
  let selectedFolderId = 0;
  let privateKey = null;
  let keyring = [];
  let unlocked = false;
  let passwordModal = null;

  function setUnlockedState(on) {
    unlocked = !!on;
    uploadBtn.disabled = !unlocked;
    newFolderBtn.disabled = !unlocked;
    unlockBtn.textContent = unlocked ? 'Ontgrendeld' : 'Ontgrendel';
  }

  function showAlert(msg) {
    if (!alertNode) return;
    alertNode.hidden = !msg;
    alertNode.textContent = msg || '';
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

  function ensurePasswordModal() {
    if (passwordModal) return passwordModal;

    const wrap = document.createElement('div');
    wrap.style.cssText = [
      'position:fixed',
      'inset:0',
      'z-index:1000000',
      'background:rgba(2,6,23,.72)',
      'display:none',
      'align-items:center',
      'justify-content:center',
      'padding:16px'
    ].join(';');

    wrap.innerHTML =
      '<div style="width:100%;max-width:430px;background:#fff;border:1px solid #d6deef;border-radius:14px;padding:16px;box-shadow:0 12px 28px rgba(2,6,23,.32);">'
      + '<h3 id="bp-docs-pw-title" style="margin:0 0 8px;color:#0f2f67;font-size:20px;font-weight:800;">Ontgrendelen</h3>'
      + '<p id="bp-docs-pw-msg" style="margin:0 0 10px;color:#475569;font-size:13px;line-height:1.4;"></p>'
      + '<div style="display:flex;gap:8px;align-items:center;">'
      + '<input id="bp-docs-pw-input" type="password" autocomplete="current-password" autocapitalize="off" autocorrect="off" spellcheck="false" style="flex:1;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:14px;-webkit-text-security:disc;">'
      + '<button type="button" id="bp-docs-pw-toggle" style="border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:9px 10px;cursor:pointer;">Toon</button>'
      + '</div>'
      + '<div id="bp-docs-pw-error" style="min-height:18px;color:#b91c1c;font-size:13px;margin-top:8px;"></div>'
      + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px;">'
      + '<button type="button" id="bp-docs-pw-cancel" style="border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:8px 10px;cursor:pointer;">Annuleren</button>'
      + '<button type="button" id="bp-docs-pw-ok" style="border:1px solid #1f5dc1;background:#1f5dc1;color:#fff;border-radius:10px;padding:8px 12px;cursor:pointer;font-weight:700;">Doorgaan</button>'
      + '</div>'
      + '</div>';

    document.body.appendChild(wrap);
    passwordModal = wrap;

    const input = wrap.querySelector('#bp-docs-pw-input');
    const toggle = wrap.querySelector('#bp-docs-pw-toggle');

    if (toggle && input) {
      toggle.addEventListener('click', function () {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        input.style.webkitTextSecurity = show ? 'none' : 'disc';
        toggle.textContent = show ? 'Verberg' : 'Toon';
      });
    }

    return wrap;
  }

  async function askPassword(title, message) {
    const modal = ensurePasswordModal();
    const titleNode = modal.querySelector('#bp-docs-pw-title');
    const msgNode = modal.querySelector('#bp-docs-pw-msg');
    const input = modal.querySelector('#bp-docs-pw-input');
    const err = modal.querySelector('#bp-docs-pw-error');
    const cancel = modal.querySelector('#bp-docs-pw-cancel');
    const ok = modal.querySelector('#bp-docs-pw-ok');
    const toggle = modal.querySelector('#bp-docs-pw-toggle');

    titleNode.textContent = title || 'Wachtwoord';
    msgNode.textContent = message || '';
    err.textContent = '';
    input.value = '';
    input.type = 'password';
    input.style.webkitTextSecurity = 'disc';
    if (toggle) toggle.textContent = 'Toon';

    return new Promise((resolve) => {
      function close(value) {
        modal.style.display = 'none';
        resolve(value);
      }

      cancel.onclick = () => close(null);
      ok.onclick = () => {
        const v = String(input.value || '');
        if (!v) {
          err.textContent = 'Vul je wachtwoord in.';
          return;
        }
        close(v);
      };

      input.onkeydown = (ev) => {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          ok.click();
        }
        if (ev.key === 'Escape') {
          ev.preventDefault();
          cancel.click();
        }
      };

      modal.style.display = 'flex';
      input.focus();
    });
  }

  async function deriveMasterKey(password, saltB64, usages) {
    const salt = new Uint8Array(fromB64(saltB64));
    const passBytes = new TextEncoder().encode(password);
    const base = await crypto.subtle.importKey('raw', passBytes, 'PBKDF2', false, ['deriveKey']);
    return crypto.subtle.deriveKey(
      { name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: PBKDF2_HASH },
      base,
      { name: 'AES-GCM', length: 256 },
      false,
      usages
    );
  }

  async function encryptJwkWithPassword(jwkObject, password) {
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const saltB64 = toB64(salt.buffer);
    const key = await deriveMasterKey(password, saltB64, ['encrypt']);
    const plain = new TextEncoder().encode(JSON.stringify(jwkObject));
    const cipher = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plain);
    return {
      salt: saltB64,
      iv: toB64(iv.buffer),
      cipher: toB64(cipher),
      iterations: PBKDF2_ITERATIONS,
      hash: PBKDF2_HASH,
    };
  }

  async function decryptJwkWithPassword(blob, password) {
    if (!blob || !blob.salt || !blob.iv || !blob.cipher) throw new Error('bad blob');
    if (Number(blob.iterations || 0) !== PBKDF2_ITERATIONS) throw new Error('invalid iterations');
    if (String(blob.hash || '') !== PBKDF2_HASH) throw new Error('invalid hash');
    const key = await deriveMasterKey(password, blob.salt, ['decrypt']);
    const iv = new Uint8Array(fromB64(blob.iv));
    const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, fromB64(blob.cipher));
    return JSON.parse(new TextDecoder().decode(plain));
  }

  async function importPrivate(jwk) {
    return crypto.subtle.importKey('jwk', jwk, { name: 'RSA-OAEP', hash: 'SHA-256' }, true, ['decrypt']);
  }

  async function importPublic(jwk) {
    return crypto.subtle.importKey('jwk', jwk, { name: 'RSA-OAEP', hash: 'SHA-256' }, true, ['encrypt']);
  }

  async function post(action, data) {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', cfg.nonce);
    Object.entries(data || {}).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      body.set(k, String(v));
    });

    const res = await fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    });

    const json = await res.json();
    if (!res.ok || !json || !json.success) {
      throw new Error((json && json.data && json.data.message) ? json.data.message : 'Verzoek mislukt');
    }
    return json.data;
  }

  async function verifyAccountPassword(password) {
    await post('bp_docs_verify_account_password', { password: password || '' });
  }

  async function uploadPublicKey(publicJwk) {
    await post('bp_docs_e2e_public_key', { public_key: JSON.stringify(publicJwk) });
  }

  async function fingerprintOf(jwk) {
    const canonical = JSON.stringify({
      kty: 'RSA',
      n: String(jwk.n || ''),
      e: String(jwk.e || ''),
      alg: 'RSA-OAEP-256',
      ext: true,
      key_ops: ['encrypt']
    });
    const bytes = new TextEncoder().encode(canonical);
    const hash = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(hash)).map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  async function generateAndStoreBundle(password) {
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

    saveJSON(STORAGE_BUNDLE_KEY, {
      version: 1,
      current: encryptedCurrent,
      keyring: []
    });

    await uploadPublicKey(publicJwk);

    if (state) {
      state.publicKeys = state.publicKeys || {};
      state.publicKeyFingerprints = state.publicKeyFingerprints || {};
      state.publicKeys[String(me)] = publicJwk;
      state.publicKeyFingerprints[String(me)] = (await fingerprintOf(publicJwk)).toLowerCase();
    }
  }

  async function unlockVault() {
    try {
      let bundle = loadJSON(STORAGE_BUNDLE_KEY, null);

      if (!bundle || !bundle.current) {
        const setupPass = await askPassword('Documentenkluis instellen', 'Voer je accountwachtwoord in om beveiligde opslag te activeren.');
        if (!setupPass) return false;
        await verifyAccountPassword(setupPass);
        await generateAndStoreBundle(setupPass);
        bundle = loadJSON(STORAGE_BUNDLE_KEY, null);
      }

      const pass = await askPassword('Documenten ontgrendelen', 'Voer je accountwachtwoord in.');
      if (!pass) return false;
      await verifyAccountPassword(pass);

      let currentJwk = null;
      try {
        currentJwk = await decryptJwkWithPassword(bundle.current, pass);
      } catch (e) {
        const recreate = window.confirm('De lokale beveiligingskluis past niet bij je accountwachtwoord. Opnieuw aanmaken? Let op: eerder versleutelde documenten op dit apparaat kunnen dan onleesbaar worden.');
        if (!recreate) {
          throw new Error('Ontgrendelen afgebroken.');
        }
        localStorage.removeItem(STORAGE_BUNDLE_KEY);
        await generateAndStoreBundle(pass);
        bundle = loadJSON(STORAGE_BUNDLE_KEY, null);
        if (!bundle || !bundle.current) {
          throw new Error('Nieuwe beveiligingskluis kon niet worden aangemaakt.');
        }
        currentJwk = await decryptJwkWithPassword(bundle.current, pass);
      }

      privateKey = await importPrivate(currentJwk);
      keyring = [];

      const oldKeys = Array.isArray(bundle.keyring) ? bundle.keyring : [];
      for (const item of oldKeys) {
        if (!item || !item.encrypted) continue;
        try {
          const jwk = await decryptJwkWithPassword(item.encrypted, pass);
          keyring.push(await importPrivate(jwk));
        } catch (e) {}
      }

      setUnlockedState(true);
      showAlert('');
      await loadState(state ? state.selectedClientId : 0);
      return true;
    } catch (e) {
      setUnlockedState(false);
      showAlert(e && e.message ? e.message : 'Ontgrendelen mislukt.');
      return false;
    }
  }

  function parsePayload(payloadText) {
    if (!payloadText || payloadText.indexOf(E2E_PREFIX) !== 0) throw new Error('payload');
    return JSON.parse(atob(payloadText.substring(E2E_PREFIX.length)));
  }

  async function decryptPayloadWithKey(pk, payloadText) {
    const json = parsePayload(payloadText);
    const wrapped = json.keys && json.keys[String(me)];
    if (!wrapped) return null;
    const rawAes = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, pk, fromB64(wrapped));
    const aes = await crypto.subtle.importKey('raw', rawAes, { name: 'AES-GCM' }, false, ['decrypt']);
    const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: new Uint8Array(fromB64(json.iv)) }, aes, fromB64(json.ct));
    return pt;
  }

  async function decryptPayload(payloadText, asJson) {
    if (!unlocked || !privateKey) return null;

    try {
      const plain = await decryptPayloadWithKey(privateKey, payloadText);
      if (plain) return asJson ? JSON.parse(new TextDecoder().decode(plain)) : plain;
    } catch (e) {}

    for (const oldKey of keyring) {
      try {
        const plain = await decryptPayloadWithKey(oldKey, payloadText);
        if (plain) return asJson ? JSON.parse(new TextDecoder().decode(plain)) : plain;
      } catch (e) {}
    }

    return null;
  }

  async function encryptPayloadForRecipients(recipientIds, dataBuffer) {
    const allIds = Array.from(new Set((recipientIds || []).map((v) => Number(v)).filter((v) => v > 0)));
    if (!allIds.includes(me)) allIds.push(me);

    const keys = {};
    const keyfps = {};
    const missing = [];

    const aes = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, aes, dataBuffer);
    const rawAes = await crypto.subtle.exportKey('raw', aes);

    for (const id of allIds) {
      const jwk = state.publicKeys[String(id)];
      const fp = state.publicKeyFingerprints[String(id)] || '';
      if (!jwk || !fp) {
        missing.push(id);
        continue;
      }

      const pub = await importPublic(jwk);
      const wrapped = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, pub, rawAes);
      keys[String(id)] = toB64(wrapped);
      keyfps[String(id)] = String(fp).toLowerCase();
    }

    if (!keys[String(me)]) {
      throw new Error('Lokale beveiligingsgegevens ontbreken. Klik opnieuw op ontgrendelen.');
    }

    if (missing.length) {
      showAlert('Niet alle betrokken gebruikers zijn nog geactiveerd voor beveiligde toegang. Opslag gaat wel door.');
    }

    const payload = {
      v: 1,
      alg: 'RSA-OAEP-256/A256GCM',
      iv: toB64(iv.buffer),
      ct: toB64(ct),
      keys,
      keyfps,
      ts: Date.now()
    };

    return E2E_PREFIX + btoa(JSON.stringify(payload));
  }

  async function loadState(clientId) {
    const data = await post('bp_docs_bootstrap', { client_id: clientId || 0 });
    state = data;
    if (!state || !Array.isArray(state.clients)) throw new Error('status data ongeldig');
    selectedFolderId = 0;
    renderClientSelect();
    await renderAll();
    if (Array.isArray(state.missingKeyUsers) && state.missingKeyUsers.length) {
      showAlert('');
    }
  }

  function renderClientSelect() {
    if (!clientSelect) return;
    const list = Array.isArray(state.clients) ? state.clients : [];
    clientSelect.innerHTML = '';
    list.forEach((c) => {
      const opt = document.createElement('option');
      opt.value = String(c.id);
      opt.textContent = String(c.name || `Cliënt ${c.id}`);
      if (Number(c.id) === Number(state.selectedClientId)) opt.selected = true;
      clientSelect.appendChild(opt);
    });
    clientSelect.disabled = !state.canSelectClient;
  }

  async function folderViewModel() {
    const folders = Array.isArray(state.folders) ? state.folders : [];
    const docs = Array.isArray(state.documents) ? state.documents : [];
    const resolved = [];

    for (const f of folders) {
      let title = '[Vergrendelde map]';
      if (Number(f.virtual || 0) === 1 || Number(f.is_external || 0) === 1) {
        title = String(f.plain_name || 'Map');
      } else if (unlocked) {
        const dec = await decryptPayload(String(f.name_payload || ''), true);
        if (dec && dec.name) title = String(dec.name);
      }
      const id = Number(f.id || 0);
      const count = docs.filter((d) => Number(d.folder_id || 0) === id).length;
      resolved.push({ id, title, count, ownerType: String(f.owner_type || '') });
    }

    return resolved;
  }

  async function renderFolders() {
    if (!foldersNode) return;
    const models = await folderViewModel();
    foldersNode.innerHTML = '';

    const allBtn = document.createElement('button');
    allBtn.type = 'button';
    allBtn.className = 'bp-docs-folder' + (selectedFolderId === 0 ? ' active' : '');
    allBtn.innerHTML = 'Alle documenten <span class="count">' + (state.documents || []).length + '</span>';
    allBtn.addEventListener('click', function () { selectedFolderId = 0; renderDocuments(); renderFolders(); });
    foldersNode.appendChild(allBtn);

    const standard = models.filter((f) => f.ownerType !== 'begeleider' || f.id === -1);
    const begeleider = models.filter((f) => f.ownerType === 'begeleider' && f.id !== -1);

    function appendSection(title, list) {
      if (!list.length) return;
      const head = document.createElement('div');
      head.style.cssText = 'font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px;';
      head.textContent = title;
      foldersNode.appendChild(head);
      list.forEach(function (f) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bp-docs-folder' + (selectedFolderId === f.id ? ' active' : '');
        btn.innerHTML = `${escapeHtml(f.title)} <span class="count">${f.count}</span>`;
        btn.addEventListener('click', function () { selectedFolderId = f.id; renderDocuments(); renderFolders(); });
        btn.addEventListener('contextmenu', async function (ev) {
          ev.preventDefault();
          if (f.id < 0) return;
          if (!confirm('Map verwijderen? Documenten blijven bewaard zonder map.')) return;
          try {
            await post('bp_docs_delete_folder', { folder_id: f.id });
            await loadState(state.selectedClientId);
          } catch (e) {
            showAlert(e.message || 'Map verwijderen mislukt.');
          }
        });
        foldersNode.appendChild(btn);
      });
    }

    appendSection('Standaard', standard);
    appendSection('Mappen begeleider', begeleider);
  }

  function sortDocs(list) {
    const v = String(sortSelect ? sortSelect.value : 'date_desc');
    return list.sort((a, b) => {
      if (v === 'date_asc') return a.created - b.created;
      if (v === 'name_asc') return a.name.localeCompare(b.name, 'nl');
      if (v === 'name_desc') return b.name.localeCompare(a.name, 'nl');
      return b.created - a.created;
    });
  }

  async function renderDocuments() {
    if (!tableBody) return;

    const rows = [];
    for (const d of (state.documents || [])) {
      const folderId = Number(d.folder_id || 0);
      if (selectedFolderId > 0 && folderId !== selectedFolderId) continue;

      let name = '[Vergrendeld document]';
      let bytes = 0;
      const isExternal = Number(d.is_external || 0) === 1;
      const externalUrl = String(d.download_url || '');
      const canDelete = !isExternal;

      if (isExternal) {
        name = String(d.plain_name || 'Document');
        bytes = Number(d.plain_size || 0) || 0;
      } else if (unlocked) {
        const meta = await decryptPayload(String(d.meta_payload || ''), true);
        if (meta) {
          if (meta.name) name = String(meta.name);
          if (meta.size) bytes = Number(meta.size) || 0;
        }
      }

      rows.push({
        id: Number(d.id || 0),
        name,
        size: bytes,
        created: Date.parse(String(d.created_at || '')) || 0,
        isExternal,
        externalUrl,
        canDelete
      });
    }

    sortDocs(rows);

    tableBody.innerHTML = '';
    if (!rows.length) {
      tableBody.innerHTML = '<tr><td colspan="4" class="empty">Geen documenten in deze map.</td></tr>';
      return;
    }

    rows.forEach((row) => {
      const tr = document.createElement('tr');
      tr.className = 'bp-docs-row';
      tr.innerHTML = [
        `<td>${escapeHtml(row.name)}</td>`,
        `<td>${row.size ? formatBytes(row.size) : '-'}</td>`,
        `<td>${row.created ? new Date(row.created).toLocaleString('nl-NL') : '-'}</td>`,
        '<td><div class="bp-docs-row-actions">'
          + '<button type="button" class="bp-docs-btn" data-act="download">Download</button>'
          + (row.canDelete ? '<button type="button" class="bp-docs-btn" data-act="delete">Verwijder</button>' : '')
          + '</div></td>'
      ].join('');

      tr.querySelector('[data-act="download"]').addEventListener('click', function () {
        downloadDocument(row.id, row);
      });
      const deleteBtn = tr.querySelector('[data-act="delete"]');
      if (deleteBtn) {
        deleteBtn.addEventListener('click', async function () {
          if (!confirm('Document verwijderen?')) return;
          try {
            await post('bp_docs_delete_document', { doc_id: row.id });
            await loadState(state.selectedClientId);
          } catch (e) {
            showAlert(e.message || 'Verwijderen mislukt.');
          }
        });
      }
      tableBody.appendChild(tr);
    });
  }

  async function renderAll() {
    await renderFolders();
    await renderDocuments();
  }

  function formatBytes(bytes) {
    const n = Number(bytes || 0);
    if (!n) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let v = n;
    let i = 0;
    while (v >= 1024 && i < units.length - 1) {
      v /= 1024;
      i++;
    }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }

  function escapeHtml(v) {
    return String(v || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  async function createFolder() {
    if (!unlocked) {
      showAlert('Ontgrendel eerst de kluis.');
      return;
    }

    const name = window.prompt('Mapnaam', 'Nieuwe map');
    if (!name) return;

    try {
      const payload = await encryptPayloadForRecipients(state.recipientIds || [], new TextEncoder().encode(JSON.stringify({ name })));
      await post('bp_docs_create_folder', {
        client_id: state.selectedClientId,
        name_payload: payload
      });
      await loadState(state.selectedClientId);
    } catch (e) {
      showAlert(e.message || 'Map aanmaken mislukt.');
    }
  }

  async function uploadFile(file) {
    if (!file) return;
    if (!unlocked) {
      showAlert('Ontgrendel eerst de kluis.');
      return;
    }

    const sizeLimit = 8 * 1024 * 1024;
    if (file.size > sizeLimit) {
      showAlert('Bestand is te groot. Maximum is 8 MB per document.');
      return;
    }

    try {
      const buffer = await file.arrayBuffer();
      const metaJson = JSON.stringify({ name: file.name, type: file.type || 'application/octet-stream', size: file.size });

      const metaPayload = await encryptPayloadForRecipients(state.recipientIds || [], new TextEncoder().encode(metaJson));
      const filePayload = await encryptPayloadForRecipients(state.recipientIds || [], buffer);

      await post('bp_docs_upload_document', {
        client_id: state.selectedClientId,
        folder_id: selectedFolderId > 0 ? selectedFolderId : 0,
        meta_payload: metaPayload,
        file_payload: filePayload
      });

      await loadState(state.selectedClientId);
      showAlert('');
    } catch (e) {
      showAlert(e.message || 'Upload mislukt.');
    }
  }

  async function downloadDocument(docId, row) {
    if (row && row.isExternal) {
      if (!row.externalUrl) {
        showAlert('Downloadlink ontbreekt voor dit externe document.');
        return;
      }
      window.location.href = row.externalUrl;
      return;
    }

    if (!unlocked) {
      showAlert('Ontgrendel eerst de kluis.');
      return;
    }

    try {
      const data = await post('bp_docs_get_document_payload', { doc_id: docId });
      const meta = await decryptPayload(String(data.metaPayload || ''), true);
      const fileBuf = await decryptPayload(String(data.filePayload || ''), false);
      if (!meta || !fileBuf) throw new Error('Decryptie mislukt.');

      const blob = new Blob([fileBuf], { type: meta.type || 'application/octet-stream' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = String(meta.name || `document-${docId}`);
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    } catch (e) {
      showAlert(e.message || 'Download mislukt.');
    }
  }

  unlockBtn.addEventListener('click', unlockVault);

  uploadBtn.addEventListener('click', function () {
    uploadInput.click();
  });

  uploadInput.addEventListener('change', function () {
    const f = uploadInput.files && uploadInput.files[0] ? uploadInput.files[0] : null;
    uploadInput.value = '';
    if (f) uploadFile(f);
  });

  newFolderBtn.addEventListener('click', createFolder);

  clientSelect.addEventListener('change', function () {
    loadState(Number(clientSelect.value || 0)).catch((e) => showAlert(e.message || 'Laden mislukt.'));
  });

  sortSelect.addEventListener('change', renderDocuments);

  setUnlockedState(false);
  loadState(0).catch((e) => showAlert(e.message || 'Laden mislukt.'));
})();
