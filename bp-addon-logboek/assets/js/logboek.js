(function(){
  if (!window.BP2SLogboek || !document.getElementById('kb-logboek-root')) return;

  var root = document.getElementById('kb-logboek-root');
  var entriesEl = document.getElementById('kb-logboek-entries');
  var filterEl = document.getElementById('lb-filter-type');
  var tellerEl = document.getElementById('lb-filter-teller');

  function esc(s){
    return String(s||'').replace(/[&<>"']/g,function(c){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]);
    });
  }

  function typeLabel(t){
    var map = {
      sollicitatie:'📧 Sollicitatie',
      mailcontact:'✉️ Mail contact',
      gesprek:'🤝 Gesprek',
      netwerk:'🌐 Netwerk',
      opleiding:'🎓 Opleiding',
      stage:'💼 Stage',
      werkbezoek:'🏢 Werkbezoek',
      jobcoach:'👩‍💼 Jobcoach',
      overig:'📝 Overig'
    };
    return map[t] || t;
  }

  function api(path, opts){
    opts = opts || {};
    // Veilige REST helper: werkt ook als wp.apiFetch anders is ingesteld.
    var root = (window.wpApiSettings && window.wpApiSettings.root) ? window.wpApiSettings.root : '/wp-json/';
    if (root.slice(-1) !== '/') root += '/';
    path = String(path || '').replace(/^\//,'');
    var url = root + (BP2SLogboek.restNs || 'bp-2s-logboek/v1') + '/' + path;

    var method = (opts.method || 'GET').toUpperCase();
    var hasBody = method !== 'GET' && method !== 'HEAD';
    var bodyObj = opts.data || opts.body;

    return fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': BP2SLogboek.nonce || ''
      },
      body: hasBody ? JSON.stringify(bodyObj || {}) : undefined
    }).then(function(r){
      return r.json().catch(function(){ return {}; }).then(function(j){
        if (!r.ok) {
          var msg = (j && (j.message || j.error)) ? (j.message || j.error) : 'Request mislukt.';
          throw { message: msg, data: j, status: r.status };
        }
        return j;
      });
    });
  }

  function setStatus(msg, ok){
    var s = document.getElementById('lb-save-status');
    if (!s) return;
    s.style.color = ok ? '#16a34a' : '#b91c1c';
    s.textContent = msg;
    if (msg) setTimeout(function(){ s.textContent=''; s.style.color='#64748b'; }, 2500);
  }

  var allItems = [];

  function computeStats(items){
    var totaal = items.length;
    var uren = 0;
    var sol = 0;
    var ges = 0;
    items.forEach(function(it){
      var u = parseFloat(it.uren);
      if (!isNaN(u)) uren += u;
      if (it.type === 'sollicitatie') sol++;
      if (it.type === 'gesprek') ges++;
    });

    var elT = document.getElementById('stat-totaal');
    var elU = document.getElementById('stat-uren');
    var elS = document.getElementById('stat-sollicitaties');
    var elG = document.getElementById('stat-gesprekken');
    if (elT) elT.textContent = totaal;
    if (elU) elU.textContent = (Math.round(uren*10)/10).toString();
    if (elS) elS.textContent = sol;
    if (elG) elG.textContent = ges;

    // print samenvatting
    var ps = document.getElementById('kb-print-samenvatting');
    if (ps){
      ps.innerHTML = '';
      // In print is flex soms rommelig. Daarom: vaste 4 kolommen.
      ps.innerHTML = ''
        + '<table class="kb-print-stats"><tr>'
        + '<td><div class="kb-print-stat"><div class="n">'+totaal+'</div><div class="l">ACTIVITEITEN</div></div></td>'
        + '<td><div class="kb-print-stat"><div class="n kb-orange">'+(Math.round(uren*10)/10)+'</div><div class="l">UREN TOTAAL</div></div></td>'
        + '<td><div class="kb-print-stat"><div class="n kb-purple">'+sol+'</div><div class="l">SOLLICITATIES</div></div></td>'
        + '<td><div class="kb-print-stat"><div class="n kb-green">'+ges+'</div><div class="l">GESPREKKEN</div></div></td>'
        + '</tr></table>';
    }
  }

  function buildPrintTable(items){
    var pe = document.getElementById('kb-print-entries');
    if (!pe) return;

    var rows = items.map(function(it){
      var uren = (it.uren === null || it.uren === '' || typeof it.uren === 'undefined') ? '' : esc(it.uren);
      return '<tr>'
        + '<td>'+esc(it.datum)+'</td>'
        + '<td>'+esc(typeLabel(it.type))+'</td>'
        + '<td>'+esc(it.omschrijving||'')+'</td>'
        + '<td>'+esc(it.resultaat||'')+'</td>'
        + '<td class="kb-num">'+uren+'</td>'
        + '</tr>';
    }).join('');

    pe.innerHTML = '<table class="kb-print-table"><thead><tr>'
      + '<th>Datum</th>'
      + '<th>Type</th>'
      + '<th>Omschrijving</th>'
      + '<th>Resultaat</th>'
      + '<th class="kb-num">Uren</th>'
      + '</tr></thead><tbody>'+rows+'</tbody></table>';
  }

  function typeOpties(selected){
    var types = [
      ['sollicitatie','📧 Sollicitatie verstuurd'],
      ['gesprek','🤝 Gesprek / intake'],
      ['mailcontact','✉️ Mail contact'],
      ['netwerk','🌐 Netwerken'],
      ['opleiding','🎓 Opleiding / cursus'],
      ['stage','💼 Stage / proefplaatsing'],
      ['werkbezoek','🏢 Werkbezoek / oriëntatie'],
      ['jobcoach','👩‍💼 Gesprek met jobcoach'],
      ['overig','📝 Overig']
    ];
    return types.map(function(t){
      return '<option value="'+t[0]+'"'+(t[0]===selected?' selected':'')+'>'+t[1]+'</option>';
    }).join('');
  }

  function render(items){
    var ft = filterEl ? filterEl.value : '';
    var filtered = ft ? items.filter(function(it){ return it.type === ft; }) : items;

    if (tellerEl){
      tellerEl.textContent = filtered.length + ' van ' + items.length;
    }

    if (!entriesEl) return;

    if (!filtered.length){
      entriesEl.innerHTML = '<div class="kb-card" style="padding:16px;color:#64748b;">Nog geen logboek-entries.</div>';
      return;
    }

    entriesEl.innerHTML = filtered.map(function(it){
      var uren = (it.uren === null || it.uren === '' || typeof it.uren === 'undefined') ? '' : (' • ' + it.uren + ' uur');
      var urenVal = (it.uren === null || it.uren === '' || typeof it.uren === 'undefined') ? '' : esc(it.uren);
      return '<div class="kb-card kb-entry" id="lb-entry-'+esc(it.id)+'">'
        + '<div class="kb-entry-head">'
        +   '<div>'
        +     '<div class="kb-entry-title">'+esc(typeLabel(it.type))+'</div>'
        +     '<div class="kb-entry-meta">'+esc(it.datum)+ uren +'</div>'
        +   '</div>'
        +   '<div style="display:flex;gap:8px;">'
        +     '<button class="kb-linkbtn" data-edit="'+esc(it.id)+'">✏️</button>'
        +     '<button class="kb-linkbtn danger" data-del="'+esc(it.id)+'">Verwijderen</button>'
        +   '</div>'
        + '</div>'
        + '<div class="kb-entry-body"><strong>Omschrijving:</strong>\n'+esc(it.omschrijving||'')+'</div>'
        + (it.resultaat ? ('<div class="kb-entry-body" style="margin-top:8px;"><strong>Resultaat:</strong>\n'+esc(it.resultaat||'')+'</div>') : '')
        + '<div id="lb-edit-'+esc(it.id)+'" style="display:none;margin-top:10px;background:#f8fafc;border-radius:10px;padding:12px;">'
        +   '<div class="kb-grid-3" style="margin-bottom:8px;">'
        +     '<div><label class="kb-field-label">Datum</label><input type="date" id="lb-edit-datum-'+esc(it.id)+'" class="kb-input" value="'+esc(it.datum)+'"></div>'
        +     '<div><label class="kb-field-label">Type</label><select id="lb-edit-type-'+esc(it.id)+'" class="kb-select">'+typeOpties(it.type)+'</select></div>'
        +     '<div><label class="kb-field-label">Uren</label><input type="number" id="lb-edit-uren-'+esc(it.id)+'" class="kb-input" placeholder="bijv. 1.5" min="0" max="24" step="0.5" value="'+urenVal+'"></div>'
        +   '</div>'
        +   '<div class="kb-grid-2" style="margin-bottom:8px;">'
        +     '<div><label class="kb-field-label">Omschrijving</label><textarea id="lb-edit-omschr-'+esc(it.id)+'" class="kb-textarea">'+esc(it.omschrijving||'')+'</textarea></div>'
        +     '<div><label class="kb-field-label">Resultaat</label><textarea id="lb-edit-res-'+esc(it.id)+'" class="kb-textarea">'+esc(it.resultaat||'')+'</textarea></div>'
        +   '</div>'
        +   '<div style="display:flex;gap:6px;">'
        +     '<button type="button" class="kb-btn kb-btn-primary kb-btn-sm" data-save-edit="'+esc(it.id)+'">Opslaan</button>'
        +     '<button type="button" class="kb-btn kb-btn-ghost kb-btn-sm" data-cancel-edit="'+esc(it.id)+'">Annuleren</button>'
        +   '</div>'
        + '</div>'
        + '</div>';
    }).join('');

    // toggle edit form
    entriesEl.querySelectorAll('[data-edit]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-edit');
        var box = document.getElementById('lb-edit-'+id);
        if (!box) return;
        box.style.display = (box.style.display === 'none' || !box.style.display) ? 'block' : 'none';
      });
    });

    // cancel edit
    entriesEl.querySelectorAll('[data-cancel-edit]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-cancel-edit');
        var box = document.getElementById('lb-edit-'+id);
        if (box) box.style.display = 'none';
      });
    });

    // save edit
    entriesEl.querySelectorAll('[data-save-edit]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-save-edit');
        var datum = (document.getElementById('lb-edit-datum-'+id)||{}).value || '';
        var type  = (document.getElementById('lb-edit-type-'+id)||{}).value || '';
        var uren  = (document.getElementById('lb-edit-uren-'+id)||{}).value || '';
        var omsch = (document.getElementById('lb-edit-omschr-'+id)||{}).value || '';
        var res   = (document.getElementById('lb-edit-res-'+id)||{}).value || '';
        api('logboek/' + id, {
          method: 'PATCH',
          data: { datum: datum, type: type, uren: uren, omschrijving: omsch, resultaat: res }
        }).then(function(){
          setStatus('Opgeslagen.', true);
          load();
        }).catch(function(e){
          setStatus((e && e.message) ? e.message : 'Opslaan mislukt.', false);
        });
      });
    });

    // bind delete
    entriesEl.querySelectorAll('[data-del]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = parseInt(btn.getAttribute('data-del'),10);
        if (!id) return;
        if (!confirm('Deze entry verwijderen?')) return;
        api('logboek/' + id, { method: 'DELETE' }).then(load).catch(function(){ setStatus('Verwijderen mislukt.', false); });
      });
    });
  }

  function load(){
    api('logboek').then(function(res){
      allItems = (res && res.items) ? res.items : [];
      computeStats(allItems);
      render(allItems);
      buildPrintTable(allItems);
    }).catch(function(){
      if (entriesEl) entriesEl.innerHTML = '<div class="kb-card" style="padding:16px;color:#b91c1c;">Fout bij laden.</div>';
    });
  }

  function save(){
    var datum = document.getElementById('lb-datum').value;
    var type  = document.getElementById('lb-type').value;
    var uren  = document.getElementById('lb-uren').value;
    var omsch = document.getElementById('lb-omschrijving').value;
    var res   = document.getElementById('lb-resultaat').value;

    api('logboek', {
      method: 'POST',
      data: {
        datum: datum,
        type: type,
        uren: uren,
        omschrijving: omsch,
        resultaat: res
      }
    }).then(function(){
      document.getElementById('lb-uren').value='';
      document.getElementById('lb-omschrijving').value='';
      document.getElementById('lb-resultaat').value='';
      setStatus('Opgeslagen.', true);
      load();
    }).catch(function(e){
      var msg = (e && e.message) ? e.message : 'Opslaan mislukt.';
      setStatus(msg, false);
    });
  }

  // events
  var saveBtn = document.getElementById('lb-save-btn');
  if (saveBtn) saveBtn.addEventListener('click', save);
  if (filterEl) filterEl.addEventListener('change', function(){ render(allItems); });

  var printBtn = root.querySelector('[data-bp-logboek-print-all]');
  if (printBtn) printBtn.addEventListener('click', function(){
    buildPrintTable(allItems);
    window.print();
  });


  load();
})();

  
  // Open/close modal
  var modal = document.getElementById('kb-sign-modal');
  var btnOpen = document.querySelector('[data-bp-sign-open]');
  function openModal(){
    if (!modal) return;
    modal.setAttribute('aria-hidden','false');
  }
  function closeModal(){
    if (!modal) return;
    modal.setAttribute('aria-hidden','true');
  }
  if (btnOpen) btnOpen.addEventListener('click', openModal);
  document.addEventListener('click', function(e){
    if (!e.target) return;
    if (e.target.matches('[data-bp-sign-close]')) {
      e.preventDefault();
      closeModal();
    }
  });


