<?php
defined('ABSPATH') || exit;

$settings = BP_Core_Maintenance::get_settings();
$registered_addons = BP_Core_Maintenance::get_registered_addons();

if (!current_user_can('manage_options')) {
    wp_die('Geen toegang.');
}

if (isset($_POST['bp_core_save_maintenance'])) {
    check_admin_referer('bp_core_save_maintenance');

    $site_enabled = isset($_POST['site_enabled']) ? 1 : 0;

    $title = isset($_POST['title']) ? sanitize_text_field((string) $_POST['title']) : '';
    $message = isset($_POST['message']) ? wp_kses_post((string) $_POST['message']) : '';

    $allowed_roles = isset($_POST['allowed_roles']) && is_array($_POST['allowed_roles'])
        ? array_values(array_filter(array_map('sanitize_text_field', $_POST['allowed_roles'])))
        : ['administrator'];

    $whitelist = isset($_POST['whitelist']) && is_array($_POST['whitelist'])
        ? array_map('intval', $_POST['whitelist'])
        : [];

    // Per pagina onderhoud
    $pages = [];
    if (isset($_POST['page_maintenance']) && is_array($_POST['page_maintenance'])) {
        foreach ($_POST['page_maintenance'] as $page_id => $val) {
            $pages[(int) $page_id] = ($val === '1') ? 1 : 0;
        }
    }

    // Per addon onderhoud
    $addons = [];
    if (isset($_POST['addon_maintenance']) && is_array($_POST['addon_maintenance'])) {
        foreach ($_POST['addon_maintenance'] as $addon_id => $val) {
            $addons[sanitize_key((string) $addon_id)] = ($val === '1') ? 1 : 0;
        }
    }

    $settings = [
        'site_enabled'  => $site_enabled,
        'title'         => $title ?: BP_Core_Maintenance::defaults()['title'],
        'message'       => $message ?: BP_Core_Maintenance::defaults()['message'],
        'allowed_roles' => $allowed_roles ?: ['administrator'],
        'whitelist'     => $whitelist,
        'pages'         => $pages,
        'addons'        => $addons,
    ];

    BP_Core_Maintenance::update_settings($settings);

    echo '<div class="notice notice-success is-dismissible"><p>Onderhoud-instellingen opgeslagen.</p></div>';
}

global $wp_roles;
$all_roles = $wp_roles ? $wp_roles->roles : [];
$all_pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'asc']);
?>
<div class="wrap">
  <h1>Onderhoud</h1>
  <p>Hier kun je de site (of losse onderdelen) tijdelijk “op slot” zetten terwijl je eraan werkt.</p>

  <form method="post">
    <?php wp_nonce_field('bp_core_save_maintenance'); ?>

    <h2>1) Hele site</h2>
    <label>
      <input type="checkbox" name="site_enabled" <?php checked(!empty($settings['site_enabled'])); ?> />
      Zet onderhoud aan voor de hele site
    </label>

    <h2 style="margin-top:18px;">2) Tekst voor bezoekers</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="bp_core_title">Titel</label></th>
        <td><input class="regular-text" id="bp_core_title" name="title" value="<?php echo esc_attr((string) $settings['title']); ?>" /></td>
      </tr>
      <tr>
        <th scope="row"><label for="bp_core_message">Bericht</label></th>
        <td>
          <textarea class="large-text" rows="4" id="bp_core_message" name="message"><?php echo esc_textarea((string) $settings['message']); ?></textarea>
          <p class="description">Tip: houd het kort en vriendelijk.</p>
        </td>
      </tr>
    </table>

    <h2>3) Wie mag er wél door?</h2>
    <p>Admins staan standaard aan. Je kan ook andere rollen toestaan.</p>
    <fieldset>
      <?php foreach ($all_roles as $role_key => $role_info): ?>
        <label style="display:block;margin:4px 0;">
          <input type="checkbox" name="allowed_roles[]" value="<?php echo esc_attr($role_key); ?>"
            <?php checked(in_array($role_key, (array) $settings['allowed_roles'], true)); ?> />
          <?php echo esc_html($role_info['name']); ?>
        </label>
      <?php endforeach; ?>
    </fieldset>

    <h2 style="margin-top:18px;">4) Pagina’s die altijd bereikbaar blijven</h2>
    <p>Handig voor bijvoorbeeld Contact of Privacy. (Login blijft altijd bereikbaar.)</p>
    <fieldset>
      <?php foreach ($all_pages as $p): ?>
        <label style="display:block;margin:4px 0;">
          <input type="checkbox" name="whitelist[]" value="<?php echo (int) $p->ID; ?>"
            <?php checked(in_array((int) $p->ID, (array) $settings['whitelist'], true)); ?> />
          <?php echo esc_html($p->post_title ?: '(zonder titel)'); ?> (ID: <?php echo (int) $p->ID; ?>)
        </label>
      <?php endforeach; ?>
    </fieldset>

    <h2 style="margin-top:18px;">5) Onderhoud per pagina</h2>
    <p>Hier kun je losse pagina’s “in onderhoud” zetten, terwijl de rest normaal blijft werken.</p>
    <table class="widefat striped" style="max-width:980px;">
      <thead>
        <tr>
          <th>Pagina</th>
          <th style="width:160px;">Onderhoud</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($all_pages as $p): $pid = (int) $p->ID; ?>
          <tr>
            <td><?php echo esc_html($p->post_title ?: '(zonder titel)'); ?> <span style="color:#666;">(ID: <?php echo $pid; ?>)</span></td>
            <td>
              <label>
                <input type="hidden" name="page_maintenance[<?php echo $pid; ?>]" value="0" />
                <input type="checkbox" name="page_maintenance[<?php echo $pid; ?>]" value="1"
                  <?php checked(!empty($settings['pages'][$pid])); ?> />
                aan
              </label>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h2 style="margin-top:18px;">6) Onderhoud per add-on</h2>
    <p>Add-ons kunnen zichzelf hier laten zien. Nu is dit nog “basis”, maar dit maakt je Core alvast klaar voor later.</p>

    <?php if (empty($registered_addons)): ?>
      <p><em>Nog geen add-ons geregistreerd.</em></p>
    <?php else: ?>
      <table class="widefat striped" style="max-width:980px;">
        <thead>
          <tr>
            <th>Add-on</th>
            <th style="width:160px;">Onderhoud</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($registered_addons as $addon_id => $addon_name): $aid = sanitize_key((string) $addon_id); ?>
            <tr>
              <td><?php echo esc_html((string) $addon_name); ?> <span style="color:#666;">(<?php echo esc_html($aid); ?>)</span></td>
              <td>
                <label>
                  <input type="hidden" name="addon_maintenance[<?php echo esc_attr($aid); ?>]" value="0" />
                  <input type="checkbox" name="addon_maintenance[<?php echo esc_attr($aid); ?>]" value="1"
                    <?php checked(!empty($settings['addons'][$aid])); ?> />
                  aan
                </label>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <p style="margin-top:18px;">
      <button class="button button-primary" type="submit" name="bp_core_save_maintenance" value="1">Opslaan</button>
    </p>
  </form>

  <hr />
  <h2>Tip</h2>
  <p>Wil je het ontwerp van de onderhoudspagina aanpassen? Zet dan in je theme:</p>
  <pre style="background:#f6f7f7;padding:12px;border:1px solid #dcdcde;max-width:980px;overflow:auto;">jouw-theme/beroepen-portaal/maintenance.php</pre>
</div>
