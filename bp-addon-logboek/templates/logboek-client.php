<?php defined('ABSPATH') || exit;

$client   = wp_get_current_user();
$org_naam = get_option('kb_organisatie_naam', 'Beroepen Portaal');

// gekoppelde begeleider (optioneel, vanuit Core)
$begeleider_id   = (int) get_user_meta($client->ID, 'kb_begeleider_id', true);
$begeleider_name = '';
if ($begeleider_id > 0) {
  $b = get_user_by('id', $begeleider_id);
  if ($b && !is_wp_error($b)) {
    $begeleider_name = (string) $b->display_name;
  }
}

// Handtekeningen (per gebruiker opgeslagen)
$client_sig      = (string) get_user_meta($client->ID, 'kb_handtekening', true);
$client_sig_name = (string) get_user_meta($client->ID, 'kb_handtekening_naam', true);
if ($client_sig_name === '') { $client_sig_name = (string) $client->display_name; }

$begeleider_sig      = '';
$begeleider_sig_name = '';
if ($begeleider_id > 0) {
  $begeleider_sig      = (string) get_user_meta($begeleider_id, 'kb_handtekening', true);
  $begeleider_sig_name = (string) get_user_meta($begeleider_id, 'kb_handtekening_naam', true);
  if ($begeleider_sig_name === '') { $begeleider_sig_name = (string) $begeleider_name; }
}

// PDF logo: via instellingen (fallback: site icon)
$logo_client = (string) get_option('bp_2s_logo_client_url', '');
if ($logo_client === '' && function_exists('get_site_icon_url')) {
  $logo_client = (string) get_site_icon_url(128);
}

$portaal  = get_permalink(get_page_by_path('portaal'));

$title      = isset($attrs['title']) ? (string)$attrs['title'] : '2e Spoor Logboek';
$introTitle = isset($attrs['introTitle']) ? (string)$attrs['introTitle'] : '2e Spoor Re-integratie Logboek';
$introText  = isset($attrs['introText']) ? (string)$attrs['introText'] : '';
$showStats  = !empty($attrs['showStats']);
$showFilter = !empty($attrs['showFilter']);
$showExport = !empty($attrs['showExport']);
$showPortaal= !empty($attrs['showPortaal']);
?>


<script>
window.BP2SLogboek = window.BP2SLogboek || {
  nonce: '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>',
  restPath: '/bp-2s-logboek/v1/'
};
</script>

