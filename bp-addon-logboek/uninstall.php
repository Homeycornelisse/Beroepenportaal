<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

// Verwijder alleen de auto-aangemaakte pagina optie.
$opt = 'bp_addon_2s_logboek_page_id';
$page_id = (int) get_option($opt, 0);

delete_option($opt);

// Pagina ook weggooien (als Core helper bestaat), anders laten staan.
if (function_exists('bp_core_addon_delete_page')) {
    bp_core_addon_delete_page($opt, true);
}
