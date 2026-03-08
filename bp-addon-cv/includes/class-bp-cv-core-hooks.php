<?php
namespace BP_CV;

defined('ABSPATH') || exit;

final class CoreHooks {

    public static function init(): void {
        // Hooks bestaan pas vanaf Core 2.0.0-alpha.12, maar filters mogen altijd gezet worden.
        add_filter('bp_core_dashboard_stats', [__CLASS__, 'filter_dashboard_stats'], 10, 2);
        add_filter('bp_core_tools_tiles', [__CLASS__, 'filter_tools_tiles'], 10, 2);
        add_filter('bp_core_nav_items', [__CLASS__, 'filter_nav_items'], 10, 3);
        add_filter('bp_core_addons_registry', [__CLASS__, 'register_addon']);
    }

    public static function register_addon($addons) {
        if (!is_array($addons)) $addons = [];
        $addons['cv'] = [
            'label' => 'CV',
            'cap'   => 'kb_use_cv',
        ];
        return $addons;
    }

    public static function filter_dashboard_stats($stats, $user_id) {
        if (!is_array($stats)) $stats = [];

        // Centrale check (rol + per-user override)
        if (function_exists('bp_core_user_can_use_addon')) {
            if (!bp_core_user_can_use_addon('cv', (int)$user_id)) {
                return $stats;
            }
        } elseif (function_exists('bp_core_user_can')) {
            if (!bp_core_user_can('kb_use_cv', (int)$user_id)) return $stats;
        }

        $has = Util::user_has_cv((int)$user_id);

        // Zoek bestaand item met key 'cv' (of 'cv_upload') en update, anders append.
        $found = false;
        foreach ($stats as $i => $it) {
            if (!is_array($it)) continue;
            $key = $it['key'] ?? '';
            if ($key === 'cv' || $key === 'cv_upload') {
                $stats[$i]['key']   = 'cv';
                $stats[$i]['label'] = 'CV geüpload';
                $stats[$i]['value'] = $has ? 'Ja' : 'Nee';
                $stats[$i]['icon']  = '📄';
                // Laat kleur leeg zodat Core/styling leidend blijft.
                $found = true;
                break;
            }
        }
        if (!$found) {
            $stats[] = [
                'key'   => 'cv',
                'label' => 'CV geüpload',
                'value' => $has ? 'Ja' : 'Nee',
                'icon'  => '📄',
            ];
        }

        return $stats;
    }

    public static function filter_tools_tiles($tiles, $user_id) {
        if (!is_array($tiles)) $tiles = [];

        if (function_exists('bp_core_user_can_use_addon')) {
            if (!bp_core_user_can_use_addon('cv', (int)$user_id)) return $tiles;
        } elseif (function_exists('bp_core_user_can')) {
            if (!bp_core_user_can('kb_use_cv', (int)$user_id)) return $tiles;
        }

        $has = Util::user_has_cv((int)$user_id);
        $url = Util::get_cv_page_url();

        $tile = [
            'key'      => 'cv',
            'title'    => 'Mijn CV',
            'subtitle' => $has ? 'CV aanwezig' : 'Nog geen CV',
            'url'      => $url ?: '#',
            'icon'     => '📄',
            'style'    => $has ? 'success' : 'warning',
        ];

        // Update bestaande tile als die er al staat.
        $found = false;
        foreach ($tiles as $i => $it) {
            if (!is_array($it)) continue;
            if (($it['key'] ?? '') === 'cv') {
                $tiles[$i] = array_merge($it, $tile);
                $found = true;
                break;
            }
        }
        if (!$found) $tiles[] = $tile;

        return $tiles;
    }

    public static function filter_nav_items($items, $context, $user_id) {
        if (!is_array($items)) $items = [];
        $context = (string)$context;

        if (function_exists('bp_core_user_can_use_addon')) {
            if (!bp_core_user_can_use_addon('cv', (int)$user_id)) return $items;
        } elseif (function_exists('bp_core_user_can')) {
            if (!bp_core_user_can('kb_use_cv', (int)$user_id)) return $items;
        }

        // Alleen voor client menu
        if ($context !== 'client') {
            return $items;
        }

        $cv_url = Util::get_cv_page_url();
        if (!$cv_url) {
            return $items;
        }

        $cv_item = [
            'key'         => 'cv',
            'label'       => '📄 CV',
            'url'         => $cv_url,
            'active_slug' => (function() {
                $pid = (int) get_option('bp_addon_cv_page_id', 0);
                if ($pid > 0) {
                    $slug = (string) get_post_field('post_name', $pid);
                    if ($slug !== '') return $slug;
                }
                return 'cv';
            })(),
        ];

        // Bestaat 'cv' al? Dan update. Anders toevoegen.
        $found = false;
        foreach ($items as $i => $it) {
            if (!is_array($it)) continue;
            if (($it['key'] ?? '') === 'cv') {
                $items[$i] = array_merge($it, $cv_item);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $items[] = $cv_item;
        }

        return $items;
    }
}
