<?php
defined('ABSPATH') || exit;

$saved = !empty($_GET['bp_saved']);
$user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
$selected_user = $user_id ? get_user_by('id', $user_id) : null;

$caps_labels = [
  BP_Core_Roles::CAP_VIEW_PORTAAL       => 'Portaal bekijken',
  BP_Core_Roles::CAP_VIEW_CLIENTS       => 'Cliënten bekijken',
  BP_Core_Roles::CAP_ADD_CLIENTS        => 'Cliënten aanmaken',
  BP_Core_Roles::CAP_EDIT_AANTEKENINGEN => 'Notities bewerken',
  BP_Core_Roles::CAP_MANAGE_TEAM        => 'Team beheren',
  BP_Core_Roles::CAP_USE_CV             => 'CV gebruiken',
];

function bp_core_admin_banner($title, $subtitle = '') {
  echo '<div style="background:#0047AB;border-radius:12px;padding:18px 20px;color:#fff;margin:18px 0 16px;">';
  echo '<div style="font-size:18px;font-weight:700;">' . esc_html($title) . '</div>';
  if ($subtitle) echo '<div style="opacity:.9;margin-top:4px;">' . esc_html($subtitle) . '</div>';
  echo '</div>';
}

$users = get_users([
  'orderby' => 'display_name',
  'order' => 'ASC',
  'fields' => ['ID','display_name','user_login'],
  'number' => 400,
]);

?>

<div class="wrap">
  <h1>Beroepenportaal</h1>
  <?php bp_core_admin_banner('Rechten per gebruiker', 'Kies een gebruiker en stel rol + extra rechten in.'); ?>

  <?php if ($saved): ?>
    <div class="notice notice-success"><p>Opgeslagen.</p></div>
  <?php endif; ?>

  <div style="display:flex;gap:16px;flex-wrap:wrap;">

    <div style="flex:1 1 320px;background:#fff;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:16px;max-width:420px;">
      <h2 style="margin-top:0;">Selecteer gebruiker</h2>
      <form method="get">
        <input type="hidden" name="page" value="bp-core-user-caps" />
        <select name="user_id" style="width:100%;max-width:100%;">
          <option value="0">— Kies —</option>
          <?php foreach ($users as $u): ?>
            <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($user_id, $u->ID); ?>>
              <?php echo esc_html($u->display_name . ' (' . $u->user_login . ')'); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p style="margin:12px 0 0;"><button class="button" type="submit">Open</button></p>
      </form>
      <p style="margin:12px 0 0;opacity:.8;">Tip: rol is de basis. Extra rechten zijn per gebruiker.</p>
    </div>

    <div style="flex:2 1 520px;background:#fff;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:16px;max-width:1100px;">
      <h2 style="margin-top:0;">Instellingen</h2>

      <?php if (!$selected_user): ?>
        <p>Kies links een gebruiker.</p>
      <?php else:
        $wp_user = new WP_User($selected_user->ID);
        $current_role = !empty($wp_user->roles[0]) ? $wp_user->roles[0] : '';
        $overrides = function_exists('bp_user_get_caps_overrides') ? bp_user_get_caps_overrides((int)$selected_user->ID) : [];
      ?>

        <p style="margin-top:0;">Gebruiker: <strong><?php echo esc_html($selected_user->display_name); ?></strong></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="bp_core_save_user_caps" />
          <?php wp_nonce_field('bp_core_save_user_caps'); ?>
          <input type="hidden" name="user_id" value="<?php echo esc_attr($selected_user->ID); ?>" />

          <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
            <div style="flex:1 1 260px;min-width:260px;">
              <label for="bp_user_role"><strong>Rol</strong></label>
              <select id="bp_user_role" name="user_role" style="width:100%;max-width:360px;">
                <option value="<?php echo esc_attr(BP_Core_Roles::ROLE_LEIDINGGEVENDE); ?>" <?php selected($current_role, BP_Core_Roles::ROLE_LEIDINGGEVENDE); ?>>Leidinggevende</option>
                <option value="<?php echo esc_attr(BP_Core_Roles::ROLE_BEGELEIDER); ?>" <?php selected($current_role, BP_Core_Roles::ROLE_BEGELEIDER); ?>>Begeleider</option>
                <option value="<?php echo esc_attr(BP_Core_Roles::ROLE_CLIENT); ?>" <?php selected($current_role, BP_Core_Roles::ROLE_CLIENT); ?>>Cliënt</option>
              </select>
              <p style="opacity:.75;margin-top:6px;">Dit is de basis. Rechten hieronder zijn aanvullend of juist uitschakelend.</p>
            </div>
          </div>

          <h3 style="margin:18px 0 8px;">Extra rechten (per gebruiker)</h3>
          <table class="widefat striped" style="border-radius:12px;overflow:hidden;">
            <thead>
              <tr>
                <th>Recht</th>
                <th>Instelling</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($caps_labels as $cap_key => $label):
              $val = isset($overrides[$cap_key]) ? $overrides[$cap_key] : 'inherit';
              if ($val !== 'allow' && $val !== 'deny') $val = 'inherit';
            ?>
              <tr>
                <td><strong><?php echo esc_html($label); ?></strong><br><span style="opacity:.75;"><code><?php echo esc_html($cap_key); ?></code></span></td>
                <td>
                  <select name="user_caps[<?php echo esc_attr($cap_key); ?>]">
                    <option value="inherit" <?php selected($val, 'inherit'); ?>>Overnemen</option>
                    <option value="allow" <?php selected($val, 'allow'); ?>>Aan</option>
                    <option value="deny" <?php selected($val, 'deny'); ?>>Uit</option>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <p style="margin:14px 0 0;">
            <button class="button button-primary" style="background:#0047AB;border-color:#0047AB;" type="submit">Opslaan</button>
          </p>
        </form>

      <?php endif; ?>
    </div>

  </div>
</div>
