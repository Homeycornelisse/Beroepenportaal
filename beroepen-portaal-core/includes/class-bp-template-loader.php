<?php
defined('ABSPATH') || exit;

class BP_Core_Template_Loader {

    /**
     * Render a template file (theme override first).
     *
     * Theme override path:
     * wp-content/themes/your-theme/beroepen-portaal/<slug>.php
     */
    public static function render(string $slug, array $vars = []): string {
        $slug = sanitize_key($slug);

        $theme_file = trailingslashit(get_stylesheet_directory()) . 'beroepen-portaal/' . $slug . '.php';
        $plugin_file = BP_CORE_DIR . 'templates/' . $slug . '.php';

        $file = file_exists($theme_file) ? $theme_file : $plugin_file;

        if (!file_exists($file)) {
            return '<div class="bp-notice">Template niet gevonden: ' . esc_html($slug) . '</div>';
        }

        ob_start();
        extract($vars, EXTR_SKIP);
        include $file;
        return (string) ob_get_clean();
    }
}
