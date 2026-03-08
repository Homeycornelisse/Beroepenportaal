<?php
/**
 * Plugin Name:       Beroepen Portaal Add-on - 2e spoor Logboek
 * Plugin URI:        https://beroepen-portaal.nl
 * Description:       2e spoor-logboek voor cliënten + begeleidernotities, inclusief PDF-export. Add-on voor Beroepen Portaal Core.
 * Version:       1.4.3
 * Author:            Ruud Cornelisse
 * Text Domain:       bp-addon-2espoor-logboek
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

defined('ABSPATH') || exit;

define('BP_2S_LOGBOEK_VERSION', '1.4.3');
define('BP_2S_LOGBOEK_DIR', plugin_dir_path(__FILE__));
define('BP_2S_LOGBOEK_URL', plugin_dir_url(__FILE__));

function bp_2s_logboek_has_core(): bool {
    return class_exists('BP_Core_Loader')
        || defined('BP_CORE_DIR')
        || function_exists('bp_core_addon_ensure_page');
}

function bp_2s_logboek_admin_notice_missing_core(): void {
    if (!current_user_can('activate_plugins')) return;
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('BP Addon 2e Spoor Logboek heeft Beroepen Portaal Core nodig. Activeer eerst de Core plugin.', 'bp-addon-2espoor-logboek');
    echo '</p></div>';
}

register_activation_hook(__FILE__, function () {
    if (!bp_2s_logboek_has_core()) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('BP Addon 2e Spoor Logboek vereist Beroepen Portaal Core. Activeer eerst de Core plugin.', 'bp-addon-2espoor-logboek'),
            esc_html__('Plugin dependency missing', 'bp-addon-2espoor-logboek'),
            ['back_link' => true]
        );
    }

    require_once BP_2S_LOGBOEK_DIR . 'includes/class-bp-2s-logboek-util.php';
    \BP_2S_Logboek\Util::ensure_tables();
    \BP_2S_Logboek\Util::ensure_caps();

    if (function_exists('bp_core_addon_ensure_page')) {
        bp_core_addon_ensure_page('bp_addon_2s_logboek_page_id', 'Logboek', '<!-- wp:bp/tweedespoor-logboek-client /-->', 'logboek');
    }
});

if (!bp_2s_logboek_has_core()) {
    add_action('admin_notices', 'bp_2s_logboek_admin_notice_missing_core');
    return;
}

require_once BP_2S_LOGBOEK_DIR . 'includes/class-bp-2s-logboek-util.php';
require_once BP_2S_LOGBOEK_DIR . 'includes/class-bp-2s-logboek-rest.php';
require_once BP_2S_LOGBOEK_DIR . 'includes/class-bp-2s-logboek-blocks.php';
require_once BP_2S_LOGBOEK_DIR . 'includes/class-bp-2s-logboek-core-hooks.php';

add_action('plugins_loaded', function () {
    \BP_2S_Logboek\Blocks::init();
    \BP_2S_Logboek\Rest::init();
    \BP_2S_Logboek\CoreHooks::init();
});

/**
 * Admin: instellingen voor PDF-logo's (cliënt + begeleider)
 */
