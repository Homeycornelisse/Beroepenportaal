<?php
/**
 * Plugin Name:       Beroepen Portaal Add-on - Berichten Inbox
 * Plugin URI:        https://beroepen-portaal.nl
 * Description:       WhatsApp-stijl berichteninbox als losse add-on voor Beroepen Portaal Core.
 * Version:           1.0.0
 * Author:            Ruud Cornelisse
 * Text Domain:       bp-addon-berichten
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

defined('ABSPATH') || exit;

define('BP_ADDON_BERICHTEN_VERSION', '1.0.0');
define('BP_ADDON_BERICHTEN_DIR', plugin_dir_path(__FILE__));
define('BP_ADDON_BERICHTEN_URL', plugin_dir_url(__FILE__));

function bp_addon_berichten_has_core(): bool {
    return class_exists('BP_Core_Loader')
        || defined('BP_CORE_DIR')
        || function_exists('bp_core_addon_ensure_page');
}

function bp_addon_berichten_admin_notice_missing_core(): void {
    if (!current_user_can('activate_plugins')) return;
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('BP Addon Berichten heeft Beroepen Portaal Core nodig. Activeer eerst de Core plugin.', 'bp-addon-berichten');
    echo '</p></div>';
}

register_activation_hook(__FILE__, function () {
    if (!bp_addon_berichten_has_core()) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('BP Addon Berichten vereist Beroepen Portaal Core. Activeer eerst de Core plugin.', 'bp-addon-berichten'),
            esc_html__('Plugin dependency missing', 'bp-addon-berichten'),
            ['back_link' => true]
        );
    }

    if (function_exists('bp_core_addon_ensure_page')) {
        bp_core_addon_ensure_page('bp_addon_berichten_page_id', 'Berichten Inbox', '<!-- wp:bp/portaal-page {"screen":"inbox"} /-->', 'portaal-inbox');
        bp_core_addon_ensure_page('bp_addon_berichten_contacts_page_id', 'Berichten Contacten', '<!-- wp:bp/portaal-page {"screen":"inbox"} /-->', 'portaal-berichten-contacten');
    }
});

if (!bp_addon_berichten_has_core()) {
    add_action('admin_notices', 'bp_addon_berichten_admin_notice_missing_core');
    return;
}

add_filter('bp_core_disable_builtin_berichten_actions', '__return_true');

require_once BP_ADDON_BERICHTEN_DIR . 'includes/class-bp-berichten.php';
require_once BP_ADDON_BERICHTEN_DIR . 'includes/class-bp-berichten-core-hooks.php';
require_once BP_ADDON_BERICHTEN_DIR . 'includes/class-bp-berichten-actions.php';

add_action('plugins_loaded', function () {
    if (class_exists('BP_Core_Berichten')) {
        BP_Core_Berichten::ensure_table();
    }
    \BP_Berichten\CoreHooks::init();
    \BP_Berichten\Actions::init();
});
