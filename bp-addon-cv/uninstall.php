<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$option_key = 'bp_addon_cv_page_id';

// 1) Verwijder alle geüploade CV's + data (tabel + bestanden)
global $wpdb;

$table = $wpdb->prefix . 'kb_cv';

// Probeer eerst alle paden op te halen (als de tabel bestaat)
$paths = [];
try {
    // Als tabel niet bestaat geeft dit meestal een DB error; we vangen dat op door leeg te blijven.
    $rows = $wpdb->get_results("SELECT pad FROM {$table}");
    if (is_array($rows)) {
        foreach ($rows as $r) {
            if (!empty($r->pad)) {
                $paths[] = (string) $r->pad;
            }
        }
    }
} catch (\Throwable $e) {
    // Niks
}

// Bestanden verwijderen
foreach ($paths as $p) {
    $p = trim($p);
    if ($p !== '' && file_exists($p)) {
        @unlink($p);
    }
}

// Tabel droppen (alle CV-data weg)
$wpdb->query("DROP TABLE IF EXISTS {$table}");

// Upload-map proberen op te ruimen (wp_uploads/kb-cv)
$uploads = wp_upload_dir();
$dir = trailingslashit($uploads['basedir']) . 'kb-cv/';
if (is_dir($dir)) {
    // Verwijder bekende bestanden die we zelf plaatsen
    $ht = $dir . '.htaccess';
    if (file_exists($ht)) {
        @unlink($ht);
    }
    // Verwijder eventuele rest-bestanden
    $files = glob($dir . '*');
    if (is_array($files)) {
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
    // Verwijder de map (alleen als hij leeg is)
    @rmdir($dir);
}

// Verwijder de pagina die door deze addon is aangemaakt.
if (function_exists('bp_core_addon_delete_page')) {
    bp_core_addon_delete_page($option_key, true);
} else {
    $page_id = (int) get_option($option_key, 0);
    if ($page_id > 0) {
        wp_delete_post($page_id, true);
    }
    delete_option($option_key);
}

// Eventuele addon opties opruimen
delete_option('bp_addon_cv_page_id');
