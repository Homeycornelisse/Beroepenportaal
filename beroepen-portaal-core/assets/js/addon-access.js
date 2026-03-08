(function(){
  function $(sel, root){ return (root||document).querySelector(sel); }
  function $all(sel, root){ return Array.from((root||document).querySelectorAll(sel)); }

  function post(url, data){
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams(data).toString()
    }).then(r => r.json());
  }

  function renderTable(box, payload){
    const addons = payload.addons || [];
    const access = payload.access || {};

    const rows = addons.map(a => {
      const mode = access[a.slug] || 'inherit';
      const defTxt = a.default_allowed ? 'Rol: toegestaan' : 'Rol: geblokkeerd';

      return `
        <tr>
          <td style="width:38%"><strong>${escapeHtml(a.label)}</strong><br><span style="opacity:.75;font-size:12px">${escapeHtml(a.slug)}</span></td>
          <td style="width:20%"><span style="font-size:12px;opacity:.8">${escapeHtml(defTxt)}</span></td>
          <td>
            <label style="margin-right:12px;"><input type="radio" name="bp_access_${escapeAttr(a.slug)}" value="inherit" ${mode==='inherit'?'checked':''}> Rol</label>
            <label style="margin-right:12px;"><input type="radio" name="bp_access_${escapeAttr(a.slug)}" value="allow" ${mode==='allow'?'checked':''}> Toestaan</label>
            <label><input type="radio" name="bp_access_${escapeAttr(a.slug)}" value="deny" ${mode==='deny'?'checked':''}> Blokkeren</label>
          </td>
        </tr>
      `;
    }).join('');

    box.innerHTML = `
      <table class="widefat striped" style="max-width:1100px">
        <thead>
          <tr>
            <th>Add-on</th>
            <th>Standaard</th>
            <th>Override</th>
          </tr>
        </thead>
        <tbody>
          ${rows || '<tr><td colspan="3">Geen add-ons gevonden.</td></tr>'}
        </tbody>
      </table>
    `;
  }

  function collectAccess(box, addons){
    const out = {};
    addons.forEach(a => {
      const name = `bp_access_${a.slug}`;
      const picked = box.querySelector(`input[name="${CSS.escape(name)}"]:checked`);
      const val = picked ? picked.value : 'inherit';
      if (val === 'allow' || val === 'deny') out[a.slug] = val;
    });
    return out;
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function escapeAttr(s){
    return String(s).replace(/[^a-zA-Z0-9_\-]/g, '');
  }

  function init(){
    const root = document.querySelector('[data-bp-addon-access]');
    if (!root) return;

    const ajaxUrl = root.getAttribute('data-ajax') || '';
    const nonce   = root.getAttribute('data-nonce') || '';

    const userSel = $('#bp-addon-access-user', root);
    const tableBox = $('#bp-addon-access-table', root);
    const msgBox = $('#bp-addon-access-msg', root);
    const saveBtn = $('#bp-addon-access-save', root);

    let lastPayload = null;

    function setMsg(txt, ok){
      if (!msgBox) return;
      msgBox.textContent = txt || '';
      msgBox.style.display = txt ? 'block' : 'none';
      msgBox.style.borderColor = ok ? '#86efac' : '#fecaca';
      msgBox.style.background = ok ? '#f0fdf4' : '#fef2f2';
      msgBox.style.color = ok ? '#166534' : '#991b1b';
    }

    function load(){
      const uid = parseInt(userSel.value || '0', 10);
      if (!uid) {
        tableBox.innerHTML = '<p>Kies eerst een gebruiker.</p>';
        return;
      }
      setMsg('', true);
      tableBox.innerHTML = '<p>Laden…</p>';

      post(ajaxUrl, {
        action: 'bp_core_get_addon_access',
        nonce: nonce,
        user_id: uid
      }).then(res => {
        if (!res || !res.success) {
          setMsg((res && res.data && res.data.message) ? res.data.message : 'Oeps, laden mislukt.', false);
          tableBox.innerHTML = '';
          return;
        }
        lastPayload = res.data;
        renderTable(tableBox, res.data);
      }).catch(() => {
        setMsg('Oeps, laden mislukt.', false);
        tableBox.innerHTML = '';
      });
    }

    userSel.addEventListener('change', load);

    saveBtn.addEventListener('click', function(e){
      e.preventDefault();
      const uid = parseInt(userSel.value || '0', 10);
      if (!uid || !lastPayload) return;

      const access = collectAccess(tableBox, lastPayload.addons || []);
      setMsg('', true);
      saveBtn.disabled = true;
      saveBtn.textContent = 'Opslaan…';

      post(ajaxUrl, {
        action: 'bp_core_save_addon_access',
        nonce: nonce,
        user_id: uid,
        access: JSON.stringify(access)
      }).then(res => {
        if (!res || !res.success) {
          setMsg((res && res.data && res.data.message) ? res.data.message : 'Opslaan mislukt.', false);
          return;
        }
        setMsg('✅ Opgeslagen.', true);
        // refresh
        load();
      }).catch(() => {
        setMsg('Opslaan mislukt.', false);
      }).finally(() => {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Opslaan';
      });
    });

    // Fix: JSON stringify in x-www-form-urlencoded => server krijgt string
    // Daarom hier een kleine hack: fetch post zet alles als string.
    // Server decodeert JSON.

    load();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
