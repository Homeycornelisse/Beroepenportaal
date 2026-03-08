<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
  wp_die('Geen rechten.');
}

$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overzicht';
$base_url = admin_url('admin.php?page=bp-core-addons');

$tabs = [
  'overzicht' => 'Overzicht',
  'toegang'   => 'Toegang per gebruiker',
];
?>
<div class="wrap">
  <h1>Add-ons</h1>

  <h2 class="nav-tab-wrapper">
    <?php foreach ($tabs as $key => $label): ?>
      <?php
        $url = add_query_arg(['tab' => $key], $base_url);
        $active = ($tab === $key) ? ' nav-tab-active' : '';
      ?>
      <a class="nav-tab<?php echo esc_attr($active); ?>" href="<?php echo esc_url($url); ?>">
        <?php echo esc_html($label); ?>
      </a>
    <?php endforeach; ?>
  </h2>

  <?php if ($tab === 'toegang'): ?>
    <?php
      // Embed de bestaande addon-access UI (zonder eigen wrap/h1)
      define('BP_CORE_ADDON_ACCESS_EMBED', true);
      include BP_CORE_DIR . 'admin/pages/addon-access.php';
    ?>
  <?php else: ?>
    <?php
      $addons = function_exists('bp_core_get_registered_addons') ? bp_core_get_registered_addons() : [];
    ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;max-width:1100px;">
      <p>Hier zie je welke add-ons zich bij de Core hebben aangemeld (registry).</p>

      <?php if (empty($addons)): ?>
        <div class="notice notice-warning" style="margin:12px 0 0;"><p>Er zijn nog geen add-ons gevonden.</p></div>
      <?php else: ?>
        <table class="widefat striped" style="margin-top:12px;">
          <thead>
            <tr>
              <th>Naam</th>
              <th>Capability</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($addons as $slug => $data): ?>
              <tr>
                <td><strong><?php echo esc_html($data['label'] ?? (string)$slug); ?></strong></td>
                <td><code><?php echo esc_html($data['cap'] ?? '-'); ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