<div class="kb-wrap" id="kb-logboek-root" data-bp-logboek="client" data-bp-begeleider-sig="<?php echo esc_attr($begeleider_sig); ?>" data-bp-begeleider-name="<?php echo esc_attr($begeleider_sig_name); ?>">

  <!-- PRINT – tabel zodat kop/voet op elke pagina herhaalt (zoals Word) -->
  <table class="kb-page-table kb-only-print">
    <thead>
      <tr><td class="kb-page-head-td">
        <div class="kb-print-headrow">
          <div class="kb-print-brand">
            <?php if ($logo_client): ?>
              <img src="<?php echo esc_url($logo_client); ?>" class="kb-print-logo" alt="">
            <?php endif; ?>
          </div>
          <div class="kb-print-right">
            <div class="kb-print-name"><?php echo esc_html($client->display_name); ?></div>
            <?php if (!empty($begeleider_name)): ?>
              <div class="kb-print-date">Begeleider: <?php echo esc_html($begeleider_name); ?></div>
            <?php endif; ?>
            <div class="kb-print-date">Exportdatum: <?php echo esc_html(date('d-m-Y')); ?></div>
          </div>
        </div>
      </td></tr>
    </thead>
    <tbody>
      <tr><td class="kb-page-body-td">
        <div id="kb-print-samenvatting"></div>
        <div class="kb-print-title">Activiteitenoverzicht</div>
        <div id="kb-print-entries"></div>
      </td></tr>
    </tbody>
    <tfoot>
      <tr><td class="kb-page-foot-td">
        <div class="kb-print-signatures">
          <div class="kb-print-sign-col">
            <div class="kb-print-sign-label">Handtekening cliënt</div>
            <div class="kb-print-sign-box">
              <div id="kb-print-sign-placeholder-client" class="kb-print-sign-placeholder"></div>
              <img id="kb-print-sign-img-client" alt="Handtekening cliënt" style="display:none;" />
            </div>
            <div class="kb-print-sign-meta">
              <div><strong>Naam:</strong> <span id="kb-print-sign-name-client"><?php echo esc_html($client_sig_name); ?></span></div>
              <div><strong>Datum:</strong> <span id="kb-print-sign-date-client"><?php echo esc_html(date('d-m-Y')); ?></span></div>
            </div>
          </div>
          <div class="kb-print-sign-col">
            <div class="kb-print-sign-label">Handtekening begeleider</div>
            <div class="kb-print-sign-box">
              <div id="kb-print-sign-placeholder-begeleider" class="kb-print-sign-placeholder"></div>
              <img id="kb-print-sign-img-begeleider" alt="Handtekening begeleider" style="display:none;" />
            </div>
            <div class="kb-print-sign-meta">
              <div><strong>Naam:</strong> <span id="kb-print-sign-name-begeleider"><?php echo esc_html($begeleider_sig_name ?: '—'); ?></span></div>
              <div><strong>Datum:</strong> <span id="kb-print-sign-date-begeleider"><?php echo esc_html(date('d-m-Y')); ?></span></div>
            </div>
          </div>
        </div>
      </td></tr>
    </tfoot>
  </table>

  <!-- SCHERM INTERFACE -->
  <div class="kb-topbar kb-no-print">
    <div class="kb-topbar-left">
      <span class="kb-emoji">📋</span>
      <span class="kb-topbar-title"><?php echo esc_html($title); ?></span>
    </div>
    <div class="kb-topbar-right">
      <?php if ($showExport): ?>
        <div class="kb-pdf-actions"><button type="button" class="kb-btn kb-btn-orange" data-bp-logboek-print-all>🖨️ PDF exporteren</button><button type="button" class="kb-btn kb-btn-ghost" data-bp-sign-open>✍️ Handtekening</button></div>
      <?php endif; ?>
      <?php if ($showPortaal && $portaal): ?>
        <a href="<?php echo esc_url($portaal); ?>" class="kb-btn kb-btn-ghost kb-btn-sm">← Portaal</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="kb-banner kb-no-print">
    <div class="kb-banner-title"><?php echo esc_html($introTitle); ?></div>
    <div class="kb-banner-text"><?php echo esc_html($introText); ?></div>
  </div>

  <?php if ($showStats): ?>
  <div class="kb-stats kb-no-print">
    <div class="kb-card kb-stat"><div class="kb-stat-num" id="stat-totaal">—</div><div class="kb-stat-label">Activiteiten</div></div>
    <div class="kb-card kb-stat"><div class="kb-stat-num kb-orange" id="stat-uren">—</div><div class="kb-stat-label">Uren totaal</div></div>
    <div class="kb-card kb-stat"><div class="kb-stat-num kb-purple" id="stat-sollicitaties">—</div><div class="kb-stat-label">Sollicitaties</div></div>
    <div class="kb-card kb-stat"><div class="kb-stat-num kb-green" id="stat-gesprekken">—</div><div class="kb-stat-label">Gesprekken</div></div>
  </div>
  <?php endif; ?>

  <div class="kb-card kb-no-print" style="margin-bottom:20px;">
    <div class="kb-card-title">+ Nieuwe activiteit toevoegen</div>

    <div class="kb-grid-3">
      <div>
        <label class="kb-field-label">Datum *</label>
        <input type="date" id="lb-datum" class="kb-input" value="<?php echo esc_attr(date('Y-m-d')); ?>">
      </div>
      <div>
        <label class="kb-field-label">Type activiteit *</label>
        <select id="lb-type" class="kb-select">
          <option value="sollicitatie">📧 Sollicitatie verstuurd</option>
          <option value="gesprek">🤝 Gesprek / intake</option>
          <option value="mailcontact">✉️ Mail contact</option>
          <option value="netwerk">🌐 Netwerken</option>
          <option value="opleiding">🎓 Opleiding / cursus</option>
          <option value="stage">💼 Stage / proefplaatsing</option>
          <option value="werkbezoek">🏢 Werkbezoek / oriëntatie</option>
          <option value="jobcoach">👩‍💼 Gesprek met jobcoach</option>
          <option value="overig">📝 Overig</option>
        </select>
      </div>
      <div>
        <label class="kb-field-label">Uren besteed</label>
        <input type="number" id="lb-uren" class="kb-input" placeholder="bijv. 1.5" min="0" max="24" step="0.5">
      </div>
    </div>

    <div class="kb-grid-2">
      <div>
        <label class="kb-field-label">Omschrijving *</label>
        <textarea id="lb-omschrijving" class="kb-textarea" placeholder="Bijv: Sollicitatie verstuurd naar ..."></textarea>
      </div>
      <div>
        <label class="kb-field-label">Resultaat / reactie</label>
        <textarea id="lb-resultaat" class="kb-textarea" placeholder="Bijv: Uitnodiging ontvangen voor gesprek..."></textarea>
      </div>
    </div>

    <div class="kb-actions">
      <div id="lb-save-status" class="kb-status"></div>
      <button type="button" id="lb-save-btn" class="kb-btn kb-btn-primary">Toevoegen</button>
    </div>
  </div>

  <?php if ($showFilter): ?>

  