// ── Signature ────────────────────────────────────
var canvas = document.getElementById('kb-sign-canvas');

// Client (huidige gebruiker)
var signImgClient = document.getElementById('kb-print-sign-img-client');
var signPlaceholderClient = document.getElementById('kb-print-sign-placeholder-client');
var signNameClient = document.getElementById('kb-print-sign-name-client');
var signDateClient = document.getElementById('kb-print-sign-date-client');

// Begeleider (optioneel, komt uit data-attribuut in PHP)
var signImgBegeleider = document.getElementById('kb-print-sign-img-begeleider');
var signPlaceholderBegeleider = document.getElementById('kb-print-sign-placeholder-begeleider');
var signNameBegeleider = document.getElementById('kb-print-sign-name-begeleider');
var signDateBegeleider = document.getElementById('kb-print-sign-date-begeleider');

var nameInput = document.getElementById('kb-sign-name');
var statusEl = document.getElementById('kb-sign-status');

function setSignBlock(imgEl, placeholderEl, nameEl, dateEl, dataUrl, nm, dt){
  if (dataUrl && imgEl){
    imgEl.src = dataUrl;
    imgEl.style.display = 'block';
    if (placeholderEl) placeholderEl.style.display = 'none';
  } else {
    if (imgEl){
      imgEl.removeAttribute('src');
      imgEl.style.display = 'none';
    }
    if (placeholderEl) placeholderEl.style.display = 'block';
  }
  if (nameEl) nameEl.textContent = nm || (nameEl.textContent || '');
  if (dateEl) dateEl.textContent = dt || (dateEl.textContent || '');
}

