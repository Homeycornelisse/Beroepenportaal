<?php
namespace BP_Documenten;

defined('ABSPATH') || exit;

final class CoreHooks {

    public static function init(): void {
        add_filter('bp_core_addons_registry', [__CLASS__, 'register_addon']);
        add_filter('bp_core_tools_tiles', [__CLASS__, 'filter_tools_tiles'], 30, 2);
        add_filter('bp_core_nav_items', [__CLASS__, 'filter_nav_items'], 20, 3);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 120);
    }

    public static function register_addon($addons): array {
        if (!is_array($addons)) $addons = [];
        $addons['documenten'] = [
            'label' => 'Documentenkluis',
            'cap'   => 'kb_use_documenten',
        ];
        return $addons;
    }

    public static function filter_tools_tiles($tiles, $user_id) {
        if (!is_array($tiles)) $tiles = [];
        $user_id = (int) $user_id;
        if ($user_id <= 0) return $tiles;
        if (function_exists('bp_core_user_can_use_addon') && !bp_core_user_can_use_addon('documenten', $user_id)) {
            return $tiles;
        }

        $url = Documenten::page_url();
        if ($url === '') return $tiles;

        $tile = [
            'key'      => 'documenten',
            'title'    => 'Documentenkluis',
            'subtitle' => 'Clientspecifieke dossiers',
            'url'      => $url,
            'icon'     => '🗂️',
            'style'    => 'info',
        ];

        $found = false;
        foreach ($tiles as $i => $it) {
            if (!is_array($it)) continue;
            if (($it['key'] ?? '') !== 'documenten') continue;
            $tiles[$i] = array_merge($it, $tile);
            $found = true;
            break;
        }
        if (!$found) $tiles[] = $tile;

        return $tiles;
    }

    public static function filter_nav_items($items, $context, $user_id) {
        if (!is_array($items)) $items = [];
        $user_id = (int) $user_id;
        if ($user_id <= 0) return $items;

        if (function_exists('bp_core_user_can_use_addon') && !bp_core_user_can_use_addon('documenten', $user_id)) {
            return $items;
        }

        $url = Documenten::page_url();
        if ($url === '') return $items;

        if (!in_array((string) $context, ['client', 'begeleider', 'leidinggevende', 'admin'], true)) {
            return $items;
        }

        $item = [
            'key' => 'documenten',
            'label' => '🗂️ Documenten',
            'url' => $url,
            'active_slug' => 'portaal-documenten',
        ];

        $found = false;
        foreach ($items as $i => $it) {
            if (!is_array($it)) continue;
            if (($it['key'] ?? '') !== 'documenten') continue;
            $items[$i] = array_merge($it, $item);
            $found = true;
            break;
        }
        if (!$found) $items[] = $item;

        return $items;
    }

    public static function enqueue_assets(): void {
        if (!is_user_logged_in() || !is_singular()) return;
        $pid = get_queried_object_id();
        if ($pid <= 0) return;

        $docs_page_id = (int) get_option('bp_addon_documenten_page_id', 0);
        if ($docs_page_id <= 0 || $pid !== $docs_page_id) return;

        wp_enqueue_style(
            'bp-addon-documenten-css',
            BP_DOCS_URL . 'assets/css/documenten.css',
            [],
            BP_DOCS_VERSION
        );

        wp_enqueue_script(
            'bp-addon-documenten-js',
            BP_DOCS_URL . 'assets/js/documenten-e2e.js',
            [],
            BP_DOCS_VERSION,
            true
        );

        wp_localize_script('bp-addon-documenten-js', 'BPDocsCfg', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bp_docs_nonce'),
            'userId' => get_current_user_id(),
        ]);

        $lock_enabled = true;
        if (function_exists('bp_core_is_page_behind_login_wall')) {
            $lock_enabled = bp_core_is_page_behind_login_wall((int) $pid);
        }
        if (!$lock_enabled) {
            return;
        }

        if (defined('BP_CORE_URL') && defined('BP_CORE_VERSION')) {
            wp_enqueue_script(
                'bp-sensitive-guard',
                BP_CORE_URL . 'assets/js/bp-sensitive-guard.js',
                [],
                BP_CORE_VERSION,
                true
            );

            wp_add_inline_script(
                'bp-sensitive-guard',
                'window.BPSensitiveGuards = window.BPSensitiveGuards || []; window.BPSensitiveGuards.push(' . wp_json_encode([
                    'scopeSelector' => '#bp-docs-app',
                    'blurSelectors' => 'input,textarea,select,button,.bp-docs-finder-item,.bp-docs-row',
                    'redactSelectors' => '.bp-docs-content,.bp-docs-sidebar,.bp-docs-toolbar',
                    'lockAfterMs' => 300000,
                ]) . ');',
                'before'
            );
        }
    }
}
