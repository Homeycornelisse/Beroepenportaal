<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
  wp_die('Geen rechten.');
}

$users = class_exists('BP_Core_Addon_Access') ? BP_Core_Addon_Access::get_manageable_users() : [];

$selected = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($selected <= 0 && !empty($users)) {
  $selected = (int)$users[0]->ID;
}

$nonce = wp_create_nonce('bp_core_addon_access');

$embed = defined('BP_CORE_ADDON_ACCESS_EMBED') && BP_CORE_ADDON_ACCESS_EMBED;
?>

<?php if (!$embed): ?>
<div class="wrap">
  <h1>Add-ontoegang per gebruiker</h1>
<?php endif; ?>

<?php if ($embed): ?>
  <div style="margin-top:12px;">
<?php endif; ?>

  <p>Kies een gebruiker en zet per add-on: <strong>Rol</strong> (standaard), <strong>Toestaan</strong> of <strong>Blokkeren</strong>.</p>

  <?php if (empty($users)): ?>
    <div class="notice notice-warning"><p>Geen gebruikers gevonden om te beheren.</p></div>
  <?php else: ?>

    <div data-bp-addon-access
         data-ajax="<?= esc_url(admin_url('admin-ajax.php')) ?>"
         data-nonce="<?= esc_attr($nonce) ?>"
         style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;max-width:1100px;">

      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <label for="bp-addon-access-user"><strong>Gebruiker</strong></label>
        <select id="bp-addon-access-user" style="min-width:360px;">
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u->ID ?>" <?= selected($selected, (int)$u->ID, false) ?>>
              <?= esc_html($u->display_name . ' (' . $u->user_email . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button class="button button-primary" id="bp-addon-access-save">Opslaan</button>
      </div>

      <div id="bp-addon-access-msg" style="display:none;margin-top:12px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;"></div>

      <div id="bp-addon-access-table" style="margin-top:14px;"><p>Laden…</p></div>

    </div>

    <?php
      // Script laden
      wp_enqueue_script('bp-core-addon-access', BP_CORE_URL . 'assets/js/addon-access.js', [], BP_CORE_VERSION, true);
    ?>

  <?php endif; ?>
<?php if ($embed): ?>
  </div>
<?php endif; ?>

<?php if (!$embed): ?>
</div>
<?php endif; ?>
