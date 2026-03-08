<?php
namespace BP_CV;

defined('ABSPATH') || exit;

/**
 * Gutenberg blok voor CV.
 * Geen shortcodes in gebruik.
 */
final class Blocks {

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_blocks']);
        // Run after theme enqueue to prevent theme CSS from overriding module UI.
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_front_assets'], 100);

        // Zorg dat de Core dit ook als portaalpagina ziet (app-mode + navbar/footer)
        add_filter('bp_core_portaal_blocks', [__CLASS__, 'add_portaal_block']);
    }

    public static function add_portaal_block($blocks) {
        if (!is_array($blocks)) $blocks = [];
        $blocks[] = 'bp/cv';
        return array_values(array_unique($blocks));
    }

    public static function register_blocks(): void {
        wp_register_script(
            'bp-cv-block',
            BP_CV_URL . 'assets/js/blocks.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n'],
            BP_CV_VERSION,
            true
        );

        register_block_type('bp/cv', [
            'api_version'     => 2,
            'editor_script'   => 'bp-cv-block',
            'render_callback' => [__CLASS__, 'render'],
            'supports'        => [
                'align' => ['wide', 'full'],
            ],
        ]);
    }

    public static function enqueue_front_assets(): void {
        if (!is_singular()) return;
        $post = get_post();
        if (!$post) return;
        if (!has_block('bp/cv', $post)) return;

        // Hergebruik de bestaande CV assets.
        Util::enqueue_assets();
    }

    public static function render($attributes = [], string $content = '', $block = null): string {
        // Render binnen block wrapper zodat FSE themes (zoals SaasLauncher) align/classes correct toepassen.
        $wrapper = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['class' => 'bp-cv-block'])
            : 'class="bp-cv-block"';

        return '<div ' . $wrapper . '>' . Shortcodes::render_cv(['shell' => '0']) . '</div>';
    }
}
