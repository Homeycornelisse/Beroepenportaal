<?php
/**
 * Plugin Name:       Beroepen Portaal Add-on - Documentenkluis
 * Plugin URI:        https://beroepen-portaal.nl
 * Description:       Client-specifieke documentenkluis met mapstructuur en end-to-end encryptie. Add-on voor Beroepen Portaal Core.
 * Version:           1.0.0
 * Author:            Ruud Cornelisse
 * Text Domain:       bp-addon-documenten
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

defined('ABSPATH') || exit;

define('BP_DOCS_VERSION', '1.0.1');
define('BP_DOCS_DIR', plugin_dir_path(__FILE__));
define('BP_DOCS_URL', plugin_dir_url(__FILE__));

function bp_docs_has_core(): bool {
    return class_exists('BP_Core_Loader')
        || defined('BP_CORE_DIR')
        || function_exists('bp_core_addon_ensure_page');
}

function bp_docs_admin_notice_missing_core(): void {
    if (!current_user_can('activate_plugins')) return;
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('BP Addon Documentenkluis heeft Beroepen Portaal Core nodig. Activeer eerst de Core plugin.', 'bp-addon-documenten');
    echo '</p></div>';
}

register_activation_hook(__FILE__, function () {
    if (!bp_docs_has_core()) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('BP Addon Documentenkluis vereist Beroepen Portaal Core. Activeer eerst de Core plugin.', 'bp-addon-documenten'),
            esc_html__('Plugin dependency missing', 'bp-addon-documenten'),
            ['back_link' => true]
        );
    }

    require_once BP_DOCS_DIR . 'includes/class-bp-documenten.php';
    \BP_Documenten\Documenten::ensure_tables();
    \BP_Documenten\Documenten::ensure_caps();

    if (function_exists('bp_core_addon_ensure_page')) {
        bp_core_addon_ensure_page('bp_addon_documenten_page_id', 'Documentenkluis', '<!-- wp:shortcode -->[bp_documenten]<!-- /wp:shortcode -->', 'portaal-documenten');
    }
});

if (!bp_docs_has_core()) {
    add_action('admin_notices', 'bp_docs_admin_notice_missing_core');
    return;
}

require_once BP_DOCS_DIR . 'includes/class-bp-documenten.php';
require_once BP_DOCS_DIR . 'includes/class-bp-documenten-core-hooks.php';

add_action('plugins_loaded', function () {
    \BP_Documenten\Documenten::init();
    \BP_Documenten\CoreHooks::init();
});
