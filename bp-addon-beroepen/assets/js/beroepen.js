(() => {
  'use strict';

  const esc = (value) => String(value == null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const appConfig = window.BPBeroepen || {};
  const DATA = (typeof BEROEPEN_DATA !== 'undefined' && Array.isArray(BEROEPEN_DATA)) ? BEROEPEN_DATA : [];
  const strings = (appConfig && typeof appConfig.strings === 'object' && appConfig.strings) ? appConfig.strings : {};

  const byName = new Map(DATA.map((b) => [String(b.n || ''), b]));

  document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-bp-beroepen-app]');
    if (!root) return;

    const mode = String(root.dataset.mode || appConfig.mode || 'client');
    if (mode === 'begeleider') {
      initBegeleider(root);
      return;
    }

    initClient(root);
  });

  function apiFetch(path, options = {}) {
    const restBase = String(appConfig.restUrl || '').replace(/\/$/, '');
    if (!restBase) return Promise.reject(new Error('Missing rest base'));

    const nonce = String(appConfig.nonce || '');
    const headers = {
      ...(options.headers || {}),
      ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
    };

    return fetch(`${restBase}${path}`, {
      credentials: 'same-origin',
      ...options,
      headers,
    });
  }

  function initClient(root) {
    applyPdfLayoutConfig(root);
    const gridEl = root.querySelector('#bp-beroepen-grid');
    const sectorEl = root.querySelector('#bp-beroepen-sector');
    const niveauEl = root.querySelector('#bp-beroepen-niveau');
    const weergaveEl = root.querySelector('#bp-beroepen-weergave');
    const zoekEl = root.querySelector('#bp-beroepen-zoek');
    const resetEl = root.querySelector('#bp-beroepen-reset');
    const printEl = root.querySelector('#bp-beroepen-print-btn');
    const counterEl = root.querySelector('#bp-beroepen-counter');

    if (!gridEl || !sectorEl || !niveauEl || !weergaveEl || !resetEl || !counterEl) return;

    let selections = {};
    const timers = new Map();

    let filterSector = '';
    let filterNiveau = '';
    let filterWeergave = 'all';
    let filterZoek = '';

    const allSectors = [...new Set(DATA.map((item) => String(item.s || '')))]
      .filter(Boolean)
      .sort((a, b) => a.localeCompare(b, 'nl'));

    for (const sector of allSectors) {
      const option = document.createElement('option');
      option.value = sector;
      option.textContent = sector;
      sectorEl.appendChild(option);
    }

    sectorEl.addEventListener('change', () => {
      filterSector = sectorEl.value;
      render();
    });

    niveauEl.addEventListener('change', () => {
      filterNiveau = niveauEl.value;
      render();
    });

    weergaveEl.addEventListener('change', () => {
      filterWeergave = weergaveEl.value;
      render();
    });

    if (zoekEl) {
      zoekEl.addEventListener('input', () => {
        filterZoek = String(zoekEl.value || '').trim().toLowerCase();
        render();
      });
    }

    resetEl.addEventListener('click', () => {
      filterSector = '';
      filterNiveau = '';
      filterWeergave = 'all';
      filterZoek = '';
      sectorEl.value = '';
      niveauEl.value = '';
      weergaveEl.value = 'all';
      if (zoekEl) zoekEl.value = '';
      render();
    });

    if (printEl) {
      printEl.addEventListener('click', () => {
        renderClientPrint();
        window.print();
      });
    }

    loadSelections().finally(() => {
      render();
    });

    async function loadSelections() {
      try {
        const res = await apiFetch('/selecties', { method: 'GET' });
        if (!res.ok) throw new Error('fetch failed');
        const payload = await res.json();
        selections = (payload && typeof payload === 'object') ? payload : {};
      } catch (_) {
        selections = {};
      }
    }

    function render() {
      const filtered = DATA.filter((item) => {
        if (filterSector && item.s !== filterSector) return false;
        if (filterNiveau && item.lvl !== filterNiveau) return false;
        if (filterZoek) {
          const hay = `${item.n || ''} ${item.s || ''} ${item.lvl || ''}`.toLowerCase();
          if (!hay.includes(filterZoek)) return false;
        }

        const selected = selections[item.n] || {};
        if (filterWeergave === 'liked' && Number(selected.vind_ik_leuk || 0) !== 1) return false;
        if (filterWeergave === 'doelgroep' && Number(selected.doelgroep || 0) !== 1) return false;

        return true;
      });

      counterEl.textContent = `${filtered.length} van ${DATA.length} beroepen`;

      if (!filtered.length) {
        gridEl.innerHTML = `<div class="bp-beroepen-empty">${esc(strings.noData || 'Geen beroepen gevonden.')}</div>`;
        return;
      }

      gridEl.innerHTML = filtered.map((item) => renderClientCard(item)).join('');
      attachClientEvents(filtered);
    }

    function renderClientPrint() {
      const container = root.querySelector('#bp-print-content');
      if (!container) return;

      const liked = DATA.filter((item) => Number((selections[item.n] && selections[item.n].vind_ik_leuk) || 0) === 1);
      if (!liked.length) {
        container.innerHTML = '<div class="bp-beroepen-empty">Geen geselecteerde beroepen om af te drukken.</div>';
        return;
      }

      let html = '<table class="bp-print-table bp-print-table-client"><thead><tr><th>Beroep</th><th>Sector</th><th>Niveau</th><th>Doelgroep</th><th>Notitie</th></tr></thead><tbody>';
      liked.forEach((item) => {
        const s = selections[item.n] || {};
        const doelgroep = Number(s.doelgroep || 0) === 1 ? 'Ja' : 'Nee';
        const niveau = item.lvl === 'Hoger' ? 'HBO/WO' : (item.lvl === 'Basis' ? 'Basis' : 'MBO');
        html += `<tr>
          <td>${esc(item.n)}</td>
          <td>${esc(item.s || '')}</td>
          <td>${esc(niveau)}</td>
          <td>${esc(doelgroep)}</td>
          <td>${esc(s.notitie || '')}</td>
        </tr>`;
      });
      html += '</tbody></table>';
      container.innerHTML = html;
    }

    function renderClientCard(item) {
      const sel = selections[item.n] || {};
      const liked = Number(sel.vind_ik_leuk || 0) === 1;
      const doelgroep = Number(sel.doelgroep || 0) === 1;
      const note = String(sel.notitie || '');

      const lvlClass = item.lvl === 'Hoger' ? 'bp-lvl-hoger' : (item.lvl === 'Basis' ? 'bp-lvl-basis' : 'bp-lvl-mbo');
      const lvlLabel = item.lvl === 'Hoger' ? 'HBO/WO' : (item.lvl === 'Basis' ? 'BASIS' : 'MBO');
      const link = item.u ? `<a class="bp-info-link" href="${esc(item.u)}" target="_blank" rel="noopener noreferrer">i Meer info op Werk.nl</a>` : '';

      return `
        <article class="bp-beroep-card" data-beroep="${esc(item.n)}">
          <header class="bp-card-head">
            <span class="bp-card-sector">${esc(item.s)}</span>
            <span class="bp-lvl ${lvlClass}">${esc(lvlLabel)}</span>
          </header>
          <h3 class="bp-card-title">${esc(item.n)}</h3>
          <label class="bp-check-row">
            <input type="checkbox" class="bp-like" ${liked ? 'checked' : ''}>
            <span>Vind ik leuk</span>
          </label>
          <label class="bp-check-row bp-check-row-muted">
            <input type="checkbox" class="bp-doelgroep" ${doelgroep ? 'checked' : ''}>
            <span>Doelgroep-registratie</span>
          </label>
          <textarea class="bp-note" rows="3" placeholder="Notities: wat trekt me aan? Wat houdt me tegen?">${esc(note)}</textarea>
          ${link}
        </article>
      `;
    }

    function attachClientEvents(items) {
      for (const item of items) {
        const selector = `.bp-beroep-card[data-beroep="${CSS.escape(item.n)}"]`;
        const card = gridEl.querySelector(selector);
        if (!card) continue;

        const likeEl = card.querySelector('.bp-like');
        const doelgroepEl = card.querySelector('.bp-doelgroep');
        const noteEl = card.querySelector('.bp-note');

        if (likeEl) likeEl.addEventListener('change', () => save(item, card, false));
        if (doelgroepEl) doelgroepEl.addEventListener('change', () => save(item, card, false));
        if (noteEl) noteEl.addEventListener('input', () => save(item, card, true));
      }
    }

    function save(item, card, debounce) {
      const likeEl = card.querySelector('.bp-like');
      const doelgroepEl = card.querySelector('.bp-doelgroep');
      const noteEl = card.querySelector('.bp-note');

      const payload = {
        beroep_naam: item.n,
        sector: item.s,
        niveau: item.lvl,
        vind_ik_leuk: likeEl && likeEl.checked ? 1 : 0,
        doelgroep: doelgroepEl && doelgroepEl.checked ? 1 : 0,
        notitie: (noteEl && noteEl.value) || '',
      };

      selections[item.n] = {
        ...selections[item.n],
        ...payload,
      };

      if (filterWeergave !== 'all') render();

      const run = () => sendSave(payload);
      if (!debounce) {
        run();
        return;
      }

      if (timers.has(item.n)) clearTimeout(timers.get(item.n));
      const timerId = setTimeout(() => {
        run();
        timers.delete(item.n);
      }, 700);
      timers.set(item.n, timerId);
    }

    async function sendSave(payload) {
      try {
        await apiFetch('/selecties', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
      } catch (_) {
        // silent fail, user can keep editing
      }
    }
  }

  function initBegeleider(root) {
    applyPdfLayoutConfig(root);
    const clientEl = root.querySelector('#bp-beroepen-client');
    const printBtnEl = root.querySelector('#bp-beroepen-begel-print');
    const counterEl = root.querySelector('#bp-beroepen-begel-counter');
    const gridEl = root.querySelector('#bp-beroepen-begel-grid');

    if (!clientEl || !counterEl || !gridEl) return;

    let currentClientId = 0;
    let currentClientMeta = null;
    let selecties = {};
    let aantekeningen = {};
    const saveTimers = new Map();
    const preselectClientId = getClientIdFromQuery();

    loadClients();

    clientEl.addEventListener('change', () => {
      const nextId = parseInt(clientEl.value || '0', 10) || 0;
      currentClientId = nextId;
      currentClientMeta = null;

      if (!nextId) {
        counterEl.textContent = '0 geselecteerde beroepen';
        if (printBtnEl) printBtnEl.style.display = 'none';
        gridEl.innerHTML = '<div class="bp-beroepen-loading">Selecteer een client om te starten...</div>';
        return;
      }

      loadWorkspace(nextId);
    });

    if (printBtnEl) {
      printBtnEl.addEventListener('click', () => {
        renderBegeleiderPrint();
        window.print();
      });
    }

    async function loadClients() {
      try {
        const res = await apiFetch('/clients', { method: 'GET' });
        if (!res.ok) throw new Error('clients failed');

        const payload = await res.json();
        const items = payload && Array.isArray(payload.items) ? payload.items : [];

        items.forEach((client) => {
          const option = document.createElement('option');
          option.value = String(client.id || '');
          option.textContent = String(client.naam || `Client #${client.id}`);
          clientEl.appendChild(option);
        });

        if (preselectClientId > 0) {
          const hasOption = Array.from(clientEl.options).some((opt) => Number(opt.value || 0) === preselectClientId);
          if (hasOption) {
            clientEl.value = String(preselectClientId);
            currentClientId = preselectClientId;
            loadWorkspace(preselectClientId);
          }
        }
      } catch (_) {
        gridEl.innerHTML = '<div class="bp-beroepen-empty">Clients laden mislukt.</div>';
      }
    }

    async function loadWorkspace(clientId) {
      gridEl.innerHTML = '<div class="bp-beroepen-loading">Geselecteerde functies laden...</div>';

      try {
        const res = await apiFetch(`/aantekeningen/${clientId}`, { method: 'GET' });
        if (!res.ok) throw new Error('workspace failed');

        const payload = await res.json();
        selecties = (payload && payload.selecties && typeof payload.selecties === 'object') ? payload.selecties : {};
        aantekeningen = (payload && payload.aantekeningen && typeof payload.aantekeningen === 'object') ? payload.aantekeningen : {};
        currentClientMeta = (payload && payload.client) ? payload.client : null;
        renderBegeleiderCards();
      } catch (_) {
        gridEl.innerHTML = '<div class="bp-beroepen-empty">Clientdata laden mislukt.</div>';
      }
    }

    function renderBegeleiderCards() {
      const selected = Object.values(selecties).filter((item) => Number((item && item.vind_ik_leuk) || 0) === 1);
      counterEl.textContent = `${selected.length} geselecteerde beroepen`;
      if (printBtnEl) printBtnEl.style.display = selected.length ? 'inline-flex' : 'none';

      if (!selected.length) {
        gridEl.innerHTML = '<div class="bp-beroepen-empty">Deze client heeft nog geen beroepen aangevinkt.</div>';
        return;
      }

      selected.sort((a, b) => String(a.sector || '').localeCompare(String(b.sector || ''), 'nl'));
      gridEl.innerHTML = selected.map((item) => renderBegeleiderCard(item)).join('');
      bindBegeleiderEvents(selected);
    }

    function renderBegeleiderPrint() {
      const nameEl = root.querySelector('#bp-print-client-name');
      const signClientEl = root.querySelector('#bp-print-sign-client');
      const contentEl = root.querySelector('#bp-begel-print-content');
      if (!contentEl) return;

      const selected = Object.values(selecties).filter((item) => Number((item && item.vind_ik_leuk) || 0) === 1);
      if (nameEl) {
        const cname = (currentClientMeta && currentClientMeta.naam) ? currentClientMeta.naam : '-';
        nameEl.textContent = `Client: ${cname}`;
        if (signClientEl) signClientEl.textContent = `${cname} · ${new Date().toLocaleDateString('nl-NL')}`;
      }

      if (!selected.length) {
        contentEl.innerHTML = '<div class="bp-beroepen-empty">Geen geselecteerde beroepen om af te drukken.</div>';
        return;
      }

      let html = '<table class="bp-print-table bp-print-table-begel"><thead><tr><th>Beroep</th><th>Sector</th><th>Niveau</th><th>LKS %</th><th>Advies</th><th>Vervolgstappen</th></tr></thead><tbody>';
      selected.forEach((item) => {
        const beroepNaam = String(item.beroep_naam || '');
        const a = aantekeningen[beroepNaam] || {};
        const dataItem = byName.get(beroepNaam) || {};
        const niveau = item.niveau || dataItem.lvl || '';
        const niveauLabel = niveau === 'Hoger' ? 'HBO/WO' : (niveau === 'Basis' ? 'Basis' : 'MBO');
        html += `<tr>
          <td>${esc(beroepNaam)}</td>
          <td>${esc(item.sector || dataItem.s || '')}</td>
          <td>${esc(niveauLabel)}</td>
          <td>${esc(a.lks_percentage || '')}</td>
          <td>${esc(a.advies || '')}</td>
          <td>${esc(a.vervolgstappen || '')}</td>
        </tr>`;
      });
      html += '</tbody></table>';
      contentEl.innerHTML = html;
    }

    function getClientIdFromQuery() {
      try {
        const qp = new URLSearchParams(window.location.search || '');
        return parseInt(qp.get('client_id') || '0', 10) || 0;
      } catch (_) {
        return 0;
      }
    }

    function renderBegeleiderCard(item) {
      const beroepNaam = String(item.beroep_naam || '');
      const old = aantekeningen[beroepNaam] || {};
      const dataItem = byName.get(beroepNaam) || {};
      const levelRaw = String(item.niveau || dataItem.lvl || 'Middelbaar');
      const sector = String(item.sector || dataItem.s || 'Onbekend');
      const infoUrl = String(dataItem.u || '');

      const lvlClass = levelRaw === 'Hoger' ? 'bp-lvl-hoger' : (levelRaw === 'Basis' ? 'bp-lvl-basis' : 'bp-lvl-mbo');
      const lvlLabel = levelRaw === 'Hoger' ? 'HBO/WO' : (levelRaw === 'Basis' ? 'BASIS' : 'MBO');
      const sterren = Math.max(0, Math.min(5, parseInt(old.sterren || '0', 10) || 0));
      const doelgroepFunctie = Number(old.doelgroep_functie || 0) === 1;

      return `
        <article class="bp-beroep-card bp-beroep-card-begel" data-beroep="${esc(beroepNaam)}">
          <header class="bp-card-head">
            <span class="bp-card-sector">${esc(sector)}</span>
            <span class="bp-lvl ${lvlClass}">${esc(lvlLabel)}</span>
          </header>

          <h3 class="bp-card-title">${esc(beroepNaam)}</h3>

          <div class="bp-client-note-wrap">
            <div class="bp-field-label">Notitie van client</div>
            <div class="bp-client-note">${esc(item.notitie || 'Geen clientnotitie ingevuld.')}</div>
          </div>

          <div class="bp-aant-grid">
            <label class="bp-check-row bp-check-row-muted bp-aant-full" style="margin-bottom:0;">
              <input type="checkbox" class="bp-dg-functie" ${doelgroepFunctie ? 'checked' : ''}>
              <span>Doelgroepfunctie</span>
            </label>

            <div class="bp-aant-col">
              <label class="bp-field-label">Passendheid</label>
              <select class="bp-aant-sterren bp-beroepen-select">
                <option value="0" ${sterren === 0 ? 'selected' : ''}>Geen score</option>
                <option value="1" ${sterren === 1 ? 'selected' : ''}>1 - Slecht passend</option>
                <option value="2" ${sterren === 2 ? 'selected' : ''}>2 - Matig</option>
                <option value="3" ${sterren === 3 ? 'selected' : ''}>3 - Redelijk</option>
                <option value="4" ${sterren === 4 ? 'selected' : ''}>4 - Goed</option>
                <option value="5" ${sterren === 5 ? 'selected' : ''}>5 - Uitstekend</option>
              </select>
            </div>

            <div class="bp-aant-col">
              <label class="bp-field-label">LKS %</label>
              <input type="number" min="0" max="100" class="bp-aant-lks bp-input" value="${esc((old.lks_percentage == null ? '' : old.lks_percentage))}" placeholder="bijv. 70">
            </div>
          </div>

          <label class="bp-field-label" style="margin-top:10px;">Professioneel advies</label>
          <textarea class="bp-note bp-aant-advies" rows="3" placeholder="Professioneel advies...">${esc(old.advies || '')}</textarea>

          <label class="bp-field-label">Vervolgstappen</label>
          <textarea class="bp-note bp-aant-stappen" rows="4" placeholder="Beschrijf de vervolgstappen...">${esc(old.vervolgstappen || '')}</textarea>

          ${infoUrl ? `<a class="bp-info-link" href="${esc(infoUrl)}" target="_blank" rel="noopener noreferrer">i Meer info op Werk.nl</a>` : ''}
        </article>
      `;
    }

    function bindBegeleiderEvents(selectedItems) {
      selectedItems.forEach((item) => {
        const beroepNaam = String(item.beroep_naam || '');
        const selector = `.bp-beroep-card[data-beroep="${CSS.escape(beroepNaam)}"]`;
        const card = gridEl.querySelector(selector);
        if (!card) return;

        const inputs = card.querySelectorAll('.bp-dg-functie, .bp-aant-sterren, .bp-aant-lks, .bp-aant-advies, .bp-aant-stappen');
        inputs.forEach((el) => {
          const evt = (el.classList.contains('bp-aant-advies') || el.classList.contains('bp-aant-stappen') || el.classList.contains('bp-aant-lks')) ? 'input' : 'change';
          el.addEventListener(evt, () => scheduleSave(beroepNaam, card));
        });
      });
    }

    function scheduleSave(beroepNaam, card) {
      if (!currentClientId) return;

      if (saveTimers.has(beroepNaam)) {
        clearTimeout(saveTimers.get(beroepNaam));
      }

      const timer = setTimeout(() => {
        saveAantekening(beroepNaam, card);
        saveTimers.delete(beroepNaam);
      }, 600);

      saveTimers.set(beroepNaam, timer);
    }

    async function saveAantekening(beroepNaam, card) {
      if (!currentClientId) return;

      const payload = {
        beroep_naam: beroepNaam,
        doelgroep_functie: card.querySelector('.bp-dg-functie') && card.querySelector('.bp-dg-functie').checked ? 1 : 0,
        sterren: parseInt((card.querySelector('.bp-aant-sterren') && card.querySelector('.bp-aant-sterren').value) || '0', 10) || 0,
        lks_percentage: ((card.querySelector('.bp-aant-lks') && card.querySelector('.bp-aant-lks').value) || '').trim(),
        advies: (card.querySelector('.bp-aant-advies') && card.querySelector('.bp-aant-advies').value) || '',
        vervolgstappen: (card.querySelector('.bp-aant-stappen') && card.querySelector('.bp-aant-stappen').value) || '',
      };

      try {
        const res = await apiFetch(`/aantekeningen/${currentClientId}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });

        if (!res.ok) throw new Error('save failed');

        aantekeningen[beroepNaam] = {
          ...(aantekeningen[beroepNaam] || {}),
          ...payload,
        };
      } catch (_) {
        // keep editing flow uninterrupted
      }
    }
  }

  function applyPdfLayoutConfig(root) {
    const cfg = appConfig && typeof appConfig.pdfLayout === 'object' && appConfig.pdfLayout ? appConfig.pdfLayout : {};
    const map = cfg && typeof cfg.map === 'object' && cfg.map ? cfg.map : {};
    const toMm = (v) => {
      const num = Number(v);
      if (!Number.isFinite(num)) return '0mm';
      return `${num}mm`;
    };

    root.style.setProperty('--bp-pdf-header-top', toMm(map.headerTopMm || 0));
    root.style.setProperty('--bp-pdf-table-top', toMm(map.tableTopMm || 0));
    root.style.setProperty('--bp-pdf-footer-top', toMm(map.footerTopMm || 0));
    root.style.setProperty('--bp-pdf-left', toMm(map.leftMm || 0));
    root.style.setProperty('--bp-pdf-right', toMm(map.rightMm || 0));

    const bgUrl = String(cfg.bgUrl || '').trim();
    if (bgUrl) {
      const safe = bgUrl.replace(/"/g, '\\"');
      root.style.setProperty('--bp-pdf-bg-url', `url("${safe}")`);
      root.classList.add('bp-has-custom-pdf-layout');
    } else {
      root.style.removeProperty('--bp-pdf-bg-url');
      root.classList.remove('bp-has-custom-pdf-layout');
    }
  }
})();
