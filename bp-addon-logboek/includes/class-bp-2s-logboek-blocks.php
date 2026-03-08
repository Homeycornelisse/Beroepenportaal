<?php
namespace BP_2S_Logboek;

defined('ABSPATH') || exit;

final class Blocks {

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_blocks']);
        // Run after theme enqueue to keep module styles predictable.
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_front_assets'], 100);
    }

    public static function register_blocks(): void {
        wp_register_script(
            'bp-2s-logboek-blocks',
            BP_2S_LOGBOEK_URL . 'assets/js/blocks.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n'],
            BP_2S_LOGBOEK_VERSION,
            true
        );

        register_block_type('bp/tweedespoor-logboek-client', [
            'api_version'     => 2,
            'editor_script'   => 'bp-2s-logboek-blocks',
            'render_callback' => [__CLASS__, 'render_client'],
            'attributes'      => [
                'title'       => ['type' => 'string', 'default' => '2e Spoor Logboek'],
                'introTitle'  => ['type' => 'string', 'default' => '2e Spoor Re-integratie Logboek'],
                'introText'   => ['type' => 'string', 'default' => 'Houd bij welke activiteiten je onderneemt voor je re-integratie. Exporteer als professionele PDF voor je begeleider of werkgever.'],
                'showStats'   => ['type' => 'boolean', 'default' => true],
                'showFilter'  => ['type' => 'boolean', 'default' => true],
                'showExport'  => ['type' => 'boolean', 'default' => true],
                'showPortaal' => ['type' => 'boolean', 'default' => true],
            ],
            'supports'        => [
                'align' => ['wide', 'full'],
            ],
        ]);

        register_block_type('bp/tweedespoor-logboek-begeleider', [
            'api_version'     => 2,
            'editor_script'   => 'bp-2s-logboek-blocks',
            'render_callback' => [__CLASS__, 'render_begeleider'],
            'attributes'      => [
                'title'      => ['type' => 'string', 'default' => 'Begeleider Logboek'],
                'showExport' => ['type' => 'boolean', 'default' => true],
            ],
            'supports'        => [
                'align' => ['wide', 'full'],
            ],
        ]);
    }

    public static function enqueue_front_assets(): void {
        if (!is_singular()) return;
        $post = get_post();
        if (!$post) return;

        // Veel sites gebruiken 1 pagina "Logboek" met alleen het client-blok.
        // In de oude app kreeg een begeleider daar een ander logboek (voortgang/notities).
        // Daarom: als de huidige gebruiker begeleider/leidinggevende is, laden we begeleider assets.
        if (has_block('bp/tweedespoor-logboek-client', $post)) {
            $mode = Util::is_begeleider_or_leidinggevende() ? 'begeleider' : 'client';
            Util::enqueue_front_assets($mode);
        }

        if (has_block('bp/tweedespoor-logboek-begeleider', $post)) {
            Util::enqueue_front_assets('begeleider');
        }
    }

    public static function render_client($attributes = [], string $content = '', $block = null): string {
        if (!Util::user_can_use()) {
            return '<div class="kb-card" style="padding:16px;">Je hebt geen toegang tot deze addon.</div>';
        }

        $wrapper = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['class' => 'bp-2s-logboek-block'])
            : 'class="bp-2s-logboek-block"';

        // Begeleiders/leidinggevenden krijgen op dezelfde pagina automatisch de begeleider-weergave.
        // Zo blijft het net als in de oude app en hoef je niet met 2 blokken te werken.
        if (Util::is_begeleider_or_leidinggevende()) {
            $attrs = wp_parse_args($attributes, [
                'title'      => 'Begeleider Logboek',
                'showExport' => true,
            ]);

            ob_start();
            include BP_2S_LOGBOEK_DIR . 'templates/logboek-begeleider.php';
            return '<div ' . $wrapper . '>' . (string) ob_get_clean() . '</div>';
        }

        $attrs = wp_parse_args($attributes, [
            'title'       => '2e Spoor Logboek',
            'introTitle'  => '2e Spoor Re-integratie Logboek',
            'introText'   => 'Houd bij welke activiteiten je onderneemt voor je re-integratie. Exporteer als professionele PDF voor je begeleider of werkgever.',
            'showStats'   => true,
            'showFilter'  => true,
            'showExport'  => true,
            'showPortaal' => true,
        ]);

        ob_start();
        include BP_2S_LOGBOEK_DIR . 'templates/logboek-client.php';
        return '<div ' . $wrapper . '>' . (string) ob_get_clean() . '</div>';
    }

    public static function render_begeleider($attributes = [], string $content = '', $block = null): string {
        if (!Util::user_can_use() || !Util::is_begeleider_or_leidinggevende()) {
            return '<div class="kb-card" style="padding:16px;">Je hebt geen toegang tot dit onderdeel.</div>';
        }

        $wrapper = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['class' => 'bp-2s-logboek-block'])
            : 'class="bp-2s-logboek-block"';

        $attrs = wp_parse_args($attributes, [
            'title'      => 'Begeleider Logboek',
            'showExport' => true,
        ]);

        ob_start();
        include BP_2S_LOGBOEK_DIR . 'templates/logboek-begeleider.php';
        return '<div ' . $wrapper . '>' . (string) ob_get_clean() . '</div>';
    }
}
