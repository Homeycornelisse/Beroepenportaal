<?php
defined('ABSPATH') || exit;

/**
 * Onderhoudsmodus:
 * - Hele site
 * - Per pagina (WordPress pagina's)
 * - Per addon (addons kunnen zichzelf registreren)
 */
final class BP_Core_Maintenance {

    private static ?BP_Core_Maintenance $instance = null;

    public static function instance(): BP_Core_Maintenance {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init(): void {
        add_action('template_redirect', [$this, 'maybe_block_frontend'], 0);
    }

    public static function option_key(): string {
        return 'bp_core_maintenance';
    }

    public static function defaults(): array {
        return [
            'site_enabled'   => 0,
            'title'          => 'Even onderhoud',
            'message'        => 'We zijn bezig met onderhoud aan de site. Probeer het later nog een keer.',
            'allowed_roles'  => ['administrator'],
            'whitelist'      => [],  // array van page IDs
            'pages'          => [],  // [page_id => 0/1]
            'addons'         => [],  // [addon_id => 0/1]
        ];
    }

    public static function get_settings(): array {
        $saved = get_option(self::option_key(), []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return wp_parse_args($saved, self::defaults());
    }

    public static function update_settings(array $settings): void {
        update_option(self::option_key(), $settings, false);
    }

    public static function current_user_is_allowed(array $settings = null): bool {
        if ($settings === null) {
            $settings = self::get_settings();
        }

        // Niet ingelogd: alleen allowed als er expliciet een rol "guest" staat (doen we nu niet)
        if (!is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();
        $allowed_roles = isset($settings['allowed_roles']) && is_array($settings['allowed_roles'])
            ? $settings['allowed_roles']
            : ['administrator'];

        foreach ((array) $user->roles as $role) {
            if (in_array($role, $allowed_roles, true)) {
                return true;
            }
        }
        return false;
    }

    public static function is_page_in_maintenance(int $page_id, array $settings = null): bool {
        if ($settings === null) {
            $settings = self::get_settings();
        }
        $pages = isset($settings['pages']) && is_array($settings['pages']) ? $settings['pages'] : [];
        return !empty($pages[$page_id]);
    }

    public static function is_addon_in_maintenance(string $addon_id, array $settings = null): bool {
        if ($settings === null) {
            $settings = self::get_settings();
        }
        $addons = isset($settings['addons']) && is_array($settings['addons']) ? $settings['addons'] : [];
        return !empty($addons[$addon_id]);
    }

    /**
     * Addons kunnen zichzelf registreren zodat Core ze kan tonen.
     *
     * Nieuw (registry):
     *   Filter: bp_core_addons_registry
     *   Return: array(
     *     'cv' => [ 'label' => 'CV', 'cap' => 'kb_use_cv' ],
     *   )
     *
     * Legacy (oude core builds):
     *   Filter: bp_core_registered_addons
     *   Return: array( [ 'addon_id' => 'Naam', ... ] )
     */
    public static function get_registered_addons(): array {
        // Nieuwe registry (met label + cap)
        $addons = apply_filters('bp_core_addons_registry', []);
        if (!is_array($addons)) {
            $addons = [];
        }

        // Legacy registry (id => naam). We mappen dit naar de nieuwe structuur.
        $legacy = apply_filters('bp_core_registered_addons', []);
        if (is_array($legacy)) {
            foreach ($legacy as $id => $name) {
                $id = sanitize_key((string) $id);
                if ($id === '') {
                    continue;
                }
                if (!isset($addons[$id])) {
                    $addons[$id] = [
                        'label' => (string) $name,
                        'cap'   => '',
                    ];
                }
            }
        }

        return $addons;
    }

    public function maybe_block_frontend(): void {
        // Alleen front-end
        if (is_admin() || wp_doing_ajax() || wp_is_json_request()) {
            return;
        }

        // Login/cron altijd doorlaten
        $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';
        if (in_array($script, ['wp-login.php', 'wp-cron.php'], true)) {
            return;
        }

        $settings = self::get_settings();

        $site_enabled = !empty($settings['site_enabled']);
        $page_enabled = false;

        if (is_page()) {
            $page_id = (int) get_queried_object_id();
            // whitelist wint altijd
            $whitelist = isset($settings['whitelist']) && is_array($settings['whitelist']) ? $settings['whitelist'] : [];
            if (in_array($page_id, $whitelist, true)) {
                return;
            }
            $page_enabled = self::is_page_in_maintenance($page_id, $settings);
        }

        if (!$site_enabled && !$page_enabled) {
            return;
        }

        // Admin (of gekozen rollen) mag door
        if (self::current_user_is_allowed($settings)) {
            return;
        }

        // Toon onderhoudspagina
        status_header(503);
        header('Retry-After: 3600');

        $title = isset($settings['title']) ? (string) $settings['title'] : self::defaults()['title'];
        $message = isset($settings['message']) ? (string) $settings['message'] : self::defaults()['message'];

        // Probeer theme override, anders plugin template
        $template = $this->locate_template('maintenance.php');
        if ($template) {
            $bp_maintenance_title = $title;
            $bp_maintenance_message = $message;
            include $template;
        } else {
            echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
            echo '<title>' . esc_html($title) . '</title></head><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:40px;max-width:860px;margin:0 auto;">';
            echo '<h1>' . esc_html($title) . '</h1>';
            echo '<p>' . esc_html($message) . '</p>';
            echo '</body></html>';
        }
        exit;
    }

    /**
     * Template override:
     * jouw-theme/beroepen-portaal/maintenance.php
     */
    private function locate_template(string $file): string {
        $theme_path = trailingslashit(get_stylesheet_directory()) . 'beroepen-portaal/' . $file;
        if (file_exists($theme_path)) {
            return $theme_path;
        }
        $plugin_path = BP_CORE_DIR . 'templates/' . $file;
        if (file_exists($plugin_path)) {
            return $plugin_path;
        }
        return '';
    }
}

/**
 * Handige helper functies voor addons.
 */
function bp_core_is_addon_in_maintenance(string $addon_id): bool {
    return BP_Core_Maintenance::is_addon_in_maintenance($addon_id);
}

if (!function_exists('bp_core_get_registered_addons')) {
    function bp_core_get_registered_addons(): array {
        return BP_Core_Maintenance::get_registered_addons();
    }
}
