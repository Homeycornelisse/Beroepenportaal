<?php
defined('ABSPATH') || exit;

$updated = !empty($_GET['updated']) || !empty($_GET['saved']);

// Labels voor de rechten (caps)
$caps_labels = [
  BP_Core_Roles::CAP_VIEW_PORTAAL       => 'Portaal bekijken',
  BP_Core_Roles::CAP_VIEW_CLIENTS       => 'Cliënten bekijken',
  BP_Core_Roles::CAP_ADD_CLIENTS        => 'Cliënten aanmaken',
  BP_Core_Roles::CAP_EDIT_AANTEKENINGEN => 'Notities bewerken',
  BP_Core_Roles::CAP_MANAGE_TEAM        => 'Team beheren',
  BP_Core_Roles::CAP_USE_CV             => 'CV gebruiken',
];

$role_keys = [
  BP_Core_Roles::ROLE_LEIDINGGEVENDE => 'Leidinggevende',
  BP_Core_Roles::ROLE_BEGELEIDER     => 'Begeleider',
  BP_Core_Roles::ROLE_CLIENT         => 'Cliënt',
];

function bp_core_admin_banner($title, $subtitle = '') {
  echo '<div style="background:#0047AB;border-radius:12px;padding:18px 20px;color:#fff;margin:18px 0 16px;">';
  echo '<div style="font-size:18px;font-weight:700;">' . esc_html($title) . '</div>';
  if ($subtitle) echo '<div style="opacity:.9;margin-top:4px;">' . esc_html($subtitle) . '</div>';
  echo '</div>';
}

?>

<div class="wrap">
  <h1>Beroepenportaal</h1>
  <?php bp_core_admin_banner('Rollen & rechten', 'Hier stel je in wat elke rol mag doen.'); ?>

  <?php if ($updated): ?>
    <div class="notice notice-success"><p>Opgeslagen.</p></div>
  <?php endif; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="bp_core_save_role_caps" />
    <?php wp_nonce_field('bp_core_save_role_caps'); ?>

    <div style="background:#fff;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:16px;max-width:1100px;">
      <p style="margin-top:0;">Tip: je kunt per gebruiker nog extra rechten geven (of juist uitzetten) via <strong>Beroepenportaal → Rechten per gebruiker</strong>.</p>

      <table class="widefat striped" style="border-radius:12px;overflow:hidden;">
        <thead>
          <tr>
            <th>Recht</th>
            <?php foreach ($role_keys as $rk => $rl): ?>
              <th><?php echo esc_html($rl); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($caps_labels as $cap_key => $label): ?>
          <tr>
            <td>
              <strong><?php echo esc_html($label); ?></strong><br>
              <span style="opacity:.75;"><code><?php echo esc_html($cap_key); ?></code></span>
            </td>
            <?php foreach ($role_keys as $role_key => $role_label):
              $role = get_role($role_key);
              $checked = ($role && $role->has_cap($cap_key));
            ?>
              <td>
                <label>
                  <input type="checkbox" name="caps[<?php echo esc_attr($role_key); ?>][<?php echo esc_attr($cap_key); ?>]" value="1" <?php checked($checked); ?> />
                  Aan
                </label>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <p style="margin:14px 0 0;">
        <button class="button button-primary" style="background:#0047AB;border-color:#0047AB;" type="submit">Opslaan</button>
      </p>
    </div>
  </form>
</div>
