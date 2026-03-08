(function(){
  if (!window.BP2SLogboek || !document.getElementById('kb-begel-logboek-root')) return;

  var rootEl   = document.getElementById('kb-begel-logboek-root');
  var clientSel= document.getElementById('begel-client');
  var msgEl    = document.getElementById('begel-client-msg');
  var _allClientOptions = [];
  var entriesEl= document.getElementById('kb-begel-entries');

  function esc(s){
    return String(s||'').replace(/[&<>"']/g,function(c){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]);
    });
  }

  function api(path, opts){
    opts = opts || {};
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
    var s = document.getElementById('bgl-save-status');
    if (!s) return;
    s.style.color = ok ? '#16a34a' : '#b91c1c';
    s.textContent = msg;
    if (msg) setTimeout(function(){ s.textContent=''; s.style.color='#64748b'; }, 2500);
  }

  function typeLabel(t){
    var map = {
      gesprek:'🤝 Gesprek',
      email:'📧 E-mail',
      belafspraak:'📞 Belafspraak',
      voortgang:'📈 Voortgang',
      rapport:'📋 Rapport',
      overig:'📝 Overig'
    };
    return map[t] || t;
  }

  function typeBadge(t){
    var map = {
      gesprek:{bg:'#dcfce7',kl:'#166534'},
      email:{bg:'#dbeafe',kl:'#1d4ed8'},
      belafspraak:{bg:'#ede9fe',kl:'#6d28d9'},
      voortgang:{bg:'#fef9c3',kl:'#92400e'},
      rapport:{bg:'#fed7aa',kl:'#c2410c'},
      overig:{bg:'#f1f5f9',kl:'#374151'}
    };
    return map[t] || {bg:'#f1f5f9',kl:'#374151'};
  }

  var currentClientId = 0;
  var currentClientName = '';
  var items = [];
  var isLeid = !!BP2SLogboek.isLeid;

  // Handtekening-elementen en helpers (outer scope zodat loadClients/clientSel ze kunnen bereiken)
  var signImgClient          = document.getElementById('kb-print-sign-img-client');
  var signPlaceholderClient  = document.getElementById('kb-print-sign-placeholder-client');
  var signNameClient         = document.getElementById('kb-print-sign-name-client');
  var signDateClient         = document.getElementById('kb-print-sign-date-client');

  var signImgBegeleider         = document.getElementById('kb-print-sign-img-begeleider');
  var signPlaceholderBegeleider = document.getElementById('kb-print-sign-placeholder-begeleider');
  var signNameBegeleider        = document.getElementById('kb-print-sign-name-begeleider');
  var signDateBegeleider        = document.getElementById('kb-print-sign-date-begeleider');

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
    if (nameEl) nameEl.textContent = nm || '—';
    if (dateEl) dateEl.textContent = dt || new Date().toLocaleDateString('nl-NL');
  }

  function loadBegeleiderSignature(){
    api('signature').then(function(res){
      res = res || {};
      var dataUrl = res.dataUrl || res.signature || '';
      setSignBlock(signImgBegeleider, signPlaceholderBegeleider, signNameBegeleider, signDateBegeleider, dataUrl, res.name || '—', res.date || '');
    }).catch(function(){
      setSignBlock(signImgBegeleider, signPlaceholderBegeleider, signNameBegeleider, signDateBegeleider, '', '—', '');
    });
  }

  function loadClientSignature(clientId){
    if (!clientId) {
      setSignBlock(signImgClient, signPlaceholderClient, signNameClient, signDateClient, '', '—', '');
      return;
    }
    api('signature-user/' + clientId).then(function(res){
      res = res || {};
      var dataUrl = res.dataUrl || res.signature || '';
      setSignBlock(signImgClient, signPlaceholderClient, signNameClient, signDateClient, dataUrl, res.name || '—', res.date || '');
    }).catch(function(){
      setSignBlock(signImgClient, signPlaceholderClient, signNameClient, signDateClient, '', '—', '');
    });
  }

  function setPrintHeader(){
    var logoEl = document.getElementById('kb-bp-print-logo');
    var siteEl = document.getElementById('kb-bp-print-sitename');
    var clientEl = document.getElementById('kb-bp-print-client');
    var userEl = document.getElementById('kb-bp-print-user');
    var dateEl = document.getElementById('kb-bp-print-date');

    if (logoEl) {
      if (BP2SLogboek.siteIcon) {
        logoEl.src = BP2SLogboek.siteIcon;
        logoEl.style.display = 'block';
      } else {
        logoEl.style.display = 'none';
      }
    }
    if (siteEl) siteEl.textContent = BP2SLogboek.siteName || '';
    if (clientEl) clientEl.textContent = currentClientName || '—';
    if (userEl) userEl.textContent = BP2SLogboek.userName || '';
    if (dateEl) {
      var d = new Date();
      dateEl.textContent = d.toLocaleDateString('nl-NL') + ' ' + d.toLocaleTimeString('nl-NL', {hour:'2-digit', minute:'2-digit'});
    }
  }

  function render(){
    if (!entriesEl) return;

    if (!currentClientId){
      entriesEl.innerHTML = '<div class="kb-card" style="padding:16px;color:#64748b;">Kies eerst een cliënt.</div>';
      return;
    }

    if (!items.length){
      entriesEl.innerHTML = '<div class="kb-card" style="padding:16px;color:#64748b;">Nog geen aantekeningen.</div>';
      return;
    }

    entriesEl.innerHTML = items.map(function(it){
      var badge = typeBadge(it.type);
      var bewerkt = parseInt(it.bewerkt || 0, 10) || 0;
      var magBewerken = isLeid || bewerkt < 1;
      var magVerwijderen = isLeid;

      var lock = (!magBewerken && !isLeid) ? '<span style="font-size:11px;color:#94a3b8;">🔒 niet meer aan te passen</span>' : '';
      var warn = (bewerkt > 0 && !isLeid) ? '<span style="font-size:10px;color:#94a3b8;background:#f1f5f9;padding:1px 6px;border-radius:6px;">✏️ 1× bewerkt</span>' : '';

      return ''
        + '<div class="kb-card kb-entry" id="bgl-entry-'+esc(it.id)+'">'
        +   '<div class="kb-entry-head" style="gap:8px;flex-wrap:wrap;">'
        +     '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
        +       '<span style="background:'+badge.bg+';color:'+badge.kl+';padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;">'+esc(typeLabel(it.type))+'</span>'
        +       '<span class="kb-entry-meta">'+esc(it.datum)+'</span>'
        +       warn
        +     '</div>'
        +     '<div class="kb-entry-actions" style="display:flex;gap:8px;align-items:center;">'
        +       (magBewerken ? ('<button class="kb-linkbtn" data-edit="'+esc(it.id)+'">✏️</button>') : lock)
        +       (magVerwijderen ? ('<button class="kb-linkbtn danger" data-del="'+esc(it.id)+'">✕</button>') : '')
        +     '</div>'
        +   '</div>'
        +   '<div class="kb-entry-body"><strong>Omschrijving:</strong><br>'+esc(it.omschrijving||'')+'</div>'
        +   (it.vervolg ? ('<div class="kb-entry-body" style="margin-top:8px;background:#f8fafc;border-radius:10px;padding:10px;"><strong>Vervolg:</strong> '+esc(it.vervolg)+'</div>') : '')
        +   '<div class="kb-card" id="bgl-edit-'+esc(it.id)+'" style="display:none;margin-top:12px;background:#f8fafc;">'
        +     '<div class="kb-grid-2">'
        +       '<div><label class="kb-field-label">Omschrijving</label><textarea class="kb-textarea" id="bgl-edit-omschr-'+esc(it.id)+'">'+esc(it.omschrijving||'')+'</textarea></div>'
        +       '<div><label class="kb-field-label">Vervolg</label><textarea class="kb-textarea" id="bgl-edit-vervolg-'+esc(it.id)+'">'+esc(it.vervolg||'')+'</textarea></div>'
        +     '</div>'
        +     '<div class="kb-actions">'
        +       '<button class="kb-btn kb-btn-primary" data-save="'+esc(it.id)+'">Opslaan</button>'
        +       '<button class="kb-btn kb-btn-ghost kb-btn-sm" data-cancel="'+esc(it.id)+'">Annuleren</button>'
        +     '</div>'
        +   '</div>'
        + '</div>';
    }).join('');

    // toggle edit
    entriesEl.querySelectorAll('[data-edit]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-edit');
        var box = document.getElementById('bgl-edit-'+id);
        if (!box) return;
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
      });
    });

    // cancel
    entriesEl.querySelectorAll('[data-cancel]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-cancel');
        var box = document.getElementById('bgl-edit-'+id);
        if (box) box.style.display = 'none';
      });
    });

    // save
    entriesEl.querySelectorAll('[data-save]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-save');
        var oms = document.getElementById('bgl-edit-omschr-'+id);
        var ver = document.getElementById('bgl-edit-vervolg-'+id);
        api('begel-logboek/entry/' + id, { method:'PATCH', data:{ omschrijving: (oms?oms.value:''), vervolg: (ver?ver.value:'') } })
          .then(function(){ setStatus('Opgeslagen.', true); load(); })
          .catch(function(e){ setStatus((e && e.message) ? e.message : 'Opslaan mislukt.', false); });
      });
    });

    // delete
    entriesEl.querySelectorAll('[data-del]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-del');
        if (!confirm('Aantekening verwijderen?')) return;
        api('begel-logboek/entry/' + id, { method:'DELETE' })
          .then(function(){ setStatus('Verwijderd.', true); load(); })
          .catch(function(e){ setStatus((e && e.message) ? e.message : 'Verwijderen mislukt.', false); });
      });
    });
  }

  function buildPrint(){
    var pe = document.getElementById('kb-begel-print-entries');
    if (!pe) return;

    setPrintHeader();

    var rows = items.map(function(it){
      return '<tr>'
        + '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;font-size:9px;white-space:nowrap;">'+esc(it.datum)+'</td>'
        + '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;font-size:9px;">'+esc(typeLabel(it.type))+'</td>'
        + '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;font-size:9px;">'+esc(it.omschrijving||'')+'</td>'
        + '<td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;font-size:9px;">'+esc(it.vervolg||'')+'</td>'
        + '</tr>';
    }).join('');

    var lijnKleur = (BP2SLogboek.lijnKleur || '#0047AB');
    pe.innerHTML = '<table style="width:100%;border-collapse:collapse;">'
      + '<thead><tr>'
      + '<th style="text-align:left;font-size:9px;border-bottom:1.5px solid '+lijnKleur+';padding:6px 8px;">Datum</th>'
      + '<th style="text-align:left;font-size:9px;border-bottom:1.5px solid '+lijnKleur+';padding:6px 8px;">Type</th>'
      + '<th style="text-align:left;font-size:9px;border-bottom:1.5px solid '+lijnKleur+';padding:6px 8px;">Omschrijving</th>'
      + '<th style="text-align:left;font-size:9px;border-bottom:1.5px solid '+lijnKleur+';padding:6px 8px;">Vervolg</th>'
      + '</tr></thead><tbody>'+rows+'</tbody></table>';
  }

  function load(){
    if (!currentClientId){
      items = [];
      render();
      return;
    }

    api('begel-logboek/' + currentClientId).then(function(res){
      items = (res && res.items) ? res.items : [];
      render();
      buildPrint();
    }).catch(function(e){
      if (entriesEl) entriesEl.innerHTML = '<div class="kb-card" style="padding:16px;color:#b91c1c;">Fout bij laden.</div>';
      if (e && e.message) setStatus(e.message, false);
    });
  }

  function loadClients(){
    api('clients').then(function(res){
      var list = (res && res.items) ? res.items : [];
      if (!clientSel) return;

      if (!list.length){
        clientSel.innerHTML = '<option value="">Geen cliënten gevonden</option>';
        currentClientId = 0;
        if (msgEl) msgEl.textContent = 'Er zijn geen cliënten gekoppeld.';
        load();
        return;
      }

      clientSel.innerHTML = '<option value="">-- Kies cliënt --</option>' + list.map(function(c){
        return '<option value="'+esc(c.id)+'">'+esc(c.naam)+'</option>';
      }).join('');

      // Sla alle opties op voor cross-browser zoekfilter (opt.hidden werkt niet betrouwbaar in <select>)
      _allClientOptions = Array.from(clientSel.options);

      if (msgEl) msgEl.textContent = 'Kies een cliënt om voortgang en aantekeningen bij te houden.';

      // Pre-selectie vanuit URL ?client_id=X (bijv. via dashboard knop per cliënt)
      var _urlPreId = 0;
      try {
        var _urlParams = new URLSearchParams(window.location.search);
        _urlPreId = parseInt(_urlParams.get('client_id') || '0', 10) || 0;
      } catch (e) {}

      if (_urlPreId) {
        var _preMatch = list.find(function(c){ return parseInt(c.id, 10) === _urlPreId; });
        if (_preMatch) {
          currentClientId   = _urlPreId;
          currentClientName = _preMatch.naam || '';
          clientSel.value   = String(_urlPreId);
          loadClientSignature(currentClientId);
          load();
        }
      } else if (list.length === 1) {
        // Als er maar 1 cliënt is, selecteer die automatisch
        currentClientId = parseInt(list[0].id, 10) || 0;
        currentClientName = list[0].naam || '';
        clientSel.value = String(currentClientId);
        loadClientSignature(currentClientId);
        load();
      }
    }).catch(function(e){
      if (msgEl) msgEl.textContent = (e && e.message) ? e.message : 'Fout bij laden.';
    });
  }

  function save(){
    if (!currentClientId){
      setStatus('Kies eerst een cliënt.', false);
      return;
    }

    var datum = (document.getElementById('bgl-datum')||{}).value || '';
    var type  = (document.getElementById('bgl-type')||{}).value || '';
    var omsch = (document.getElementById('bgl-omschrijving')||{}).value || '';
    var verv  = (document.getElementById('bgl-vervolg')||{}).value || '';

    api('begel-logboek/' + currentClientId, { method:'POST', data:{ datum: datum, type: type, omschrijving: omsch, vervolg: verv } })
      .then(function(){
        document.getElementById('bgl-omschrijving').value='';
        document.getElementById('bgl-vervolg').value='';
        setStatus('Opgeslagen.', true);
        load();
      })
      .catch(function(e){ setStatus((e && e.message) ? e.message : 'Opslaan mislukt.', false); });
  }

  if (clientSel){
    clientSel.addEventListener('change', function(){
      currentClientId = parseInt(clientSel.value,10) || 0;
      loadClientSignature(currentClientId);
      currentClientName = '';
      if (currentClientId){
        var opt = clientSel.options[clientSel.selectedIndex];
        currentClientName = opt ? opt.textContent : '';
      }
      load();
    });
  }

  var searchEl = document.getElementById('begel-client-search');
  if (searchEl && clientSel) {
    searchEl.addEventListener('input', function() {
      var q = this.value.trim().toLowerCase();
      var prevVal = clientSel.value;
      // Verwijder alle opties en voeg alleen matching opties toe (cross-browser fix)
      while (clientSel.options.length > 0) clientSel.remove(0);
      _allClientOptions.forEach(function(opt) {
        if (opt.value === '' || q === '' || opt.textContent.toLowerCase().indexOf(q) !== -1) {
          clientSel.appendChild(opt);
        }
      });
      // Herstel selectie als die nog zichtbaar is
      if (prevVal) {
        var found = Array.from(clientSel.options).some(function(o){ return o.value === prevVal; });
        clientSel.value = found ? prevVal : '';
      }
    });
  }

  var saveBtn = document.getElementById('bgl-save-btn');
  if (saveBtn) saveBtn.addEventListener('click', save);

  var printBtn = rootEl.querySelector('[data-bp-begel-print]');
  if (printBtn) printBtn.addEventListener('click', function(){
    buildPrint();
    window.print();
  });

  // =========================
  // Handtekening (begeleider)
  // =========================
  (function(){
    var modal = document.getElementById('kb-sign-modal');
    var btnOpen = rootEl.querySelector('[data-bp-sign-open]');
    if (!modal || !btnOpen) return;

    function openModal(){
      modal.setAttribute('aria-hidden', 'false');
      modal.classList.add('is-open');
    }
    function closeModal(){
      modal.setAttribute('aria-hidden', 'true');
      modal.classList.remove('is-open');
    }

    btnOpen.addEventListener('click', openModal);
    modal.addEventListener('click', function(e){
      if (e.target && e.target.matches('[data-bp-sign-close]')) closeModal();
    });

    var canvas = document.getElementById('kb-sign-canvas');
    var ctx = canvas ? canvas.getContext('2d') : null;
    var btnClear = modal.querySelector('[data-bp-sign-clear]');
    var btnSave  = modal.querySelector('[data-bp-sign-save]');
    var nameInput = document.getElementById('kb-sign-name');
    var statusEl  = document.getElementById('kb-sign-status');

    // canvas tekenen
    if (canvas && ctx){
      ctx.lineWidth = 2;
      ctx.lineCap = 'round';
      ctx.strokeStyle = '#0f172a';

      var drawing = false;
      function pos(ev){
        var r = canvas.getBoundingClientRect();
        var x = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left;
        var y = (ev.touches ? ev.touches[0].clientY : ev.clientY) - r.top;
        return {x:x, y:y};
      }
      function start(ev){
        drawing = true;
        var p = pos(ev);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        ev.preventDefault();
      }
      function move(ev){
        if (!drawing) return;
        var p = pos(ev);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        ev.preventDefault();
      }
      function end(){ drawing = false; }

      canvas.addEventListener('mousedown', start);
      canvas.addEventListener('mousemove', move);
      window.addEventListener('mouseup', end);
      canvas.addEventListener('touchstart', start, {passive:false});
      canvas.addEventListener('touchmove', move, {passive:false});
      canvas.addEventListener('touchend', end);
    }

    if (btnClear && canvas && ctx){
      btnClear.addEventListener('click', function(){
        ctx.clearRect(0,0,canvas.width, canvas.height);
        setStatus('Gewist.', true);
      });
    }

    if (btnSave && canvas){
      btnSave.addEventListener('click', function(){
        var dataUrl = '';
        try { dataUrl = canvas.toDataURL('image/png'); } catch(e) {}
        var nm = nameInput ? nameInput.value : '';
        api('signature', { method:'POST', data:{ dataUrl: dataUrl, name: nm } })
          .then(function(res){
            setStatus('Opgeslagen.', true);
            var dt = res.date || new Date().toLocaleDateString('nl-NL');
            setSignBlock(signImgBegeleider, signPlaceholderBegeleider, signNameBegeleider, signDateBegeleider, res.dataUrl || dataUrl, res.name || nm, dt);
          })
          .catch(function(e){ setStatus((e && e.message) ? e.message : 'Opslaan mislukt.', false); });
      });
    }

    // init
    loadBegeleiderSignature();
  })();

  loadClients();
  render();
})();

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