function setStatus(msg, ok){
  if (!statusEl) return;
  statusEl.textContent = msg || '';
  statusEl.className = 'kb-sign-status' + (ok ? ' is-ok' : ' is-bad');
}

function loadClientSignature(){
  if (!window.wp || !wp.apiFetch) return;

  wp.apiFetch({
    path: (BP2SLogboek.restPath || '/bp-2s-logboek/v1/') + 'signature',
    method: 'GET',
    headers: { 'X-WP-Nonce': BP2SLogboek.nonce }
  }).then(function(res){
    res = res || {};
    var dataUrl = res.dataUrl || res.signature || '';
    var nm = res.name || '';
    var dt = res.date || new Date().toLocaleDateString('nl-NL');

    setSignBlock(signImgClient, signPlaceholderClient, signNameClient, signDateClient, dataUrl, nm, dt);

    // teken ook op canvas (preview) als er al een handtekening is
    if (canvas && dataUrl){
      var ctx = canvas.getContext('2d');
      var img = new Image();
      img.onload = function(){
        ctx.clearRect(0,0,canvas.width,canvas.height);
        ctx.drawImage(img,0,0,canvas.width,canvas.height);
      };
      img.src = dataUrl;
    }
  }).catch(function(){});
}

function loadBegeleiderSignatureFromData(){
  var root = document.getElementById('kb-logboek-root');
  if (!root) return;

  var dataUrl = root.getAttribute('data-bp-begeleider-sig') || '';
  var nm = root.getAttribute('data-bp-begeleider-name') || '';
  var dt = new Date().toLocaleDateString('nl-NL');

  // als er geen begeleider is gekoppeld -> laat placeholders staan
  if (!nm && signNameBegeleider) nm = signNameBegeleider.textContent || '—';

  setSignBlock(signImgBegeleider, signPlaceholderBegeleider, signNameBegeleider, signDateBegeleider, dataUrl, nm, dt);
}

