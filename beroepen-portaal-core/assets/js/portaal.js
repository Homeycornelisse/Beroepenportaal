/* ============================================================
   Beroepen Portaal — portaal.js v5.0
   Fixes: CV upload, begeleider tabs, logboek readonly,
   begel-logboek, documenten, inklap fix, navbar, footer
   ============================================================ */
'use strict';

const esc = s => String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const sterLabel = n => ['—','Slecht passend','Matig','Redelijk','Goed passend','Uitstekend'][n]||'—';
const apiFetch = (url, opts={}) => fetch(KB.apiUrl + url, {
    headers: {'X-WP-Nonce': KB.nonce, 'Content-Type': 'application/json'},
    credentials: 'same-origin', ...opts });
const fmtDatum = s => s ? new Date(s.replace(' ','T')).toLocaleDateString('nl-NL',{day:'numeric',month:'short',year:'numeric'}) : '—';
const fmtSize  = b => b>1024*1024 ? (b/1024/1024).toFixed(1)+' MB' : b>1024 ? Math.round(b/1024)+' KB' : b+' B';
const mimeIcon = m => m?.includes('pdf')?'📄':m?.includes('word')?'📝':m?.includes('image')?'🖼️':m?.includes('sheet')||m?.includes('excel')?'📊':'📎';

document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('kb-portaal-root'))  initPortaal();
  if (document.getElementById('kb-begel-root'))    initBegeleider();
  if (document.getElementById('kb-logboek-root'))  initLogboek();
  if (document.getElementById('kb-cv-root'))       initCV();
  if (document.getElementById('kb-adresboek-root')) initAdresboek();
});

/* ═══════════════════════════════════════════════════════════
   PORTAAL (cliënt)
═══════════════════════════════════════════════════════════ */
function initPortaal() {
  let opgeslagen={}, filterSector='', filterNiveau='', filterZoek='', alleenAangevinkt=false, saveTimer=null;
  const gridEl = document.getElementById('kb-grid');
  const teller = document.getElementById('kb-teller');

  apiFetch('/selecties').then(r=>r.json()).then(data=>{
    opgeslagen = data||{};
    initPortaalUI();
  }).catch(()=>{ opgeslagen = window.KB_OPGESLAGEN||{}; initPortaalUI(); });

  function initPortaalUI() {
    const sectorSel = document.getElementById('kb-sector-filter');
    const niveauSel = document.getElementById('kb-niveau-filter');
    const zoekInput = document.getElementById('kb-zoek');
    const filterBtn = document.getElementById('kb-filter-btn');
    const beroepen  = BEROEPEN_DATA;

    [...new Set(beroepen.map(b=>b.s))].sort((a,z)=>a.localeCompare(z,'nl')).forEach(s=>{
      const o = document.createElement('option'); o.value=s; o.textContent=s; sectorSel.appendChild(o);
    });

    sectorSel.addEventListener('change', ()=>{ filterSector=sectorSel.value; render(); });
    niveauSel.addEventListener('change', ()=>{ filterNiveau=niveauSel.value; render(); });
    zoekInput.addEventListener('input',  ()=>{ filterZoek=zoekInput.value.toLowerCase(); render(); });
    filterBtn.addEventListener('click',  ()=>{
      alleenAangevinkt=!alleenAangevinkt;
      filterBtn.classList.toggle('kb-btn-orange',alleenAangevinkt);
      filterBtn.classList.toggle('kb-btn-ghost',!alleenAangevinkt);
      render();
    });

    // PDF: alleen aangevinkte beroepen tonen
    window.addEventListener('beforeprint', ()=>{
      document.querySelectorAll('.kb-beroep-card').forEach(card=>{
        card.style.display = opgeslagen[card.dataset.beroep]?.vind_ik_leuk==1 ? '' : 'none';
      });
      if (teller) teller.textContent = `${Object.values(opgeslagen).filter(s=>s.vind_ik_leuk==1).length} aangevinkte beroepen`;
    });
    window.addEventListener('afterprint', ()=>{
      document.querySelectorAll('.kb-beroep-card').forEach(c=>c.style.display='');
      render();
    });

    render();

    function render() {
      const gef = beroepen.filter(b=>{
        if (filterSector && b.s!==filterSector) return false;
        if (filterNiveau && b.lvl!==filterNiveau) return false;
        if (filterZoek && !b.n.toLowerCase().includes(filterZoek) && !b.s.toLowerCase().includes(filterZoek)) return false;
        if (alleenAangevinkt && !opgeslagen[b.n]?.vind_ik_leuk) return false;
        return true;
      });
      if (teller) teller.textContent = `${gef.length} van ${beroepen.length} beroepen`;
      gridEl.innerHTML='';
      if (!gef.length) {
        gridEl.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:48px;color:#94a3b8;"><div style="font-size:36px;">🔍</div><p style="font-weight:600;margin-top:8px;">Geen beroepen gevonden</p></div>';
        return;
      }
      gef.forEach(b=>gridEl.appendChild(maakKaart(b)));
    }

    function maakKaart(b) {
      const opg  = opgeslagen[b.n]||{};
      const like = !!opg.vind_ik_leuk, doel=!!opg.doelgroep, note=opg.notitie||'';
      const lvlCls = b.lvl==='Hoger'?'kb-lvl-hoger':b.lvl==='Basis'?'kb-lvl-basis':'kb-lvl-mbo';
      const lvlLbl = b.lvl==='Hoger'?'HBO/WO':b.lvl==='Basis'?'BASIS':'MBO';
      const card = document.createElement('div');
      card.className='kb-beroep-card'; card.dataset.beroep=b.n;
      card.innerHTML=`
        <div class="kb-beroep-header">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:4px;">
            <div class="kb-beroep-naam">${esc(b.n)}</div>
            <div class="kb-kaart-rechts" style="display:flex;align-items:center;gap:4px;flex-shrink:0;">
              <span class="kb-lvl ${lvlCls}">${lvlLbl}</span>
            </div>
          </div>
          <div class="kb-beroep-sector">${esc(b.s)}</div>
        </div>
        <div class="kb-beroep-body">
          <label class="kb-check-label"><input type="checkbox" class="kb-like-cb" ${like?'checked':''}><span>Vind ik leuk</span></label>
          <label class="kb-check-label" style="color:#64748b;font-weight:500;"><input type="checkbox" class="kb-doel-cb" style="accent-color:var(--kb-mid);" ${doel?'checked':''}><span>Doelgroep-registratie</span></label>
        </div>
        <div class="kb-beroep-footer">
          <div class="kb-field-label">Mijn notitie</div>
          <textarea class="kb-notitie kb-note-ta" placeholder="Schrijf hier waarom dit beroep jou aanspreekt…" rows="2">${esc(note)}</textarea>
        </div>`;
      if (b.u) {
        const rechts = card.querySelector('.kb-kaart-rechts');
        const a = document.createElement('a');
        a.href = b.u; a.target = '_blank'; a.rel = 'noopener';
        a.title = 'Meer info op UWV werk.nl';
        a.textContent = 'ⓘ';
        a.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#dbeafe;color:#2563eb;font-size:11px;font-weight:700;text-decoration:none;flex-shrink:0;';
        a.addEventListener('click', e => e.stopPropagation());
        rechts.insertBefore(a, rechts.firstChild);
      }
      card.querySelector('.kb-like-cb').addEventListener('change', ()=>slaOp(b,card));
      card.querySelector('.kb-doel-cb').addEventListener('change', ()=>slaOp(b,card));
      card.querySelector('.kb-note-ta').addEventListener('input',  ()=>{ clearTimeout(saveTimer); saveTimer=setTimeout(()=>slaOp(b,card),800); });
      return card;
    }

    function slaOp(b, card) {
      const like = card.querySelector('.kb-like-cb').checked;
      const doel = card.querySelector('.kb-doel-cb').checked;
      const note = card.querySelector('.kb-note-ta').value;
      opgeslagen[b.n] = {vind_ik_leuk:like?1:0, doelgroep:doel?1:0, notitie:note};
      apiFetch('/selecties', {method:'POST', body:JSON.stringify(
        {beroep_naam:b.n, sector:b.s, niveau:b.lvl, vind_ik_leuk:like?1:0, doelgroep:doel?1:0, notitie:note})});
      if (alleenAangevinkt && !like) render();
    }
  }
}

// Tabs in admin-blok (front-end dashboard)
document.addEventListener('DOMContentLoaded', function () {
  const tabWrap = document.querySelector('.bp-tabs');
  if (!tabWrap) return;

  const buttons  = tabWrap.querySelectorAll('.bp-tab-button');
  const contents = document.querySelectorAll('.bp-tab-content');
  if (!buttons.length || !contents.length) return;

  const getUrl = () => new URL(window.location.href);

  const activate = (key, pushState = true) => {
    buttons.forEach(b => b.classList.toggle('active', b.dataset.tab === key));
    contents.forEach(c => c.classList.toggle('active', c.id === 'bp-tab-' + key));

    // Bewaar actieve tab in de URL (zodat je niet terug springt naar "Gebruikers")
    if (pushState) {
      const url = getUrl();
      url.searchParams.set('bp_tab', key);
      window.history.replaceState({}, '', url.toString());
    }
  };

  // Bij laden: tab uit URL pakken
  const url = getUrl();
  const initial = url.searchParams.get('bp_tab') || url.searchParams.get('tab');
  if (initial) {
    activate(initial, false);
  }

  buttons.forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      activate(this.dataset.tab, true);
    });
  });

  // Als er formulieren zijn die een bp_tab hidden field hebben: houd die synchroon.
  document.addEventListener('submit', function () {
    try {
      const u = getUrl();
      const current = u.searchParams.get('bp_tab') || u.searchParams.get('tab');
      if (!current) return;
      document.querySelectorAll('input[name="bp_tab"]').forEach(inp => {
        inp.value = current;
      });
    } catch (e) {}
  }, true);
});

