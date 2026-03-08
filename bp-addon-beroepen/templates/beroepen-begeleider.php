<?php
defined('ABSPATH') || exit;

$logo_begel = (string) get_option('bp_2s_beroepen_logo_begeleider_url', '');
if ($logo_begel === '') {
  $logo_begel = (string) get_option('bp_beroepen_pdf_logo_begeleider_url', '');
}
if ($logo_begel === '') {
  $logo_begel = (string) get_option('bp_2s_logo_begeleider_url', '');
}
if ($logo_begel === '' && function_exists('get_site_icon_url')) {
  $logo_begel = (string) get_site_icon_url(128);
}
$logo_height = (int) get_option('bp_beroepen_pdf_logo_height', 20);
if ($logo_height <= 0) {
  $logo_height = (int) get_option('bp_2s_logo_height', 20);
}
$logo_height = max(10, min(80, $logo_height));
$lijn_kleur = (string) get_option('bp_beroepen_pdf_line_color', '#0047AB');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $lijn_kleur)) {
  $lijn_kleur = (string) get_option('bp_2s_lijn_kleur', '#0047AB');
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $lijn_kleur)) {
  $lijn_kleur = '#0047AB';
}
$show_sign_client = (int) get_option('bp_beroepen_pdf_sign_client', 1) === 1;
$show_sign_begeleider = (int) get_option('bp_beroepen_pdf_sign_begeleider', 1) === 1;
$print_date = date('d-m-Y');
?>
<div class="bp-beroepen" data-bp-beroepen-app data-mode="begeleider">
  <div class="bp-beroepen-filterbar">
    <div class="bp-beroepen-filterbar-title">Begeleider werkruimte beroepen</div>
    <div class="bp-beroepen-filterbar-text">Kies een client, bekijk geselecteerde functies en geef professioneel advies met vervolgstappen en LKS %.</div>

    <div class="bp-beroepen-toolbar">
      <select id="bp-beroepen-client" class="bp-beroepen-select">
        <option value="">Selecteer client</option>
      </select>

      <button type="button" id="bp-beroepen-begel-print" class="bp-beroepen-reset bp-beroepen-print-btn" style="display:none;">PDF afdrukken</button>
      <div id="bp-beroepen-begel-counter" class="bp-beroepen-counter">0 geselecteerde beroepen</div>
    </div>
  </div>

  <div id="bp-beroepen-begel-grid" class="bp-beroepen-grid">
    <div class="bp-beroepen-loading">Selecteer een client om te starten...</div>
  </div>

  <table class="bp-page-table bp-print-only">
    <thead>
      <tr><td class="bp-page-head-td">
        <div class="bp-print-headrow" style="border-bottom-color:<?php echo esc_attr($lijn_kleur); ?>;">
          <div class="bp-print-brand">
            <?php if ($logo_begel): ?>
              <img src="<?php echo esc_url($logo_begel); ?>" class="bp-print-logo" style="max-height:<?php echo (int) $logo_height; ?>px;" alt="">
            <?php endif; ?>
            <?php if (!$logo_begel): ?>
              <div class="bp-print-brandtext">
                <div class="bp-print-org"><?php echo esc_html(function_exists('bp_core_get_org_name') ? bp_core_get_org_name('Beroepen Portaal') : get_bloginfo('name')); ?></div>
                <div class="bp-print-sub">BEGELEIDER DOSSIER BEROEPEN</div>
              </div>
            <?php endif; ?>
          </div>
          <div class="bp-print-right">
            <div class="bp-print-name" id="bp-print-client-name">Client: -</div>
            <div class="bp-print-date">Exportdatum: <?php echo esc_html(date('d-m-Y')); ?></div>
          </div>
        </div>
      </td></tr>
    </thead>
    <tbody>
      <tr><td class="bp-page-body-td">
        <div id="bp-begel-print-content"></div>
      </td></tr>
    </tbody>
    <tfoot>
      <tr><td class="bp-page-foot-td">
        <div class="bp-print-footrow" style="border-top-color:<?php echo esc_attr($lijn_kleur); ?>;">
          <span><?php echo esc_html(function_exists('bp_core_get_org_name') ? bp_core_get_org_name('Beroepen Portaal') : get_bloginfo('name')); ?></span>
          <span>Begeleider dossier beroepen</span>
        </div>
        <?php if ($show_sign_client || $show_sign_begeleider): ?>
          <div class="bp-print-signatures">
            <?php if ($show_sign_client): ?>
              <div class="bp-print-signature-box">
                <div class="bp-print-sign-label">Client handtekening</div>
                <div class="bp-print-sign-line"></div>
                <div class="bp-print-sign-meta" id="bp-print-sign-client">Client · <?php echo esc_html($print_date); ?></div>
              </div>
            <?php endif; ?>
            <?php if ($show_sign_begeleider): ?>
              <div class="bp-print-signature-box">
                <div class="bp-print-sign-label">Begeleider handtekening</div>
                <div class="bp-print-sign-line"></div>
                <div class="bp-print-sign-meta"><?php echo esc_html(wp_get_current_user()->display_name); ?> · <?php echo esc_html($print_date); ?></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </td></tr>
    </tfoot>
  </table>
</div>
