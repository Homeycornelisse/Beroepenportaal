<?php
defined('ABSPATH') || exit;

$theme_dir = get_stylesheet_directory();
$target_dir = trailingslashit($theme_dir) . 'beroepen-portaal/';

$templates = [];
$tpl_dir = BP_CORE_DIR . 'templates/';
if (is_dir($tpl_dir)) {
    foreach (glob($tpl_dir . '*.php') as $file) {
        $templates[] = basename($file);
    }
}

$did = isset($_GET['bp_done']) ? sanitize_text_field(wp_unslash($_GET['bp_done'])) : '';
?>
<div class="wrap">
  <h1>Templates (simpel aanpassen)</h1>
  <p>Wil je het uiterlijk aanpassen zonder in de plugin te werken? Dan kun je templates in je theme zetten. De Core gebruikt dan automatisch jouw versie.</p>

  <?php if ($did === '1'): ?>
    <div class="notice notice-success"><p>Klaar! Templates zijn gekopieerd naar: <code><?php echo esc_html($target_dir); ?></code></p></div>
  <?php elseif ($did === '0'): ?>
    <div class="notice notice-error"><p>Het kopiëren is niet gelukt. Controleer of de map schrijfbaar is.</p></div>
  <?php endif; ?>

  <h2>1 klik kopiëren</h2>
  <p>Druk op de knop. Daarna kun je de bestanden aanpassen in je theme map:</p>
  <p><code><?php echo esc_html($target_dir); ?></code></p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('bp_core_copy_templates'); ?>
    <input type="hidden" name="action" value="bp_core_copy_templates">
    <label style="display:block;margin:10px 0;">
      <input type="checkbox" name="overwrite" value="1">
      Bestaande bestanden overschrijven
    </label>
    <button class="button button-primary">Kopieer templates naar theme</button>
  </form>

  <h2>Welke templates?</h2>
  <ul>
    <?php foreach ($templates as $t): ?>
      <li><code><?php echo esc_html($t); ?></code></li>
    <?php endforeach; ?>
  </ul>

  <p><strong>Tip:</strong> Wil je testen of het werkt? Zet bovenaan in bijvoorbeeld <code>dashboard.php</code> een extra tekst. Zie je het terug op de pagina, dan is je override actief.</p>
</div>
