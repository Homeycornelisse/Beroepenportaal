<?php
namespace BP_Sollicitatiebrieven;

defined('ABSPATH') || exit;

final class CoreHooks {
    public static function init(): void {
        add_filter('bp_core_addons_registry', [__CLASS__, 'register_addon']);
        add_filter('bp_core_tools_tiles', [__CLASS__, 'filter_tools_tiles'], 35, 2);
        add_filter('bp_core_nav_items', [__CLASS__, 'filter_nav_items'], 25, 3);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 130);

        add_filter('bp_docs_external_folders', [Sollicitatiebrieven::class, 'docs_external_folders'], 10, 2);
        add_filter('bp_docs_external_documents', [Sollicitatiebrieven::class, 'docs_external_documents'], 10, 2);
    }

    public static function register_addon($addons): array {
        if (!is_array($addons)) $addons = [];
        $addons['sollicitatiebrieven'] = [
            'label' => 'Sollicitatiebrieven',
            'cap' => 'kb_use_sollicitatiebrieven',
        ];
        return $addons;
    }

    public static function filter_tools_tiles($tiles, $user_id) {
        if (!is_array($tiles)) $tiles = [];
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !self::can_use_ui($user_id)) return $tiles;
        if (function_exists('bp_core_user_can_use_addon') && !bp_core_user_can_use_addon('sollicitatiebrieven', $user_id)) return $tiles;

        $url = Sollicitatiebrieven::page_url();
        if ($url === '') return $tiles;

        $tile = [
            'key' => 'sollicitatiebrieven',
            'title' => 'Sollicitatiebrieven',
            'subtitle' => 'Schrijven, uploaden en veilig bewaren',
            'url' => $url,
            'icon' => '✉️',
            'style' => 'info',
        ];

        $found = false;
        foreach ($tiles as $i => $it) {
            if (!is_array($it) || (($it['key'] ?? '') !== 'sollicitatiebrieven')) continue;
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
        if ($user_id <= 0 || !self::can_use_ui($user_id)) return $items;
        if (!in_array((string) $context, ['client', 'begeleider'], true)) return $items;
        if (function_exists('bp_core_user_can_use_addon') && !bp_core_user_can_use_addon('sollicitatiebrieven', $user_id)) return $items;

        $url = Sollicitatiebrieven::page_url();
        if ($url === '') return $items;

        $item = [
            'key' => 'sollicitatiebrieven',
            'label' => '✉️ Sollicitatiebrieven',
            'url' => $url,
            'active_slug' => 'portaal-sollicitatiebrieven',
        ];

        $found = false;
        foreach ($items as $i => $it) {
            if (!is_array($it) || (($it['key'] ?? '') !== 'sollicitatiebrieven')) continue;
            $items[$i] = array_merge($it, $item);
            $found = true;
            break;
        }
        if (!$found) $items[] = $item;

        if ($context === 'begeleider') {
            $tpl_url = self::templates_page_url();
            if ($tpl_url !== '') {
                $tpl_item = [
                    'key' => 'sollicitatie_templates',
                    'label' => '🧩 Brief templates',
                    'url' => $tpl_url,
                    'active_slug' => 'portaal-sollicitatie-templates',
                ];
                $tpl_found = false;
                foreach ($items as $i => $it) {
                    if (!is_array($it) || (($it['key'] ?? '') !== 'sollicitatie_templates')) continue;
                    $items[$i] = array_merge($it, $tpl_item);
                    $tpl_found = true;
                    break;
                }
                if (!$tpl_found) $items[] = $tpl_item;
            }
        }

        return $items;
    }

    public static function enqueue_assets(): void {
        if (!is_user_logged_in() || !is_singular()) return;

        $pid = get_queried_object_id();
        $page_id = (int) get_option('bp_addon_sollicitatiebrieven_page_id', 0);
        $tpl_page_id = (int) get_option('bp_addon_sollicitatie_templates_page_id', 0);
        if ($pid <= 0) return;
        if ($pid !== $page_id && $pid !== $tpl_page_id) return;

        wp_enqueue_style(
            'bp-addon-sollicitatiebrieven-css',
            BP_SB_URL . 'assets/css/sollicitatiebrieven.css',
            [],
            BP_SB_VERSION
        );
        wp_enqueue_script(
            'bp-addon-sollicitatiebrieven-js',
            BP_SB_URL . 'assets/js/sollicitatiebrieven.js',
            [],
            BP_SB_VERSION,
            true
        );

        wp_localize_script('bp-addon-sollicitatiebrieven-js', 'BPSBCfg', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bp_sb_nonce'),
        ]);

        $lock_enabled = true;
        if (function_exists('bp_core_is_page_behind_login_wall')) {
            $lock_enabled = bp_core_is_page_behind_login_wall((int) $pid);
        }
        if (!$lock_enabled) return;

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
                    'scopeSelector' => '#bp-sb-app',
                    'blurSelectors' => 'input,textarea,select,button,.bp-sb-list-item',
                    'redactSelectors' => '.bp-sb-editor,.bp-sb-list,.bp-sb-help',
                    'lockAfterMs' => 300000,
                ]) . ');',
                'before'
            );
            wp_add_inline_script(
                'bp-sensitive-guard',
                'window.BPSensitiveGuards = window.BPSensitiveGuards || []; window.BPSensitiveGuards.push(' . wp_json_encode([
                    'scopeSelector' => '#bp-sb-templates-app',
                    'blurSelectors' => 'input,textarea,select,button,.bp-sb-template-item',
                    'redactSelectors' => '.bp-sb-editor,.bp-sb-template-list',
                    'lockAfterMs' => 300000,
                ]) . ');',
                'before'
            );
        }
    }

    private static function can_use_ui(int $user_id): bool {
        $user = get_user_by('id', $user_id);
        if (!$user) return false;
        if (class_exists('BP_Core_Roles') && \BP_Core_Roles::is_client($user)) return true;
        if (class_exists('BP_Core_Roles') && \BP_Core_Roles::is_begeleider($user) && !\BP_Core_Roles::is_leidinggevende($user)) return true;
        return false;
    }

    private static function templates_page_url(): string {
        $pid = (int) get_option('bp_addon_sollicitatie_templates_page_id', 0);
        return $pid > 0 ? (string) get_permalink($pid) : '';
    }
}
