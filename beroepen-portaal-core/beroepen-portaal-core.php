<?php
/**
 * Plugin Name:       Beroepen Portaal Core
 * Plugin URI:        https://beroepen-portaal.nl
 * Description:       Core plugin (motor) voor Beroepen Portaal: rollen, rechten, dashboard, dataset, onderhoud, templates en beheer.
 * Version:           3.9.1-beta.1
 * Author:            Ruud Cornelisse
 * Text Domain:       beroepen-portaal-core
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

defined('ABSPATH') || exit;

// Veiligheid: voorkom dubbele load (bijv. per ongeluk 2 core-mappen)
if (defined('BP_CORE_VERSION') || class_exists('BP_Core_Loader', false)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Beroepen Portaal Core:</strong> De core lijkt dubbel geladen (meerdere core plugins/mappen). Verwijder of hernoem de oude core-map in wp-content/plugins.</p></div>';
    });
    return;
}

define('BP_CORE_VERSION', '3.3.0-beta.5');
define('BP_CORE_FILE', __FILE__);
define('BP_CORE_DIR', plugin_dir_path(__FILE__));
define('BP_CORE_URL', plugin_dir_url(__FILE__));

// Veiligheid: als bestanden ontbreken, crash niet maar toon melding
$bp_core_loader = BP_CORE_DIR . 'includes/class-bp-loader.php';
$bp_core_install = BP_CORE_DIR . 'includes/class-bp-install.php';
if (!file_exists($bp_core_loader) || !file_exists($bp_core_install)) {
    add_action('admin_notices', function () use ($bp_core_loader, $bp_core_install) {
        echo '<div class="notice notice-error"><p><strong>Beroepen Portaal Core:</strong> Bestanden ontbreken. Controleer of de plugin volledig is geüpload.</p></div>';
    });
    return;
}

require_once $bp_core_loader;
require_once $bp_core_install;

// Migratie bestaande installaties: tabellen + verplichte pagina's
add_action('plugins_loaded', function () {
    if (class_exists('BP_Core_Install')) {
        BP_Core_Install::ensure_pages();
    }
}, 20);

function bp_core() {
    return BP_Core_Loader::instance();
}

bp_core()->init();

// Installatie: tabellen + basisrollen (veilig opnieuw uit te voeren)
register_activation_hook(__FILE__, function () {
    // Sommige hosts/WP-configs tonen warnings tijdens activatie.
    // Dat kan leiden tot "onverwachte uitvoer" en header-problemen.
    // Daarom bufferen we alles en gooien we het weg.
    if (!defined('BP_CORE_ACTIVATING')) define('BP_CORE_ACTIVATING', true);
	    $buffered = false;
	    if (function_exists('ob_start')) {
	        $buffered = ob_start();
	    }

    try {
        if (class_exists('BP_Core_Install')) {
            BP_Core_Install::install();
        }
        if (class_exists('BP_Core_Roles')) {
            // Zet standaardrollen/caps neer (als startpunt)
            BP_Core_Roles::reset_defaults();
        }

        // Stap 3 (B): standaard pagina's automatisch aanmaken + koppelen
        if (function_exists('bp_core_ensure_default_pages')) {
            bp_core_ensure_default_pages(false);
        }
    } finally {
	        if ($buffered && function_exists('ob_get_level') && ob_get_level() > 0) {
	            // Gooi eventuele output weg om "headers already sent" te voorkomen.
	            @ob_end_clean();
        }
    }
});
