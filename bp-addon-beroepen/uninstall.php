<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (function_exists('bp_core_addon_delete_page')) {
    bp_core_addon_delete_page('bp_addon_beroepen_page_id');
} else {
    delete_option('bp_addon_beroepen_page_id');
}