/* ═══════════════════════════════════════════════════════════
   CV (client én begeleider)
═══════════════════════════════════════════════════════════ */
function initCV() {
  const root = document.getElementById('kb-cv-root');
  if (!root) return;
  const CLIENT_ID = parseInt(root.dataset.client)||0;
  const IS_BEGEL  = KB.isBegel == 1;

  // Laad CV data
  apiFetch(`/cv/${CLIENT_ID}`).then(r=>r.json()).then(data=>{
    const cv = data.cv;
    const cvNaam = document.getElementById('kb-cv-naam');
    const cvDatum = document.getElementById('kb-cv-datum');
    const cvToon  = document.getElementById('kb-huidig-cv');
    const cvGeen  = document.getElementById('kb-geen-cv');
    if (cv && cv.bestandsnaam) {
      if (cvNaam)  cvNaam.textContent  = cv.bestandsnaam;
      if (cvDatum) cvDatum.textContent = 'Geüpload: ' + fmtDatum(cv.geupload);
      if (cvToon)  cvToon.style.display  = 'block';
      if (cvGeen)  cvGeen.style.display  = 'none';

      // Download knop (zichtbaar voor iedereen met toegang)
      const dlBtn = document.getElementById('kb-cv-download');
      if (dlBtn) {
        dlBtn.style.display = 'inline-flex';
        dlBtn.href = KB.apiUrl + '/cv/' + CLIENT_ID + '/download?_wpnonce=' + KB.nonce;
      }
      // Verwijder knop (alleen voor cliënt zelf)
      const delBtn = document.getElementById('kb-cv-verwijder');
      if (delBtn && !IS_BEGEL) delBtn.style.display = 'inline-flex';

      // Toon samenvatting
      if (cv.samenvatting) {
        try {
          const parsed = JSON.parse(cv.samenvatting);
          renderProfielKaart(parsed);
        } catch(e) {}
      }
    } else {
      if (cvToon) cvToon.style.display = 'none';
      if (cvGeen) cvGeen.style.display = 'block';
    }
  });

  // CV verwijderen
  window.verwijderCV = function() {
    if (!confirm('CV verwijderen? Dit kan niet ongedaan worden gemaakt.')) return;
    apiFetch(`/cv/${CLIENT_ID}`, {method:'DELETE'}).then(r=>r.json()).then(data=>{
      if (data.ok) {
        document.getElementById('kb-huidig-cv').style.display = 'none';
        document.getElementById('kb-geen-cv').style.display   = 'block';
        const dlBtn = document.getElementById('kb-cv-download');
        const delBtn = document.getElementById('kb-cv-verwijder');
        if (dlBtn)  dlBtn.style.display  = 'none';
        if (delBtn) delBtn.style.display = 'none';
      }
    });
  };

  function renderProfielKaart(d) {
    const kaart = document.getElementById('kb-profiel-card');
    const sam   = document.getElementById('kb-samenvatting');
    const kern  = document.getElementById('kb-kernvaardigheden');
    if (!kaart||!d) return;
    kaart.style.display='block';
    if (sam && d.samenvatting) sam.textContent = d.samenvatting;
    if (kern && d.profiel?.kernvaardigheden) {
      kern.innerHTML = d.profiel.kernvaardigheden.map(v=>
        `<span style="background:#ede9fe;color:#6d28d9;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;">${esc(v)}</span>`
      ).join('');
    }
  }

  // Tab wisselen
  window.switchCvTab = function(tab, btn) {
    document.querySelectorAll('.kb-tab-content').forEach(t=>t.style.display='none');
    document.querySelectorAll('.kb-tab-btn').forEach(b=>b.classList.remove('active'));
    const el = document.getElementById('tab-'+tab);
    if (el) el.style.display='block';
    if (btn && btn.classList) btn.classList.add('active');
    if (tab==='brieven') laadBrieven();
    if (tab==='analyse') laadAnalyse();
  };

  // Upload drag-drop
  window.handleDrop = function(e) {
    e.preventDefault();
    const dz = document.getElementById('kb-dropzone');
    if (dz) { dz.style.borderColor='#c4b5fd'; dz.style.background='#faf5ff'; }
    const file = e.dataTransfer?.files?.[0];
    if (file) uploadCV(file);
  };

  // CV upload
  window.uploadCV = function(file) {
    if (!file) return;
    const status = document.getElementById('kb-upload-status');
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['pdf','doc','docx'].includes(ext)) {
      status.innerHTML='<span style="color:#dc2626;">❌ Alleen PDF, DOC en DOCX zijn toegestaan.</span>'; return;
    }
    if (file.size > 5*1024*1024) {
      status.innerHTML='<span style="color:#dc2626;">❌ Bestand mag maximaal 5MB zijn.</span>'; return;
    }
    status.innerHTML='<span style="color:var(--kb-purple);">⏳ Uploaden…</span>';
    const fd = new FormData();
    fd.append('action', 'kb_upload_cv');
    fd.append('nonce',  KB.cvNonce);
    fd.append('cv',     file);
    fetch(KB.ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
    .then(r=>r.json()).then(data=>{
      if (data.success) {
        status.innerHTML='<span style="color:#166534;">✅ CV geüpload!</span>';
        document.getElementById('kb-geen-cv').style.display  = 'none';
        document.getElementById('kb-huidig-cv').style.display = 'block';
        document.getElementById('kb-cv-naam').textContent  = data.data.bestandsnaam;
        document.getElementById('kb-cv-datum').textContent = 'Zojuist geüpload';
        setTimeout(()=>status.innerHTML='', 3500);
      } else {
        status.innerHTML='<span style="color:#dc2626;">❌ ' + esc(data.data?.error||'Upload mislukt.') + '</span>';
      }
    }).catch(err=>{
      status.innerHTML='<span style="color:#dc2626;">❌ Verbindingsfout bij upload.</span>';
      console.error('CV upload error:', err);
    });
  };

  function laadAnalyse() {
    apiFetch(`/cv/${CLIENT_ID}`).then(r=>r.json()).then(data=>{
      if (!data.cv?.beroepen_suggesties) return;
      try {
        const parsed = JSON.parse(data.cv.samenvatting || '{}');
        renderAnalyse(parsed, data.cv);
      } catch(e) {}
    });
  }

  function renderAnalyse(parsed, cv) {
    const vp = document.getElementById('kb-verbeterpunten');
    const bs = document.getElementById('kb-beroepen-suggesties');
    if (!vp||!bs) return;
    if (cv.verbeterpunten) {
      try {
        const v = JSON.parse(cv.verbeterpunten);
        vp.innerHTML = (v||[]).map(p=>`<div style="background:#fff7ed;border-left:3px solid var(--kb-orange);border-radius:10px;padding:12px;margin-bottom:8px;">
          <div style="font-weight:700;color:var(--kb-orange);font-size:13px;">${esc(p.punt||p)}</div>
          ${p.toelichting?`<div style="font-size:12px;color:#64748b;margin-top:4px;">${esc(p.toelichting)}</div>`:''}
        </div>`).join('');
      } catch(e) {}
    }
    if (cv.beroepen_suggesties) {
      try {
        const b = JSON.parse(cv.beroepen_suggesties);
        bs.innerHTML = (b||[]).map(s=>`<div style="background:white;border:1px solid var(--kb-border);border-radius:12px;padding:14px;display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px;">
          <div><div style="font-weight:700;color:var(--kb-blue);">${esc(s.beroep||s)}</div>${s.reden?`<div style="font-size:12px;color:#64748b;margin-top:3px;">${esc(s.reden)}</div>`:''}</div>
          ${s.match?`<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;flex-shrink:0;">${s.match}%</span>`:''}
        </div>`).join('');
      } catch(e) {}
    }
  }

  window.analyseerCV = function(heranalyse) {
    const btn = document.getElementById('kb-analyse-btn');
    const stat = document.getElementById('kb-analyse-status');
    if (btn) { btn.disabled=true; btn.textContent='Analyseren…'; }
    if (stat) stat.innerHTML='<span style="color:var(--kb-purple);">⏳ AI analyseert jouw CV…</span>';
    apiFetch('/cv/analyseer', {method:'POST', body:JSON.stringify({client_id:CLIENT_ID, heranalyse:heranalyse?1:0})})
    .then(r=>r.json()).then(data=>{
      if (btn) { btn.disabled=false; btn.textContent='🤖 Analyseer CV'; }
      if (data.error) { if(stat) stat.innerHTML=`<span style="color:#dc2626;">❌ ${esc(data.error)}</span>`; return; }
      if (stat) stat.innerHTML='<span style="color:#166534;">✅ Analyse klaar!</span>';
      if (data.profiel) renderProfielKaart(data);
      laadAnalyse();
    });
  };

  // Brieven
  let huidigBriefId = null;
  window.genereerBrief = function() {
    const vac   = document.getElementById('kb-vac-tekst')?.value.trim();
    const titel = document.getElementById('kb-vac-titel')?.value.trim();
    const toon  = document.getElementById('kb-brief-toon')?.value||'professioneel';
    const btn   = document.getElementById('kb-brief-btn');
    const prev  = document.getElementById('kb-brief-preview');
    if (!vac) { alert('Vul een vacaturetekst in.'); return; }
    if (btn) { btn.disabled=true; btn.textContent='Brief schrijven…'; }
    apiFetch('/cv/brief', {method:'POST', body:JSON.stringify({client_id:CLIENT_ID, vacature_tekst:vac, vacature_titel:titel, toon})})
    .then(r=>r.json()).then(data=>{
      if (btn) { btn.disabled=false; btn.textContent='✨ Brief genereren'; }
      if (data.error) { alert('❌ '+data.error); return; }
      huidigBriefId = data.id;
      if (prev) { prev.style.display='block'; prev.querySelector('textarea').value=data.brief_tekst||''; }
    });
  };

  window.slaOpBrief = function() {
    if (!huidigBriefId) return;
    const ta = document.querySelector('#kb-brief-preview textarea');
    apiFetch(`/brieven/${huidigBriefId}`, {method:'PATCH', body:JSON.stringify({brief_tekst:ta?.value, opgeslagen:1})})
    .then(()=>laadBrieven());
  };

  function laadBrieven() {
    apiFetch(`/brieven/${CLIENT_ID}`).then(r=>r.json()).then(data=>{
      const el = document.getElementById('kb-brieven-lijst');
      if (!el) return;
      if (!data.length) { el.innerHTML='<div style="text-align:center;padding:20px;color:#94a3b8;">Nog geen opgeslagen brieven.</div>'; return; }
      el.innerHTML = data.filter(b=>b.opgeslagen).map(b=>`
        <div style="background:#f8fafc;border-radius:12px;padding:14px;margin-bottom:8px;cursor:pointer;" onclick="laadBrief(${b.id})">
          <div style="font-weight:700;font-size:13px;color:var(--kb-blue);">${esc(b.vacature_titel||'Ongenoemde vacature')}</div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;">${fmtDatum(b.aangemaakt)}</div>
        </div>`).join('');
    });
  }

  window.laadBrief = function(id) {
    apiFetch(`/brieven/${CLIENT_ID}`).then(r=>r.json()).then(data=>{
      const b = data.find(x=>x.id==id);
      if (!b) return;
      huidigBriefId = b.id;
      const prev = document.getElementById('kb-brief-preview');
      if (prev) { prev.style.display='block'; prev.querySelector('textarea').value=b.brief_tekst; }
      switchCvTab('brieven', document.querySelector('[data-tab="brieven"]'));
    });
  };

  window.verwijderBrief = function(id) {
    if (!confirm('Brief verwijderen?')) return;
    apiFetch(`/brieven/${id}`, {method:'DELETE'}).then(()=>laadBrieven());
  };
}

/* ═══════════════════════════════════════════════════════════
   BEGELEIDER DASHBOARD — alle tabs
═══════════════════════════════════════════════════════════ */
function initBegeleider() {
  let huidigClient = null, saveTimer = null;

  const clientList = document.getElementById('kb-client-list');

  // Nieuwe cliënt knop
  document.getElementById('kb-nieuwe-client-btn')?.addEventListener('click', ()=>openClientModal('nieuw',null));
  bindModalEvents();
  laadClients(false);
  laadMeldingen();

  // Hoofd tab wisselen
  window.begelTab = function(e, tabId) {
    e.preventDefault();
    document.querySelectorAll('.begel-tab-inhoud').forEach(t=>t.style.display='none');
    document.querySelectorAll('.kb-begel-tab').forEach(t=>t.classList.remove('active'));
    document.getElementById('begel-tab-'+tabId).style.display='block';
    e.currentTarget.classList.add('active');
    if (tabId==='clients') laadClientsTabel();
    if (tabId==='archief') laadArchiefTabel();
  };

  // Werkruimte sub-tab wisselen
  window.wsTab = function(e, tabId) {
    e.preventDefault();
    document.querySelectorAll('.ws-tab-inhoud').forEach(t=>t.style.display='none');
    document.querySelectorAll('.kb-ws-tab').forEach(t=>t.classList.remove('active'));
    document.getElementById('ws-'+tabId).style.display='block';
    e.currentTarget.classList.add('active');
    if (!huidigClient) return;
    if (tabId==='logboek-client') laadClientLogboek(huidigClient);
    if (tabId==='cv-client')      laadClientCV(huidigClient);
    if (tabId==='brieven-client') laadClientBrieven(huidigClient);
    if (tabId==='opdrachten')     laadOpdrachten(huidigClient);
    if (tabId==='begel-logboek')  laadBegelLogboek(huidigClient);
    if (tabId==='documenten')     laadDocumenten(huidigClient);
    if (tabId==='naw')            laadNAW(huidigClient);
  };

  // ── Clients laden ──────────────────────────────────────
  function laadClients(archief) {
    if (!clientList) return;
    clientList.innerHTML='<div style="padding:20px;text-align:center;color:#94a3b8;">Laden…</div>';
    apiFetch('/clients?archief='+(archief?'1':'0'))
      .then(r=>r.json()).then(clients=>renderClientLijst(clients,archief))
      .catch(()=>{ clientList.innerHTML='<p style="color:red;padding:12px;">Fout bij laden.</p>'; });
  }

  function renderClientLijst(clients, archief) {
    if (!clients.length) {
      clientList.innerHTML=`<div style="text-align:center;padding:32px;color:#94a3b8;">
        <div style="font-size:28px;margin-bottom:8px;">${archief?'📦':'👤'}</div>
        <div style="font-weight:600;font-size:13px;">${archief?'Geen gearchiveerden':'Nog geen cliënten'}</div>
        ${!archief?'<button onclick="openClientModal(\'nieuw\',null)" class="kb-btn kb-btn-purple kb-btn-sm" style="margin-top:10px;">+ Nieuw</button>':''}
      </div>`; return;
    }
    clientList.innerHTML='';
    clients.forEach(c=>{
      const div = document.createElement('div');
      div.className='kb-client-btn'; div.dataset.id=c.id;
      const teamBadge = !c.eigen_client ? `<span style="background:#fef9c3;color:#92400e;padding:1px 5px;border-radius:6px;font-size:9px;font-weight:700;display:block;margin-top:2px;">team</span>` : '';
      div.innerHTML=`
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;">
          <div style="flex:1;min-width:0;">
            <div style="font-weight:700;color:var(--kb-blue);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(c.naam)}</div>
            <div style="font-size:11px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(c.email)}</div>
            ${teamBadge}
          </div>
          <div style="display:flex;gap:3px;align-items:center;flex-shrink:0;">
            ${c.aangevinkt>0?`<span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;">${c.aangevinkt}</span>`:''}
            ${c.eigen_client ? `<button class="kb-client-edit-btn kb-btn kb-btn-ghost kb-btn-sm" style="padding:3px 7px;font-size:11px;">✏️</button>` : ''}
            ${!c.eigen_client && KB.isLeid==1 ? `<button class="kb-client-ovnm-btn kb-btn kb-btn-ghost kb-btn-sm" style="padding:3px 7px;font-size:11px;" title="Overnemen">↗</button>` : ''}
            ${!c.eigen_client && KB.isLeid!=1 ? `<button class="kb-client-verzoek-btn kb-btn kb-btn-ghost kb-btn-sm" style="padding:3px 7px;font-size:11px;" title="Overname aanvragen">📋</button>` : ''}
            ${c.eigen_client ? `<button class="kb-client-arch-btn kb-btn kb-btn-ghost kb-btn-sm" style="padding:3px 7px;font-size:11px;" title="${c.gearchiveerd?'Terugzetten':'Archiveren'}">${c.gearchiveerd?'↩':'📦'}</button>` : ''}
          </div>
        </div>`;
      div.addEventListener('click', e=>{
        if (e.target.closest('.kb-client-edit-btn')) { e.stopPropagation(); openClientModal('edit',c); return; }
        if (e.target.closest('.kb-client-arch-btn')) { e.stopPropagation(); archiveerClient(c); return; }
        if (e.target.closest('.kb-client-ovnm-btn')) { e.stopPropagation(); overneemClient(c); return; }
        if (e.target.closest('.kb-client-verzoek-btn')) { e.stopPropagation(); verzoekOvernemen(c); return; }
        selecteerClient(c, div);
      });
      clientList.appendChild(div);
    });
  }

  function selecteerClient(client, btnEl) {
    huidigClient = client;
    document.querySelectorAll('.kb-client-btn').forEach(b=>{ b.style.borderColor='#e2e8f0'; b.style.background='white'; });
    if (btnEl) { btnEl.style.borderColor='var(--kb-purple)'; btnEl.style.background='#faf5ff'; }
    else {
      // Zoek de juiste knop op basis van id
      const match = clientList?.querySelector(`.kb-client-btn[data-id="${client.id}"]`);
      if (match) { match.style.borderColor='var(--kb-purple)'; match.style.background='#faf5ff'; }
    }

    const el = document.getElementById('kb-print-clientnaam');
    if (el) el.textContent = client.naam;

    const tabsEl = document.getElementById('kb-ws-tabs');
    const werkruimte = document.getElementById('kb-werkruimte');

    // PRIVACY: alleen de direct gekoppelde begeleider mag data zien
    if (!client.eigen_client) {
      if (tabsEl) tabsEl.style.display = 'none';
      if (werkruimte) werkruimte.innerHTML = `
        <div class="kb-card" style="text-align:center;padding:56px 32px;border:2px solid #fca5a5;background:#fef2f2;">
          <div style="font-size:40px;margin-bottom:14px;">🔒</div>
          <div style="font-weight:800;font-size:16px;color:#dc2626;margin-bottom:8px;">Geen toegang</div>
          <div style="font-size:14px;color:#64748b;max-width:380px;margin:0 auto;line-height:1.6;">
            U bent niet als begeleider gekoppeld aan <strong>${esc(client.naam)}</strong>.
            Alleen de direct gekoppelde begeleider mag de gegevens inzien.
          </div>
          <button onclick="verzoekOvernemen(${client.id})" class="kb-btn kb-btn-purple" style="margin-top:20px;">📋 Overname aanvragen</button>
        </div>`;
      return;
    }

    // Toegang OK: toon tabs
    if (tabsEl) tabsEl.style.display='flex';
    document.querySelectorAll('.ws-tab-inhoud').forEach(t=>t.style.display='none');
    document.getElementById('ws-beroepen').style.display='block';
    document.querySelectorAll('.kb-ws-tab').forEach(t=>t.classList.remove('active'));
    document.querySelector('[data-wstab="beroepen"]')?.classList.add('active');

    if (werkruimte) werkruimte.innerHTML='<div style="padding:40px;text-align:center;color:#94a3b8;">Laden…</div>';

    apiFetch(`/aantekeningen/${client.id}`).then(async r=>{
      if (r.status === 403) {
        if (tabsEl) tabsEl.style.display='none';
        if (werkruimte) werkruimte.innerHTML=`
          <div class="kb-card" style="text-align:center;padding:56px 32px;border:2px solid #fca5a5;background:#fef2f2;">
            <div style="font-size:40px;margin-bottom:14px;">🔒</div>
            <div style="font-weight:800;font-size:16px;color:#dc2626;margin-bottom:8px;">Geen toegang</div>
          </div>`;
        return;
      }
      const data = await r.json();
      renderWerkruimte(client, data.selecties||{}, data.aantekeningen||{});
    });
  }

  // ── Beroepen werkruimte ───────────────────────────────
  function renderWerkruimte(client, selecties, aantekeningen) {
    const werkruimte = document.getElementById('kb-werkruimte');
    const aangevinkt = Object.values(selecties).filter(s=>s.vind_ik_leuk==1);

    if (!aangevinkt.length) {
      werkruimte.innerHTML=`<div class="kb-card" style="text-align:center;padding:48px;">
        <div style="font-size:36px;margin-bottom:12px;">📭</div>
        <div style="font-weight:700;">${esc(client.naam)} heeft nog geen beroepen aangevinkt</div>
        <div style="font-size:13px;color:#94a3b8;margin-top:6px;">De cliënt logt in via het portaal en vinkt beroepen aan.</div>
      </div>`; return;
    }

    const groepen = {};
    aangevinkt.forEach(s=>{ const sec=s.sector||'Overig'; if(!groepen[sec]) groepen[sec]=[]; groepen[sec].push(s); });

    let html = `
      <div class="kb-no-print" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
        <div>
          <div style="font-size:16px;font-weight:700;color:var(--kb-blue);">${esc(client.naam)}</div>
          <div style="font-size:12px;color:#64748b;">${aangevinkt.length} aangevinkte beroepen · ${esc(client.email)}</div>
        </div>
        <div style="display:flex;gap:8px;">
          <button onclick="kbAllesUitklappen()" class="kb-btn kb-btn-ghost kb-btn-sm">▼ Alles</button>
          <button onclick="kbAllesInklappen()" class="kb-btn kb-btn-ghost kb-btn-sm">▲ Alles</button>
          <button onclick="window.print()" class="kb-btn kb-btn-purple">🖨️ PDF-dossier</button>
        </div>
      </div>
      <div id="kb-aant-status" class="kb-save-status kb-no-print" style="margin-bottom:8px;text-align:right;font-size:12px;color:#94a3b8;min-height:18px;"></div>`;

    Object.keys(groepen).sort((a,z)=>a.localeCompare(z,'nl')).forEach(sector=>{
      html += `<div class="kb-sector-groep"><div class="kb-sector-titel">${esc(sector)}</div>`;
      groepen[sector].forEach(s=>{
        const a = aantekeningen[s.beroep_naam]||{};
        const sterren = parseInt(a.sterren)||0;
        const lvlCls = s.niveau==='Hoger'?'kb-lvl-hoger':s.niveau==='Basis'?'kb-lvl-basis':'kb-lvl-mbo';
        const lvlLbl = s.niveau==='Hoger'?'HBO/WO':s.niveau==='Basis'?'BASIS':'MBO';
        const dg = !!a.doelgroep_functie;
        const uid = 'dg-'+s.beroep_naam.replace(/[^a-z0-9]/gi,'-');

        html += `
        <div class="kb-aant-card" data-beroep="${esc(s.beroep_naam)}">
          <div class="kb-aant-kop">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
              <div style="display:flex;align-items:center;gap:8px;">
                <span class="kb-inklap-pijl">▼</span>
                <span style="font-weight:700;color:var(--kb-blue);font-size:14px;">${esc(s.beroep_naam)}</span>
              </div>
              <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
                ${(()=>{const bd=BEROEPEN_DATA.find(x=>x.n===s.beroep_naam);return bd&&bd.u?`<a href="${bd.u}" target="_blank" rel="noopener" title="Meer info op UWV werk.nl" style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#dbeafe;color:#2563eb;font-size:10px;font-weight:700;text-decoration:none;" onclick="event.stopPropagation()">ⓘ</a>`:'';})()}
                <span class="kb-lvl ${lvlCls}">${lvlLbl}</span>
                ${dg?'<span class="kb-badge-dg">DG-functie</span>':''}
                <span class="kb-sterren-preview">${'★'.repeat(sterren)}${'☆'.repeat(5-sterren)}</span>
              </div>
            </div>
            ${s.notitie?`<div class="kb-client-notitie-preview kb-no-print">💬 ${esc(s.notitie.substring(0,100))}${s.notitie.length>100?'…':''}</div>`:''}
          </div>
          <div class="kb-aant-body">
            <div class="kb-doelgroep-toggle ${dg?'actief':''}" style="margin-bottom:14px;">
              <input type="checkbox" class="kb-dg-cb" id="${uid}" ${dg?'checked':''}>
              <label for="${uid}" style="cursor:pointer;flex:1;">
                <div style="font-size:13px;font-weight:700;" class="kb-dg-label">${dg?'✓ Doelgroepfunctie':'Doelgroepfunctie'}</div>
                <div style="font-size:11px;color:#94a3b8;" class="kb-dg-sub">${dg?'Aangemerkt — klik om te wissen':'Klik om te markeren'}</div>
              </label>
            </div>
            <div style="margin-bottom:14px;">
              <div class="kb-field-label">Passendheid <span style="font-size:10px;font-weight:400;color:#94a3b8;">(klik actieve ster om te wissen)</span></div>
              <div class="kb-sterren-rij" data-huidig="${sterren}">
                ${[1,2,3,4,5].map(i=>`<button class="kb-ster ${sterren>=i?'actief':''}" data-val="${i}" type="button">★</button>`).join('')}
                <span class="kb-ster-label">${sterLabel(sterren)}</span>
              </div>
            </div>
            <div class="kb-aant-grid">
              <div>
                <div class="kb-field-label">LKS %</div>
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:12px;">
                  <input type="number" class="kb-field-number kb-lks-in" min="0" max="100" value="${a.lks_percentage??''}" placeholder="—">
                  <span style="font-size:12px;color:#94a3b8;">% WML</span>
                </div>
                <div class="kb-field-label">Advies</div>
                <textarea class="kb-field-textarea kb-advies-in" placeholder="Professioneel advies…">${esc(a.advies||'')}</textarea>
              </div>
              <div>
                <div class="kb-field-label">Vervolgstappen</div>
                <textarea class="kb-field-textarea kb-stappen-in" style="min-height:120px;" placeholder="1. Stage aanvragen…">${esc(a.vervolgstappen||'')}</textarea>
              </div>
            </div>
            ${s.notitie?`<div style="margin-top:10px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:10px 12px;">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#166534;margin-bottom:3px;">Notitie van cliënt</div>
              <div style="font-size:12px;color:#374151;">${esc(s.notitie)}</div>
            </div>`:''}
          </div>
        </div>`;
      });
      html += '</div>';
    });

    werkruimte.innerHTML = html;
    bindWerkruimteEvents(client);
  }

  function bindWerkruimteEvents(client) {
    // Kop klikbaar maken — FIX: direct onclick op het element zelf binden
    document.querySelectorAll('.kb-aant-kop').forEach(kop=>{
      kop.onclick = () => kbToggleInklap(kop);
    });

    // Doelgroep toggles
    document.querySelectorAll('.kb-doelgroep-toggle').forEach(el=>{
      const cb = el.querySelector('.kb-dg-cb');
      const kaart = el.closest('.kb-aant-card');
      cb.addEventListener('change', ()=>{
        const actief = cb.checked;
        el.classList.toggle('actief',actief);
        el.querySelector('.kb-dg-label').textContent = actief?'✓ Doelgroepfunctie':'Doelgroepfunctie';
        el.querySelector('.kb-dg-sub').textContent   = actief?'Aangemerkt — klik om te wissen':'Klik om te markeren';
        const bestaand = kaart?.querySelector('.kb-badge-dg');
        if (actief && !bestaand) {
          const prev = kaart.querySelector('.kb-sterren-preview');
          if (prev) { const sp=document.createElement('span'); sp.className='kb-badge-dg'; sp.textContent='DG-functie'; prev.before(sp); }
        } else if (!actief && bestaand) bestaand.remove();
        schedule(kaart.dataset.beroep);
      });
    });

    // Sterren
    document.querySelectorAll('.kb-sterren-rij').forEach(rij=>{
      const kaart = rij.closest('.kb-aant-card');
      const sterren = rij.querySelectorAll('.kb-ster');
      sterren.forEach(ster=>{
        ster.addEventListener('click', ()=>{
          const val = parseInt(ster.dataset.val);
          const huidig = parseInt(rij.dataset.huidig)||0;
          const nieuw  = huidig===val ? 0 : val;
          rij.dataset.huidig = nieuw;
          sterren.forEach(s=>s.classList.toggle('actief',parseInt(s.dataset.val)<=nieuw));
          rij.querySelector('.kb-ster-label').textContent = sterLabel(nieuw);
          const prev = kaart?.querySelector('.kb-sterren-preview');
          if (prev) prev.textContent = '★'.repeat(nieuw)+'☆'.repeat(5-nieuw);
          schedule(kaart.dataset.beroep);
        });
        ster.addEventListener('mouseenter', ()=>{ const v=parseInt(ster.dataset.val); sterren.forEach(s=>s.style.transform=parseInt(s.dataset.val)<=v?'scale(1.15)':''); });
        ster.addEventListener('mouseleave', ()=>sterren.forEach(s=>s.style.transform=''));
      });
    });

    // Tekstvelden
    document.querySelectorAll('.kb-advies-in,.kb-stappen-in,.kb-lks-in').forEach(el=>{
      el.addEventListener('input', ()=>schedule(el.closest('.kb-aant-card').dataset.beroep));
    });

    function schedule(beroep) {
      clearTimeout(saveTimer);
      const s = document.getElementById('kb-aant-status'); if(s) s.textContent='Opslaan…';
      saveTimer = setTimeout(()=>slaAantOp(beroep, client), 700);
    }
  }

  function slaAantOp(beroep, client) {
    const kaart   = document.querySelector(`.kb-aant-card[data-beroep="${CSS.escape(beroep)}"]`);
    if (!kaart) return;
    apiFetch(`/aantekeningen/${client.id}`, {method:'POST', body:JSON.stringify({
      beroep_naam:       beroep,
      sterren:           parseInt(kaart.querySelector('.kb-sterren-rij')?.dataset.huidig)||0,
      doelgroep_functie: kaart.querySelector('.kb-dg-cb')?.checked ? 1 : 0,
      lks_percentage:    kaart.querySelector('.kb-lks-in')?.value||'',
      advies:            kaart.querySelector('.kb-advies-in')?.value||'',
      vervolgstappen:    kaart.querySelector('.kb-stappen-in')?.value||'',
    })}).then(r=>r.json()).then(data=>{
      const s = document.getElementById('kb-aant-status');
      if (s) { s.textContent=data.ok?'✓ Opgeslagen':'⚠️ Fout'; setTimeout(()=>s.textContent='',2500); }
    });
  }

  // ── Logboek cliënt (readonly) ─────────────────────────
  function laadClientLogboek(client) {
    const el = document.getElementById('kb-ws-logboek-client');
    if (!el) return;
    el.innerHTML='<div style="padding:20px;color:#94a3b8;">Laden…</div>';
    apiFetch(`/logboek?client_id=${client.id}`).then(r=>r.json()).then(entries=>{
      if (!entries.length) {
        el.innerHTML=`<div class="kb-card" style="text-align:center;padding:40px;color:#94a3b8;">
          <div style="font-size:32px;">📋</div>
          <div style="font-weight:600;margin-top:8px;">Nog geen logboek-entries</div>
          <div style="font-size:13px;margin-top:4px;">${esc(client.naam)} heeft nog geen activiteiten bijgehouden.</div>
        </div>`; return;
      }
      const uren = entries.reduce((s,e)=>s+(parseFloat(e.uren)||0),0);
      const soll = entries.filter(e=>e.type==='sollicitatie').length;
      let html = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
          <div style="font-weight:700;color:var(--kb-blue);">Logboek van ${esc(client.naam)} <span style="font-weight:400;font-size:12px;color:#64748b;">(alleen inzien)</span></div>
          <div style="display:flex;gap:10px;">
            <span style="font-size:12px;color:#64748b;">📊 ${entries.length} activiteiten · ${uren>0?uren.toFixed(1)+' uur · ':''} ${soll} sollicitaties</span>
          </div>
        </div>`;
      const typKl = t => ({sollicitatie:{bg:'#dbeafe',kl:'#1d4ed8'},gesprek:{bg:'#dcfce7',kl:'#166534'},
        netwerk:{bg:'#ede9fe',kl:'#6d28d9'},opleiding:{bg:'#fef9c3',kl:'#92400e'},
        stage:{bg:'#fed7aa',kl:'#c2410c'},werkbezoek:{bg:'#e0f2fe',kl:'#0369a1'},
        jobcoach:{bg:'#fce7f3',kl:'#be185d'},overig:{bg:'#f1f5f9',kl:'#374151'}}[t])||{bg:'#f1f5f9',kl:'#374151'};
      const typLbl = t => ({sollicitatie:'📧 Sollicitatie',gesprek:'🤝 Gesprek',netwerk:'🌐 Netwerken',
        opleiding:'🎓 Opleiding',stage:'💼 Stage',werkbezoek:'🏢 Werkbezoek',
        jobcoach:'👩‍💼 Jobcoach',overig:'📝 Overig'}[t])||t;

      // Groepeer per maand
      const maanden = {};
      entries.forEach(e=>{
        const d = new Date(e.datum+'T00:00:00');
        const key = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
        const lbl = d.toLocaleDateString('nl-NL',{month:'long',year:'numeric'});
        if (!maanden[key]) maanden[key]={lbl,es:[]};
        maanden[key].es.push(e);
      });

      Object.keys(maanden).sort().reverse().forEach(key=>{
        const {lbl,es} = maanden[key];
        html += `<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;padding:6px 0 4px;border-bottom:1px solid #f1f5f9;margin-bottom:6px;">${lbl}</div>`;
        es.forEach(e=>{
          const {bg,kl} = typKl(e.type);
          const dat = new Date(e.datum+'T00:00:00').toLocaleDateString('nl-NL',{weekday:'short',day:'numeric',month:'short'});
          html += `<div class="kb-log-entry" style="opacity:.9;">
            <div class="kb-log-datum">
              <div style="font-size:9px;text-transform:uppercase;color:#94a3b8;">${dat.split(' ')[0]}</div>
              <div style="font-size:20px;font-weight:800;color:var(--kb-blue);line-height:1.1;">${dat.split(' ')[1]}</div>
              <div style="font-size:9px;color:#94a3b8;">${dat.split(' ').slice(2).join(' ')}</div>
            </div>
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                <span style="background:${bg};color:${kl};padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;">${typLbl(e.type)}</span>
                ${e.uren?`<span style="font-size:11px;color:#64748b;">${e.uren} uur</span>`:''}
              </div>
              <div style="font-size:12px;font-weight:600;color:#374151;">${esc(e.omschrijving)}</div>
              ${e.resultaat?`<div style="margin-top:4px;font-size:11px;color:#64748b;background:#f8fafc;border-radius:6px;padding:4px 8px;"><strong>Resultaat:</strong> ${esc(e.resultaat)}</div>`:''}
            </div>
          </div>`;
        });
      });

      el.innerHTML = html;
    });
  }

  // ── CV cliënt (inzien) ────────────────────────────────
  function laadClientCV(client) {
    const el = document.getElementById('kb-ws-cv-client');
    if (!el) return;
    el.innerHTML='<div style="padding:20px;color:#94a3b8;">Laden…</div>';
    apiFetch(`/cv/${client.id}`).then(r=>r.json()).then(data=>{
      const cv = data.cv;
      if (!cv) {
        el.innerHTML=`<div class="kb-card" style="text-align:center;padding:40px;color:#94a3b8;">
          <div style="font-size:32px;">📄</div>
          <div style="font-weight:600;margin-top:8px;">Geen CV</div>
          <div style="font-size:13px;margin-top:4px;">${esc(client.naam)} heeft nog geen CV geüpload.</div>
        </div>`; return;
      }

      const downloadUrl = `${KB.apiUrl}/cv/${client.id}/download?_wpnonce=${KB.nonce}`;
      let html = `<div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
          <div style="font-weight:700;color:var(--kb-blue);">CV van ${esc(client.naam)}</div>
        </div>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
          <span style="font-size:28px;">📄</span>
          <div style="flex:1;">
            <div style="font-weight:700;color:#166534;">${esc(cv.bestandsnaam)}</div>
            <div style="font-size:12px;color:#64748b;">Geüpload: ${fmtDatum(cv.geupload)}</div>
          </div>
          <a href="${downloadUrl}" download class="kb-btn kb-btn-ghost kb-btn-sm" style="display:inline-flex;align-items:center;gap:5px;">⬇️ CV downloaden</a>
        </div>`;

      if (cv.samenvatting) {
        try {
          const p = JSON.parse(cv.samenvatting);
          if (p.samenvatting) html += `<div class="kb-card" style="margin-bottom:14px;">
            <div style="font-weight:700;color:var(--kb-blue);margin-bottom:8px;">📊 Profiel samenvatting</div>
            <div style="font-size:13px;color:#374151;line-height:1.6;">${esc(p.samenvatting)}</div>
            ${p.profiel?.kernvaardigheden ? `<div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">${p.profiel.kernvaardigheden.map(v=>`<span style="background:#ede9fe;color:#6d28d9;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;">${esc(v)}</span>`).join('')}</div>` : ''}
          </div>`;
        } catch(e) {}
      }

      if (cv.verbeterpunten) {
        try {
          const v = JSON.parse(cv.verbeterpunten);
          if (v?.length) html += `<div class="kb-card" style="margin-bottom:14px;">
            <div style="font-weight:700;color:var(--kb-blue);margin-bottom:8px;">💡 Verbeterpunten (AI)</div>
            ${v.map(p=>`<div style="background:#fff7ed;border-left:3px solid var(--kb-orange);border-radius:8px;padding:10px;margin-bottom:6px;font-size:12px;">
              <div style="font-weight:700;color:var(--kb-orange);">${esc(p.punt||p)}</div>
              ${p.toelichting?`<div style="color:#64748b;margin-top:3px;">${esc(p.toelichting)}</div>`:''}
            </div>`).join('')}
          </div>`;
        } catch(e) {}
      }

      if (cv.tekst) {
        html += `<div class="kb-card" style="margin-bottom:14px;">
          <div style="font-weight:700;color:var(--kb-blue);margin-bottom:8px;">📝 CV Tekst</div>
          <div style="font-size:12px;color:#374151;line-height:1.6;white-space:pre-wrap;max-height:300px;overflow-y:auto;background:#f8fafc;padding:12px;border-radius:8px;">${esc(cv.tekst.substring(0,3000))}${cv.tekst.length>3000?'…':''}</div>
        </div>`;
      }

      // Review brieven sectie voor begeleider
      html += `<div id="kb-begel-brieven-sectie"></div>`;
      el.innerHTML = html;

      // Laad review-brieven apart
      apiFetch(`/brieven/${client.id}`).then(r=>r.json()).then(brieven=>{
        const sectie = document.getElementById('kb-begel-brieven-sectie');
        if (!sectie) return;
        const review = (brieven||[]).filter(b=>b.review_aangevraagd==1);
        if (!review.length) {
          sectie.innerHTML=`<div class="kb-card" style="color:#94a3b8;text-align:center;padding:24px;font-size:13px;">Geen brieven ter controle ingediend.</div>`;
          return;
        }
        let bHtml = `<div class="kb-card"><div style="font-weight:700;color:var(--kb-blue);font-size:15px;margin-bottom:14px;">📬 Sollicitatiebrieven ter controle</div>`;
        review.forEach(b=>{
          const statusKleur = b.review_status==='goedgekeurd'
            ? 'background:#f0fdf4;color:#166534;border-color:#86efac'
            : b.review_status==='aanpassen'
            ? 'background:#fffbeb;color:#92400e;border-color:#fde68a'
            : 'background:#eff6ff;color:#1e40af;border-color:#bfdbfe';
          const statusLabel = b.review_status==='goedgekeurd' ? '✅ Goedgekeurd'
            : b.review_status==='aanpassen' ? '📝 Aanpassen'
            : '⏳ Wacht op review';
          const downloadUrl = `${KB.apiUrl}/brieven/${b.id}/download?_wpnonce=${KB.nonce}`;
          bHtml += `<div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
              <div>
                <div style="font-weight:700;color:#1e293b;">${esc(b.vacature_titel||'Sollicitatiebrief')}</div>
                <div style="font-size:11px;color:#94a3b8;margin-top:2px;">${fmtDatum(b.aangemaakt)} · ${b.ai_gegenereerd?'🤖 AI':'✍️ Handmatig'}${b.heeft_bestand?' · 📎 Bestand':''}</div>
              </div>
              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid;${statusKleur}">${statusLabel}</span>
                <a href="${downloadUrl}" download class="kb-btn kb-btn-ghost kb-btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;">⬇️ Download</a>
              </div>
            </div>
            ${b.brief_tekst && !b.heeft_bestand ? `<div style="background:#f8fafc;border-radius:8px;padding:12px;font-size:12px;color:#374151;line-height:1.6;max-height:200px;overflow-y:auto;white-space:pre-wrap;margin-bottom:12px;">${esc(b.brief_tekst.substring(0,1000))}${b.brief_tekst.length>1000?'…':''}</div>` : ''}
            ${b.review_feedback ? `<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 12px;font-size:12px;color:#166534;margin-bottom:12px;"><strong>Jouw feedback:</strong> ${esc(b.review_feedback)}</div>` : ''}
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
              <select id="review-status-${b.id}" class="kb-login-input" style="flex:1;min-width:140px;padding:6px 10px;font-size:12px;">
                <option value="goedgekeurd" ${b.review_status==='goedgekeurd'?'selected':''}>✅ Goedkeuren</option>
                <option value="aanpassen" ${b.review_status==='aanpassen'?'selected':''}>📝 Aanpassen nodig</option>
              </select>
              <input type="text" id="review-fb-${b.id}" class="kb-login-input" style="flex:2;min-width:180px;padding:6px 10px;font-size:12px;" placeholder="Feedback (optioneel)…" value="${esc(b.review_feedback||'')}">
              <button onclick="stuurFeedback(${b.id},${client.id})" class="kb-btn kb-btn-purple kb-btn-sm">Feedback sturen</button>
            </div>
          </div>`;
        });
        bHtml += '</div>';
        sectie.innerHTML = bHtml;
      });
    });
  }

  // ── Begeleider: brieven tab (eigen tab) ──────────────────
  function laadClientBrieven(client) {
    const el = document.getElementById('kb-ws-brieven-client');
    if (!el) return;
    el.innerHTML='<div style="padding:20px;color:#94a3b8;">Laden…</div>';
    apiFetch(`/brieven/${client.id}`).then(r=>r.json()).then(brieven=>{
      const review = (brieven||[]).filter(b=>b.review_aangevraagd==1);
      if (!review.length) {
        el.innerHTML=`<div class="kb-card" style="text-align:center;padding:40px;color:#94a3b8;"><div style="font-size:32px;">✉️</div><div style="font-weight:600;margin-top:8px;">Geen brieven ter controle ingediend</div><div style="font-size:13px;margin-top:4px;">${esc(client.naam)} heeft nog geen review aangevraagd.</div></div>`;
        return;
      }
      let html = `<div class="kb-card"><div style="font-weight:700;color:var(--kb-blue);font-size:15px;margin-bottom:14px;">📬 Brieven ter controle — ${esc(client.naam)}</div>`;
      review.forEach(b=>{
        const statusKleur = b.review_status==='goedgekeurd'
          ? 'background:#f0fdf4;color:#166534;border-color:#86efac'
          : b.review_status==='aanpassen'
          ? 'background:#fffbeb;color:#92400e;border-color:#fde68a'
          : 'background:#eff6ff;color:#1e40af;border-color:#bfdbfe';
        const statusLabel = b.review_status==='goedgekeurd' ? '✅ Goedgekeurd'
          : b.review_status==='aanpassen' ? '📝 Aanpassen'
          : '⏳ Wacht op review';
        const downloadUrl = `${KB.apiUrl}/brieven/${b.id}/download?_wpnonce=${KB.nonce}`;
        const open = true; // standaard open
        html += `<div style="border:1px solid #e2e8f0;border-radius:12px;margin-bottom:12px;overflow:hidden;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 16px;background:#f8fafc;cursor:pointer;" onclick="klapBegelBriefToggle(${b.id})">
            <div>
              <div style="font-weight:700;color:#1e293b;">${esc(b.vacature_titel||'Sollicitatiebrief')}</div>
              <div style="font-size:11px;color:#94a3b8;margin-top:2px;">${fmtDatum(b.aangemaakt)} · ${b.ai_gegenereerd?'🤖 AI':'✍️ Handmatig'}${b.heeft_bestand?' · 📎 Bestand':''}</div>
            </div>
            <div style="display:flex;gap:6px;align-items:center;">
              <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid;${statusKleur}">${statusLabel}</span>
              <a href="${downloadUrl}" download class="kb-btn kb-btn-ghost kb-btn-sm" style="font-size:11px;" onclick="event.stopPropagation()">⬇️ Download</a>
              <span id="bbr-pijl-${b.id}" style="color:#94a3b8;font-size:12px;">▲</span>
            </div>
          </div>
          <div id="bbr-body-${b.id}" style="padding:14px 16px;">
            ${b.brief_tekst && !b.heeft_bestand ? `<div style="background:#f8fafc;border-radius:8px;padding:12px;font-size:12px;color:#374151;line-height:1.6;max-height:250px;overflow-y:auto;white-space:pre-wrap;margin-bottom:12px;">${esc(b.brief_tekst.substring(0,1500))}${b.brief_tekst.length>1500?'…':''}</div>` : ''}
            ${b.heeft_bestand ? `<div style="padding:10px 0;font-size:13px;color:#64748b;">📎 Geüpload bestand — <a href="${downloadUrl}" download style="color:var(--kb-blue);">Downloaden</a></div>` : ''}
            ${b.review_feedback ? `<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 12px;font-size:12px;color:#166534;margin-bottom:12px;"><strong>Jouw eerdere feedback:</strong> ${esc(b.review_feedback)}</div>` : ''}
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
              <select id="review-status-${b.id}" class="kb-login-input" style="flex:1;min-width:140px;padding:6px 10px;font-size:12px;">
                <option value="goedgekeurd" ${b.review_status==='goedgekeurd'?'selected':''}>✅ Goedkeuren</option>
                <option value="aanpassen" ${b.review_status==='aanpassen'?'selected':''}>📝 Aanpassen nodig</option>
              </select>
              <input type="text" id="review-fb-${b.id}" class="kb-login-input" style="flex:2;min-width:180px;padding:6px 10px;font-size:12px;" placeholder="Feedback (optioneel)…" value="${esc(b.review_feedback||'')}">
              <button onclick="stuurFeedback(${b.id},${client.id})" class="kb-btn kb-btn-purple kb-btn-sm">Feedback sturen</button>
            </div>
          </div>
        </div>`;
      });
      html += '</div>';
      el.innerHTML = html;
    });
  }

  window.klapBegelBriefToggle = function(id) {
    const body = document.getElementById('bbr-body-'+id);
    const pijl = document.getElementById('bbr-pijl-'+id);
    if (!body) return;
    const open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    if (pijl) pijl.textContent = open ? '▼' : '▲';
  };

  // Override stuurFeedback to also reload brieven tab
  window.stuurFeedback = function(briefId, clientId) {
    const status   = document.getElementById('review-status-'+briefId)?.value || 'goedgekeurd';
    const feedback = document.getElementById('review-fb-'+briefId)?.value.trim() || '';
    const btn = document.querySelector(`button[onclick="stuurFeedback(${briefId},${clientId})"]`);
    if (btn) { btn.disabled = true; btn.textContent = 'Opslaan…'; }

    apiFetch(`/brieven/${briefId}/review-feedback`, {
      method: 'POST',
      body: JSON.stringify({ status, feedback })
    }).then(r=>r.json()).then(data=>{
      if (data.ok) {
        if (btn) { btn.textContent = '✅ Opgeslagen'; btn.style.background='#f0fdf4'; btn.style.color='#166534'; }
        setTimeout(() => {
          laadClientBrieven(huidigClient);
          laadClientCV(huidigClient);
        }, 1500);
      } else {
        if (btn) { btn.disabled = false; btn.textContent = 'Feedback sturen'; }
        alert('❌ ' + (data.error || 'Opslaan mislukt'));
      }
    }).catch(() => {
      if (btn) { btn.disabled = false; btn.textContent = 'Feedback sturen'; }
    });
  };

  // ── Opdrachten (begeleider zet klaar, cliënt levert in) ──
  function laadOpdrachten(client) {
    const el = document.getElementById('kb-ws-opdrachten');
    if (!el) return;
    el.innerHTML='<div style="padding:20px;color:#94a3b8;">Laden…</div>';
    apiFetch(`/opdrachten/${client.id}`).then(r=>r.json()).then(opdrachten=>{
      renderOpdrachten(client, opdrachten||[]);
    });
  }

  function renderOpdrachten(client, opdrachten) {
    const el = document.getElementById('kb-ws-opdrachten');
    const statusKl = s => ({openstaand:{bg:'#e0f2fe',kl:'#0369a1'},ingeleverd:{bg:'#dcfce7',kl:'#166534'},beoordeeld:{bg:'#ede9fe',kl:'#6d28d9'}}[s])||{bg:'#f1f5f9',kl:'#374151'};
    const statusLbl = s => ({openstaand:'⏳ Openstaand',ingeleverd:'📥 Ingeleverd',beoordeeld:'✅ Beoordeeld'}[s])||s;

    let html = `
      <div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div style="font-weight:700;color:var(--kb-blue);">Opdrachten — ${esc(client.naam)}</div>
      </div>
      <div class="kb-card" style="margin-bottom:16px;">
        <div style="font-weight:700;color:var(--kb-blue);font-size:14px;margin-bottom:12px;">📎 Nieuwe opdracht klaarzetten</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
          <div><label class="kb-field-label">Titel *</label><input type="text" id="opdr-titel" class="kb-login-input" placeholder="bijv. Sollicitatiebrief schrijven"></div>
          <div><label class="kb-field-label">Omschrijving</label><input type="text" id="opdr-omschr" class="kb-login-input" placeholder="Instructies voor de cliënt…"></div>
        </div>
        <div style="margin-bottom:10px;">
          <label class="kb-field-label">Bijlage (optioneel — PDF, Word, etc.)</label>
          <input type="file" id="opdr-file" class="kb-login-input" style="padding:6px;" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.jpg,.png">
        </div>
        <button onclick="maakOpdracht(${client.id})" class="kb-btn kb-btn-purple">📤 Opdracht klaarzetten</button>
        <div id="opdr-status" style="font-size:13px;margin-top:8px;min-height:18px;"></div>
      </div>`;

    if (!opdrachten.length) {
      html += '<div style="text-align:center;padding:24px;color:#94a3b8;font-size:13px;">Nog geen opdrachten klaargezet.</div>';
    } else {
      opdrachten.forEach(o=>{
        const {bg,kl} = statusKl(o.status);
        const downloadUrl = `${KB.apiUrl}/opdrachten/download/${o.id}?_wpnonce=${KB.nonce}`;
        html += `<div class="kb-doc-item">
          <div class="kb-doc-icon">📚</div>
          <div class="kb-doc-info">
            <div class="kb-doc-naam">${esc(o.titel)}</div>
            <div class="kb-doc-meta">
              ${o.omschrijving?esc(o.omschrijving)+' · ':''}${fmtDatum(o.aangemaakt)}
              <span style="background:${bg};color:${kl};padding:1px 8px;border-radius:10px;font-size:10px;font-weight:700;margin-left:6px;">${statusLbl(o.status)}</span>
            </div>
            ${o.inlevering_naam ? `<div style="font-size:11px;color:#166534;margin-top:3px;">📥 Ingeleverd: ${esc(o.inlevering_naam)} · <a href="${downloadUrl}" download style="color:var(--kb-blue);">Download inlevering</a></div>` : ''}
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0;">
            ${o.opdracht_naam ? `<a href="${KB.apiUrl}/opdrachten/download/${o.id}?_wpnonce=${KB.nonce}&type=opdracht" class="kb-btn kb-btn-ghost kb-btn-sm" download>⬇️ Opdracht</a>` : ''}
            ${o.inlevering_naam ? `<a href="${downloadUrl}" class="kb-btn kb-btn-ghost kb-btn-sm" download>📥 Inlevering</a>` : ''}
            <button onclick="verwijderOpdracht(${o.id},${client.id})" class="kb-btn kb-btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;padding:4px 8px;font-size:11px;">✕</button>
          </div>
        </div>`;
      });
    }
    el.innerHTML = html;
  }

  window.maakOpdracht = function(clientId) {
    const titel  = document.getElementById('opdr-titel')?.value.trim();
    const omschr = document.getElementById('opdr-omschr')?.value.trim();
    const file   = document.getElementById('opdr-file')?.files?.[0];
    const stat   = document.getElementById('opdr-status');
    if (!titel) { if(stat) stat.innerHTML='<span style="color:#dc2626;">Titel is verplicht.</span>'; return; }
    if(stat) stat.innerHTML='<span style="color:var(--kb-purple);">⏳ Uploaden…</span>';
    const fd = new FormData();
    fd.append('client_id',   clientId);
    fd.append('titel',       titel);
    fd.append('omschrijving', omschr||'');
    if (file) fd.append('opdracht_bestand', file);
    fetch(KB.apiUrl + '/opdrachten/upload', {
      method: 'POST',
      headers: {'X-WP-Nonce': KB.nonce},
      body: fd,
      credentials: 'same-origin'
    })
    .then(r=>r.json()).then(data=>{
      if (data.ok||data.id) {
        if(stat) stat.innerHTML='<span style="color:#166534;">✅ Opdracht klaargezet!</span>';
        document.getElementById('opdr-titel').value='';
        document.getElementById('opdr-omschr').value='';
        document.getElementById('opdr-file').value='';
        setTimeout(()=>stat.innerHTML='',3000);
        laadOpdrachten(huidigClient);
      } else {
        if(stat) stat.innerHTML='<span style="color:#dc2626;">❌ '+(data.data?.error||data.error||'Mislukt')+'</span>';
      }
    }).catch(()=>{ if(stat) stat.innerHTML='<span style="color:#dc2626;">❌ Verbindingsfout.</span>'; });
  };

  window.verwijderOpdracht = function(id, clientId) {
    if (!confirm('Opdracht verwijderen?')) return;
    apiFetch(`/opdrachten/${id}`, {method:'DELETE'}).then(()=>laadOpdrachten(huidigClient));
  };

  // ── Begeleider logboek (eigen notities over cliënt) ───
  function laadBegelLogboek(client) {
    const el = document.getElementById('kb-ws-begel-logboek');
    if (!el) return;
    el.innerHTML='<div style="padding:20px;color:#94a3b8;">Laden…</div>';
    apiFetch(`/begel-logboek/${client.id}`).then(r=>r.json()).then(entries=>{
      renderBegelLogboek(client, entries||[]);
    });
  }

  function renderBegelLogboek(client, entries) {
    const el = document.getElementById('kb-ws-begel-logboek');
    const IS_LEID = KB.isLeid == 1;
    const typKl = t => ({gesprek:{bg:'#dcfce7',kl:'#166534'},email:{bg:'#dbeafe',kl:'#1d4ed8'},
      belafspraak:{bg:'#ede9fe',kl:'#6d28d9'},voortgang:{bg:'#fef9c3',kl:'#92400e'},
      rapport:{bg:'#fed7aa',kl:'#c2410c'},overig:{bg:'#f1f5f9',kl:'#374151'}}[t])||{bg:'#f1f5f9',kl:'#374151'};
    const typLbl = t => ({gesprek:'🤝 Gesprek',email:'📧 E-mail',belafspraak:'📞 Belafspraak',
      voortgang:'📈 Voortgangsrapportage',rapport:'📋 Rapport',overig:'📝 Overig'}[t])||t;

    let html = `
      <div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div style="font-weight:700;color:var(--kb-blue);">Mijn logboek over ${esc(client.naam)}</div>
      </div>
      <!-- Nieuw item toevoegen -->
      <div class="kb-card" style="margin-bottom:16px;">
        <div style="font-weight:700;color:var(--kb-blue);font-size:14px;margin-bottom:12px;">+ Nieuwe aantekening</div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
          <div>
            <label class="kb-field-label">Datum *</label>
            <input type="date" id="bgl-datum" class="kb-login-input" value="${new Date().toISOString().split('T')[0]}">
          </div>
          <div>
            <label class="kb-field-label">Type *</label>
            <select id="bgl-type" style="width:100%;padding:10px 12px;border:1.5px solid var(--kb-border);border-radius:10px;font-size:13px;font-family:inherit;outline:none;background:white;">
              <option value="gesprek">🤝 Gesprek</option>
              <option value="email">📧 E-mail</option>
              <option value="belafspraak">📞 Belafspraak</option>
              <option value="voortgang">📈 Voortgangsrapportage</option>
              <option value="rapport">📋 Rapport</option>
              <option value="overig">📝 Overig</option>
            </select>
          </div>
          <div style="display:flex;align-items:flex-end;">
            <button onclick="voegBegelLogToe(${client.id})" class="kb-btn kb-btn-purple" style="width:100%;">Toevoegen</button>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label class="kb-field-label">Omschrijving *</label>
            <textarea id="bgl-omschrijving" class="kb-notitie" style="min-height:72px;" placeholder="Wat is er besproken of gedaan?"></textarea>
          </div>
          <div>
            <label class="kb-field-label">Vervolg / actie</label>
            <textarea id="bgl-vervolg" class="kb-notitie" style="min-height:72px;" placeholder="Vervolgafspraak, actie, deadline…"></textarea>
          </div>
        </div>
        <div id="bgl-save-status" style="font-size:12px;color:#64748b;margin-top:8px;min-height:16px;"></div>
      </div>`;

    if (!entries.length) {
      html += '<div style="text-align:center;padding:24px;color:#94a3b8;font-size:13px;">Nog geen logboek-entries.</div>';
    } else {
      entries.forEach(e=>{
        const {bg,kl} = typKl(e.type);
        const bewerktCount = parseInt(e.bewerkt_count)||0;
        const magBewerken  = IS_LEID || bewerktCount < 1; // max 1x voor begeleider, onbeperkt voor leid
        const magVerwijderen = IS_LEID; // alleen leid mag verwijderen
        html += `<div class="kb-begel-log-entry" id="bgl-entry-${e.id}">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;gap:8px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="background:${bg};color:${kl};padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;">${typLbl(e.type)}</span>
              <span style="font-size:12px;color:#64748b;">${fmtDatum(e.datum)}</span>
              ${bewerktCount>0&&!IS_LEID ? '<span style="font-size:10px;color:#94a3b8;background:#f1f5f9;padding:1px 6px;border-radius:6px;">✏️ 1× bewerkt (max bereikt)</span>' : ''}
            </div>
            <div style="display:flex;gap:4px;">
              ${magBewerken ? `<button onclick="bewerkBegelLog(${e.id})" class="kb-btn kb-btn-ghost kb-btn-sm" style="padding:3px 8px;font-size:11px;">${bewerktCount>0&&!IS_LEID?'⚠️':'✏️'}</button>` : '<span style="font-size:10px;color:#94a3b8;padding:3px 6px;">🔒 niet meer aan te passen</span>'}
              ${magVerwijderen ? `<button onclick="verwijderBegelLog(${e.id},${client.id})" class="kb-btn kb-btn-sm" style="padding:3px 8px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;font-size:11px;">✕</button>` : ''}
            </div>
          </div>
          <div style="font-size:13px;color:#374151;font-weight:500;">${esc(e.omschrijving)}</div>
          ${e.vervolg?`<div style="margin-top:6px;font-size:12px;color:#64748b;background:#f8fafc;border-radius:8px;padding:6px 10px;"><strong>Vervolg:</strong> ${esc(e.vervolg)}</div>`:''}
          <div id="bgl-edit-${e.id}" style="display:none;margin-top:10px;background:#f8fafc;border-radius:8px;padding:12px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
              <div><label class="kb-field-label">Omschrijving</label><textarea class="kb-notitie" id="bgl-edit-omschr-${e.id}" style="min-height:60px;">${esc(e.omschrijving)}</textarea></div>
              <div><label class="kb-field-label">Vervolg</label><textarea class="kb-notitie" id="bgl-edit-vervolg-${e.id}" style="min-height:60px;">${esc(e.vervolg||'')}</textarea></div>
            </div>
            <div style="display:flex;gap:6px;">
              <button onclick="slaBegelLogOp(${e.id},${client.id})" class="kb-btn kb-btn-purple kb-btn-sm">💾 Opslaan</button>
              <button onclick="document.getElementById('bgl-edit-${e.id}').style.display='none'" class="kb-btn kb-btn-ghost kb-btn-sm">Annuleren</button>
            </div>
          </div>
        </div>`;
      });
    }
    el.innerHTML = html;
  }

  window.bewerkBegelLog = function(id) {
    const editDiv = document.getElementById('bgl-edit-'+id);
    if (editDiv) editDiv.style.display = editDiv.style.display === 'none' ? 'block' : 'none';
  };

  window.slaBegelLogOp = function(id, clientId) {
    const omschr  = document.getElementById('bgl-edit-omschr-'+id)?.value.trim();
    const vervolg = document.getElementById('bgl-edit-vervolg-'+id)?.value.trim();
    apiFetch(`/begel-logboek/entry/${id}`, {method:'PATCH', body:JSON.stringify({omschrijving:omschr, vervolg})})
    .then(r=>r.json()).then(data=>{
      if (data.ok) laadBegelLogboek(huidigClient);
      else if (data.error) alert('❌ '+data.error);
    });
  };

  window.voegBegelLogToe = function(clientId) {
    const datum    = document.getElementById('bgl-datum')?.value;
    const type     = document.getElementById('bgl-type')?.value;
    const omschr   = document.getElementById('bgl-omschrijving')?.value.trim();
    const vervolg  = document.getElementById('bgl-vervolg')?.value.trim();
    const stat     = document.getElementById('bgl-save-status');
    if (!datum||!omschr) { if(stat) stat.textContent='Datum en omschrijving zijn verplicht.'; return; }
    apiFetch(`/begel-logboek/${clientId}`, {method:'POST', body:JSON.stringify({datum,type,omschrijving:omschr,vervolg})})
    .then(r=>r.json()).then(data=>{
      if (data.ok) {
        if (stat) { stat.innerHTML='<span style="color:#166534;">✅ Opgeslagen!</span>'; setTimeout(()=>stat.textContent='',2500); }
        document.getElementById('bgl-omschrijving').value='';
        document.getElementById('bgl-vervolg').value='';
        laadBegelLogboek(huidigClient);
      } else { if(stat) stat.textContent='❌ Fout bij opslaan.'; }
    });
  };

  window.verwijderBegelLog = function(id, clientId) {
    if (!confirm('Aantekening verwijderen?')) return;
    apiFetch(`/begel-logboek/entry/${id}`, {method:'DELETE'}).then(()=>laadBegelLogboek(huidigClient));
  };

  // ── Documenten ────────────────────────────────────────
  function laadDocumenten(client) {
    const el = document.getElementById('kb-ws-documenten');
    if (!el) return;
    el.innerHTML='<div style="padding:20px;color:#94a3b8;">Laden…</div>';
    apiFetch(`/documenten/${client.id}`).then(r=>r.json()).then(docs=>{
      renderDocumenten(client, docs||[]);
    });
  }

  function renderDocumenten(client, docs) {
    const el = document.getElementById('kb-ws-documenten');
    let html = `
      <div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div style="font-weight:700;color:var(--kb-blue);">Documenten — ${esc(client.naam)}</div>
      </div>
      <!-- Upload -->
      <div class="kb-card" style="margin-bottom:16px;">
        <div style="font-weight:700;color:var(--kb-blue);font-size:14px;margin-bottom:12px;">📎 Document uploaden</div>
        <div class="kb-doc-dropzone" id="kb-doc-dropzone"
          onclick="document.getElementById('kb-doc-file').click()"
          ondragover="event.preventDefault();this.style.borderColor='var(--kb-purple)';"
          ondragleave="this.style.borderColor='#c4b5fd';"
          ondrop="handleDocDrop(event,${client.id})">
          <div style="font-size:28px;margin-bottom:6px;">📎</div>
          <div style="font-weight:700;color:var(--kb-purple);font-size:13px;">Klik of sleep een bestand</div>
          <div style="font-size:11px;color:#94a3b8;margin-top:4px;">PDF, Word, Excel, afbeelding — max 10MB</div>
        </div>
        <input type="file" id="kb-doc-file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" style="display:none;" onchange="uploadDoc(this.files[0],${client.id})">
        <div style="margin-top:10px;">
          <label class="kb-field-label">Omschrijving (optioneel)</label>
          <input type="text" id="kb-doc-omschrijving" class="kb-login-input" placeholder="bijv. Arbeidsdeskundig rapport 2025">
        </div>
        <div id="kb-doc-status" style="font-size:13px;margin-top:8px;min-height:18px;"></div>
      </div>`;

    if (!docs.length) {
      html += '<div style="text-align:center;padding:24px;color:#94a3b8;font-size:13px;">Nog geen documenten geüpload.</div>';
    } else {
      docs.forEach(d=>{
        html += `<div class="kb-doc-item">
          <div class="kb-doc-icon">${mimeIcon(d.mime_type)}</div>
          <div class="kb-doc-info">
            <div class="kb-doc-naam">${esc(d.bestandsnaam)}</div>
            <div class="kb-doc-meta">${d.omschrijving?esc(d.omschrijving)+' · ':''}${fmtSize(d.bestandsgrootte)} · ${fmtDatum(d.aangemaakt)}</div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0;">
            <a href="${KB.apiUrl}/documenten/download/${d.id}?_wpnonce=${KB.nonce}" class="kb-btn kb-btn-ghost kb-btn-sm" download>⬇️</a>
            <button onclick="verwijderDoc(${d.id},${client.id})" class="kb-btn kb-btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;padding:4px 8px;font-size:11px;">✕</button>
          </div>
        </div>`;
      });
    }

    el.innerHTML = html;
  }

  window.handleDocDrop = function(e, clientId) {
    e.preventDefault();
    const dz = document.getElementById('kb-doc-dropzone');
    if (dz) dz.style.borderColor='#c4b5fd';
    const file = e.dataTransfer?.files?.[0];
    if (file) uploadDoc(file, clientId);
  };

  window.uploadDoc = function(file, clientId) {
    if (!file) return;
    const stat = document.getElementById('kb-doc-status');
    if (file.size > 10*1024*1024) { stat.innerHTML='<span style="color:#dc2626;">❌ Max 10MB per bestand.</span>'; return; }
    stat.innerHTML='<span style="color:var(--kb-purple);">⏳ Uploaden…</span>';
    const fd = new FormData();
    fd.append('action',      'kb_upload_document');
    fd.append('nonce',       KB.docNonce);
    fd.append('client_id',   clientId);
    fd.append('document',    file);
    fd.append('omschrijving', document.getElementById('kb-doc-omschrijving')?.value||'');
    fetch(KB.ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
    .then(r=>r.json()).then(data=>{
      if (data.success) {
        stat.innerHTML='<span style="color:#166534;">✅ Geüpload!</span>';
        if (document.getElementById('kb-doc-omschrijving')) document.getElementById('kb-doc-omschrijving').value='';
        setTimeout(()=>stat.innerHTML='',3000);
        laadDocumenten(huidigClient);
      } else {
        stat.innerHTML='<span style="color:#dc2626;">❌ '+(data.data?.error||'Upload mislukt.')+'</span>';
      }
    }).catch(()=>stat.innerHTML='<span style="color:#dc2626;">❌ Verbindingsfout.</span>');
  };

  window.verwijderDoc = function(id, clientId) {
    if (!confirm('Document verwijderen?')) return;
    apiFetch(`/documenten/${id}`, {method:'DELETE'}).then(()=>laadDocumenten(huidigClient));
  };

  // ── NAW gegevens ──────────────────────────────────────
  function laadNAW(client) {
    if (!client) return;
    const velden = ['naam','email','telefoon','geboortedatum','adres','postcode','woonplaats','bsn','notitie'];
    velden.forEach(v => {
      const el = document.getElementById('naw-'+v);
      if (el) el.textContent = client[v] || '—';
      const inp = document.getElementById('nawi-'+v);
      if (inp) inp.value = client[v] || '';
    });
    // Leidinggevende acties sectie
    const IS_LEID = KB.isLeid == 1;
    const leidActies = document.getElementById('kb-leid-acties');
    if (leidActies) leidActies.style.display = IS_LEID ? 'block' : 'none';
    if (IS_LEID) {
      const begel_sel = document.getElementById('kb-leid-begel-sel');
      if (begel_sel) {
        begel_sel.innerHTML = '<option value="">— Geen begeleider —</option>';
        // Gebruik /mijn-team-begeleiders: toegankelijk voor leidinggevende (geen admin rechten nodig)
        apiFetch('/mijn-team-begeleiders').then(r=>r.json()).then(begels=>{
          (begels||[]).forEach(b=>{
            const opt = document.createElement('option');
            opt.value=b.id; opt.textContent=b.naam;
            if (b.id==client.begeleider_id) opt.selected=true;
            begel_sel.appendChild(opt);
          });
          if ((begels||[]).length===0) {
            begel_sel.innerHTML='<option value="">— Geen begeleiders in uw team —</option>';
          }
        }).catch(()=>{
          begel_sel.innerHTML='<option value="">— Fout bij laden begeleiders —</option>';
        });
      }
      // Vul leidinggevenden overdragen dropdown
      const leid_sel = document.getElementById('kb-leid-overdragen-sel');
      if (leid_sel) {
        leid_sel.innerHTML='<option value="">— Kies leidinggevende —</option>';
        apiFetch('/leidinggevenden').then(r=>r.json()).then(leids=>{
          (leids||[]).forEach(l=>{
            if (l.id==KB.userId) return; // niet zichzelf
            const opt=document.createElement('option'); opt.value=l.id; opt.textContent=l.naam;
            leid_sel.appendChild(opt);
          });
        });
      }
    }
  }

  window.nawBewerken = function() {
    document.querySelectorAll('.kb-naw-value').forEach(el=>el.style.display='none');
    document.querySelectorAll('.kb-naw-input').forEach(el=>el.style.display='block');
    document.getElementById('kb-naw-edit-btn').style.display='none';
    document.getElementById('kb-naw-save-btn').style.display='inline-flex';
    document.getElementById('kb-naw-cancel-btn').style.display='inline-flex';
  };

  window.nawAnnuleren = function() {
    document.querySelectorAll('.kb-naw-value').forEach(el=>el.style.display='block');
    document.querySelectorAll('.kb-naw-input').forEach(el=>el.style.display='none');
    document.getElementById('kb-naw-edit-btn').style.display='inline-flex';
    document.getElementById('kb-naw-save-btn').style.display='none';
    document.getElementById('kb-naw-cancel-btn').style.display='none';
  };

  window.nawOpslaan = function() {
    if (!huidigClient) return;
    const body = {
      naam:          document.getElementById('nawi-naam')?.value.trim(),
      email:         document.getElementById('nawi-email')?.value.trim(),
      telefoon:      document.getElementById('nawi-telefoon')?.value.trim(),
      geboortedatum: document.getElementById('nawi-geboortedatum')?.value,
      adres:         document.getElementById('nawi-adres')?.value.trim(),
      postcode:      document.getElementById('nawi-postcode')?.value.trim(),
      woonplaats:    document.getElementById('nawi-woonplaats')?.value.trim(),
      bsn:           document.getElementById('nawi-bsn')?.value.trim(),
      notitie:       document.getElementById('nawi-notitie')?.value.trim(),
    };
    const msgEl = document.getElementById('kb-naw-msg');
    apiFetch(`/clients/${huidigClient.id}`, {method:'PATCH', body:JSON.stringify(body)})
    .then(r=>r.json()).then(data=>{
      if (data.ok) {
        Object.assign(huidigClient, body);
        laadNAW(huidigClient);
        nawAnnuleren();
        msgEl.innerHTML='<div style="background:#f0fdf4;border:1px solid #86efac;padding:8px 12px;border-radius:8px;color:#166534;font-size:13px;">✅ Opgeslagen</div>';
        setTimeout(()=>msgEl.innerHTML='',3000);
      }
    });
  };

  window.leidToewijzen = function() {
    if (!huidigClient) return;
    const bid = parseInt(document.getElementById('kb-leid-begel-sel')?.value)||0;
    if (!confirm(`Begeleider toewijzen aan ${huidigClient.naam}?`)) return;
    apiFetch(`/clients/${huidigClient.id}/toewijzen`,{method:'POST',body:JSON.stringify({begeleider_id:bid})})
    .then(r=>r.json()).then(d=>{
      if (d.ok) {
        huidigClient.begeleider_id = bid;
        document.getElementById('kb-naw-msg').innerHTML='<div style="background:#f0fdf4;border:1px solid #86efac;padding:8px 12px;border-radius:8px;color:#166534;font-size:13px;">✅ Begeleider toegewezen</div>';
        setTimeout(()=>document.getElementById('kb-naw-msg').innerHTML='',3000);
        laadClients(false);
      }
    });
  };

  window.leidOverdragen = function() {
    if (!huidigClient) return;
    const nieuwe_leid = parseInt(document.getElementById('kb-leid-overdragen-sel')?.value)||0;
    if (!nieuwe_leid) return alert('Kies een leidinggevende.');
    const mee = document.getElementById('kb-leid-begel-mee')?.checked;
    if (!confirm(`Cliënt "${huidigClient.naam}" overdragen aan andere leidinggevende? ${mee?'Begeleider blijft gekoppeld.':'Begeleider wordt losgekoppeld.'}`)) return;
    apiFetch(`/clients/${huidigClient.id}/overdragen-leid`,{method:'POST',body:JSON.stringify({nieuwe_leidinggevende_id:nieuwe_leid,begeleider_meenemen:mee?1:0})})
    .then(r=>r.json()).then(d=>{
      if (d.ok) { alert('✅ '+d.bericht); laadClients(false); }
      else alert('❌ '+d.error);
    });
  };

  window.leidClientVerwijderen = function() {
    if (!huidigClient) return;
    if (!confirm(`Cliënt "${huidigClient.naam}" definitief verwijderen?\n\nAlle data (logboek, CV, brieven, documenten) wordt ook verwijderd.\nDit kan NIET ongedaan worden gemaakt.`)) return;
    if (!confirm(`Laatste bevestiging: cliënt "${huidigClient.naam}" verwijderen?`)) return;
    apiFetch(`/clients/${huidigClient.id}`,{method:'DELETE'})
    .then(r=>r.json()).then(d=>{
      if (d.ok) {
        huidigClient = null;
        document.getElementById('kb-ws-tabs').style.display='none';
        document.querySelectorAll('.ws-tab-inhoud').forEach(t=>t.style.display='none');
        laadClients(false);
        alert('✅ Cliënt verwijderd.');
      } else alert('❌ '+(d.error||'Fout'));
    });
  };

  // ── Cliënten tabel (beheer tab) ───────────────────────
  function laadClientsTabel() {
    const el = document.getElementById('kb-clients-tabel');
    if (!el) return;
    el.innerHTML='<div style="padding:20px;color:#94a3b8;text-align:center;">Laden…</div>';
    apiFetch('/clients?archief=0').then(r=>r.json()).then(clients=>{
      if (!clients.length) {
        el.innerHTML=`<div style="text-align:center;padding:40px;color:#94a3b8;">
          <div style="font-size:32px;">👤</div>
          <div style="font-weight:600;margin-top:8px;">Nog geen actieve cliënten</div>
          <button onclick="openClientModal('nieuw',null)" class="kb-btn kb-btn-purple" style="margin-top:14px;">+ Eerste cliënt aanmaken</button>
        </div>`; return;
      }
      let html=`<table class="kb-client-tbl">
        <thead><tr><th>Naam</th><th>E-mail</th><th>Begeleider</th><th>Aangevinkt</th><th>Aangemaakt</th><th>Acties</th></tr></thead><tbody>`;
      clients.forEach(c=>{
        const eigen = c.eigen_client;
        html+=`<tr>
          <td style="font-weight:700;color:var(--kb-blue);">${esc(c.naam)}${!eigen?'<span style="background:#fef9c3;color:#92400e;padding:1px 6px;border-radius:6px;font-size:10px;font-weight:700;margin-left:6px;">team</span>':''}</td>
          <td style="color:#64748b;">${esc(c.email)}</td>
          <td style="color:#64748b;font-size:12px;">${esc(c.begeleider_naam||'—')}</td>
          <td>${c.aangevinkt>0?`<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">${c.aangevinkt}</span>`:'—'}</td>
          <td style="color:#64748b;font-size:12px;">${fmtDatum(c.aangemaakt)}</td>
          <td><div style="display:flex;gap:4px;">
            ${eigen ? `<button onclick='openClientModal("edit",${JSON.stringify(c).replace(/'/g,"&#39;")})' class="kb-btn kb-btn-ghost kb-btn-sm">✏️</button>` : ''}
            ${!eigen ? `<button onclick='overneemClientById(${c.id},"${esc(c.naam)}")' class="kb-btn kb-btn-ghost kb-btn-sm" title="Overnemen">↗ Overnemen</button>` : ''}
            ${eigen ? `<button onclick='archiveerVanTabel(${c.id},"${esc(c.naam)}")' class="kb-btn kb-btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;">📦</button>` : ''}
            ${eigen && KB.isLeid==1 ? `<button onclick='verwijderClientVanTabel(${c.id},"${esc(c.naam)}")' class="kb-btn kb-btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;">🗑️</button>` : ''}
          </div></td>
        </tr>`;
      });
      html+='</tbody></table>';
      el.innerHTML=html;
    });
  }

  function laadArchiefTabel() {
    const el = document.getElementById('kb-archief-tabel');
    if (!el) return;
    el.innerHTML='<div style="padding:20px;color:#94a3b8;text-align:center;">Laden…</div>';
    apiFetch('/clients?archief=1').then(r=>r.json()).then(clients=>{
      if (!clients.length) {
        el.innerHTML='<div style="text-align:center;padding:40px;color:#94a3b8;"><div style="font-size:32px;">📦</div><div style="font-weight:600;margin-top:8px;">Geen gearchiveerde cliënten</div></div>'; return;
      }
      let html=`<table class="kb-client-tbl">
        <thead><tr><th>Naam</th><th>E-mail</th><th>Aangevinkt</th><th>Acties</th></tr></thead><tbody>`;
      clients.forEach(c=>{ html+=`<tr>
        <td style="font-weight:700;color:#64748b;">${esc(c.naam)}</td>
        <td style="color:#94a3b8;">${esc(c.email)}</td>
        <td>${c.aangevinkt>0?`<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">${c.aangevinkt}</span>`:'—'}</td>
        <td><button onclick='terugzettenClient(${c.id},"${esc(c.naam)}")' class="kb-btn kb-btn-ghost kb-btn-sm">↩ Terugzetten</button></td>
      </tr>`; });
      html+='</tbody></table>';
      el.innerHTML=html;
    });
  }

  function archiveerClient(client) {
    if (!confirm(client.gearchiveerd?`${client.naam} terugzetten?`:`${client.naam} archiveren?`)) return;
    apiFetch(`/clients/${client.id}`, {method:'PATCH', body:JSON.stringify({gearchiveerd:client.gearchiveerd?0:1})})
    .then(()=>{ laadClients(false); document.getElementById('kb-werkruimte').innerHTML=''; });
  }

  window.archiveerVanTabel = function(id,naam) {
    if (!confirm(`${naam} archiveren?`)) return;
    apiFetch(`/clients/${id}`,{method:'PATCH',body:JSON.stringify({gearchiveerd:1})}).then(()=>{ laadClientsTabel(); laadClients(false); });
  };

  window.terugzettenClient = function(id,naam) {
    if (!confirm(`${naam} terugzetten naar actief?`)) return;
    apiFetch(`/clients/${id}`,{method:'PATCH',body:JSON.stringify({gearchiveerd:0})}).then(()=>{ laadArchiefTabel(); laadClients(false); });
  };

  function overneemClient(client) {
    if (!confirm(`Cliënt "${client.naam}" overnemen? Alle dossierdata wordt aan jou gekoppeld.`)) return;
    apiFetch(`/clients/${client.id}/overnemen`,{method:'POST',body:'{}'})
    .then(r=>r.json()).then(data=>{
      if (data.error) { alert('❌ '+data.error); return; }
      alert('✅ '+data.bericht); laadClients(false);
    });
  }

  function verzoekOvernemen(client) {
    if (!confirm(`Overname aanvragen voor cliënt "${client.naam}"?\n\nJe leidinggevende ontvangt een melding en moet dit goedkeuren.`)) return;
    apiFetch(`/clients/${client.id}/overnemen-verzoek`,{method:'POST',body:'{}'})
    .then(r=>r.json()).then(data=>{
      if (data.error) alert('❌ '+data.error);
      else alert('✅ '+data.bericht);
    });
  }

  window.overneemClientById = function(id, naam) {
    if (KB.isLeid==1) {
      if (!confirm(`Cliënt "${naam}" overnemen?`)) return;
      apiFetch(`/clients/${id}/overnemen`,{method:'POST',body:'{}'})
      .then(r=>r.json()).then(data=>{
        if (data.error) { alert('❌ '+data.error); return; }
        alert('✅ '+data.bericht); laadClientsTabel(); laadClients(false);
      });
    } else {
      if (!confirm(`Overname aanvragen voor cliënt "${naam}"? Je leidinggevende ontvangt een melding.`)) return;
      apiFetch(`/clients/${id}/overnemen-verzoek`,{method:'POST',body:'{}'})
      .then(r=>r.json()).then(data=>{
        if (data.error) alert('❌ '+data.error);
        else alert('✅ '+data.bericht);
      });
    }
  };

  window.verwijderClientVanTabel = function(id, naam) {
    if (!confirm(`⚠️ Cliënt "${naam}" definitief verwijderen?\n\nAlle data (logboek, CV, brieven, documenten) wordt permanent gewist.\nDit kan NIET ongedaan worden gemaakt!`)) return;
    apiFetch(`/clients/${id}`,{method:'DELETE'})
    .then(r=>r.json()).then(data=>{
      if (data.error) { alert('❌ '+data.error); return; }
      alert('✅ Cliënt verwijderd.');
      laadClientsTabel(); laadClients(false);
    });
  };

  // ── Meldingen ──────────────────────────────────────────
  function laadMeldingen() {
    apiFetch('/meldingen?ongelezen=1').then(r=>r.json()).then(meldingen=>{
      const isLeid = KB.isLeid == 1;
      // Haal ook overname-verzoeken op voor leidinggevenden
      const verzoekPromise = isLeid
        ? apiFetch('/overnemen-verzoeken').then(r=>r.json()).catch(()=>[])
        : Promise.resolve([]);

      verzoekPromise.then(verzoeken=>{
        const heeftMeldingen = meldingen && meldingen.length;
        const heeftVerzoeken = verzoeken && verzoeken.length;
        if (!heeftMeldingen && !heeftVerzoeken) return;

        const wrap = document.getElementById('kb-begel-root');
        if (!wrap) return;
        const banner = document.createElement('div');
        banner.id = 'kb-meldingen-banner';
        banner.style.cssText='background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;padding:14px 18px;margin-bottom:16px;';

        let html = '';
        if (heeftMeldingen) {
          html += `<div style="font-weight:700;color:#1d4ed8;margin-bottom:8px;">🔔 ${meldingen.length} nieuwe melding${meldingen.length>1?'en':''}</div>` +
            meldingen.map(m=>{
              const isReview = m.bericht && m.bericht.includes('brief');
              return `<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #dbeafe;">
              <div>
                <span style="font-weight:600;color:#374151;">${esc(m.client_naam||'Cliënt')}</span>
                <span style="color:#64748b;font-size:13px;margin-left:6px;">${esc(m.bericht)}</span>
              </div>
              <div style="display:flex;gap:6px;">
                ${isReview && m.client_id ? `<button onclick="openClientNaarCV(${m.client_id})" class="kb-btn kb-btn-ghost kb-btn-sm" style="font-size:11px;background:#eff6ff;color:#1d4ed8;">📄 Brief bekijken</button>` : ''}
                <button onclick="markeerGelezen(${m.id})" class="kb-btn kb-btn-ghost kb-btn-sm" style="font-size:11px;">✓ Gelezen</button>
              </div>
            </div>`;
            }).join('');
        }
        if (heeftVerzoeken) {
          html += `<div style="font-weight:700;color:#92400e;margin-top:${heeftMeldingen?'12px':'0'};margin-bottom:8px;">📋 ${verzoeken.length} overname-verzoek${verzoeken.length>1?'en':''}</div>` +
            verzoeken.map(v=>`<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #fde68a;">
              <div style="font-size:13px;">
                <strong>${esc(v.aanvrager_naam)}</strong> wil cliënt <strong>${esc(v.client_naam)}</strong> overnemen van <em>${esc(v.huidige_begel_naam)}</em>
              </div>
              <div style="display:flex;gap:6px;">
                <button onclick="goedkeurenVerzoek(${v.client_id},true)" class="kb-btn kb-btn-sm" style="background:#f0fdf4;color:#166534;border:1px solid #86efac;font-size:11px;">✅ Goedkeuren</button>
                <button onclick="goedkeurenVerzoek(${v.client_id},false)" class="kb-btn kb-btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;font-size:11px;">✕ Afwijzen</button>
              </div>
            </div>`).join('');
        }
        banner.innerHTML = html;
        wrap.insertBefore(banner, wrap.firstChild);
      });
    }).catch(()=>{});
  }

  window.goedkeurenVerzoek = function(clientId, goedkeuren) {
    const actie = goedkeuren ? 'goedkeuren' : 'afwijzen';
    if (!confirm(`Overname-verzoek ${actie}?`)) return;
    apiFetch(`/clients/${clientId}/overnemen-goedkeuren`,{method:'POST',body:JSON.stringify({goedkeuren:goedkeuren?1:0})})
    .then(r=>r.json()).then(d=>{
      alert(d.bericht||d.error||'Klaar');
      document.getElementById('kb-meldingen-banner')?.remove();
      laadMeldingen();
      laadClients(false);
    });
  };

  window.markeerGelezen = function(id) {
    apiFetch(`/meldingen/${id}/gelezen`,{method:'POST',body:'{}'}).then(()=>{
      const banner = document.getElementById('kb-meldingen-banner');
      if (banner) banner.remove();
    });
  };

  // Navigeer naar een cliënt en open direct de CV-tab (voor review-brieven)
  window.openClientNaarCV = function(clientId) {
    apiFetch('/clients').then(r=>r.json()).then(clients=>{
      const client = clients.find(c=>c.id==clientId);
      if (!client) return;
      // Selecteer cliënt visueel in de lijst
      const match = clientList?.querySelector(`.kb-client-btn[data-id="${clientId}"]`);
      if (match) { match.scrollIntoView({block:'nearest'}); selecteerClient(client, match); }
      else { selecteerClient(client, null); }
      // Open CV-tab na korte vertraging
      setTimeout(()=>{
        document.querySelectorAll('.ws-tab-inhoud').forEach(t=>t.style.display='none');
        document.querySelectorAll('.kb-ws-tab').forEach(t=>t.classList.remove('active'));
        const cvTab = document.getElementById('ws-cv-client');
        const cvBtn = document.querySelector('[data-wstab="cv-client"]');
        if (cvTab) cvTab.style.display='block';
        if (cvBtn) cvBtn.classList.add('active');
        laadClientCV(client);
      }, 200);
    });
  };

  // ── Client Modal ──────────────────────────────────────
  window.openClientModal = function(mode, client) {
    const modal = document.getElementById('kb-client-modal');
    if (!modal) return;
    modal.dataset.mode = mode;
    modal.dataset.cid  = client?.id||'';
    document.getElementById('kb-cm-title').textContent = mode==='nieuw'?'Nieuwe cliënt aanmaken':'Cliënt bewerken';
    document.getElementById('kb-cm-naam').value    = client?.naam||'';
    document.getElementById('kb-cm-email').value   = client?.email||'';
    document.getElementById('kb-cm-tel').value     = client?.telefoon||'';
    document.getElementById('kb-cm-notitie').value = client?.notitie||'';
    document.getElementById('kb-cm-pw').value      = '';
    document.getElementById('kb-cm-msg').innerHTML = '';
    // Laad leidinggevenden
    apiFetch('/leidinggevenden').then(r=>r.json()).then(leids=>{
      const sel = document.getElementById('kb-cm-leid');
      sel.innerHTML='<option value="">— Geen (automatisch) —</option>';
      leids.forEach(l => {
        const opt = document.createElement('option');
        opt.value=l.id; opt.textContent=l.naam;
        if (client?.leidinggevende_id == l.id) opt.selected=true;
        sel.appendChild(opt);
      });
    }).catch(()=>{});
    modal.style.display = 'flex';
  };

  function bindModalEvents() {
    const modal = document.getElementById('kb-client-modal');
    if (!modal) return;
    [document.getElementById('kb-cm-cancel'),document.getElementById('kb-cm-cancel2')].forEach(b=>b?.addEventListener('click',()=>modal.style.display='none'));
    modal.addEventListener('click', e=>{ if(e.target===modal) modal.style.display='none'; });
    document.addEventListener('keydown', e=>{ if(e.key==='Escape') modal.style.display='none'; });

    document.getElementById('kb-cm-save')?.addEventListener('click', ()=>{
      const mode  = modal.dataset.mode, cid = modal.dataset.cid;
      const naam  = document.getElementById('kb-cm-naam').value.trim();
      const email = document.getElementById('kb-cm-email').value.trim();
      const tel   = document.getElementById('kb-cm-tel').value.trim();
      const not   = document.getElementById('kb-cm-notitie').value.trim();
      const pw    = document.getElementById('kb-cm-pw').value.trim();
      const leid  = parseInt(document.getElementById('kb-cm-leid')?.value)||0;
      const msg   = document.getElementById('kb-cm-msg');
      const btn   = document.getElementById('kb-cm-save');
      if (!naam||!email) { msg.innerHTML='<span style="color:#dc2626;">Naam en e-mail zijn verplicht.</span>'; return; }
      btn.disabled=true; btn.textContent='Opslaan…';
      if (mode==='nieuw') {
        const body = {naam,email,telefoon:tel,notitie:not};
        if (pw) body.wachtwoord = pw;
        if (leid) body.leidinggevende_id = leid;
        apiFetch('/clients',{method:'POST',body:JSON.stringify(body)})
        .then(r=>r.json()).then(data=>{
          btn.disabled=false; btn.textContent='Opslaan';
          if (data.error) { msg.innerHTML=`<span style="color:#dc2626;">❌ ${esc(data.error)}</span>`; return; }
          msg.innerHTML=`<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:14px;">
            ✅ <strong>${esc(naam)}</strong> aangemaakt!<br>
            <div style="margin-top:6px;">Wachtwoord: <code style="font-size:15px;font-weight:800;background:#dcfce7;padding:3px 8px;border-radius:6px;">${esc(data.wachtwoord)}</code></div>
          </div>`;
          laadClients(false); laadClientsTabel();
        });
      } else {
        const body = {naam,email,telefoon:tel,notitie:not};
        if (pw&&pw.length>=6) body.wachtwoord=pw;
        if (leid) body.leidinggevende_id=leid;
        apiFetch(`/clients/${cid}`,{method:'PATCH',body:JSON.stringify(body)})
        .then(r=>r.json()).then(data=>{
          btn.disabled=false; btn.textContent='Opslaan';
          if (data.ok) { modal.style.display='none'; laadClients(false); laadClientsTabel(); }
          else msg.innerHTML='<span style="color:#dc2626;">❌ Fout bij opslaan.</span>';
        });
      }
    });
  }
}

/* ═══════════════════════════════════════════════════════════
   ADRESBOEK (begeleider — partners & contacten)
═══════════════════════════════════════════════════════════ */
function initAdresboek() {
  const root = document.getElementById('kb-adresboek-root');
  if (!root) return;
  laadAdresboek();

  function laadAdresboek() {
    root.innerHTML='<div style="padding:20px;color:#94a3b8;text-align:center;">Laden…</div>';
    apiFetch('/adresboek').then(r=>r.json()).then(items=>renderAdresboek(items||[]));
  }

  function renderAdresboek(items) {
    let html = `
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div style="font-size:16px;font-weight:700;color:var(--kb-blue);">📇 Adresboek — Partners & Contacten</div>
        <button onclick="document.getElementById('kb-adr-form').style.display=document.getElementById('kb-adr-form').style.display==='none'?'block':'none'" class="kb-btn kb-btn-purple">+ Nieuw contact</button>
      </div>
      <!-- Nieuw contact formulier -->
      <div id="kb-adr-form" class="kb-card" style="margin-bottom:16px;display:none;">
        <div style="font-weight:700;color:var(--kb-blue);margin-bottom:12px;">Nieuw contact toevoegen</div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
          <div><label class="kb-field-label">Naam *</label><input type="text" id="adr-naam" class="kb-login-input" placeholder="Jan de Vries"></div>
          <div><label class="kb-field-label">Organisatie</label><input type="text" id="adr-org" class="kb-login-input" placeholder="Bedrijfsnaam"></div>
          <div><label class="kb-field-label">Functie</label><input type="text" id="adr-functie" class="kb-login-input" placeholder="Jobcoach"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
          <div><label class="kb-field-label">E-mail</label><input type="email" id="adr-email" class="kb-login-input" placeholder="jan@bedrijf.nl"></div>
          <div><label class="kb-field-label">Telefoon</label><input type="tel" id="adr-tel" class="kb-login-input" placeholder="06-12345678"></div>
        </div>
        <div><label class="kb-field-label">Notitie</label><textarea id="adr-notitie" class="kb-notitie" style="min-height:60px;" placeholder="Extra informatie…"></textarea></div>
        <div style="margin-top:12px;display:flex;gap:8px;">
          <button onclick="slaContactOp()" class="kb-btn kb-btn-purple">💾 Opslaan</button>
          <button onclick="document.getElementById('kb-adr-form').style.display='none'" class="kb-btn kb-btn-ghost">Annuleren</button>
        </div>
        <div id="adr-status" style="margin-top:8px;font-size:13px;min-height:18px;"></div>
      </div>`;

    if (!items.length) {
      html += '<div style="text-align:center;padding:40px;color:#94a3b8;"><div style="font-size:32px;">📇</div><div style="font-weight:600;margin-top:8px;">Nog geen contacten</div><div style="font-size:13px;margin-top:4px;">Voeg partners en contacten toe via de knop hierboven.</div></div>';
    } else {
      html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;">';
      items.forEach(item=>{
        html += `<div class="kb-adres-card" id="adr-card-${item.id}">
          <div id="adr-view-${item.id}">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
              <div>
                <div style="font-weight:700;color:var(--kb-blue);font-size:14px;">${esc(item.naam)}</div>
                ${item.functie||item.organisatie ? `<div style="font-size:12px;color:#64748b;">${esc(item.functie||'')}${item.functie&&item.organisatie?' @ ':''}${esc(item.organisatie||'')}</div>` : ''}
              </div>
              <div style="display:flex;gap:4px;">
                <button onclick="bewerkContact(${item.id})" class="kb-btn kb-btn-ghost kb-btn-sm" style="padding:3px 7px;">✏️</button>
                <button onclick="verwijderContact(${item.id})" class="kb-btn kb-btn-sm" style="padding:3px 7px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;">✕</button>
              </div>
            </div>
            ${item.email ? `<div style="font-size:12px;margin-bottom:3px;">📧 <a href="mailto:${esc(item.email)}" style="color:var(--kb-blue);">${esc(item.email)}</a></div>` : ''}
            ${item.telefoon ? `<div style="font-size:12px;margin-bottom:3px;">📞 <a href="tel:${esc(item.telefoon)}" style="color:var(--kb-blue);">${esc(item.telefoon)}</a></div>` : ''}
            ${item.notitie ? `<div style="font-size:12px;color:#64748b;margin-top:6px;background:#f8fafc;border-radius:6px;padding:6px 8px;">${esc(item.notitie)}</div>` : ''}
          </div>
          <div id="adr-edit-${item.id}" style="display:none;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
              <input type="text" id="adr-e-naam-${item.id}" class="kb-login-input" value="${esc(item.naam)}" placeholder="Naam">
              <input type="text" id="adr-e-org-${item.id}" class="kb-login-input" value="${esc(item.organisatie)}" placeholder="Organisatie">
              <input type="text" id="adr-e-functie-${item.id}" class="kb-login-input" value="${esc(item.functie)}" placeholder="Functie">
              <input type="email" id="adr-e-email-${item.id}" class="kb-login-input" value="${esc(item.email)}" placeholder="E-mail">
              <input type="tel" id="adr-e-tel-${item.id}" class="kb-login-input" value="${esc(item.telefoon)}" placeholder="Telefoon" style="grid-column:span 2;">
            </div>
            <textarea id="adr-e-notitie-${item.id}" class="kb-notitie" style="min-height:50px;margin-bottom:8px;">${esc(item.notitie||'')}</textarea>
            <div style="display:flex;gap:6px;">
              <button onclick="slaContactWijzigingOp(${item.id})" class="kb-btn kb-btn-purple kb-btn-sm">💾 Opslaan</button>
              <button onclick="document.getElementById('adr-edit-${item.id}').style.display='none';document.getElementById('adr-view-${item.id}').style.display='block';" class="kb-btn kb-btn-ghost kb-btn-sm">Annuleren</button>
            </div>
          </div>
        </div>`;
      });
      html += '</div>';
    }
    root.innerHTML = html;
  }

  window.slaContactOp = function() {
    const naam    = document.getElementById('adr-naam')?.value.trim();
    const stat    = document.getElementById('adr-status');
    if (!naam) { if(stat) stat.innerHTML='<span style="color:#dc2626;">Naam is verplicht.</span>'; return; }
    apiFetch('/adresboek', {method:'POST', body:JSON.stringify({
      naam,
      organisatie: document.getElementById('adr-org')?.value.trim()||'',
      functie:     document.getElementById('adr-functie')?.value.trim()||'',
      email:       document.getElementById('adr-email')?.value.trim()||'',
      telefoon:    document.getElementById('adr-tel')?.value.trim()||'',
      notitie:     document.getElementById('adr-notitie')?.value.trim()||'',
    })}).then(r=>r.json()).then(data=>{
      if (data.ok||data.id) {
        if(stat) stat.innerHTML='<span style="color:#166534;">✅ Opgeslagen!</span>';
        ['adr-naam','adr-org','adr-functie','adr-email','adr-tel','adr-notitie'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
        setTimeout(()=>{ if(stat)stat.innerHTML=''; document.getElementById('kb-adr-form').style.display='none'; },1500);
        laadAdresboek();
      } else if(stat) stat.innerHTML='<span style="color:#dc2626;">❌ Fout: '+(data.error||'onbekend')+'</span>';
    });
  };

  window.bewerkContact = function(id) {
    document.getElementById('adr-view-'+id).style.display='none';
    document.getElementById('adr-edit-'+id).style.display='block';
  };

  window.slaContactWijzigingOp = function(id) {
    apiFetch(`/adresboek/${id}`, {method:'PATCH', body:JSON.stringify({
      naam:       document.getElementById('adr-e-naam-'+id)?.value.trim(),
      organisatie:document.getElementById('adr-e-org-'+id)?.value.trim()||'',
      functie:    document.getElementById('adr-e-functie-'+id)?.value.trim()||'',
      email:      document.getElementById('adr-e-email-'+id)?.value.trim()||'',
      telefoon:   document.getElementById('adr-e-tel-'+id)?.value.trim()||'',
      notitie:    document.getElementById('adr-e-notitie-'+id)?.value.trim()||'',
    })}).then(r=>r.json()).then(()=>laadAdresboek());
  };

  window.verwijderContact = function(id) {
    if (!confirm('Contact verwijderen?')) return;
    apiFetch(`/adresboek/${id}`, {method:'DELETE'}).then(()=>laadAdresboek());
  };
}

// ── Inklap helpers ────────────────────────────────────────
window.kbToggleInklap = function(kop) {
  const kaart = kop.closest('.kb-aant-card');
  const body  = kaart?.querySelector('.kb-aant-body');
  const pijl  = kop.querySelector('.kb-inklap-pijl');
  if (!body) return;
  const isOpen = body.style.display!=='none';
  body.style.display = isOpen ? 'none' : '';
  if (pijl) pijl.style.transform = isOpen ? 'rotate(-90deg)' : '';
};

window.kbAllesUitklappen = function() {
  document.querySelectorAll('.kb-aant-body').forEach(b=>b.style.display='');
  document.querySelectorAll('.kb-inklap-pijl').forEach(p=>p.style.transform='');
};

window.kbAllesInklappen = function() {
  document.querySelectorAll('.kb-aant-body').forEach(b=>b.style.display='none');
  document.querySelectorAll('.kb-inklap-pijl').forEach(p=>p.style.transform='rotate(-90deg)');
};

/* ═══════════════════════════════════════════════════════════
   LOGBOEK (2e spoor — cliënt)
═══════════════════════════════════════════════════════════ */
function initLogboek() {
  let entries = [];

  laadLogboek();
  document.getElementById('lb-save-btn')?.addEventListener('click', ()=>voegToe());
  document.getElementById('lb-filter-type')?.addEventListener('change', ()=>renderEntries());

  function laadLogboek() {
    apiFetch('/logboek').then(r=>r.json()).then(data=>{
      entries=data||[]; updateStats(); renderEntries();
    }).catch(()=>{ document.getElementById('kb-logboek-entries').innerHTML='<p style="color:red;">Fout bij laden.</p>'; });
  }

  function updateStats() {
    const uren=entries.reduce((s,e)=>s+(parseFloat(e.uren)||0),0);
    const upd = (id,v) => { const el=document.getElementById(id); if(el) el.textContent=v; };
    upd('stat-totaal',entries.length);
    upd('stat-uren',uren%1===0?uren:uren.toFixed(1));
    upd('stat-sollicitaties',entries.filter(e=>e.type==='sollicitatie').length);
    upd('stat-gesprekken',entries.filter(e=>e.type==='gesprek'||e.type==='jobcoach').length);
  }

  function renderEntries() {
    const container  = document.getElementById('kb-logboek-entries');
    const prContainer= document.getElementById('kb-print-entries');
    if (!container) return;
    const filter = document.getElementById('lb-filter-type')?.value||'';
    const gef = entries.filter(e=>!filter||e.type===filter);
    const telEl = document.getElementById('lb-filter-teller');
    if (telEl) telEl.textContent = `${gef.length} van ${entries.length}`;
    if (!gef.length) {
      container.innerHTML=`<div style="text-align:center;padding:48px;color:#94a3b8;">
        <div style="font-size:36px;">📋</div>
        <div style="font-weight:600;margin-top:10px;">${entries.length?'Geen resultaten':'Nog geen activiteiten'}</div>
      </div>`; return;
    }
    const perMaand={};
    gef.forEach(e=>{
      const d=new Date(e.datum+'T00:00:00');
      const key=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
      const label=d.toLocaleDateString('nl-NL',{month:'long',year:'numeric'});
      if(!perMaand[key]) perMaand[key]={label,entries:[]};
      perMaand[key].entries.push(e);
    });

    let html='', prHtml='';
    Object.keys(perMaand).sort().reverse().forEach(key=>{
      const {label,entries:m} = perMaand[key];
      const mu=m.reduce((s,e)=>s+(parseFloat(e.uren)||0),0);
      html+=`<div class="kb-log-maand-header">${label} <span style="font-weight:400;color:#94a3b8;">${m.length} activiteiten${mu>0?' · '+mu.toFixed(1)+' uur':''}</span></div>`;
      prHtml+=`<div style="margin-bottom:14px;">
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;color:#003082;border-bottom:1.5px solid #003082;padding-bottom:4px;margin-bottom:6px;">${label}</div>
        <table style="width:100%;border-collapse:collapse;font-size:10px;"><thead><tr style="background:#f4f6fb;">
          <th style="text-align:left;padding:4px 6px;color:#003082;width:70px;">Datum</th>
          <th style="text-align:left;padding:4px 6px;color:#003082;width:90px;">Type</th>
          <th style="text-align:left;padding:4px 6px;color:#003082;">Omschrijving</th>
          <th style="text-align:left;padding:4px 6px;color:#003082;">Resultaat</th>
          <th style="text-align:right;padding:4px 6px;color:#003082;width:36px;">Uren</th>
        </tr></thead><tbody>`;
      m.forEach((e,i)=>{
        const {bg,kl}=typKl(e.type);
        const dat=new Date(e.datum+'T00:00:00').toLocaleDateString('nl-NL',{weekday:'short',day:'numeric',month:'short'});
        html+=`<div class="kb-log-entry" data-id="${e.id}">
          <div class="kb-log-datum">
            <div style="font-size:9px;text-transform:uppercase;color:#94a3b8;">${dat.split(' ')[0]}</div>
            <div style="font-size:20px;font-weight:800;color:var(--kb-blue);line-height:1.1;">${dat.split(' ')[1]}</div>
            <div style="font-size:9px;color:#94a3b8;">${dat.split(' ').slice(2).join(' ')}</div>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
              <span style="background:${bg};color:${kl};padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">${typLbl(e.type)}</span>
              ${e.uren?`<span style="font-size:11px;color:#64748b;">${e.uren} uur</span>`:''}
            </div>
            <div style="font-size:13px;font-weight:600;color:#374151;">${esc(e.omschrijving)}</div>
            ${e.resultaat?`<div style="margin-top:4px;font-size:12px;color:#64748b;background:#f8fafc;border-radius:6px;padding:5px 8px;"><strong>Resultaat:</strong> ${esc(e.resultaat)}</div>`:''}
          </div>
          <div class="kb-log-acties kb-no-print">
            <button onclick="bewerkLogEntry(${e.id},'${esc(e.omschrijving.replace(/'/g,"\\'"))}','${esc((e.resultaat||'').replace(/'/g,"\\'"))}')" class="kb-btn kb-btn-ghost kb-btn-sm" style="padding:4px 8px;font-size:11px;">✏️</button>
            <button onclick="verwijderEntry(${e.id})" class="kb-btn kb-btn-sm" style="padding:4px 8px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;font-size:11px;">✕</button>
          </div>
          <div id="log-edit-${e.id}" style="display:none;margin-top:8px;grid-column:1/-1;">
            <textarea class="kb-notitie" id="log-edit-omschr-${e.id}" style="min-height:60px;margin-bottom:6px;">${esc(e.omschrijving)}</textarea>
            <input type="text" id="log-edit-result-${e.id}" class="kb-login-input" value="${esc(e.resultaat||'')}" placeholder="Resultaat (optioneel)" style="margin-bottom:6px;">
            <div style="display:flex;gap:6px;">
              <button onclick="slaLogEntryOp(${e.id})" class="kb-btn kb-btn-purple kb-btn-sm">💾 Opslaan</button>
              <button onclick="document.getElementById('log-edit-${e.id}').style.display='none'" class="kb-btn kb-btn-ghost kb-btn-sm">Annuleren</button>
            </div>
          </div>
        </div>`;
        prHtml+=`<tr style="background:${i%2===0?'white':'#f8fafc'};border-bottom:1px solid #f1f5f9;">
          <td style="padding:5px 6px;vertical-align:top;">${new Date(e.datum+'T00:00:00').toLocaleDateString('nl-NL')}</td>
          <td style="padding:5px 6px;vertical-align:top;font-weight:600;">${typLbl(e.type).replace(/\p{Emoji}/gu,'').trim()}</td>
          <td style="padding:5px 6px;vertical-align:top;">${esc(e.omschrijving)}</td>
          <td style="padding:5px 6px;vertical-align:top;color:#64748b;">${esc(e.resultaat||'—')}</td>
          <td style="padding:5px 6px;text-align:right;">${e.uren||'—'}</td>
        </tr>`;
      });
      prHtml+='</tbody></table></div>';
    });
    container.innerHTML=html;
    if (prContainer) prContainer.innerHTML=prHtml;
  }

  function typKl(t) { return ({sollicitatie:{bg:'#dbeafe',kl:'#1d4ed8'},gesprek:{bg:'#dcfce7',kl:'#166534'},netwerk:{bg:'#ede9fe',kl:'#6d28d9'},opleiding:{bg:'#fef9c3',kl:'#92400e'},stage:{bg:'#fed7aa',kl:'#c2410c'},werkbezoek:{bg:'#e0f2fe',kl:'#0369a1'},jobcoach:{bg:'#fce7f3',kl:'#be185d'},overig:{bg:'#f1f5f9',kl:'#374151'}}[t])||{bg:'#f1f5f9',kl:'#374151'}; }
  function typLbl(t) { return ({sollicitatie:'📧 Sollicitatie',gesprek:'🤝 Gesprek',netwerk:'🌐 Netwerken',opleiding:'🎓 Opleiding',stage:'💼 Stage',werkbezoek:'🏢 Werkbezoek',jobcoach:'👩‍💼 Jobcoach',overig:'📝 Overig'}[t])||t; }

  window.voegToe = function() {
    const datum=document.getElementById('lb-datum').value, type=document.getElementById('lb-type').value;
    const omschr=document.getElementById('lb-omschrijving').value.trim();
    const result=document.getElementById('lb-resultaat').value.trim();
    const uren=document.getElementById('lb-uren').value;
    const stat=document.getElementById('lb-save-status'), btn=document.getElementById('lb-save-btn');
    if (!datum||!omschr) { stat.textContent='Datum en omschrijving zijn verplicht.'; return; }
    btn.disabled=true; btn.textContent='Opslaan…';
    apiFetch('/logboek',{method:'POST',body:JSON.stringify({datum,type,omschrijving:omschr,resultaat:result,uren:uren||null})})
    .then(r=>r.json()).then(data=>{
      btn.disabled=false; btn.textContent='Toevoegen';
      if (data.ok) {
        stat.innerHTML='<span style="color:#166534;">✅ Opgeslagen!</span>'; setTimeout(()=>stat.textContent='',2500);
        document.getElementById('lb-omschrijving').value=''; document.getElementById('lb-resultaat').value='';
        document.getElementById('lb-uren').value=''; document.getElementById('lb-datum').value=new Date().toISOString().split('T')[0];
        laadLogboek();
      } else stat.textContent='❌ Fout.';
    });
  };

  window.verwijderEntry = function(id) {
    if (!confirm('Activiteit verwijderen?')) return;
    apiFetch(`/logboek/${id}`,{method:'DELETE'}).then(()=>laadLogboek());
  };

  window.bewerkLogEntry = function(id) {
    const editDiv = document.getElementById('log-edit-'+id);
    if (editDiv) editDiv.style.display = editDiv.style.display === 'none' ? 'block' : 'none';
  };

  window.slaLogEntryOp = function(id) {
    const omschr  = document.getElementById('log-edit-omschr-'+id)?.value.trim();
    const result  = document.getElementById('log-edit-result-'+id)?.value.trim();
    if (!omschr) { alert('Omschrijving is verplicht.'); return; }
    apiFetch(`/logboek/${id}`, {method:'PATCH', body:JSON.stringify({omschrijving:omschr, resultaat:result||''})})
    .then(r=>r.json()).then(data=>{
      if (data.ok) laadLogboek();
      else alert('❌ '+(data.error||'Opslaan mislukt'));
    });
  };

  window.printLogboek = function() {
    const uren=entries.reduce((s,e)=>s+(parseFloat(e.uren)||0),0);
    const soll=entries.filter(e=>e.type==='sollicitatie').length;
    const gesp=entries.filter(e=>e.type==='gesprek'||e.type==='jobcoach').length;
    const eerste=entries.length?new Date(entries[entries.length-1].datum+'T00:00:00').toLocaleDateString('nl-NL'):'—';
    const laatste=entries.length?new Date(entries[0].datum+'T00:00:00').toLocaleDateString('nl-NL'):'—';
    const el=document.getElementById('kb-print-samenvatting');
    if (el) el.innerHTML=`<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px;">
      <div style="background:#f0f4ff;border-radius:8px;padding:10px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#003082;">${entries.length}</div><div style="font-size:9px;color:#64748b;text-transform:uppercase;">Activiteiten</div></div>
      <div style="background:#fff7ed;border-radius:8px;padding:10px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#e85d00;">${uren>0?uren.toFixed(1):0}</div><div style="font-size:9px;color:#64748b;text-transform:uppercase;">Uren totaal</div></div>
      <div style="background:#f5f3ff;border-radius:8px;padding:10px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#7c3aed;">${soll}</div><div style="font-size:9px;color:#64748b;text-transform:uppercase;">Sollicitaties</div></div>
      <div style="background:#f0fdf4;border-radius:8px;padding:10px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#166534;">${gesp}</div><div style="font-size:9px;color:#64748b;text-transform:uppercase;">Gesprekken</div></div>
    </div><div style="font-size:9px;color:#94a3b8;margin-bottom:14px;">Periode: ${eerste} t/m ${laatste}</div>`;
    window.print();
  };
}
