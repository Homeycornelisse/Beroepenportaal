(function () {
  'use strict';

  const cfg = window.BPSBCfg || {};
  if (!cfg.ajaxUrl || !cfg.nonce) return;

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

  async function upload(action, formData) {
    formData.append('action', action);
    formData.append('nonce', cfg.nonce);
    const res = await fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    });
    const json = await res.json();
    if (!res.ok || !json || !json.success) {
      throw new Error((json && json.data && json.data.message) ? json.data.message : 'Upload mislukt');
    }
    return json.data;
  }

  function escapeHtml(v) {
    return String(v || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setupLettersPage() {
    const app = document.getElementById('bp-sb-app');
    if (!app) return;
    expandToFullWidth(app);

    const elClient = document.getElementById('bp-sb-client');
    const elTemplate = document.getElementById('bp-sb-template');
    const elTemplateBtn = document.getElementById('bp-sb-template-btn');
    const elTitle = document.getElementById('bp-sb-title');
    const elCompany = document.getElementById('bp-sb-company');
    const elVacancy = document.getElementById('bp-sb-vacancy');
    const elRecruiter = document.getElementById('bp-sb-recruiter');
    const elDate = document.getElementById('bp-sb-date');
    const elContent = document.getElementById('bp-sb-content');
    const elSave = document.getElementById('bp-sb-save');
    const elExportDocx = document.getElementById('bp-sb-export-docx');
    const elExportForm = document.getElementById('bp-sb-export-form');
    const exClient = document.getElementById('bp-sb-export-client-id');
    const exTitle = document.getElementById('bp-sb-export-title');
    const exCompany = document.getElementById('bp-sb-export-company');
    const exVacancy = document.getElementById('bp-sb-export-vacancy');
    const exRecruiter = document.getElementById('bp-sb-export-recruiter');
    const exDate = document.getElementById('bp-sb-export-date');
    const exContent = document.getElementById('bp-sb-export-content');
    const elUploadTitle = document.getElementById('bp-sb-upload-title');
    const elUploadFile = document.getElementById('bp-sb-upload-file');
    const elUploadBtn = document.getElementById('bp-sb-upload-btn');
    const elList = document.getElementById('bp-sb-list-items');
    const elMsg = document.getElementById('bp-sb-msg');

    let state = null;
    let templates = [];

    function toDutchDate(value) {
      if (!value) return '-';
      if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        const parts = value.split('-');
        return `${parts[2]}-${parts[1]}-${parts[0]}`;
      }
      return value;
    }

    function renderPreview() {
      const map = {
        title: (elTitle && elTitle.value) ? elTitle.value : 'Sollicitatiebrief',
        company: (elCompany && elCompany.value) ? elCompany.value : '-',
        vacancy: (elVacancy && elVacancy.value) ? elVacancy.value : '-',
        recruiter: (elRecruiter && elRecruiter.value) ? elRecruiter.value : '-',
        date: toDutchDate((elDate && elDate.value) ? elDate.value : ''),
      };

      Object.keys(map).forEach((key) => {
        const nodes = app.querySelectorAll(`[data-bp-sb-pv="${key}"]`);
        nodes.forEach((n) => { n.textContent = map[key]; });
      });

      const body = String((elContent && elContent.value) ? elContent.value : '').trim();
      const target = app.querySelector('[data-bp-sb-pv="content"]');
      if (target) {
        if (!body) {
          target.innerHTML = '<p>Geachte heer/mevrouw,</p><p>Hier komt de inhoud van de sollicitatiebrief.</p>';
        } else {
          const paras = body.split(/\n{2,}/).map((p) => `<p>${escapeHtml(p).replace(/\n/g, '<br>')}</p>`).join('');
          target.innerHTML = paras;
        }
      }
    }

    function showMsg(text, isError) {
      if (!elMsg) return;
      elMsg.hidden = !text;
      elMsg.textContent = text || '';
      elMsg.style.borderColor = isError ? '#fca5a5' : '#d9e2f3';
      elMsg.style.color = isError ? '#991b1b' : '#0f172a';
      elMsg.style.background = isError ? '#fef2f2' : '#f8fafc';
    }

    function renderClients() {
      if (!elClient || !state || !Array.isArray(state.clients)) return;
      elClient.innerHTML = '';
      state.clients.forEach((c) => {
        const opt = document.createElement('option');
        opt.value = String(c.id);
        opt.textContent = String(c.name || ('Client ' + c.id));
        if (Number(c.id) === Number(state.selectedClientId)) opt.selected = true;
        elClient.appendChild(opt);
      });
      elClient.disabled = !state.canSelectClient;
    }

    function renderTemplateSelect() {
      if (!elTemplate) return;
      elTemplate.innerHTML = '<option value="">Kies een template</option>';
      templates.forEach((t) => {
        const opt = document.createElement('option');
        opt.value = String(t.id || '');
        opt.textContent = String(t.label || t.id || 'Template');
        elTemplate.appendChild(opt);
      });
    }

    function renderItems() {
      if (!elList) return;
      const items = Array.isArray(state && state.items) ? state.items : [];
      if (!items.length) {
        elList.innerHTML = '<div class="bp-sb-empty">Nog geen sollicitatiebrieven opgeslagen.</div>';
        return;
      }

      elList.innerHTML = '';
      items.forEach((item) => {
        const type = String(item.type || 'tekst');
        const tr = document.createElement('div');
        tr.className = 'bp-sb-list-item';

        const sizeLabel = type === 'bestand' && Number(item.file_size || 0) > 0
          ? ` · ${Math.round((Number(item.file_size) / 1024) * 10) / 10} KB`
          : '';

        tr.innerHTML =
          '<div class="bp-sb-item-top">' +
          '<div>' +
          `<div class="bp-sb-item-title">${escapeHtml(item.title || 'Sollicitatiebrief')}</div>` +
          `<div class="bp-sb-item-meta">${escapeHtml(item.company || '')}${item.vacancy ? ' · ' + escapeHtml(item.vacancy) : ''}</div>` +
          `<div class="bp-sb-item-meta">${type === 'bestand' ? 'Upload' : 'Tekst'}${sizeLabel}</div>` +
          '</div>' +
          '<div class="bp-sb-item-actions">' +
          `<button type="button" data-go="${escapeHtml(item.download_url || '')}" ${item.download_url ? '' : 'disabled'}>Download</button>` +
          `<button type="button" data-del="${Number(item.id || 0)}">Verwijder</button>` +
          '</div>' +
          '</div>';

        const goBtn = tr.querySelector('button[data-go]');
        if (goBtn) {
          goBtn.addEventListener('click', function () {
            const url = String(goBtn.getAttribute('data-go') || '');
            if (!url) return;
            window.location.href = url;
          });
        }

        const del = tr.querySelector('button[data-del]');
        if (del) {
          del.addEventListener('click', async function () {
            const id = Number(del.getAttribute('data-del') || 0);
            if (!id) return;
            if (!window.confirm('Item verwijderen?')) return;
            try {
              await post('bp_sb_delete_item', { item_id: id });
              await loadState(Number(elClient.value || 0));
              showMsg('Item verwijderd.', false);
            } catch (e) {
              showMsg(e.message || 'Verwijderen mislukt.', true);
            }
          });
        }

        elList.appendChild(tr);
      });
    }

    async function loadState(clientId) {
      state = await post('bp_sb_bootstrap', { client_id: clientId || 0 });
      templates = Array.isArray(state.templates) ? state.templates : [];
      renderClients();
      renderTemplateSelect();
      renderItems();
    }

    async function saveText() {
      const clientId = Number(elClient.value || 0);
      if (!clientId) return showMsg('Selecteer eerst een cliënt.', true);
      if (!String(elContent.value || '').trim()) return showMsg('Vul eerst de brieftekst in.', true);

      try {
        await post('bp_sb_save_text', {
          client_id: clientId,
          title: elTitle.value || '',
          company: elCompany.value || '',
          vacancy: elVacancy.value || '',
          content: elContent.value || ''
        });
        elTitle.value = '';
        elCompany.value = '';
        elVacancy.value = '';
        elContent.value = '';
        await loadState(clientId);
        showMsg('Sollicitatiebrief opgeslagen.', false);
      } catch (e) {
        showMsg(e.message || 'Opslaan mislukt.', true);
      }
    }

    async function uploadFile() {
      const clientId = Number(elClient.value || 0);
      const file = elUploadFile && elUploadFile.files && elUploadFile.files[0] ? elUploadFile.files[0] : null;
      if (!clientId) return showMsg('Selecteer eerst een cliënt.', true);
      if (!file) return showMsg('Kies eerst een bestand.', true);

      try {
        const formData = new FormData();
        formData.append('client_id', String(clientId));
        formData.append('title', String(elUploadTitle.value || ''));
        formData.append('company', String(elCompany.value || ''));
        formData.append('vacancy', String(elVacancy.value || ''));
        formData.append('file', file);
        await upload('bp_sb_upload_file', formData);

        if (elUploadFile) elUploadFile.value = '';
        if (elUploadTitle) elUploadTitle.value = '';
        await loadState(clientId);
        showMsg('Bestand veilig opgeslagen.', false);
      } catch (e) {
        showMsg(e.message || 'Upload mislukt.', true);
      }
    }

    if (elSave) elSave.addEventListener('click', saveText);
    if (elExportDocx && elExportForm) {
      elExportDocx.addEventListener('click', function () {
        const clientId = Number(elClient.value || 0);
        if (!clientId) return showMsg('Selecteer eerst een cliënt.', true);
        if (!String(elContent.value || '').trim()) return showMsg('Vul eerst de brieftekst in.', true);

        if (exClient) exClient.value = String(clientId);
        if (exTitle) exTitle.value = String(elTitle.value || 'Sollicitatiebrief');
        if (exCompany) exCompany.value = String(elCompany.value || '');
        if (exVacancy) exVacancy.value = String(elVacancy.value || '');
        if (exRecruiter) exRecruiter.value = String((elRecruiter && elRecruiter.value) || '');
        if (exDate) exDate.value = String((elDate && elDate.value) || '');
        if (exContent) exContent.value = String(elContent.value || '');
        elExportForm.submit();
      });
    }
    if (elUploadBtn) elUploadBtn.addEventListener('click', uploadFile);
    if (elClient) {
      elClient.addEventListener('change', function () {
        loadState(Number(elClient.value || 0)).catch((e) => showMsg(e.message || 'Laden mislukt.', true));
      });
    }
    if (elTemplateBtn) {
      elTemplateBtn.addEventListener('click', function () {
        const key = String(elTemplate.value || '');
        const tpl = templates.find((t) => String(t.id || '') === key);
        if (!tpl || !tpl.body) return;
        elContent.value = String(tpl.body);
        renderPreview();
        showMsg('Template geplaatst. Pas de tekst aan op cliënt en vacature.', false);
      });
    }

    [elTitle, elCompany, elVacancy, elRecruiter, elDate, elContent].forEach((node) => {
      if (!node) return;
      node.addEventListener('input', renderPreview);
      node.addEventListener('change', renderPreview);
    });

    const now = new Date();
    if (elDate && !elDate.value) {
      const mm = String(now.getMonth() + 1).padStart(2, '0');
      const dd = String(now.getDate()).padStart(2, '0');
      elDate.value = `${now.getFullYear()}-${mm}-${dd}`;
    }
    renderPreview();

    loadState(0).catch((e) => showMsg(e.message || 'Laden mislukt.', true));
  }

  function setupTemplateManagerPage() {
    const app = document.getElementById('bp-sb-templates-app');
    if (!app) return;
    expandToFullWidth(app);

    const listNode = document.getElementById('bp-sb-tpl-list');
    const btnAdd = document.getElementById('bp-sb-tpl-add');
    const btnSave = document.getElementById('bp-sb-tpl-save');
    const msg = document.getElementById('bp-sb-tpl-msg');

    let templates = [];

    function showMsg(text, isError) {
      if (!msg) return;
      msg.hidden = !text;
      msg.textContent = text || '';
      msg.style.borderColor = isError ? '#fca5a5' : '#d9e2f3';
      msg.style.color = isError ? '#991b1b' : '#0f172a';
      msg.style.background = isError ? '#fef2f2' : '#f8fafc';
    }

    function render() {
      if (!listNode) return;
      if (!templates.length) {
        listNode.innerHTML = '<div class="bp-sb-empty">Nog geen templates.</div>';
        return;
      }
      listNode.innerHTML = '';
      templates.forEach((t, idx) => {
        const row = document.createElement('div');
        row.className = 'bp-sb-template-item';
        row.innerHTML =
          '<div class="bp-sb-row"><label>Template ID</label><input type="text" data-k="id" value="' + escapeHtml(t.id || '') + '"></div>' +
          '<div class="bp-sb-row"><label>Label</label><input type="text" data-k="label" value="' + escapeHtml(t.label || '') + '"></div>' +
          '<div class="bp-sb-row"><label>Inhoud</label><textarea rows="8" data-k="body">' + escapeHtml(t.body || '') + '</textarea></div>' +
          '<div class="bp-sb-actions"><button type="button" data-remove="' + idx + '">Verwijderen</button></div>';
        listNode.appendChild(row);

        const removeBtn = row.querySelector('button[data-remove]');
        if (removeBtn) {
          removeBtn.addEventListener('click', function () {
            templates.splice(idx, 1);
            render();
          });
        }
      });
    }

    function collect() {
      const out = [];
      const rows = listNode ? listNode.querySelectorAll('.bp-sb-template-item') : [];
      rows.forEach((row) => {
        const id = String((row.querySelector('[data-k="id"]') || {}).value || '').trim();
        const label = String((row.querySelector('[data-k="label"]') || {}).value || '').trim();
        const body = String((row.querySelector('[data-k="body"]') || {}).value || '');
        if (!id || !label || !body.trim()) return;
        out.push({ id, label, body });
      });
      return out;
    }

    async function bootstrap() {
      const data = await post('bp_sb_templates_bootstrap', {});
      templates = Array.isArray(data.templates) ? data.templates : [];
      render();
    }

    if (btnAdd) {
      btnAdd.addEventListener('click', function () {
        templates.push({ id: 'nieuw_template', label: 'Nieuwe template', body: '' });
        render();
      });
    }
    if (btnSave) {
      btnSave.addEventListener('click', async function () {
        try {
          const payload = collect();
          if (!payload.length) {
            showMsg('Minimaal 1 template met inhoud is verplicht.', true);
            return;
          }
          const data = await post('bp_sb_templates_save', { templates: JSON.stringify(payload) });
          templates = Array.isArray(data.templates) ? data.templates : payload;
          render();
          showMsg('Templates opgeslagen.', false);
        } catch (e) {
          showMsg(e.message || 'Opslaan mislukt.', true);
        }
      });
    }

    bootstrap().catch((e) => showMsg(e.message || 'Templates laden mislukt.', true));
  }

  setupLettersPage();
  setupTemplateManagerPage();

  function expandToFullWidth(root) {
    let node = root;
    let depth = 0;
    while (node && node.parentElement && depth < 6) {
      node = node.parentElement;
      depth++;
      if (!node || !node.style) continue;
      const cls = String(node.className || '');
      if (
        cls.indexOf('wp-block-post-content') !== -1 ||
        cls.indexOf('entry-content') !== -1 ||
        cls.indexOf('kb-wrap') !== -1 ||
        cls.indexOf('content-area') !== -1
      ) {
        node.style.maxWidth = 'none';
        node.style.width = '100%';
      }
    }
  }
})();
