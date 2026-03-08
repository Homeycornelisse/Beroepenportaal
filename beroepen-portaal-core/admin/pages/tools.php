<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
  wp_die('Geen rechten.');
}

$links = [
  ['Dataset', admin_url('admin.php?page=bp-core-dataset')],
  ['Pagina-koppelingen', admin_url('admin.php?page=bp-core-pages')],
  ['Instellingen', admin_url('admin.php?page=bp-core-settings')],
  ['Add-ons', admin_url('admin.php?page=bp-core-addons')],
  ['Templates', admin_url('admin.php?page=bp-core-templates')],
  ['Onderhoud', admin_url('admin.php?page=bp-core-maintenance')],
];
?>
<div class="wrap">
  <h1>Beroepen Portaal Tools</h1>
  <p>Hier vind je de instellingen en technische onderdelen van het portaal.</p>

  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;max-width:980px;">
    <ul style="margin:0;padding-left:18px;">
      <?php foreach ($links as $l): ?>
        <li style="margin:6px 0;">
          <a href="<?php echo esc_url($l[1]); ?>"><?php echo esc_html($l[0]); ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
