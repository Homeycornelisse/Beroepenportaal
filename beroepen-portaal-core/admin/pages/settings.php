<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) {
    wp_die('Geen rechten.');
}
wp_enqueue_media();

$saved = !empty($_GET['bp_saved_security']);
$saved_brand = !empty($_GET['bp_saved_brand']);
$saved_loginwall = !empty($_GET['bp_saved_loginwall']);
$active_tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'security';
if (!in_array($active_tab, ['security', 'brand', 'loginwall'], true)) {
    $active_tab = 'security';
}
$required_roles = get_option('bp_core_2fa_required_roles', []);
$required_roles = is_array($required_roles) ? array_map('sanitize_key', $required_roles) : [];
$login_wall_pages = function_exists('bp_core_get_login_wall_pages') ? bp_core_get_login_wall_pages() : [];
$login_wall_pages = is_array($login_wall_pages) ? array_map('absint', $login_wall_pages) : [];
$org_name = function_exists('bp_core_get_org_name') ? bp_core_get_org_name('Beroepen Portaal') : 'Beroepen Portaal';
$org_logo = function_exists('bp_core_get_org_logo') ? bp_core_get_org_logo('') : '';
$site_icon = function_exists('bp_core_get_site_icon_url') ? bp_core_get_site_icon_url('') : '';
$brand = function_exists('bp_core_get_brand_colors') ? bp_core_get_brand_colors() : [
    'blue' => '#0047AB',
    'mid' => '#003A8C',
    'orange' => '#E85D00',
    'purple' => '#7C3AED',
    'bg' => '#F4F6FB',
    'border' => '#E2E8F0',
    'text' => '#1E293B',
    'link' => '#0047AB',
    'muted' => '#64748B',
];
$all_pages = get_pages([
    'sort_column' => 'post_title',
    'sort_order' => 'ASC',
]);
?>
<div class="wrap">
    <h1>Instellingen</h1>
    <h2 class="nav-tab-wrapper" style="margin-bottom:14px;">
        <a href="<?php echo esc_url(add_query_arg('tab', 'security')); ?>" class="nav-tab <?php echo $active_tab === 'security' ? 'nav-tab-active' : ''; ?>">Beveiliging</a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'brand')); ?>" class="nav-tab <?php echo $active_tab === 'brand' ? 'nav-tab-active' : ''; ?>">Huisstijl</a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'loginwall')); ?>" class="nav-tab <?php echo $active_tab === 'loginwall' ? 'nav-tab-active' : ''; ?>">Loginmuur</a>
    </h2>

    <?php if ($saved && $active_tab === 'security'): ?>
        <div class="notice notice-success is-dismissible"><p>Beveiligingsinstellingen opgeslagen.</p></div>
    <?php endif; ?>
    <?php if ($saved_brand && $active_tab === 'brand'): ?>
        <div class="notice notice-success is-dismissible"><p>Huisstijl-instellingen opgeslagen.</p></div>
    <?php endif; ?>
    <?php if ($saved_loginwall && $active_tab === 'loginwall'): ?>
        <div class="notice notice-success is-dismissible"><p>Loginmuur-instellingen opgeslagen.</p></div>
    <?php endif; ?>

    <?php if ($active_tab === 'security'): ?>
        <h2>Beveiliging</h2>
        <p>2FA is verplicht voor alle portaalrollen. Als mobiele 2FA uit staat, gebruikt login automatisch e-mailcode.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bp_core_save_security_settings'); ?>
            <input type="hidden" name="action" value="bp_core_save_security_settings">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Verplicht voor cliënten</th>
                    <td><strong>Altijd aan</strong></td>
                </tr>
                <tr>
                    <th scope="row">Ook verplicht voor</th>
                    <td>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="bp_2fa_roles[]" value="kb_begeleider" <?php checked(in_array('kb_begeleider', $required_roles, true)); ?>>
                            Begeleider
                        </label>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="bp_2fa_roles[]" value="kb_leidinggevende" <?php checked(in_array('kb_leidinggevende', $required_roles, true)); ?>>
                            Leidinggevende
                        </label>
                        <label style="display:block;">
                            <input type="checkbox" name="bp_2fa_roles[]" value="administrator" <?php checked(in_array('administrator', $required_roles, true)); ?>>
                            Administrator
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button('Opslaan'); ?>
        </form>
    <?php endif; ?>

    <?php if ($active_tab === 'brand'): ?>
        <h2>Huisstijl</h2>
        <p>Stel hier organisatiekleuren, logo en site icon in voor het portaal.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bp_core_save_brand_settings'); ?>
            <input type="hidden" name="action" value="bp_core_save_brand_settings">

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="bp_core_org_name">Organisatienaam</label></th>
                <td>
                    <input type="text" id="bp_core_org_name" name="bp_core_org_name" class="regular-text" value="<?php echo esc_attr($org_name); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bp_core_org_logo">Logo URL</label></th>
                <td>
                    <input type="url" id="bp_core_org_logo" name="bp_core_org_logo" class="regular-text" value="<?php echo esc_attr($org_logo); ?>" placeholder="https://...">
                    <button type="button" class="button" id="bp_core_pick_org_logo">Kies / upload</button>
                    <button type="button" class="button" id="bp_core_clear_org_logo">Leegmaken</button>
                    <p class="description">Gebruik een directe afbeeldings-URL (png/jpg/svg).</p>
                    <div style="margin-top:10px;display:flex;align-items:center;gap:10px;">
                        <div style="width:56px;height:56px;border:1px solid #d0d7e2;border-radius:10px;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                            <?php if ($org_logo !== ''): ?>
                                <img src="<?php echo esc_url($org_logo); ?>" alt="Logo voorbeeld" style="max-width:100%;max-height:100%;object-fit:contain;">
                            <?php else: ?>
                                <span style="font-size:11px;color:#64748b;font-weight:700;">LOGO</span>
                            <?php endif; ?>
                        </div>
                        <span class="description">Voorbeeld weergave van je organisatie-logo.</span>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bp_core_site_icon">Site icon URL</label></th>
                <td>
                    <input type="url" id="bp_core_site_icon" name="bp_core_site_icon" class="regular-text" value="<?php echo esc_attr($site_icon); ?>" placeholder="https://...">
                    <button type="button" class="button" id="bp_core_pick_site_icon">Kies / upload</button>
                    <button type="button" class="button" id="bp_core_clear_site_icon">Leegmaken</button>
                    <p class="description">Wordt gebruikt als favicon/app icon voor het portaal.</p>
                    <div style="margin-top:10px;display:flex;align-items:center;gap:10px;">
                        <div style="width:40px;height:40px;border:1px solid #d0d7e2;border-radius:8px;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                            <?php if ($site_icon !== ''): ?>
                                <img src="<?php echo esc_url($site_icon); ?>" alt="Site icon voorbeeld" style="max-width:100%;max-height:100%;object-fit:contain;">
                            <?php else: ?>
                                <span style="font-size:10px;color:#64748b;font-weight:700;">ICON</span>
                            <?php endif; ?>
                        </div>
                        <span class="description">Voorbeeld van favicon / app-icon.</span>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">Kleuren</th>
                <td>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:12px;max-width:760px;">
                        <label>Primair blauw<br><input type="color" name="bp_core_brand_colors[blue]" value="<?php echo esc_attr($brand['blue']); ?>"></label>
                        <label>Donker blauw<br><input type="color" name="bp_core_brand_colors[mid]" value="<?php echo esc_attr($brand['mid']); ?>"></label>
                        <label>Accent oranje<br><input type="color" name="bp_core_brand_colors[orange]" value="<?php echo esc_attr($brand['orange']); ?>"></label>
                        <label>Accent paars<br><input type="color" name="bp_core_brand_colors[purple]" value="<?php echo esc_attr($brand['purple']); ?>"></label>
                        <label>Achtergrond<br><input type="color" name="bp_core_brand_colors[bg]" value="<?php echo esc_attr($brand['bg']); ?>"></label>
                        <label>Randen<br><input type="color" name="bp_core_brand_colors[border]" value="<?php echo esc_attr($brand['border']); ?>"></label>
                        <label>Tekst<br><input type="color" name="bp_core_brand_colors[text]" value="<?php echo esc_attr($brand['text']); ?>"></label>
                        <label>Linkjes<br><input type="color" name="bp_core_brand_colors[link]" value="<?php echo esc_attr($brand['link'] ?? $brand['blue']); ?>"></label>
                        <label>Subtekst<br><input type="color" name="bp_core_brand_colors[muted]" value="<?php echo esc_attr($brand['muted']); ?>"></label>
                    </div>
                </td>
            </tr>
        </table>

        <?php submit_button('Huisstijl opslaan'); ?>
    </form>
    <?php endif; ?>

    <?php if ($active_tab === 'loginwall'): ?>
        <h2>Loginmuur</h2>
        <p>Alleen de gekozen pagina's staan achter de loginmuur. Inactiviteitsvergrendeling draait ook alleen op deze pagina's.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bp_core_save_login_wall_settings'); ?>
            <input type="hidden" name="action" value="bp_core_save_login_wall_settings">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Pagina's achter loginmuur</th>
                    <td>
                        <div style="max-height:360px;overflow:auto;border:1px solid #dcdcde;border-radius:8px;padding:10px;background:#fff;">
                            <?php if (empty($all_pages)): ?>
                                <p style="margin:0;">Geen pagina's gevonden.</p>
                            <?php else: ?>
                                <?php foreach ($all_pages as $p): ?>
                                    <?php $pid = (int) $p->ID; ?>
                                    <label style="display:block;margin-bottom:8px;">
                                        <input type="checkbox" name="bp_login_wall_pages[]" value="<?php echo $pid; ?>" <?php checked(in_array($pid, $login_wall_pages, true)); ?>>
                                        <?php echo esc_html($p->post_title ?: ('Pagina #' . $pid)); ?> <span style="color:#646970;">(ID: <?php echo $pid; ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>
            <?php submit_button('Loginmuur opslaan'); ?>
        </form>
    <?php endif; ?>
</div>
<script>
(function(){
  function bindPicker(pickId, clearId, inputId){
    var pick = document.getElementById(pickId);
    var clear = document.getElementById(clearId);
    var input = document.getElementById(inputId);
    if (pick && input) {
      pick.addEventListener('click', function(e){
        e.preventDefault();
        var frame = wp.media({
          title: 'Kies een afbeelding',
          button: { text: 'Gebruik deze afbeelding' },
          multiple: false
        });
        frame.on('select', function(){
          var att = frame.state().get('selection').first().toJSON();
          if (!att || !att.url) return;
          input.value = att.url;
        });
        frame.open();
      });
    }
    if (clear && input) {
      clear.addEventListener('click', function(e){
        e.preventDefault();
        input.value = '';
      });
    }
  }
  bindPicker('bp_core_pick_org_logo','bp_core_clear_org_logo','bp_core_org_logo');
  bindPicker('bp_core_pick_site_icon','bp_core_clear_site_icon','bp_core_site_icon');
})();
</script>
