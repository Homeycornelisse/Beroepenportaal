<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$folders = $wpdb->prefix . 'bp_docs_folders';
$items = $wpdb->prefix . 'bp_docs_items';

$wpdb->query("DROP TABLE IF EXISTS {$items}");
$wpdb->query("DROP TABLE IF EXISTS {$folders}");

if (function_exists('bp_core_addon_delete_page')) {
    bp_core_addon_delete_page('bp_addon_documenten_page_id', true);
}

delete_option('bp_addon_documenten_page_id');