<div class="kb-modal kb-no-print" id="kb-sign-modal" aria-hidden="true">
  <div class="kb-modal-overlay" data-bp-sign-close></div>
  <div class="kb-modal-card">
    <div class="kb-modal-head">
      <div class="kb-modal-title">Digitale handtekening</div>
      <button type="button" class="kb-btn kb-btn-ghost kb-btn-sm" data-bp-sign-close>Sluiten ✕</button>
    </div>
    <div class="kb-card kb-no-print" style="margin-top:0;">
    <h3 style="margin:0 0 10px 0;">Digitale handtekening</h3>
    <p style="margin:0 0 12px 0; color:#64748b;">Zet je handtekening hieronder. Die komt ook mee in de PDF.</p>

    <div class="kb-sign-row">
      <div class="kb-sign-pad">
        <canvas id="kb-sign-canvas" width="520" height="180" style="width:100%;max-width:520px;height:auto;display:block;touch-action:none;"></canvas>

<div class="kb-sign-upload">
  <label class="kb-label">Of upload een handtekening (PNG/JPG)</label>
  <input type="file" id="kb-sign-upload" accept="image/png,image/jpeg" />
</div>
        <div class="kb-sign-actions">
          <button type="button" class="kb-btn kb-btn-ghost" data-bp-sign-clear>Wissen</button>
          <button type="button" class="kb-btn kb-btn-primary" data-bp-sign-save>Opslaan</button>
        </div>
      </div>

      <div class="kb-sign-meta">
        <label class="kb-label">Naam</label>
        <input id="kb-sign-name" class="kb-input" type="text" value="<?php echo esc_attr($client->display_name); ?>" />
        <div class="kb-sign-hint">Datum wordt automatisch gezet.</div>
        <div id="kb-sign-status" class="kb-sign-status" aria-live="polite"></div>
      </div>
    </div>
  </div>
  </div>
</div>


  <!-- NB: handtekening preview hoort niet op het dashboard, alleen in print (footer hierboven). -->

  <div class="kb-filter kb-no-print">
    <select id="lb-filter-type" class="kb-select" style="max-width:260px;">
      <option value="">Alle typen</option>
      <option value="sollicitatie">📧 Sollicitaties</option>
      <option value="gesprek">🤝 Gesprekken</option>
      <option value="mailcontact">✉️ Mail contact</option>
      <option value="netwerk">🌐 Netwerken</option>
      <option value="opleiding">🎓 Opleiding</option>
      <option value="stage">💼 Stage</option>
      <option value="werkbezoek">🏢 Werkbezoek</option>
      <option value="jobcoach">👩‍💼 Jobcoach</option>
      <option value="overig">📝 Overig</option>
    </select>
    <div id="lb-filter-teller" class="kb-filter-count"></div>
  </div>
  <?php endif; ?>

  <div id="kb-logboek-entries" class="kb-no-print"></div>

</div>
