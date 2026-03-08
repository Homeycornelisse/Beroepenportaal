<?php
defined('ABSPATH') || exit;

$nonce = wp_create_nonce('bp_core_addon_access');

$users = class_exists('BP_Core_Addon_Access') ? BP_Core_Addon_Access::get_manageable_users() : [];

$selected = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($selected <= 0 && !empty($users)) {
  $selected = (int)$users[0]->ID;
}
?>

<div class="bp-wrap" style="max-width:1100px;margin:0 auto;">
  <div class="bp-card" style="padding:18px;">
    <h2 style="margin-top:0;">Add-onrechten per gebruiker</h2>
    <p style="margin-top:6px;opacity:.85;">Hier kies je per gebruiker welke add-ons wél of niet gebruikt mogen worden. Als je niets instelt, volgt het gewoon de rol.</p>

    <?php if (empty($users)): ?>
      <div class="bp-alert" style="background:#fff7ed;border:1px solid #fed7aa;padding:12px 14px;border-radius:12px;">
        Geen gebruikers gevonden om te beheren.
      </div>
    <?php else: ?>

      <div data-bp-addon-access
           data-ajax="<?= esc_url(admin_url('admin-ajax.php')) ?>"
           data-nonce="<?= esc_attr($nonce) ?>">

        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
          <label for="bp-addon-access-user"><strong>Gebruiker</strong></label>
          <select id="bp-addon-access-user" style="min-width:320px;max-width:100%;">
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u->ID ?>" <?= selected($selected, (int)$u->ID, false) ?>>
                <?= esc_html(function_exists('bp_core_user_label') ? bp_core_user_label($u) : (string)$u->display_name) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button class="bp-btn bp-btn-primary" id="bp-addon-access-save" type="button">Opslaan</button>
        </div>

        <div id="bp-addon-access-msg" style="display:none;margin-top:12px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;"></div>

        <div id="bp-addon-access-table" style="margin-top:14px;">
          <p>Laden…</p>
        </div>
      </div>

    <?php endif; ?>
  </div>
</div>
