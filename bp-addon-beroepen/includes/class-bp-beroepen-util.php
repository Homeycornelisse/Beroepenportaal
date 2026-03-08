<?php
namespace BP_Beroepen;

defined('ABSPATH') || exit;

final class Util {

    public static function ensure_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_selecties = $wpdb->prefix . 'kb_selecties';
        $table_aant      = $wpdb->prefix . 'kb_aantekeningen';
        $charset = $wpdb->get_charset_collate();

        $sql_selecties = "CREATE TABLE {$table_selecties} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            beroep_naam VARCHAR(255) NOT NULL,
            sector VARCHAR(120) NOT NULL DEFAULT '',
            niveau VARCHAR(50) NOT NULL DEFAULT '',
            vind_ik_leuk TINYINT(1) NOT NULL DEFAULT 0,
            doelgroep TINYINT(1) NOT NULL DEFAULT 0,
            notitie TEXT NULL,
            bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_client_beroep (client_id, beroep_naam(191)),
            KEY idx_client (client_id)
        ) {$charset};";

        $sql_aant = "CREATE TABLE {$table_aant} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            begeleider_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            beroep_naam VARCHAR(255) NOT NULL,
            sterren TINYINT(1) NOT NULL DEFAULT 0,
            doelgroep_functie TINYINT(1) NOT NULL DEFAULT 0,
            lks_percentage DECIMAL(5,2) NULL,
            advies TEXT NULL,
            vervolgstappen TEXT NULL,
            bijgewerkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_begel_client_beroep (begeleider_id, client_id, beroep_naam(191)),
            KEY idx_begeleider (begeleider_id),
            KEY idx_client (client_id)
        ) {$charset};";

        dbDelta($sql_selecties);
        dbDelta($sql_aant);
    }

    public static function ensure_caps(): void {
        $cap = 'kb_use_beroepen';

        foreach (['kb_client', 'kb_begeleider', 'kb_leidinggevende'] as $role_key) {
            $role = get_role($role_key);
            if ($role && !$role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }

        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap($cap)) {
            $admin->add_cap($cap);
        }
    }

    public static function user_can_use(?int $user_id = null): bool {
        if (!is_user_logged_in()) return false;

        $user_id = (int) ($user_id ?: get_current_user_id());
        if ($user_id <= 0) return false;

        if (function_exists('bp_core_user_can_use_addon')) {
            if (!bp_core_user_can_use_addon('beroepen', $user_id)) {
                return false;
            }
        }

        if (!function_exists('bp_user_has_cap') && defined('BP_CORE_DIR')) {
            $caps_file = trailingslashit(BP_CORE_DIR) . 'includes/class-bp-user-caps.php';
            if (is_readable($caps_file)) {
                require_once $caps_file;
            }
        }

        if (function_exists('bp_user_has_cap')) {
            return (bool) bp_user_has_cap($user_id, 'kb_use_beroepen');
        }

        $user = get_user_by('id', $user_id);
        if (!$user) return false;

        return user_can($user, 'kb_use_beroepen') || user_can($user, 'manage_options');
    }

    public static function enqueue_front_assets(): void {
        wp_enqueue_style(
            'bp-beroepen',
            BP_BEROEPEN_URL . 'assets/css/beroepen.css',
            [],
            BP_BEROEPEN_VERSION
        );
        if (function_exists('bp_core_get_brand_colors')) {
            $c = bp_core_get_brand_colors();
            $css = ':root{'
                . '--kb-blue:' . esc_attr((string) $c['blue']) . ';'
                . '--kb-mid:' . esc_attr((string) $c['mid']) . ';'
                . '--kb-orange:' . esc_attr((string) $c['orange']) . ';'
                . '--kb-purple:' . esc_attr((string) $c['purple']) . ';'
                . '--kb-bg:' . esc_attr((string) $c['bg']) . ';'
                . '--kb-border:' . esc_attr((string) $c['border']) . ';'
                . '--kb-text:' . esc_attr((string) $c['text']) . ';'
                . '--kb-muted:' . esc_attr((string) $c['muted']) . ';'
                . '}';
            wp_add_inline_style('bp-beroepen', $css);
        }

        wp_enqueue_script(
            'bp-beroepen-dataset',
            BP_BEROEPEN_URL . 'assets/js/dataset.js',
            [],
            BP_BEROEPEN_VERSION,
            true
        );

        wp_enqueue_script(
            'bp-beroepen',
            BP_BEROEPEN_URL . 'assets/js/beroepen.js',
            ['bp-beroepen-dataset'],
            BP_BEROEPEN_VERSION,
            true
        );

        wp_localize_script('bp-beroepen', 'BPBeroepen', [
            'pdfLayout' => [
                'bgUrl' => self::get_pdf_layout_bg_url(),
                'map'   => self::get_pdf_layout_map(),
            ],
            'restUrl' => rest_url('bp-beroepen/v1'),
            'nonce'   => wp_create_nonce('wp_rest'),
            'mode'    => self::is_begeleider_or_leidinggevende() ? 'begeleider' : 'client',
            'strings' => [
                'loading' => 'Beroepen laden...',
                'noData'  => 'Geen beroepen gevonden voor dit filter.',
                'saveError' => 'Opslaan mislukt. Probeer opnieuw.',
            ],
        ]);

        if (defined('BP_CORE_URL') && defined('BP_CORE_VERSION')) {
            $guard_cfg = [
                'scopeSelector' => '.bp-beroepen',
                'blurSelectors' => implode(',', [
                    'input[type="text"]',
                    'input[type="search"]',
                    'input[type="number"]',
                    'textarea',
                    'select',
                    'button',
                    '.bp-info-link',
                ]),
                'redactSelectors' => implode(',', [
                    '.bp-card-title',
                    '.bp-client-note',
                    '.bp-card-sector',
                    '.bp-beroepen-counter',
                ]),
                'lockAfterMs' => 300000,
            ];

            wp_enqueue_script(
                'bp-sensitive-guard',
                BP_CORE_URL . 'assets/js/bp-sensitive-guard.js',
                [],
                BP_CORE_VERSION,
                true
            );

            wp_add_inline_script(
                'bp-sensitive-guard',
                'window.BPSensitiveGuards = window.BPSensitiveGuards || []; window.BPSensitiveGuards.push(' . wp_json_encode($guard_cfg) . ');',
                'before'
            );
        }
    }

    private static function get_pdf_layout_bg_url(): string {
        $key = self::is_begeleider_or_leidinggevende() ? 'bp_pdf_layout_bg_beroepen_begeleider' : 'bp_pdf_layout_bg_beroepen_client';
        $bg = (string) get_option($key, '');
        if ($bg === '' && !self::is_begeleider_or_leidinggevende()) {
            $bg = (string) get_option('bp_beroepen_pdf_layout_bg_url', '');
        }
        return $bg;
    }

    private static function get_pdf_layout_map(): array {
        $key = self::is_begeleider_or_leidinggevende() ? 'bp_pdf_layout_map_beroepen_begeleider' : 'bp_pdf_layout_map_beroepen_client';
        $raw = (string) get_option($key, '');
        if ($raw === '' && !self::is_begeleider_or_leidinggevende()) {
            $raw = (string) get_option('bp_beroepen_pdf_layout_map', '');
        }
        $out = [
            'headerTopMm' => 0,
            'tableTopMm'  => 0,
            'footerTopMm' => 0,
            'leftMm'      => 0,
            'rightMm'     => 0,
        ];
        if ($raw === '') return $out;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return $out;
        foreach ($out as $k => $v) {
            if (isset($decoded[$k]) && is_numeric($decoded[$k])) {
                $out[$k] = (float) $decoded[$k];
            }
        }
        return $out;
    }

    public static function is_begeleider_or_leidinggevende(?int $user_id = null): bool {
        $user_id = (int) ($user_id ?: get_current_user_id());
        if ($user_id <= 0) return false;
        $user = get_user_by('id', $user_id);
        if (!$user) return false;

        $roles = (array) $user->roles;
        return in_array('kb_begeleider', $roles, true) || in_array('kb_leidinggevende', $roles, true) || user_can($user, 'manage_options');
    }

    public static function get_page_url(): string {
        if (function_exists('bp_core_addon_page_url')) {
            $url = (string) bp_core_addon_page_url('bp_addon_beroepen_page_id');
            if ($url !== '') return $url;
        }

        $page_id = (int) get_option('bp_addon_beroepen_page_id', 0);
        if ($page_id > 0) {
            $url = get_permalink($page_id);
            if ($url) return (string) $url;
        }

        return '';
    }
}
