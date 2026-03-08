<?php
namespace BP_2S_Logboek;

defined('ABSPATH') || exit;

final class CoreHooks {

    public static function init(): void {
        add_filter('bp_core_addons_registry', [__CLASS__, 'register_addon']);

        // Zorg dat Core dit ook als portaalpagina ziet (app-mode + navbar/footer)
        add_filter('bp_core_portaal_blocks', [__CLASS__, 'add_portaal_blocks']);

        // Voeg logboek link toe aan de portaal navigatie (Core bouwt menu)
        add_filter('bp_core_nav_items', [__CLASS__, 'add_nav_item'], 10, 2);

        // Voeg logboek-tegel toe aan client dashboard
        add_filter('bp_core_apply_tools_tiles', [__CLASS__, 'add_dashboard_tile'], 10, 2);

        // Belangrijk: sommige installs beperken de blokkenlijst in Gutenberg.
        // Dan zie je alleen een paar BP-blokken (zoals in jouw screenshot).
        // Met deze filter voegen we onze blokken toe aan die 'allowed blocks' lijst.
        add_filter('allowed_block_types_all', [__CLASS__, 'allow_blocks_in_editor'], 9999, 2);
    }

    public static function register_addon($addons): array {
        if (!is_array($addons)) $addons = [];
        $addons['2espoor-logboek'] = [
            'label' => '2eSpoor Logboek',
            'cap'   => 'kb_use_logboek',
        ];
        return $addons;
    }

    public static function add_portaal_blocks($blocks): array {
        if (!is_array($blocks)) $blocks = [];
        $blocks[] = 'bp/tweedespoor-logboek-client';
        $blocks[] = 'bp/tweedespoor-logboek-begeleider';
        return array_values(array_unique($blocks));
    }

    public static function add_nav_item($items, string $context) {
        if (!is_array($items)) return $items;
        // Alleen voor client/begeleider
        if (!in_array($context, ['client', 'begeleider'], true)) return $items;

        // Voeg toe als pagina bestaat
        $page_id = (int) get_option('bp_addon_2s_logboek_page_id', 0);
        if ($page_id <= 0) return $items;

        $items[] = [
            'label' => 'Logboek',
            'url'   => get_permalink($page_id),
            'icon'  => '📋',
            'slug'  => 'logboek',
        ];

        return $items;
    }

    public static function add_dashboard_tile(array $tiles, int $user_id): array {
        // Alleen voor clients met toegang
        if (!Util::user_can_use()) return $tiles;

        // Alleen tonen als er een logboek-pagina is
        $page_id = (int) get_option('bp_addon_2s_logboek_page_id', 0);
        if ($page_id <= 0) {
            $page = get_page_by_path('logboek');
            if ($page) $page_id = (int) $page->ID;
        }
        if ($page_id <= 0) return $tiles;

        // Aantal entries ophalen voor de subtitle
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kb_logboek WHERE client_id = %d",
            $user_id
        ));

        $tiles[] = [
            'key'      => 'logboek-2espoor',
            'title'    => '2e Spoor Logboek',
            'subtitle' => $count > 0 ? $count . ' aantekening' . ($count === 1 ? '' : 'en') : 'Voortgang bijhouden',
            'url'      => get_permalink($page_id),
            'icon'     => '📋',
            'style'    => 'purple',
        ];

        return $tiles;
    }

    public static function allow_blocks_in_editor($allowed, $context) {
        // Als alles is toegestaan, niks doen.
        if ($allowed === true || $allowed === null) return $allowed;

        // Alleen uitbreiden als er al een lijst is.
        if (!is_array($allowed)) return $allowed;

        $mine = [
            'bp/tweedespoor-logboek-client',
            'bp/tweedespoor-logboek-begeleider',
        ];

        foreach ($mine as $b) {
            if (!in_array($b, $allowed, true)) {
                $allowed[] = $b;
            }
        }

        return $allowed;
    }
}
