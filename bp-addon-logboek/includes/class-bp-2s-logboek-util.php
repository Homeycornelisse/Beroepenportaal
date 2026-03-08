<?php
namespace BP_2S_Logboek;

defined('ABSPATH') || exit;

final class Util {

    public static function ensure_tables(): void {
        global $wpdb;
        require_once ABSPATH . "wp-admin/includes/upgrade.php";

        $charset = $wpdb->get_charset_collate();
        $table1  = $wpdb->prefix . "kb_logboek";
        $table2  = $wpdb->prefix . "kb_begel_logboek";

        $sql1 = "CREATE TABLE {$table1} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            datum DATE NOT NULL,
            type VARCHAR(50) NOT NULL,
            omschrijving LONGTEXT NOT NULL,
            resultaat LONGTEXT NULL,
            uren DECIMAL(6,2) NULL,
            aangemaakt DATETIME NOT NULL,
            bijgewerkt DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_client (client_id),
            KEY idx_datum (datum)
        ) {$charset};";

        $sql2 = "CREATE TABLE {$table2} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            begeleider_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            datum DATE NOT NULL,
            type VARCHAR(50) NOT NULL,
            omschrijving LONGTEXT NOT NULL,
            vervolg VARCHAR(255) NULL,
            aangemaakt DATETIME NOT NULL,
            bewerkt TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_client (client_id),
            KEY idx_begeleider (begeleider_id),
            KEY idx_datum (datum)
        ) {$charset};";

        dbDelta($sql1);
        dbDelta($sql2);
    }

    public static function ensure_caps(): void {
        // Nieuwe cap voor deze addon
        $cap = 'kb_use_logboek';

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

    public static function user_can_use(): bool {
        if (!is_user_logged_in()) return false;

        // Core addon access (als Core aanwezig is)
        if (function_exists('bp_core_user_can_use_addon')) {
            if (!bp_core_user_can_use_addon('2espoor-logboek')) return false;
        }

        $u = wp_get_current_user();
        if (!$u || !$u->ID) return false;

        $uid = (int) $u->ID;

        // Core ondersteunt per-user cap overrides via bp_user_has_cap().
        // In sommige installs wordt dat bestand alleen in admin geladen.
        // Dus: als Core aanwezig is, laden we het bestand ook op de voorkant.
        if (!function_exists('bp_user_has_cap') && defined('BP_CORE_DIR')) {
            $f = trailingslashit(BP_CORE_DIR) . 'includes/class-bp-user-caps.php';
            if (is_readable($f)) { require_once $f; }
        }

        // Gebruik bp_user_has_cap() als het bestaat, anders fallback naar user_can().
        if (function_exists('bp_user_has_cap')) {
            return (bool) bp_user_has_cap($uid, 'kb_use_logboek');
        }

        // manage_options als veiligheidsfallback voor admins (zodat plugin-instellingen bereikbaar blijven)
        return user_can($u, 'kb_use_logboek') || user_can($u, 'manage_options');
    }

    public static function is_begeleider_or_leidinggevende(): bool {
        $u = wp_get_current_user();
        if (!$u || !$u->ID) return false;
        return in_array('kb_begeleider', (array) $u->roles, true) || in_array('kb_leidinggevende', (array) $u->roles, true);
    }

    public static function is_leidinggevende(): bool {
        $u = wp_get_current_user();
        if (!$u || !$u->ID) return false;
        return in_array('kb_leidinggevende', (array) $u->roles, true);
    }

    public static function enqueue_front_assets(string $mode = 'client'): void {
        // CSS
        wp_enqueue_style(
            'bp-2s-logboek',
            BP_2S_LOGBOEK_URL . 'assets/css/logboek.css',
            [],
            BP_2S_LOGBOEK_VERSION
        );

        // JS
        $handle = $mode === 'begeleider' ? 'bp-2s-logboek-begel' : 'bp-2s-logboek-client';
        $src    = $mode === 'begeleider' ? 'assets/js/begel-logboek.js' : 'assets/js/logboek.js';

        wp_enqueue_script(
            $handle,
            BP_2S_LOGBOEK_URL . $src,
            ['wp-api-fetch'],
            BP_2S_LOGBOEK_VERSION,
            true
        );

        wp_add_inline_script('wp-api-fetch', 'window.wpApiSettings = window.wpApiSettings || {};', 'before');

        $logo_client = (string) get_option('bp_2s_logo_client_url', '');
        $logo_begel  = (string) get_option('bp_2s_logo_begeleider_url', '');
        $logo_height = max(10, min(80, (int) get_option('bp_2s_logo_height', 20)));
        $lijn_kleur  = (string) get_option('bp_2s_lijn_kleur', '#0047AB');
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $lijn_kleur)) {
            $lijn_kleur = '#0047AB';
        }

        $fallback_icon = (string) get_site_icon_url(64);
        $logo_for_mode = $mode === 'begeleider' ? ($logo_begel ?: $fallback_icon) : ($logo_client ?: $fallback_icon);

        wp_localize_script($handle, 'BP2SLogboek', [
            'restPath'  => '/bp-2s-logboek/v1/',
            'restNs'    => 'bp-2s-logboek/v1',
            'nonce'     => wp_create_nonce('wp_rest'),
            'mode'      => $mode,
            'isLeid'    => Util::is_leidinggevende(),
            'siteIcon'  => $logo_for_mode,
            'siteName'  => (string) get_bloginfo('name'),
            'userName'  => is_user_logged_in() ? (string) wp_get_current_user()->display_name : '',
            'lijnKleur' => $lijn_kleur,
            'logoHeight'=> $logo_height,
        ]);

        $brand_css = '';
        if (function_exists('bp_core_get_brand_colors')) {
            $c = bp_core_get_brand_colors();
            $brand_css = '.kb-wrap{'
                . '--kb-blue:' . esc_attr((string) $c['blue']) . ';'
                . '--kb-mid:' . esc_attr((string) $c['mid']) . ';'
                . '--kb-orange:' . esc_attr((string) $c['orange']) . ';'
                . '--kb-purple:' . esc_attr((string) $c['purple']) . ';'
                . '--kb-bg:' . esc_attr((string) $c['bg']) . ';'
                . '--kb-border:' . esc_attr((string) $c['border']) . ';'
                . '--kb-text:' . esc_attr((string) $c['text']) . ';'
                . '--kb-muted:' . esc_attr((string) $c['muted']) . ';'
                . '}';
        }

        // Inline CSS overrides voor lijnkleur en logo-hoogte
        $esc_kleur  = esc_attr($lijn_kleur);
        $esc_height = (int) $logo_height;
        wp_add_inline_style('bp-2s-logboek', "
            {$brand_css}
            @media print {
                .kb-print-logo { max-height: {$esc_height}px !important; }
                .kb-print-headrow    { border-bottom-color: {$esc_kleur} !important; }
                .kb-print-signature  { border-top-color:    {$esc_kleur} !important; }
                .kb-print-sign       { border-top-color:    {$esc_kleur} !important; }
                .kb-print-signatures { border-top-color:    {$esc_kleur} !important; }
            }
        ");

        // Zet nonce voor apiFetch
        wp_add_inline_script($handle, "
            if (window.wp && wp.apiFetch) {
                wp.apiFetch.use( wp.apiFetch.createNonceMiddleware('" . esc_js(wp_create_nonce('wp_rest')) . "') );
            }
        ", 'before');

        if (defined('BP_CORE_URL') && defined('BP_CORE_VERSION')) {
            $scope = $mode === 'begeleider' ? '#kb-begel-logboek-root' : '#kb-logboek-root';
            $guard_cfg = [
                'scopeSelector' => $scope,
                'blurSelectors' => implode(',', [
                    'input[type="text"]',
                    'input[type="search"]',
                    'input[type="number"]',
                    'input[type="date"]',
                    'textarea',
                    'select',
                    'button',
                ]),
                'redactSelectors' => implode(',', [
                    '.kb-entry-body',
                    '.kb-entry-result',
                    '.kb-entry-title',
                    '.kb-status',
                    '.kb-filter-count',
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
}
