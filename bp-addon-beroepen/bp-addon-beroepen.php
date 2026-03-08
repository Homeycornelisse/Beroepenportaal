<?php
/**
 * Plugin Name:       Beroepen Portaal Addon - Beroepen
 * Plugin URI:        https://beroepen-portaal.nl
 * Description:       Beroepen kaartenmodule met filters, selecties en notities. Addon voor Beroepen Portaal Core.
 * Version:           1.1.2
 * Author:            Ruud Cornelisse
 * Text Domain:       bp-addon-beroepen
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

defined('ABSPATH') || exit;

define('BP_BEROEPEN_VERSION', '1.1.2');
define('BP_BEROEPEN_DIR', plugin_dir_path(__FILE__));
define('BP_BEROEPEN_URL', plugin_dir_url(__FILE__));

function bp_beroepen_has_core(): bool {
    return class_exists('BP_Core_Loader')
        || defined('BP_CORE_DIR')
        || function_exists('bp_core_addon_ensure_page');
}

function bp_beroepen_admin_notice_missing_core(): void {
    if (!current_user_can('activate_plugins')) return;

    echo '<div class="notice notice-error"><p>';
    echo esc_html__('BP Addon Beroepen heeft Beroepen Portaal Core nodig. Activeer eerst de Core plugin.', 'bp-addon-beroepen');
    echo '</p></div>';
}

register_activation_hook(__FILE__, function () {
    if (!bp_beroepen_has_core()) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('BP Addon Beroepen vereist Beroepen Portaal Core. Activeer eerst de Core plugin.', 'bp-addon-beroepen'),
            esc_html__('Plugin dependency missing', 'bp-addon-beroepen'),
            ['back_link' => true]
        );
    }

    require_once BP_BEROEPEN_DIR . 'includes/class-bp-beroepen-util.php';
    \BP_Beroepen\Util::ensure_table();
    \BP_Beroepen\Util::ensure_caps();
    update_option('bp_addon_beroepen_version', BP_BEROEPEN_VERSION, false);

    if (function_exists('bp_core_addon_ensure_page')) {
        bp_core_addon_ensure_page(
            'bp_addon_beroepen_page_id',
            'Beroepenoverzicht',
            '<!-- wp:bp/beroepen /-->',
            'beroepenoverzicht'
        );
    }
});

if (!bp_beroepen_has_core()) {
    add_action('admin_notices', 'bp_beroepen_admin_notice_missing_core');
    return;
}

require_once BP_BEROEPEN_DIR . 'includes/class-bp-beroepen-util.php';
require_once BP_BEROEPEN_DIR . 'includes/class-bp-beroepen-rest.php';
require_once BP_BEROEPEN_DIR . 'includes/class-bp-beroepen-blocks.php';
require_once BP_BEROEPEN_DIR . 'includes/class-bp-beroepen-core-hooks.php';

add_action('plugins_loaded', function () {
    \BP_Beroepen\Blocks::init();
    \BP_Beroepen\Rest::init();
    \BP_Beroepen\CoreHooks::init();

    // Zorg dat bestaande installs automatisch schema/caps krijgen na updates.
    $installed = (string) get_option('bp_addon_beroepen_version', '');
    if ($installed !== BP_BEROEPEN_VERSION) {
        \BP_Beroepen\Util::ensure_table();
        \BP_Beroepen\Util::ensure_caps();
        update_option('bp_addon_beroepen_version', BP_BEROEPEN_VERSION, false);
    }
});
