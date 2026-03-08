<?php
defined('ABSPATH') || exit;

$is_leid = current_user_can('kb_manage_team') || in_array('kb_leidinggevende', (array) wp_get_current_user()->roles, true);

$title      = isset($attrs['title']) ? (string) $attrs['title'] : 'Begeleider Logboek';
$showExport = !empty($attrs['showExport']);

$user = wp_get_current_user();
?>

<script>
window.BP2SLogboek = window.BP2SLogboek || {
  nonce: '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>',
  restPath: '/bp-2s-logboek/v1/'
};
</script>

<div class="kb-wrap" id="kb-begel-logboek-root" data-bp-logboek="begeleider" data-bp-is-leid="<?php echo $is_leid ? 1 : 0; ?>">

  <div class="kb-topbar kb-no-print">
    <div class="kb-topbar-left">
      <span class="kb-emoji">📝</span>
      <span class="kb-topbar-title"><?php echo esc_html($title); ?></span>
    </div>
    <div class="kb-topbar-right">
      <?php if ($showExport): ?>
        <div class="kb-pdf-actions">
          <button type="button" class="kb-btn kb-btn-orange" data-bp-begel-print>🖨️ PDF exporteren</button>
          <button type="button" class="kb-btn kb-btn-ghost" data-bp-sign-open>✍️ Handtekening</button>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Handtekening modal (alleen op scherm) -->
  <div class="kb-modal kb-no-print" id="kb-sign-modal" aria-hidden="true">
    <div class="kb-modal-overlay" data-bp-sign-close></div>
    <div class="kb-modal-card">
      <div class="kb-modal-head">
        <div class="kb-modal-title">Digitale handtekening</div>
        <button type="button" class="kb-btn kb-btn-ghost kb-btn-sm" data-bp-sign-close>Sluiten ✕</button>
      </div>

      <div class="kb-card kb-no-print" style="margin-top:0;">
        <p style="margin:0 0 12px 0; color:#64748b;">Zet je handtekening hieronder. Als je niks opslaat, komt er in de PDF gewoon een nette lijn te staan.</p>

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
            <input id="kb-sign-name" class="kb-input" type="text" value="<?php echo esc_attr($user->display_name); ?>" />
            <div class="kb-sign-hint">Datum wordt automatisch gezet.</div>
            <div id="kb-sign-status" class="kb-sign-status" aria-live="polite"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="kb-card kb-no-print" style="margin-bottom:16px;">
    <div class="kb-card-title">Kies cliënt</div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <input type="search" id="begel-client-search" class="kb-input" placeholder="Zoek cliënt..." style="max-width:min(200px,100%);flex:1 1 150px;">
      <select id="begel-client" class="kb-select" style="max-width:min(420px,100%);flex:2 1 200px;"></select>
    </div>
    <div id="begel-client-msg" style="margin-top:10px;color:#64748b;font-size:13px;"></div>
  </div>

  <div class="kb-card kb-no-print" style="margin-bottom:16px;">
    <div class="kb-card-title">+ Nieuwe aantekening</div>

    <div class="kb-grid-3">
      <div>
        <label class="kb-field-label">Datum *</label>
        <input type="date" id="bgl-datum" class="kb-input" value="<?php echo esc_attr(date('Y-m-d')); ?>">
      </div>
      <div>
        <label class="kb-field-label">Type *</label>
        <select id="bgl-type" class="kb-select">
          <option value="gesprek">🤝 Gesprek</option>
          <option value="email">📧 E-mail</option>
          <option value="belafspraak">📞 Belafspraak</option>
          <option value="voortgang">📈 Voortgangsrapportage</option>
          <option value="rapport">📋 Rapport</option>
          <option value="overig">📝 Overig</option>
        </select>
      </div>
      <div style="display:flex;align-items:flex-end;">
        <button type="button" id="bgl-save-btn" class="kb-btn kb-btn-primary" style="width:100%;">Toevoegen</button>
      </div>
    </div>

    <div class="kb-grid-2">
      <div>
        <label class="kb-field-label">Omschrijving *</label>
        <textarea id="bgl-omschrijving" class="kb-textarea" placeholder="Wat is er besproken of gedaan?"></textarea>
      </div>
      <div>
        <label class="kb-field-label">Vervolg / actie</label>
        <textarea id="bgl-vervolg" class="kb-textarea" placeholder="Vervolgafspraak, actie, deadline…"></textarea>
      </div>
    </div>

    <div id="bgl-save-status" class="kb-status"></div>
  </div>

  <div id="kb-begel-entries" class="kb-no-print"></div>

  <!-- PRINT – tabel zodat kop/voet op elke pagina herhaalt (zoals Word) -->
  <table class="kb-page-table kb-only-print">
    <thead>
      <tr><td class="kb-page-head-td">
        <div class="kb-print-headrow">
          <div class="kb-print-brand">
            <img id="kb-bp-print-logo" class="kb-print-logo" alt="Logo">
          </div>
          <div class="kb-print-meta">
            <div><strong>Cliënt:</strong> <span id="kb-bp-print-client">—</span></div>
            <div><strong>Begeleider:</strong> <span id="kb-bp-print-user">—</span></div>
            <div><strong>Export:</strong> <span id="kb-bp-print-date">—</span></div>
          </div>
        </div>
      </td></tr>
    </thead>
    <tbody>
      <tr><td class="kb-page-body-td">
        <div id="kb-begel-print-entries"></div>
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
              <div><strong>Naam:</strong> <span id="kb-print-sign-name-client">—</span></div>
              <div><strong>Datum:</strong> <span id="kb-print-sign-date-client">—</span></div>
            </div>
          </div>
          <div class="kb-print-sign-col">
            <div class="kb-print-sign-label">Handtekening begeleider</div>
            <div class="kb-print-sign-box">
              <div id="kb-print-sign-placeholder-begeleider" class="kb-print-sign-placeholder"></div>
              <img id="kb-print-sign-img-begeleider" alt="Handtekening begeleider" style="display:none;" />
            </div>
            <div class="kb-print-sign-meta">
              <div><strong>Naam:</strong> <span id="kb-print-sign-name-begeleider">—</span></div>
              <div><strong>Datum:</strong> <span id="kb-print-sign-date-begeleider">—</span></div>
            </div>
          </div>
        </div>
      </td></tr>
    </tfoot>
  </table>

</div>
</div>
