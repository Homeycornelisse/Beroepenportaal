<?php
defined('ABSPATH') || exit;

$logo_client = (string) get_option('bp_2s_beroepen_logo_client_url', '');
if ($logo_client === '') {
  $logo_client = (string) get_option('bp_beroepen_pdf_logo_client_url', '');
}
if ($logo_client === '') {
  $logo_client = (string) get_option('bp_2s_logo_client_url', '');
}
if ($logo_client === '' && function_exists('get_site_icon_url')) {
  $logo_client = (string) get_site_icon_url(128);
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
<div class="bp-beroepen" data-bp-beroepen-app>
  <div class="bp-beroepen-filterbar">
    <div class="bp-beroepen-filterbar-title">Beroepenoverzicht</div>
    <div class="bp-beroepen-filterbar-text">Filter op sector en niveau en vink beroepen aan die passen bij jouw traject.</div>

    <div class="bp-beroepen-toolbar">
      <select id="bp-beroepen-sector" class="bp-beroepen-select">
        <option value="">Alle sectoren</option>
      </select>

      <select id="bp-beroepen-niveau" class="bp-beroepen-select">
        <option value="">Alle niveaus</option>
        <option value="Basis">Basisvakmanschap</option>
        <option value="Middelbaar">MBO</option>
        <option value="Hoger">HBO/WO</option>
      </select>

      <select id="bp-beroepen-weergave" class="bp-beroepen-select">
        <option value="all">Alles tonen</option>
        <option value="liked">Alleen Vind ik leuk</option>
        <option value="doelgroep">Alleen Doelgroep-registratie</option>
      </select>
      <input type="search" id="bp-beroepen-zoek" class="bp-beroepen-search" placeholder="Zoek beroep, sector of niveau...">

      <button type="button" id="bp-beroepen-reset" class="bp-beroepen-reset">Filters wissen</button>
      <button type="button" id="bp-beroepen-print-btn" class="bp-beroepen-reset bp-beroepen-print-btn">PDF afdrukken</button>

      <div id="bp-beroepen-counter" class="bp-beroepen-counter">0 van 0 beroepen</div>
    </div>
  </div>

  <div id="bp-beroepen-grid" class="bp-beroepen-grid">
    <div class="bp-beroepen-loading">Beroepen laden...</div>
  </div>

  <table class="bp-page-table bp-print-only">
    <thead>
      <tr><td class="bp-page-head-td">
        <div class="bp-print-headrow" style="border-bottom-color:<?php echo esc_attr($lijn_kleur); ?>;">
          <div class="bp-print-brand">
            <?php if ($logo_client): ?>
              <img src="<?php echo esc_url($logo_client); ?>" class="bp-print-logo" style="max-height:<?php echo (int) $logo_height; ?>px;" alt="">
            <?php endif; ?>
            <?php if (!$logo_client): ?>
              <div class="bp-print-brandtext">
                <div class="bp-print-org"><?php echo esc_html(function_exists('bp_core_get_org_name') ? bp_core_get_org_name('Beroepen Portaal') : get_bloginfo('name')); ?></div>
                <div class="bp-print-sub">BEROEPENOVERZICHT - VIND IK LEUK</div>
              </div>
            <?php endif; ?>
          </div>
          <div class="bp-print-right">
            <div class="bp-print-name"><?php echo esc_html(wp_get_current_user()->display_name); ?></div>
            <div class="bp-print-date">Exportdatum: <?php echo esc_html(date('d-m-Y')); ?></div>
          </div>
        </div>
      </td></tr>
    </thead>
    <tbody>
      <tr><td class="bp-page-body-td">
        <div id="bp-print-content"></div>
      </td></tr>
    </tbody>
    <tfoot>
      <tr><td class="bp-page-foot-td">
        <div class="bp-print-footrow" style="border-top-color:<?php echo esc_attr($lijn_kleur); ?>;">
          <span><?php echo esc_html(function_exists('bp_core_get_org_name') ? bp_core_get_org_name('Beroepen Portaal') : get_bloginfo('name')); ?></span>
          <span>Beroepenoverzicht PDF</span>
        </div>
        <?php if ($show_sign_client || $show_sign_begeleider): ?>
          <div class="bp-print-signatures">
            <?php if ($show_sign_client): ?>
              <div class="bp-print-signature-box">
                <div class="bp-print-sign-label">Client handtekening</div>
                <div class="bp-print-sign-line"></div>
                <div class="bp-print-sign-meta"><?php echo esc_html(wp_get_current_user()->display_name); ?> · <?php echo esc_html($print_date); ?></div>
              </div>
            <?php endif; ?>
            <?php if ($show_sign_begeleider): ?>
              <div class="bp-print-signature-box">
                <div class="bp-print-sign-label">Begeleider handtekening</div>
                <div class="bp-print-sign-line"></div>
                <div class="bp-print-sign-meta">Naam begeleider · <?php echo esc_html($print_date); ?></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </td></tr>
    </tfoot>
  </table>
</div>
