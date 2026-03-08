<?php
/**
 * Plugin Name:       Beroepen Portaal Add-on - Sollicitatiebrieven
 * Plugin URI:        https://beroepen-portaal.nl
 * Description:       Add-on voor het schrijven en opslaan van sollicitatiebrieven, inclusief veilige upload en documenten-koppeling.
 * Version:           1.2.0
 * Author:            Ruud Cornelisse
 * Text Domain:       bp-addon-sollicitatiebrieven
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

defined('ABSPATH') || exit;

define('BP_SB_VERSION', '1.2.0');
define('BP_SB_DIR', plugin_dir_path(__FILE__));
define('BP_SB_URL', plugin_dir_url(__FILE__));

function bp_sb_has_core(): bool {
    return class_exists('BP_Core_Loader')
        || defined('BP_CORE_DIR')
        || function_exists('bp_core_addon_ensure_page');
}

function bp_sb_admin_notice_missing_core(): void {
    if (!current_user_can('activate_plugins')) return;
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('BP Addon Sollicitatiebrieven heeft Beroepen Portaal Core nodig. Activeer eerst de Core plugin.', 'bp-addon-sollicitatiebrieven');
    echo '</p></div>';
}

register_activation_hook(__FILE__, function () {
    if (!bp_sb_has_core()) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('BP Addon Sollicitatiebrieven vereist Beroepen Portaal Core. Activeer eerst de Core plugin.', 'bp-addon-sollicitatiebrieven'),
            esc_html__('Plugin dependency missing', 'bp-addon-sollicitatiebrieven'),
            ['back_link' => true]
        );
    }

    require_once BP_SB_DIR . 'includes/class-bp-sollicitatiebrieven.php';
    \BP_Sollicitatiebrieven\Sollicitatiebrieven::ensure_tables();
    \BP_Sollicitatiebrieven\Sollicitatiebrieven::ensure_caps();

    if (function_exists('bp_core_addon_ensure_page')) {
        bp_core_addon_ensure_page(
            'bp_addon_sollicitatiebrieven_page_id',
            'Sollicitatiebrieven',
            '<!-- wp:shortcode -->[bp_sollicitatiebrieven]<!-- /wp:shortcode -->',
            'portaal-sollicitatiebrieven'
        );
        bp_core_addon_ensure_page(
            'bp_addon_sollicitatie_templates_page_id',
            'Sollicitatiebrief templates',
            '<!-- wp:shortcode -->[bp_sollicitatie_templates]<!-- /wp:shortcode -->',
            'portaal-sollicitatie-templates'
        );
    }
});

if (!bp_sb_has_core()) {
    add_action('admin_notices', 'bp_sb_admin_notice_missing_core');
    return;
}

require_once BP_SB_DIR . 'includes/class-bp-sollicitatiebrieven.php';
require_once BP_SB_DIR . 'includes/class-bp-sollicitatiebrieven-core-hooks.php';

add_action('plugins_loaded', function () {
    \BP_Sollicitatiebrieven\Sollicitatiebrieven::init();
    \BP_Sollicitatiebrieven\CoreHooks::init();
});

add_action('init', function () {
    if (!function_exists('bp_core_addon_ensure_page')) return;
    if ((int) get_option('bp_addon_sollicitatiebrieven_page_id', 0) <= 0) {
        bp_core_addon_ensure_page(
            'bp_addon_sollicitatiebrieven_page_id',
            'Sollicitatiebrieven',
            '<!-- wp:shortcode -->[bp_sollicitatiebrieven]<!-- /wp:shortcode -->',
            'portaal-sollicitatiebrieven'
        );
    }
    if ((int) get_option('bp_addon_sollicitatie_templates_page_id', 0) <= 0) {
        bp_core_addon_ensure_page(
            'bp_addon_sollicitatie_templates_page_id',
            'Sollicitatiebrief templates',
            '<!-- wp:shortcode -->[bp_sollicitatie_templates]<!-- /wp:shortcode -->',
            'portaal-sollicitatie-templates'
        );
    }
}, 20);