add_action('admin_menu', function () {
    if (!current_user_can('manage_options')) return;

    $parent = 'bp-core-tools';
    // Fallback als Core niet actief is / menu ontbreekt
    global $menu;
    $has_parent = false;
    if (is_array($menu)) {
        foreach ($menu as $m) {
            if (!empty($m[2]) && $m[2] === $parent) { $has_parent = true; break; }
        }
    }
    if (!$has_parent) {
        $parent = 'options-general.php';
    }

    add_submenu_page(
        $parent,
        'PDF instellingen',
        'PDF instellingen',
        'manage_options',
        'bp-2s-logboek-settings',
        function () {
            if (!current_user_can('manage_options')) return;

            // opslaan
            if (isset($_POST['bp_2s_save']) && check_admin_referer('bp_2s_logboek_settings')) {
                $client_url  = isset($_POST['bp_2s_logo_client_url']) ? esc_url_raw((string)$_POST['bp_2s_logo_client_url']) : '';
                $begel_url   = isset($_POST['bp_2s_logo_begeleider_url']) ? esc_url_raw((string)$_POST['bp_2s_logo_begeleider_url']) : '';
                $beroepen_client_url = isset($_POST['bp_2s_beroepen_logo_client_url']) ? esc_url_raw((string)$_POST['bp_2s_beroepen_logo_client_url']) : '';
                $beroepen_begel_url  = isset($_POST['bp_2s_beroepen_logo_begeleider_url']) ? esc_url_raw((string)$_POST['bp_2s_beroepen_logo_begeleider_url']) : '';
                $pdf_type = isset($_POST['bp_pdf_type']) ? sanitize_key((string) $_POST['bp_pdf_type']) : 'beroepen_client';
                $valid_pdf_types = ['beroepen_client', 'beroepen_begeleider', 'logboek_client', 'logboek_begeleider'];
                if (!in_array($pdf_type, $valid_pdf_types, true)) $pdf_type = 'beroepen_client';
                $layout_bg_url = isset($_POST['bp_beroepen_pdf_layout_bg_url']) ? esc_url_raw((string)$_POST['bp_beroepen_pdf_layout_bg_url']) : '';
                $layout_map = isset($_POST['bp_beroepen_pdf_layout_map']) ? wp_unslash((string) $_POST['bp_beroepen_pdf_layout_map']) : '';
                $sign_client = !empty($_POST['bp_beroepen_pdf_sign_client']) ? 1 : 0;
                $sign_begeleider = !empty($_POST['bp_beroepen_pdf_sign_begeleider']) ? 1 : 0;
                $logo_height = isset($_POST['bp_2s_logo_height']) ? max(10, min(80, (int)$_POST['bp_2s_logo_height'])) : 20;
                $lijn_kleur  = isset($_POST['bp_2s_lijn_kleur']) ? sanitize_hex_color((string)$_POST['bp_2s_lijn_kleur']) : '#0047AB';
                if (!$lijn_kleur) $lijn_kleur = '#0047AB';
                update_option('bp_2s_logo_client_url', $client_url);
                update_option('bp_2s_logo_begeleider_url', $begel_url);
                update_option('bp_2s_beroepen_logo_client_url', $beroepen_client_url);
                update_option('bp_2s_beroepen_logo_begeleider_url', $beroepen_begel_url);
                update_option('bp_pdf_layout_bg_' . $pdf_type, $layout_bg_url);
                if (json_decode($layout_map, true) !== null || trim($layout_map) === '') {
                    update_option('bp_pdf_layout_map_' . $pdf_type, trim($layout_map));
                    if ($pdf_type === 'beroepen_client') {
                        update_option('bp_beroepen_pdf_layout_bg_url', $layout_bg_url);
                        update_option('bp_beroepen_pdf_layout_map', trim($layout_map));
                    }
                }
                update_option('bp_beroepen_pdf_sign_client', $sign_client);
                update_option('bp_beroepen_pdf_sign_begeleider', $sign_begeleider);
                update_option('bp_2s_logo_height', $logo_height);
                update_option('bp_2s_lijn_kleur', $lijn_kleur);
                echo '<div class="notice notice-success is-dismissible"><p>Opgeslagen.</p></div>';
            }

            $client_url  = (string) get_option('bp_2s_logo_client_url', '');
            $begel_url   = (string) get_option('bp_2s_logo_begeleider_url', '');
            $beroepen_client_url = (string) get_option('bp_2s_beroepen_logo_client_url', '');
            $beroepen_begel_url  = (string) get_option('bp_2s_beroepen_logo_begeleider_url', '');
            $selected_pdf_type = isset($_GET['pdf']) ? sanitize_key((string) $_GET['pdf']) : 'beroepen_client';
            $valid_pdf_types = ['beroepen_client', 'beroepen_begeleider', 'logboek_client', 'logboek_begeleider'];
            if (!in_array($selected_pdf_type, $valid_pdf_types, true)) $selected_pdf_type = 'beroepen_client';
            $layout_bg_key = 'bp_pdf_layout_bg_' . $selected_pdf_type;
            $layout_map_key = 'bp_pdf_layout_map_' . $selected_pdf_type;
            $default_map = '{"headerTopMm":0,"tableTopMm":0,"footerTopMm":0,"leftMm":0,"rightMm":0}';
            $beroepen_layout_bg_url = (string) get_option($layout_bg_key, '');
            $beroepen_layout_map = (string) get_option($layout_map_key, '');
            if ($beroepen_layout_map === '') {
                if ($selected_pdf_type === 'beroepen_client') {
                    $beroepen_layout_bg_url = (string) get_option('bp_beroepen_pdf_layout_bg_url', $beroepen_layout_bg_url);
                    $beroepen_layout_map = (string) get_option('bp_beroepen_pdf_layout_map', $default_map);
                } else {
                    $beroepen_layout_map = $default_map;
                }
            }
            $logo_height = (int) get_option('bp_2s_logo_height', 20);
            $lijn_kleur  = (string) get_option('bp_2s_lijn_kleur', '#0047AB');
            $sign_client = (int) get_option('bp_beroepen_pdf_sign_client', 1);
            $sign_begeleider = (int) get_option('bp_beroepen_pdf_sign_begeleider', 1);
            $active_tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'algemeen';
            if (!in_array($active_tab, ['algemeen', 'layout'], true)) $active_tab = 'algemeen';
            $base_url = admin_url('admin.php?page=bp-2s-logboek-settings');

            wp_enqueue_media();
            ?>
            <div class="wrap">
              <h1>PDF instellingen</h1>
              <p>Hier stel je de logo's en lijnkleur in voor de PDF's. Deze instellingen worden gebruikt door zowel 2e spoor Logboek als de Beroepen addon.</p>
              <h2 class="nav-tab-wrapper" style="margin-bottom:14px;">
                <a href="<?php echo esc_url(add_query_arg('tab', 'algemeen', $base_url)); ?>" class="nav-tab <?php echo $active_tab === 'algemeen' ? 'nav-tab-active' : ''; ?>">Algemeen</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'layout', $base_url)); ?>" class="nav-tab <?php echo $active_tab === 'layout' ? 'nav-tab-active' : ''; ?>">PDF layout editor</a>
              </h2>

              <form method="post">
                <?php wp_nonce_field('bp_2s_logboek_settings'); ?>

                <table class="form-table" role="presentation">
                  <?php if ($active_tab === 'algemeen'): ?>
                  <tr>
                    <th scope="row">Logo voor cliënt-PDF</th>
                    <td>
                      <input type="text" class="regular-text" id="bp_2s_logo_client_url" name="bp_2s_logo_client_url" value="<?php echo esc_attr($client_url); ?>" />
                      <button type="button" class="button" id="bp_2s_pick_client">Kies / upload</button>
                      <button type="button" class="button" id="bp_2s_clear_client">Leegmaken</button>
                      <div style="margin-top:10px;">
                        <img id="bp_2s_preview_client" src="<?php echo esc_url($client_url); ?>" style="max-height:48px;<?php echo $client_url ? '' : 'display:none;'; ?>" alt="" />
                      </div>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">Logo voor begeleider-PDF</th>
                    <td>
                      <input type="text" class="regular-text" id="bp_2s_logo_begeleider_url" name="bp_2s_logo_begeleider_url" value="<?php echo esc_attr($begel_url); ?>" />
                      <button type="button" class="button" id="bp_2s_pick_begel">Kies / upload</button>
                      <button type="button" class="button" id="bp_2s_clear_begel">Leegmaken</button>
                      <div style="margin-top:10px;">
                        <img id="bp_2s_preview_begel" src="<?php echo esc_url($begel_url); ?>" style="max-height:48px;<?php echo $begel_url ? '' : 'display:none;'; ?>" alt="" />
                      </div>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">Logo voor Beroepen client-PDF</th>
                    <td>
                      <input type="text" class="regular-text" id="bp_2s_beroepen_logo_client_url" name="bp_2s_beroepen_logo_client_url" value="<?php echo esc_attr($beroepen_client_url); ?>" />
                      <button type="button" class="button" id="bp_2s_pick_beroepen_client">Kies / upload</button>
                      <button type="button" class="button" id="bp_2s_clear_beroepen_client">Leegmaken</button>
                      <div style="margin-top:10px;">
                        <img id="bp_2s_preview_beroepen_client" src="<?php echo esc_url($beroepen_client_url); ?>" style="max-height:48px;<?php echo $beroepen_client_url ? '' : 'display:none;'; ?>" alt="" />
                      </div>
                      <p class="description">Alleen voor de Beroepen PDF (client).</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">Logo voor Beroepen begeleider-PDF</th>
                    <td>
                      <input type="text" class="regular-text" id="bp_2s_beroepen_logo_begeleider_url" name="bp_2s_beroepen_logo_begeleider_url" value="<?php echo esc_attr($beroepen_begel_url); ?>" />
                      <button type="button" class="button" id="bp_2s_pick_beroepen_begel">Kies / upload</button>
                      <button type="button" class="button" id="bp_2s_clear_beroepen_begel">Leegmaken</button>
                      <div style="margin-top:10px;">
                        <img id="bp_2s_preview_beroepen_begel" src="<?php echo esc_url($beroepen_begel_url); ?>" style="max-height:48px;<?php echo $beroepen_begel_url ? '' : 'display:none;'; ?>" alt="" />
                      </div>
                      <p class="description">Alleen voor de Beroepen PDF (begeleider).</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">Handtekeningvelden (Beroepen PDF)</th>
                    <td>
                      <label style="display:block;margin-bottom:8px;">
                        <input type="checkbox" name="bp_beroepen_pdf_sign_client" value="1" <?php checked($sign_client, 1); ?>>
                        Toon handtekeningveld cliënt (met naam + datum)
                      </label>
                      <label style="display:block;">
                        <input type="checkbox" name="bp_beroepen_pdf_sign_begeleider" value="1" <?php checked($sign_begeleider, 1); ?>>
                        Toon handtekeningveld begeleider (met naam + datum)
                      </label>
                    </td>
                  </tr>
                  <?php endif; ?>
                <?php if ($active_tab === 'layout'): ?>
                  <tr>
                    <th scope="row">PDF type</th>
                    <td>
                      <select id="bp_pdf_type" name="bp_pdf_type">
                        <option value="beroepen_client" <?php selected($selected_pdf_type, 'beroepen_client'); ?>>Beroepen - Client PDF</option>
                        <option value="beroepen_begeleider" <?php selected($selected_pdf_type, 'beroepen_begeleider'); ?>>Beroepen - Begeleider PDF</option>
                        <option value="logboek_client" <?php selected($selected_pdf_type, 'logboek_client'); ?>>2e Spoor Logboek - Client PDF</option>
                        <option value="logboek_begeleider" <?php selected($selected_pdf_type, 'logboek_begeleider'); ?>>2e Spoor Logboek - Begeleider PDF</option>
                      </select>
                      <p class="description">Kies welk PDF-type je nu bewerkt.</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">Beroepen PDF-layout (achtergrond)</th>
                    <td>
                      <input type="text" class="regular-text" id="bp_beroepen_pdf_layout_bg_url" name="bp_beroepen_pdf_layout_bg_url" value="<?php echo esc_attr($beroepen_layout_bg_url); ?>" />
                      <button type="button" class="button" id="bp_pick_beroepen_layout_bg">Kies / upload</button>
                      <button type="button" class="button" id="bp_clear_beroepen_layout_bg">Leegmaken</button>
                      <div style="margin-top:10px;">
                        <img id="bp_preview_beroepen_layout_bg" src="<?php echo esc_url($beroepen_layout_bg_url); ?>" style="max-height:80px;<?php echo $beroepen_layout_bg_url ? '' : 'display:none;'; ?>" alt="" />
                      </div>
                      <p class="description">Upload een achtergrondafbeelding voor de Beroepen PDF (bijv. briefpapier).</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">Beroepen PDF veldmapping (JSON)</th>
                    <td>
                      <textarea id="bp_beroepen_pdf_layout_map" name="bp_beroepen_pdf_layout_map" rows="5" class="large-text code" style="display:none;"><?php echo esc_textarea($beroepen_layout_map); ?></textarea>
                      <div id="bp-pdf-layout-editor" style="display:grid;grid-template-columns:1fr 260px;gap:14px;max-width:1180px;">
                        <div style="border:1px solid #d6dce8;border-radius:10px;background:#fff;padding:12px;">
                          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                            <button type="button" class="button" data-add-layer="logo">Logo</button>
                            <button type="button" class="button" data-add-layer="org">Organisatienaam</button>
                            <button type="button" class="button" data-add-layer="title">Documenttitel</button>
                            <button type="button" class="button" data-add-layer="client">Clientnaam</button>
                            <button type="button" class="button" data-add-layer="date">Exportdatum</button>
                            <button type="button" class="button" data-add-layer="table">Tabel</button>
                            <button type="button" class="button" data-add-layer="signature">Handtekening</button>
                          </div>
                          <div id="bp-pdf-canvas" style="position:relative;width:100%;max-width:880px;aspect-ratio:1.414/1;margin:0 auto;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc center/cover no-repeat;overflow:hidden;">
                            <div style="position:absolute;left:0;right:0;top:0;height:22px;border-bottom:1px dashed #cbd5e1;opacity:.6;"></div>
                            <div style="position:absolute;left:0;right:0;bottom:0;height:22px;border-top:1px dashed #cbd5e1;opacity:.6;"></div>
                          </div>
                        </div>
                        <div style="border:1px solid #d6dce8;border-radius:10px;background:#fff;padding:12px;">
                          <div style="font-weight:700;margin-bottom:8px;">Lagen</div>
                          <div id="bp-pdf-layers"></div>
                          <hr>
                          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <label>Header top (mm)<input type="number" step="0.5" id="bp-map-header-top" class="small-text"></label>
                            <label>Tabel top (mm)<input type="number" step="0.5" id="bp-map-table-top" class="small-text"></label>
                            <label>Footer top (mm)<input type="number" step="0.5" id="bp-map-footer-top" class="small-text"></label>
                            <label>Marge links (mm)<input type="number" step="0.5" id="bp-map-left" class="small-text"></label>
                            <label>Marge rechts (mm)<input type="number" step="0.5" id="bp-map-right" class="small-text"></label>
                          </div>
                          <p class="description" style="margin-top:8px;">Sleep lagen in de preview. Posities worden als percentage opgeslagen.</p>
                        </div>
                      </div>
                      <p style="margin-top:10px;">
                        <button type="button" class="button button-secondary" id="bp-pdf-preview-btn">Preview PDF</button>
                      </p>
                    </td>
                  </tr>
                  <?php endif; ?>
                  <?php if ($active_tab === 'algemeen'): ?>
                  <tr>
                    <th scope="row">Logo-hoogte in PDF</th>
                    <td>
                      <input type="number" name="bp_2s_logo_height" value="<?php echo esc_attr($logo_height); ?>" min="10" max="80" step="1" style="width:80px;" /> px
                      <p class="description">Hoogte van het logo in de PDF-koptekst (10–80 px, standaard 20).</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">Kleur van de lijnen in PDF</th>
                    <td>
                      <input type="color" name="bp_2s_lijn_kleur" value="<?php echo esc_attr($lijn_kleur); ?>" style="height:36px;width:60px;padding:2px;cursor:pointer;" />
                      <span style="margin-left:8px;font-size:13px;color:#555;"><?php echo esc_html($lijn_kleur); ?></span>
                      <p class="description">Kleur van de horizontale lijnen in de PDF's (standaard #0047AB).</p>
                    </td>
                  </tr>
                  <?php endif; ?>
                </table>

                <p class="submit">
                  <button type="submit" name="bp_2s_save" class="button button-primary">Opslaan</button>
                </p>
              </form>
            </div>

            <script>
            (function(){
              function pick(btnId, inputId, imgId){
                var btn = document.getElementById(btnId);
                var input = document.getElementById(inputId);
                var img = document.getElementById(imgId);
                if (!btn || !input) return;
                btn.addEventListener('click', function(e){
                  e.preventDefault();
                  var frame = wp.media({
                    title: 'Kies een logo',
                    button: { text: 'Gebruik dit logo' },
                    multiple: false
                  });
                  frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    if (!att || !att.url) return;
                    input.value = att.url;
                    if (img){ img.src = att.url; img.style.display = 'block'; }
                  });
                  frame.open();
                });
              }

              function clear(btnId, inputId, imgId){
                var btn = document.getElementById(btnId);
                var input = document.getElementById(inputId);
                var img = document.getElementById(imgId);
                if (!btn || !input) return;
                btn.addEventListener('click', function(e){
                  e.preventDefault();
                  input.value = '';
                  if (img){ img.src=''; img.style.display='none'; }
                });
              }

              pick('bp_2s_pick_client','bp_2s_logo_client_url','bp_2s_preview_client');
              pick('bp_2s_pick_begel','bp_2s_logo_begeleider_url','bp_2s_preview_begel');
              pick('bp_2s_pick_beroepen_client','bp_2s_beroepen_logo_client_url','bp_2s_preview_beroepen_client');
              pick('bp_2s_pick_beroepen_begel','bp_2s_beroepen_logo_begeleider_url','bp_2s_preview_beroepen_begel');
              pick('bp_pick_beroepen_layout_bg','bp_beroepen_pdf_layout_bg_url','bp_preview_beroepen_layout_bg');
              clear('bp_2s_clear_client','bp_2s_logo_client_url','bp_2s_preview_client');
              clear('bp_2s_clear_begel','bp_2s_logo_begeleider_url','bp_2s_preview_begel');
              clear('bp_2s_clear_beroepen_client','bp_2s_beroepen_logo_client_url','bp_2s_preview_beroepen_client');
              clear('bp_2s_clear_beroepen_begel','bp_2s_beroepen_logo_begeleider_url','bp_2s_preview_beroepen_begel');
              clear('bp_clear_beroepen_layout_bg','bp_beroepen_pdf_layout_bg_url','bp_preview_beroepen_layout_bg');

              (function initPdfEditor(){
                var hidden = document.getElementById('bp_beroepen_pdf_layout_map');
                var canvas = document.getElementById('bp-pdf-canvas');
                var layerList = document.getElementById('bp-pdf-layers');
                var bgInput = document.getElementById('bp_beroepen_pdf_layout_bg_url');
                if (!hidden || !canvas || !layerList) return;

                var map = {};
                try { map = JSON.parse(hidden.value || '{}') || {}; } catch(e){ map = {}; }
                if (!Array.isArray(map.elements)) map.elements = [];
                if (typeof map.headerTopMm !== 'number') map.headerTopMm = 0;
                if (typeof map.tableTopMm !== 'number') map.tableTopMm = 0;
                if (typeof map.footerTopMm !== 'number') map.footerTopMm = 0;
                if (typeof map.leftMm !== 'number') map.leftMm = 0;
                if (typeof map.rightMm !== 'number') map.rightMm = 0;

                var defaults = {
                  logo: 'Logo',
                  org: 'Organisatienaam',
                  title: 'Documenttitel',
                  client: 'Clientnaam',
                  date: 'Exportdatum',
                  table: 'Tabel',
                  signature: 'Handtekening'
                };

                var headerTop = document.getElementById('bp-map-header-top');
                var tableTop = document.getElementById('bp-map-table-top');
                var footerTop = document.getElementById('bp-map-footer-top');
                var leftMm = document.getElementById('bp-map-left');
                var rightMm = document.getElementById('bp-map-right');
                if (headerTop) headerTop.value = String(map.headerTopMm || 0);
                if (tableTop) tableTop.value = String(map.tableTopMm || 0);
                if (footerTop) footerTop.value = String(map.footerTopMm || 0);
                if (leftMm) leftMm.value = String(map.leftMm || 0);
                if (rightMm) rightMm.value = String(map.rightMm || 0);

                function saveMap(){
                  map.headerTopMm = Number(headerTop && headerTop.value || 0) || 0;
                  map.tableTopMm = Number(tableTop && tableTop.value || 0) || 0;
                  map.footerTopMm = Number(footerTop && footerTop.value || 0) || 0;
                  map.leftMm = Number(leftMm && leftMm.value || 0) || 0;
                  map.rightMm = Number(rightMm && rightMm.value || 0) || 0;
                  hidden.value = JSON.stringify(map);
                }

                function refreshBg(){
                  var bg = bgInput && bgInput.value ? String(bgInput.value) : '';
                  canvas.style.backgroundImage = bg ? ('url(\"' + bg.replace(/"/g, '\\"') + '\")') : 'none';
                }

                function renderLayers(){
                  canvas.querySelectorAll('.bp-pdf-layer').forEach(function(n){ n.remove(); });
                  layerList.innerHTML = '';
                  map.elements.forEach(function(el, idx){
                    if (typeof el.x !== 'number') el.x = 8;
                    if (typeof el.y !== 'number') el.y = 8;
                    if (typeof el.w !== 'number') el.w = 24;
                    if (typeof el.h !== 'number') el.h = 7;
                    var node = document.createElement('div');
                    node.className = 'bp-pdf-layer';
                    node.dataset.idx = String(idx);
                    node.style.cssText = 'position:absolute;left:'+el.x+'%;top:'+el.y+'%;width:'+el.w+'%;height:'+el.h+'%;border:1px dashed #0b56c6;background:rgba(11,86,198,.08);border-radius:6px;padding:2px 6px;font-size:11px;color:#0f2f67;cursor:move;display:flex;align-items:center;';
                    node.textContent = defaults[el.type] || el.type || 'Laag';
                    canvas.appendChild(node);

                    var row = document.createElement('div');
                    row.style.cssText = 'display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;border:1px solid #e2e8f0;border-radius:8px;padding:6px;margin-bottom:6px;';
                    row.innerHTML = '<div><strong style="font-size:12px;">'+(defaults[el.type] || el.type || 'Laag')+'</strong><div style="font-size:11px;color:#64748b;">x '+el.x.toFixed(1)+'% · y '+el.y.toFixed(1)+'%</div></div><button type="button" class="button-link-delete">verwijder</button>';
                    row.querySelector('button').addEventListener('click', function(){
                      map.elements.splice(idx, 1);
                      saveMap();
                      renderLayers();
                    });
                    layerList.appendChild(row);
                  });

                  saveMap();
                }

                function clamp(v, min, max){ return Math.max(min, Math.min(max, v)); }
                function bindDrag(){
                  var drag = null;
                  canvas.querySelectorAll('.bp-pdf-layer').forEach(function(node){
                    node.addEventListener('pointerdown', function(ev){
                      var idx = Number(node.dataset.idx || -1);
                      if (idx < 0 || !map.elements[idx]) return;
                      node.setPointerCapture(ev.pointerId);
                      drag = { idx: idx, ox: ev.clientX, oy: ev.clientY };
                    });
                    node.addEventListener('pointermove', function(ev){
                      if (!drag) return;
                      var el = map.elements[drag.idx];
                      if (!el) return;
                      var rect = canvas.getBoundingClientRect();
                      var dx = ((ev.clientX - drag.ox) / rect.width) * 100;
                      var dy = ((ev.clientY - drag.oy) / rect.height) * 100;
                      drag.ox = ev.clientX; drag.oy = ev.clientY;
                      el.x = clamp((el.x || 0) + dx, 0, 100 - (el.w || 20));
                      el.y = clamp((el.y || 0) + dy, 0, 100 - (el.h || 7));
                      node.style.left = el.x + '%';
                      node.style.top = el.y + '%';
                      saveMap();
                    });
                    node.addEventListener('pointerup', function(){ drag = null; renderLayers(); });
                  });
                }

                document.querySelectorAll('[data-add-layer]').forEach(function(btn){
                  btn.addEventListener('click', function(){
                    var type = btn.getAttribute('data-add-layer') || 'custom';
                    map.elements.push({ type:type, x:8, y:8 + (map.elements.length * 8 % 70), w:26, h:7 });
                    saveMap();
                    renderLayers();
                    bindDrag();
                  });
                });

                [headerTop,tableTop,footerTop,leftMm,rightMm].forEach(function(inp){
                  if (!inp) return;
                  inp.addEventListener('input', saveMap);
                });
                if (bgInput) {
                  bgInput.addEventListener('input', refreshBg);
                  bgInput.addEventListener('change', refreshBg);
                }

                var previewBtn = document.getElementById('bp-pdf-preview-btn');
                var pdfTypeEl = document.getElementById('bp_pdf_type');
                if (pdfTypeEl) {
                  pdfTypeEl.addEventListener('change', function(){
                    var u = new URL(window.location.href);
                    u.searchParams.set('tab', 'layout');
                    u.searchParams.set('pdf', pdfTypeEl.value || 'beroepen_client');
                    window.location.href = u.toString();
                  });
                }
                if (previewBtn) {
                  previewBtn.addEventListener('click', function(){
                    saveMap();
                    var lijnInput = document.querySelector('input[name="bp_2s_lijn_kleur"]');
                    var lijn = (lijnInput && lijnInput.value) ? lijnInput.value : '#0047AB';
                    var logoHInput = document.querySelector('input[name="bp_2s_logo_height"]');
                    var logoH = Number(logoHInput && logoHInput.value || 20) || 20;
                    var showSignClient = !!document.querySelector('input[name="bp_beroepen_pdf_sign_client"]:checked');
                    var showSignBegeleider = !!document.querySelector('input[name="bp_beroepen_pdf_sign_begeleider"]:checked');
                    var logoClient = document.getElementById('bp_2s_beroepen_logo_client_url');
                    var logoBegel = document.getElementById('bp_2s_beroepen_logo_begeleider_url');
                    var logo2sClient = document.getElementById('bp_2s_logo_client_url');
                    var logo2sBegel = document.getElementById('bp_2s_logo_begeleider_url');
                    var pdfType = (pdfTypeEl && pdfTypeEl.value) ? String(pdfTypeEl.value) : 'beroepen_client';
                    var bg = bgInput && bgInput.value ? String(bgInput.value) : '';
                    var org = <?php echo wp_json_encode(function_exists('bp_core_get_org_name') ? bp_core_get_org_name('Beroepen Portaal') : get_bloginfo('name')); ?>;
                    var today = new Date();
                    var d = String(today.getDate()).padStart(2,'0') + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + today.getFullYear();

                    function signBlock(clientName, begeleiderName){
                      var html = '<div class="bp-print-signatures">';
                      if (showSignClient) {
                        html += '<div class="bp-print-signature-box"><div class="bp-print-sign-label">Client handtekening</div><div class="bp-print-sign-line"></div><div class="bp-print-sign-meta">'+clientName+' · '+d+'</div></div>';
                      }
                      if (showSignBegeleider) {
                        html += '<div class="bp-print-signature-box"><div class="bp-print-sign-label">Begeleider handtekening</div><div class="bp-print-sign-line"></div><div class="bp-print-sign-meta">'+begeleiderName+' · '+d+'</div></div>';
                      }
                      html += '</div>';
                      return html;
                    }

                    var css = `
                      body{margin:0;background:#eef2f7;font-family:Inter,Arial,sans-serif;color:#0f172a}
                      .pv-wrap{max-width:1060px;margin:0 auto;padding:18px}
                      .pv-card{background:#fff;border:1px solid #d7deea;border-radius:12px;padding:14px;margin-bottom:14px}
                      .pv-title{font-weight:800;color:#0f2f67;margin:0 0 10px}
                      .bp-page-body-td{background:${bg ? `url('${bg.replace(/'/g, "\\'")}') center/cover no-repeat` : '#fff'};}
                      .bp-print-headrow{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;border-bottom:2px solid ${lijn};padding-bottom:6px;margin-top:${Number(map.headerTopMm||0)}mm;margin-bottom:10px}
                      .bp-print-brand{display:flex;align-items:flex-start;gap:10px}
                      .bp-print-logo{max-width:200px;object-fit:contain;display:block;max-height:${logoH}px}
                      .bp-print-org{font-size:13px;font-weight:800;color:${lijn};line-height:1.1}
                      .bp-print-sub{font-size:8.5px;color:#334155;text-transform:uppercase;letter-spacing:.08em}
                      .bp-print-right{text-align:right;display:flex;flex-direction:column;align-items:flex-end}
                      .bp-print-name{font-size:10px;font-weight:600;color:#0f172a}
                      .bp-print-date{font-size:9px;color:#334155}
                      .bp-print-table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:${Number(map.tableTopMm||0)}mm}
                      .bp-print-table th,.bp-print-table td{border-bottom:1px solid #cbd5e1;padding:5px 7px;vertical-align:top;text-align:left;font-size:10px;color:#0f172a;word-break:break-word;line-height:1.4}
                      .bp-print-table th{font-size:8.6px;text-transform:uppercase;color:#1e293b;letter-spacing:.04em}
                      .bp-print-footrow{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:calc(8px + ${Number(map.footerTopMm||0)}mm);padding-top:6px;border-top:1.5px solid ${lijn};font-size:8.5px;color:#475569;text-transform:uppercase;letter-spacing:.04em}
                      .bp-print-signatures{margin-top:6px;display:grid;grid-template-columns:1fr 1fr;gap:10px}
                      .bp-print-signature-box{border:1px solid #cbd5e1;border-radius:4px;padding:6px;min-height:44px}
                      .bp-print-sign-label{font-size:8px;text-transform:uppercase;color:#64748b;margin-bottom:6px;letter-spacing:.06em}
                      .bp-print-sign-line{border-bottom:1px solid #475569;height:16px}
                      .bp-print-sign-meta{margin-top:4px;font-size:8px;color:#334155}
                    `;

                    var useClientLogo = (pdfType === 'logboek_client') ? (logo2sClient && logo2sClient.value) : (logoClient && logoClient.value);
                    var useBegelLogo = (pdfType === 'logboek_begeleider') ? (logo2sBegel && logo2sBegel.value) : (logoBegel && logoBegel.value);
                    var clientSub = (pdfType.indexOf('logboek_') === 0) ? '2E SPOOR LOGBOEK' : 'BEROEPENOVERZICHT - VIND IK LEUK';
                    var begelSub = (pdfType.indexOf('logboek_') === 0) ? 'BEGELEIDER LOGBOEK' : 'BEGELEIDER DOSSIER BEROEPEN';
                    var clientLogo = useClientLogo ? '<img src="'+useClientLogo+'" class="bp-print-logo" alt="">' : '<div><div class="bp-print-org">'+org+'</div><div class="bp-print-sub">'+clientSub+'</div></div>';
                    var begelLogo = useBegelLogo ? '<img src="'+useBegelLogo+'" class="bp-print-logo" alt="">' : '<div><div class="bp-print-org">'+org+'</div><div class="bp-print-sub">'+begelSub+'</div></div>';

                    var html = `
                      <div class="pv-wrap">
                        <div class="pv-card">
                          <h3 class="pv-title">Client PDF preview</h3>
                          <div class="bp-print-headrow">
                            <div class="bp-print-brand">${clientLogo}</div>
                            <div class="bp-print-right"><div class="bp-print-name">Voorbeeld cliënt</div><div class="bp-print-date">Exportdatum: ${d}</div></div>
                          </div>
                          <div class="bp-page-body-td">
                            <table class="bp-print-table">
                              <thead><tr><th>Beroep</th><th>Sector</th><th>Niveau</th><th>Doelgroep</th><th>Notitie</th></tr></thead>
                              <tbody>
                                <tr><td>Tekenaar bouwkunde</td><td>Bouw</td><td>MBO</td><td>Ja</td><td>Voorbeeld notitie cliënt</td></tr>
                                <tr><td>CNC-machinebediener</td><td>Industrie</td><td>MBO</td><td>Nee</td><td>Interesse in techniek</td></tr>
                              </tbody>
                            </table>
                          </div>
                          <div class="bp-print-footrow"><span>${org}</span><span>Beroepenoverzicht PDF</span></div>
                          ${signBlock('Voorbeeld cliënt','Voorbeeld begeleider')}
                        </div>

                        <div class="pv-card">
                          <h3 class="pv-title">Begeleider PDF preview</h3>
                          <div class="bp-print-headrow">
                            <div class="bp-print-brand">${begelLogo}</div>
                            <div class="bp-print-right"><div class="bp-print-name">Client: Voorbeeld cliënt</div><div class="bp-print-date">Exportdatum: ${d}</div></div>
                          </div>
                          <div class="bp-page-body-td">
                            <table class="bp-print-table">
                              <thead><tr><th>Beroep</th><th>Sector</th><th>Niveau</th><th>LKS %</th><th>Advies</th><th>Vervolgstappen</th></tr></thead>
                              <tbody>
                                <tr><td>Tekenaar bouwkunde</td><td>Bouw</td><td>MBO</td><td>70</td><td>Passend profiel</td><td>Meeloopdag plannen</td></tr>
                                <tr><td>CNC-machinebediener</td><td>Industrie</td><td>MBO</td><td>65</td><td>Goed potentieel</td><td>Praktijktest + scholing</td></tr>
                              </tbody>
                            </table>
                          </div>
                          <div class="bp-print-footrow"><span>${org}</span><span>Begeleider dossier beroepen</span></div>
                          ${signBlock('Voorbeeld cliënt','<?php echo esc_js(wp_get_current_user()->display_name); ?>')}
                        </div>
                      </div>
                    `;

                    var w = window.open('', 'bp_pdf_preview', 'width=1180,height=900');
                    if (!w) return;
                    w.document.open();
                    w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>PDF preview</title><style>'+css+'</style></head><body>'+html+'</body></html>');
                    w.document.close();
                    w.focus();
                  });
                }

                refreshBg();
                renderLayers();
                bindDrag();
              })();
            })();
            </script>
            <?php
        }
    );
});

// Activatiehook staat bovenaan (inclusief Core dependency check).
