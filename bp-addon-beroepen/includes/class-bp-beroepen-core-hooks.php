<?php
namespace BP_Beroepen;

defined('ABSPATH') || exit;

final class CoreHooks {

    public static function init(): void {
        add_filter('bp_core_addons_registry', [__CLASS__, 'register_addon']);
        add_filter('bp_core_portaal_blocks', [__CLASS__, 'add_portaal_blocks']);
        add_filter('bp_core_nav_items', [__CLASS__, 'add_nav_item'], 10, 3);
        add_filter('bp_core_tools_tiles', [__CLASS__, 'add_dashboard_tile'], 10, 2);
        add_filter('allowed_block_types_all', [__CLASS__, 'allow_blocks_in_editor'], 9999, 2);
    }

    public static function register_addon($addons): array {
        if (!is_array($addons)) $addons = [];

        $addons['beroepen'] = [
            'label' => 'Beroepen',
            'cap'   => 'kb_use_beroepen',
        ];

        return $addons;
    }

    public static function add_portaal_blocks($blocks): array {
        if (!is_array($blocks)) $blocks = [];
        $blocks[] = 'bp/beroepen';
        return array_values(array_unique($blocks));
    }

    public static function add_nav_item($items, $context, int $user_id = 0) {
        if (!is_array($items)) return $items;
        $context = (string) $context;
        if (!in_array($context, ['client', 'begeleider'], true)) return $items;

        if (!Util::user_can_use($user_id)) return $items;

        $url = Util::get_page_url();
        if ($url === '') return $items;
        $page_id = (int) get_option('bp_addon_beroepen_page_id', 0);
        $active_slug = 'beroepenoverzicht';
        if ($page_id > 0) {
            $slug = (string) get_post_field('post_name', $page_id);
            if ($slug !== '') $active_slug = $slug;
        }

        foreach ($items as $existing) {
            if (is_array($existing) && (($existing['key'] ?? '') === 'beroepen')) {
                return $items;
            }
        }

        $items[] = [
            'key'         => 'beroepen',
            'label'       => 'Beroepen',
            'url'         => $url,
            'active_slug' => $active_slug,
        ];

        return $items;
    }

    public static function add_dashboard_tile(array $tiles, int $user_id): array {
        if (!Util::user_can_use($user_id)) return $tiles;

        $url = Util::get_page_url();
        if ($url === '') return $tiles;

        global $wpdb;
        $table = $wpdb->prefix . 'kb_selecties';

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE client_id = %d AND vind_ik_leuk = 1",
            $user_id
        ));

        $tiles[] = [
            'key'      => 'beroepen',
            'title'    => 'Beroepen',
            'subtitle' => $count > 0 ? $count . ' favorieten' : 'Bekijk 306 beroepen',
            'url'      => $url,
            'icon'     => 'i',
            'style'    => 'info',
        ];

        return $tiles;
    }

    public static function allow_blocks_in_editor($allowed, $context) {
        if ($allowed === true || $allowed === null) return $allowed;
        if (!is_array($allowed)) return $allowed;

        if (!in_array('bp/beroepen', $allowed, true)) {
            $allowed[] = 'bp/beroepen';
        }

        return $allowed;
    }
}