function initPad(){
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  ctx.lineWidth = 2;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#0f172a';
  var drawing = false;

  function pos(e){
    var r = canvas.getBoundingClientRect();
    var x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
    var y = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
    return {x:x, y:y};
  }

  function start(e){
    drawing = true;
    var p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
    e.preventDefault();
  }
  function move(e){
    if (!drawing) return;
    var p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    e.preventDefault();
  }
  function end(){ drawing = false; }

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);

  canvas.addEventListener('touchstart', start, {passive:false});
  canvas.addEventListener('touchmove', move, {passive:false});
  canvas.addEventListener('touchend', end);
}

var btnClear = document.querySelector('[data-bp-sign-clear]');
var btnSave  = document.querySelector('[data-bp-sign-save]');
var uploadEl = document.getElementById('kb-sign-upload');

if (uploadEl && canvas){
  uploadEl.addEventListener('change', function(){
    var file = uploadEl.files && uploadEl.files[0];
    if (!file) return;

    if (!/^image\/(png|jpeg)$/.test(file.type)){
      setStatus('Upload alleen PNG of JPG.', false);
      uploadEl.value = '';
      return;
    }

    var reader = new FileReader();
    reader.onload = function(evt){
      var img = new Image();
      img.onload = function(){
        var ctx = canvas.getContext('2d');
        ctx.clearRect(0,0,canvas.width,canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        setStatus('Upload geladen. Klik op Opslaan.', true);
      };
      img.src = evt.target.result;
    };
    reader.readAsDataURL(file);
  });
}

if (btnClear && canvas){
  btnClear.addEventListener('click', function(){
    var ctx = canvas.getContext('2d');
    ctx.clearRect(0,0,canvas.width,canvas.height);
    setStatus('Gewist.', true);
  });
}

if (btnSave && canvas){
  btnSave.addEventListener('click', function(){
    if (!window.wp || !wp.apiFetch) return;
    var dataUrl = canvas.toDataURL('image/png');
    var nm = nameInput ? nameInput.value : '';
    setStatus('Opslaan…', true);

    wp.apiFetch({
      path: (BP2SLogboek.restPath || '/bp-2s-logboek/v1/') + 'signature',
      method: 'POST',
      data: { signature: dataUrl, dataUrl: dataUrl, name: nm },
      headers: { 'X-WP-Nonce': BP2SLogboek.nonce }
    }).then(function(res){
      res = res || {};
      var dt = res.date || new Date().toLocaleDateString('nl-NL');
      setSignBlock(signImgClient, signPlaceholderClient, signNameClient, signDateClient, dataUrl, nm, dt);
      setStatus('Opgeslagen ✅', true);
    }).catch(function(){
      setStatus('Opslaan mislukt.', false);
    });
  });
}

initPad();
loadClientSignature();
loadBegeleiderSignatureFromData();

// ===============================
  // Begeleider logboek UI (zoals oude app)
  // ===============================
  function isLeidinggevende(){
    var root = document.getElementById('kb-begel-logboek-root');
    if(root && root.getAttribute('data-bp-is-leid')==='1') return true;
    return document.body && document.body.classList.contains('bp-role-leidinggevende');
  }

  function fmtDatumNL(iso){
    if(!iso) return '';
    var p = String(iso).split('-'); if(p.length!==3) return iso;
    return p[2]+'-'+p[1]+'-'+p[0];
  }

  function typMeta(t){
    var map = {
      gesprek:{bg:'#dcfce7',cl:'#166534',lbl:'🤝 Gesprek'},
      email:{bg:'#dbeafe',cl:'#1d4ed8',lbl:'📧 E-mail'},
      belafspraak:{bg:'#ede9fe',cl:'#6d28d9',lbl:'📞 Belafspraak'},
      voortgang:{bg:'#fef9c3',cl:'#92400e',lbl:'📈 Voortgang'},
      rapport:{bg:'#fed7aa',cl:'#c2410c',lbl:'📋 Rapport'},
      overig:{bg:'#f1f5f9',cl:'#374151',lbl:'📝 Overig'}
    };
    return map[t] || {bg:'#f1f5f9',cl:'#374151',lbl:(t||'Overig')};
  }

  async function api(path, opts){
    opts = opts || {};
    opts.headers = Object.assign({'Content-Type':'application/json','X-WP-Nonce': (window.wpApiSettings?wpApiSettings.nonce:'')}, opts.headers||{});
    opts.credentials = 'same-origin';
    var root = (window.wpApiSettings && wpApiSettings.root) ? wpApiSettings.root : '/wp-json/';
    var url = root.replace(/\/$/,'') + '/bp-2s-logboek/v1' + path;
    return fetch(url, opts);
  }

  async function laadClientsVoorBegeleider(){
    var sel = document.getElementById('begel-client');
    if(!sel) return;
    sel.innerHTML = '<option value="">Laden…</option>';
    try{
      var r = await api('/clients');
      var data = await r.json();
      if(!Array.isArray(data)) data = [];
      sel.innerHTML = '<option value="">— Kies cliënt —</option>' + data.map(function(c){
        return '<option value="'+c.id+'">'+esc(c.naam)+'</option>';
      }).join('');
    }catch(e){
      sel.innerHTML = '<option value="">Fout bij laden</option>';
    }
  }

  async function laadBegelEntries(clientId){
    var wrap = document.getElementById('kb-begel-entries');
    if(!wrap) return;
    if(!clientId){ wrap.innerHTML = '<div class="kb-card" style="color:#64748b;">Kies eerst een cliënt.</div>'; return; }
    wrap.innerHTML = '<div style="padding:12px;color:#94a3b8;">Laden…</div>';
    try{
      var r = await api('/begel-logboek/'+clientId);
      var entries = await r.json();
      if(!Array.isArray(entries)) entries = [];
      renderBegelEntries(clientId, entries);
    }catch(e){
      wrap.innerHTML = '<div class="kb-card" style="color:#dc2626;">Fout bij laden.</div>';
    }
  }

  function renderBegelEntries(clientId, entries){
    var wrap = document.getElementById('kb-begel-entries');
    var IS_LEID = isLeidinggevende();
    if(!wrap) return;
    if(!entries.length){
      wrap.innerHTML = '<div class="kb-card" style="text-align:center;color:#94a3b8;">Nog geen logboek-entries.</div>';
      return;
    }
    var html = '';
    entries.forEach(function(e){
      var meta = typMeta(e.type);
      var bewerkt = parseInt(e.bewerkt_count||0,10) || 0;
      var magBewerken = IS_LEID || bewerkt < 1;
      var magVerwijderen = IS_LEID;
      html += '<div class="kb-card" style="margin-bottom:12px;" id="bgl-entry-'+e.id+'">';
      html += '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:flex-start;margin-bottom:6px;">';
      html += '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
      html += '<span style="background:'+meta.bg+';color:'+meta.cl+';padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;">'+esc(meta.lbl)+'</span>';
      html += '<span style="font-size:12px;color:#64748b;">'+esc(fmtDatumNL(e.datum))+'</span>';
      if(bewerkt>0 && !IS_LEID){
        html += '<span style="font-size:10px;color:#94a3b8;background:#f1f5f9;padding:1px 6px;border-radius:6px;">✏️ 1× bewerkt (max bereikt)</span>';
      }
      html += '</div>';
      html += '<div style="display:flex;gap:6px;align-items:center;">';
      if(magBewerken){
        html += '<button type="button" class="kb-btn kb-btn-ghost kb-btn-sm" data-bgl-edit="'+e.id+'">✏️</button>';
      } else {
        html += '<span style="font-size:10px;color:#94a3b8;">🔒 niet meer aan te passen</span>';
      }
      if(magVerwijderen){
        html += '<button type="button" class="kb-btn kb-btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;" data-bgl-del="'+e.id+'" data-bgl-client="'+clientId+'">✕</button>';
      }
      html += '</div></div>';
      html += '<div style="font-size:13px;color:#374151;font-weight:500;">'+esc(e.omschrijving||'')+'</div>';
      if(e.vervolg){
        html += '<div style="margin-top:6px;font-size:12px;color:#64748b;background:#f8fafc;border-radius:8px;padding:6px 10px;"><strong>Vervolg:</strong> '+esc(e.vervolg)+'</div>';
      }
      html += '<div id="bgl-edit-'+e.id+'" style="display:none;margin-top:10px;background:#f8fafc;border-radius:8px;padding:12px;">';
      html += '<div class="kb-grid-2" style="margin-bottom:8px;">';
      html += '<div><label class="kb-field-label">Omschrijving</label><textarea class="kb-textarea" id="bgl-edit-omschr-'+e.id+'" style="min-height:60px;">'+esc(e.omschrijving||'')+'</textarea></div>';
      html += '<div><label class="kb-field-label">Vervolg</label><textarea class="kb-textarea" id="bgl-edit-vervolg-'+e.id+'" style="min-height:60px;">'+esc(e.vervolg||'')+'</textarea></div>';
      html += '</div>';
      html += '<div style="display:flex;gap:6px;">';
      html += '<button type="button" class="kb-btn kb-btn-primary kb-btn-sm" data-bgl-save="'+e.id+'" data-bgl-client="'+clientId+'">💾 Opslaan</button>';
      html += '<button type="button" class="kb-btn kb-btn-ghost kb-btn-sm" data-bgl-cancel="'+e.id+'">Annuleren</button>';
      html += '</div></div>';
      html += '</div>';
    });
    wrap.innerHTML = html;

    // wire actions
    wrap.querySelectorAll('[data-bgl-edit]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = this.getAttribute('data-bgl-edit');
        var box = document.getElementById('bgl-edit-'+id);
        if(box) box.style.display = (box.style.display==='none' || !box.style.display) ? 'block' : 'none';
      });
    });
    wrap.querySelectorAll('[data-bgl-cancel]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = this.getAttribute('data-bgl-cancel');
        var box = document.getElementById('bgl-edit-'+id);
        if(box) box.style.display = 'none';
      });
    });
    wrap.querySelectorAll('[data-bgl-save]').forEach(function(btn){
      btn.addEventListener('click', async function(){
        var id = this.getAttribute('data-bgl-save');
        var clientId = this.getAttribute('data-bgl-client');
        var oms = document.getElementById('bgl-edit-omschr-'+id).value.trim();
        var ver = document.getElementById('bgl-edit-vervolg-'+id).value.trim();
        var r = await api('/begel-logboek/entry/'+id, {method:'PATCH', body: JSON.stringify({omschrijving:oms, vervolg:ver})});
        var data = await r.json();
        if(data && data.ok){ laadBegelEntries(clientId); }
        else alert('Opslaan mislukt');
      });
    });
    wrap.querySelectorAll('[data-bgl-del]').forEach(function(btn){
      btn.addEventListener('click', async function(){
        if(!confirm('Entry verwijderen?')) return;
        var id = this.getAttribute('data-bgl-del');
        var clientId = this.getAttribute('data-bgl-client');
        var r = await api('/begel-logboek/entry/'+id, {method:'DELETE'});
        var data = await r.json();
        if(data && data.ok){ laadBegelEntries(clientId); }
      });
    });
  }

  function initBegeleider(){
    var root = document.getElementById('kb-begel-logboek-root');
    if(!root) return;
    laadClientsVoorBegeleider();
    var sel = document.getElementById('begel-client');
    if(sel){
      sel.addEventListener('change', function(){ laadBegelEntries(this.value); });
    }
    var btn = document.getElementById('bgl-save-btn');
    if(btn){
      btn.addEventListener('click', async function(){
        var clientId = document.getElementById('begel-client').value;
        var datum = document.getElementById('bgl-datum').value;
        var type = document.getElementById('bgl-type').value;
        var oms = document.getElementById('bgl-omschrijving').value.trim();
        var ver = document.getElementById('bgl-vervolg').value.trim();
        var stat = document.getElementById('bgl-save-status');
        if(!clientId){ if(stat) stat.textContent='Kies eerst een cliënt.'; return; }
        if(!datum || !oms){ if(stat) stat.textContent='Datum en omschrijving zijn verplicht.'; return; }
        if(stat) stat.textContent='Opslaan…';
        try{
          var r = await api('/begel-logboek/'+clientId, {method:'POST', body: JSON.stringify({datum:datum, type:type, omschrijving:oms, vervolg:ver})});
          var data = await r.json();
          if(data && data.ok){
            if(stat) stat.textContent='✅ Opgeslagen';
            document.getElementById('bgl-omschrijving').value='';
            document.getElementById('bgl-vervolg').value='';
            laadBegelEntries(clientId);
            setTimeout(function(){ if(stat) stat.textContent=''; }, 2000);
          } else {
            if(stat) stat.textContent='❌ Opslaan mislukt';
          }
        }catch(e){
          if(stat) stat.textContent='❌ Opslaan mislukt';
        }
      });
    }
  }

  // init begeleider na DOM ready
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', initBegeleider);
  } else {
    initBegeleider();
  }


// --- Handtekening upload (PNG/JPG) ---
document.addEventListener('change', function(e){
  if (!e.target || e.target.id !== 'kb-sign-upload') return;
  const file = e.target.files && e.target.files[0];
  if (!file) return;
  if (!/^image\/(png|jpeg)$/.test(file.type)) {
    alert('Upload alleen PNG of JPG.');
    e.target.value = '';
    return;
  }
  const reader = new FileReader();
  reader.onload = function(evt){
    const img = new Image();
    img.onload = function(){
      const canvas = document.getElementById('kb-sign-canvas');
      if (!canvas) return;
      const ctx = canvas.getContext('2d');
      ctx.clearRect(0,0,canvas.width,canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    };
    img.src = evt.target.result;
  };
  reader.readAsDataURL(file);
});

