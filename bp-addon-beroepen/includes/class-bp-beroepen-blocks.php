<?php
namespace BP_Beroepen;

defined('ABSPATH') || exit;

final class Blocks {

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_blocks']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_front_assets'], 100);
    }

    public static function register_blocks(): void {
        wp_register_script(
            'bp-beroepen-blocks',
            BP_BEROEPEN_URL . 'assets/js/blocks.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n'],
            BP_BEROEPEN_VERSION,
            true
        );

        register_block_type('bp/beroepen', [
            'api_version'     => 2,
            'editor_script'   => 'bp-beroepen-blocks',
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

        if (!has_block('bp/beroepen', $post)) return;

        Util::enqueue_front_assets();
    }

    public static function render($attributes = [], string $content = '', $block = null): string {
        if (!Util::user_can_use()) {
            return '<div class="kb-card" style="padding:16px;">Je hebt geen toegang tot deze addon.</div>';
        }

        $wrapper = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['class' => 'bp-beroepen-block'])
            : 'class="bp-beroepen-block"';

        ob_start();
        if (Util::is_begeleider_or_leidinggevende()) {
            include BP_BEROEPEN_DIR . 'templates/beroepen-begeleider.php';
        } else {
            include BP_BEROEPEN_DIR . 'templates/beroepen-client.php';
        }

        return '<div ' . $wrapper . '>' . (string) ob_get_clean() . '</div>';
    }
}
