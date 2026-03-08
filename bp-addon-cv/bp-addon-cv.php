<?php
/**
 * Plugin Name:       Beroepen Portaal Addon - CV
 * Plugin URI:        https://beroepen-portaal.nl
 * Description:       CV uploaden, vervangen, verwijderen en veilig downloaden (cliënt + begeleider). Add-on voor Beroepen Portaal Core.
 * Version:           1.1.0-beta.7
 * Author:            Ruud Cornelisse
 * Text Domain:       bp-addon-cv
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

defined('ABSPATH') || exit;

define('BP_CV_VERSION', '1.1.0-beta.7');
define('BP_CV_DIR', plugin_dir_path(__FILE__));
define('BP_CV_URL', plugin_dir_url(__FILE__));

function bp_cv_has_core(): bool {
    return class_exists('BP_Core_Loader')
        || defined('BP_CORE_DIR')
        || function_exists('bp_core_addon_ensure_page');
}

function bp_cv_admin_notice_missing_core(): void {
    if (!current_user_can('activate_plugins')) return;
    echo '<div class="notice notice-error"><p>';
    echo esc_html__('BP Addon CV heeft Beroepen Portaal Core nodig. Activeer eerst de Core plugin.', 'bp-addon-cv');
    echo '</p></div>';
}

register_activation_hook(__FILE__, function () {
    if (!bp_cv_has_core()) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('BP Addon CV vereist Beroepen Portaal Core. Activeer eerst de Core plugin.', 'bp-addon-cv'),
            esc_html__('Plugin dependency missing', 'bp-addon-cv'),
            ['back_link' => true]
        );
    }

    require_once BP_CV_DIR . 'includes/class-bp-cv-util.php';
    \BP_CV\Util::ensure_upload_dir();
    \BP_CV\Util::ensure_kb_table();

    if (function_exists('bp_core_addon_ensure_page')) {
        bp_core_addon_ensure_page('bp_addon_cv_page_id', 'Mijn CV', '<!-- wp:bp/cv /-->', 'cv');
    }
});

if (!bp_cv_has_core()) {
    add_action('admin_notices', 'bp_cv_admin_notice_missing_core');
    return;
}

require_once BP_CV_DIR . 'includes/class-bp-cv-util.php';
require_once BP_CV_DIR . 'includes/class-bp-cv-shortcodes.php';
require_once BP_CV_DIR . 'includes/class-bp-cv-blocks.php';
require_once BP_CV_DIR . 'includes/class-bp-cv-download.php';
// Shell (full-page HTML) is niet meer nodig; Core levert navbar/footer via app-mode.
// require_once BP_CV_DIR . 'includes/class-bp-cv-shell.php';
require_once BP_CV_DIR . 'includes/class-bp-cv-core-hooks.php';

add_action('plugins_loaded', function () {
    \BP_CV\Blocks::init();
    \BP_CV\Download::init();
    \BP_CV\CoreHooks::init();
});
