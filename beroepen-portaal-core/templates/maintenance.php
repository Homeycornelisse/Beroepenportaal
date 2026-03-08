<?php
defined('ABSPATH') || exit;
/**
 * Maintenance template
 * Variabelen:
 * - $bp_maintenance_title
 * - $bp_maintenance_message
 */
$title = isset($bp_maintenance_title) ? (string) $bp_maintenance_title : 'Even onderhoud';
$message = isset($bp_maintenance_message) ? (string) $bp_maintenance_message : 'We zijn bezig met onderhoud. Probeer later nog een keer.';
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo esc_html($title); ?></title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f6f7fb;color:#111;}
    .wrap{max-width:860px;margin:0 auto;padding:48px 20px;}
    .card{background:#fff;border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.08);border:1px solid rgba(0,0,0,.06);}
    h1{margin:0 0 10px 0;font-size:28px;}
    p{margin:0;font-size:16px;line-height:1.5;color:#333;}
    .small{margin-top:14px;color:#666;font-size:13px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1><?php echo esc_html($title); ?></h1>
      <p><?php echo wp_kses_post(nl2br(esc_html($message))); ?></p>
      <div class="small">HTTP 503 – Onderhoud</div>
    </div>
  </div>
</body>
</html>
