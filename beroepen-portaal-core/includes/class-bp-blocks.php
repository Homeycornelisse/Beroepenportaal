<?php
defined('ABSPATH') || exit;

/**
 * Gutenberg blokken voor Beroepen Portaal Core.
 * Geen shortcodes. Alles via blokken.
 */
final class BP_Core_Blocks {

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_blocks']);
        // Run late so theme styles load first; plugin styles can safely override when needed.
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_front_assets'], 100);
        add_action('wp_head', [__CLASS__, 'output_site_icon'], 3);
        // Brede/volledige blokuitlijning activeren (ongeacht thema-instelling)
        add_action('after_setup_theme', function() {
            add_theme_support('align-wide');
        }, 99);
    }

    public static function register_blocks(): void {
        // Editor script (geen build nodig; gebruikt wp.* globals)
        wp_register_script(
            'bp-core-blocks',
            BP_CORE_URL . 'assets/js/blocks.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n'],
            (string) filemtime(BP_CORE_DIR . 'assets/js/blocks.js') ?: BP_CORE_VERSION,
            true
        );

        // Core portaal pagina
        register_block_type('bp/portaal-page', [
            'api_version'     => 2,
            'editor_script'   => 'bp-core-blocks',
            'render_callback' => [__CLASS__, 'render_portaal_page'],
            'attributes'      => [
                'screen' => ['type' => 'string', 'default' => 'dashboard'],
                'align'  => ['type' => 'string', 'default' => ''],
            ],
            'supports'        => [
                'align' => ['full', 'wide'],
            ],
        ]);

        // Rechten per gebruiker (front-end beheer)
        register_block_type('bp/rechten-per-gebruiker', [
            'api_version'     => 2,
            'editor_script'   => 'bp-core-blocks',
            'render_callback' => [__CLASS__, 'render_rechten_per_gebruiker'],
            'attributes'      => [],
        ]);

        // Login / Uitloggen knop
        register_block_type('bp/login-knop', [
            'api_version'     => 2,
            'editor_script'   => 'bp-core-blocks',
            'render_callback' => [__CLASS__, 'render_login_knop'],
            'attributes'      => [
                'login_url' => ['type' => 'string', 'default' => ''],
            ],
        ]);
    }

    private static function is_portaal_request(): bool {
        if (is_admin()) return false;
        if (!is_singular()) return false;

        $post = get_post();
        if (!$post) return false;

        // 1) Als er portaal blokken in de pagina staan (ook addons mogen hier aanhaken)
        $blocks = apply_filters('bp_core_portaal_blocks', [
            'bp/portaal-page',
            'bp/rechten-per-gebruiker',
            'bp/login-knop',
        ]);

        if (is_array($blocks)) {
            foreach ($blocks as $b) {
                $b = is_string($b) ? $b : '';
                if ($b && has_block($b, $post)) {
                    return true;
                }
            }
        }

        // 2) Als de pagina gekoppeld is in de core settings (pages-link)
        if (function_exists('bp_core_get_linked_pages')) {
            $pages = bp_core_get_linked_pages();
            $pid = (int) $post->ID;
            foreach ($pages as $k => $v) {
                if ((int)$v === $pid) return true;
            }
        }

        $is = false;
        return (bool) apply_filters('bp_core_is_portaal_request', $is, $post);
    }

    public static function enqueue_front_assets(): void {
        if (!self::is_portaal_request() && !is_front_page()) return;

        wp_enqueue_style(
            'bp-core-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
            [],
            null
        );

        wp_enqueue_style('bp-core-style', BP_CORE_URL . 'assets/css/portaal.css', [], BP_CORE_VERSION);
        if (function_exists('bp_core_get_brand_colors')) {
            $c = bp_core_get_brand_colors();
            $inline_css = ':root{'
                . '--kb-blue:' . esc_attr($c['blue']) . ';'
                . '--kb-mid:' . esc_attr($c['mid']) . ';'
                . '--kb-orange:' . esc_attr($c['orange']) . ';'
                . '--kb-purple:' . esc_attr($c['purple']) . ';'
                . '--kb-bg:' . esc_attr($c['bg']) . ';'
                . '--kb-border:' . esc_attr($c['border']) . ';'
                . '--kb-text:' . esc_attr($c['text']) . ';'
                . '--kb-link:' . esc_attr($c['link'] ?? $c['blue']) . ';'
                . '--kb-muted:' . esc_attr($c['muted']) . ';'
                . '}';
            wp_add_inline_style('bp-core-style', $inline_css);
        }
        // dataset.js is alleen voor de beroepen-module (aparte addon in de toekomst)
        // wp_enqueue_script('bp-core-dataset', BP_CORE_URL . 'assets/js/dataset.js', [], BP_CORE_VERSION, true);
        // portaal.js bevat het tab-systeem voor admin dashboard + begeleider workspace
        wp_enqueue_script('bp-core-app', BP_CORE_URL . 'assets/js/portaal.js', [], BP_CORE_VERSION, true);
        // berichten.js: inbox interactie (markeer gelezen, etc.)
        wp_enqueue_script('bp-core-berichten', BP_CORE_URL . 'assets/js/berichten.js', ['bp-core-app'], BP_CORE_VERSION, true);

        // Gevoelige data op front-end portaalpagina's afschermen bij inactiviteit.
        self::enqueue_sensitive_guard();
    }

    public static function output_site_icon(): void {
        if (is_admin()) return;
        if (!function_exists('bp_core_get_site_icon_url')) return;
        $icon = (string) bp_core_get_site_icon_url('');
        if ($icon === '') return;
        $safe = esc_url($icon);
        echo '<link rel="icon" href="' . $safe . '" sizes="32x32">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . $safe . '">' . "\n";
    }

    private static function enqueue_sensitive_guard(): void {
        if (!is_user_logged_in()) return;
        $pid = get_queried_object_id();
        if ($pid <= 0) return;
        if (function_exists('bp_core_is_page_behind_login_wall') && !bp_core_is_page_behind_login_wall((int) $pid)) {
            return;
        }
        if ($pid > 0) {
            $msg_inbox = (int) get_option('bp_addon_berichten_page_id', 0);
            $msg_contacts = (int) get_option('bp_addon_berichten_contacts_page_id', 0);
            $docs_page = (int) get_option('bp_addon_documenten_page_id', 0);
            if ($pid === $msg_inbox || $pid === $msg_contacts || $pid === $docs_page) {
                // Berichten-addon heeft eigen lock-flow; dubbele lock-overlays voorkomen.
                return;
            }
        }

        wp_enqueue_script(
            'bp-sensitive-guard',
            BP_CORE_URL . 'assets/js/bp-sensitive-guard.js',
            [],
            BP_CORE_VERSION,
            true
        );
        wp_localize_script('bp-sensitive-guard', 'BPGuardAuth', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bp_core_verify_account_password'),
        ]);

        $guards = [
            [
                'scopeSelector' => '#kb-dashboard-root',
                'blurSelectors' => implode(',', [
                    'input[type="text"]',
                    'input[type="search"]',
                    'input[type="number"]',
                    'input[type="date"]',
                    'input[type="email"]',
                    'input[type="tel"]',
                    'textarea',
                    'select',
                    'button',
                    'a.kb-btn',
                    'a.bp-btn',
                ]),
                'redactSelectors' => implode(',', [
                    '.kb-hero-title',
                    '.kb-hero-sub',
                    '.kb-alert',
                    '.kb-card',
                    '.kb-list',
                    '.kb-inbox',
                ]),
                'lockAfterMs' => 300000,
            ],
            [
                'scopeSelector' => '.kb-account-wrap',
                'blurSelectors' => implode(',', [
                    'input[type="text"]',
                    'input[type="search"]',
                    'input[type="number"]',
                    'input[type="date"]',
                    'input[type="email"]',
                    'input[type="tel"]',
                    'input[type="password"]',
                    'input[type="file"]',
                    'textarea',
                    'select',
                    'button',
                    'a',
                    'summary',
                ]),
                'redactSelectors' => implode(',', [
                    '.kb-account-notice',
                    '.kb-account-section',
                ]),
                'lockAfterMs' => 300000,
            ],
            [
                'scopeSelector' => '[data-bp-addon-access], .bp-wrap',
                'blurSelectors' => implode(',', [
                    'input[type="text"]',
                    'input[type="search"]',
                    'input[type="number"]',
                    'input[type="email"]',
                    'textarea',
                    'select',
                    'button',
                    'a.bp-btn',
                ]),
                'redactSelectors' => implode(',', [
                    '#bp-addon-access-table',
                    '#bp-addon-access-msg',
                ]),
                'lockAfterMs' => 300000,
            ],
        ];

        foreach ($guards as $guard_cfg) {
            wp_add_inline_script(
                'bp-sensitive-guard',
                'window.BPSensitiveGuards = window.BPSensitiveGuards || []; window.BPSensitiveGuards.push(' . wp_json_encode($guard_cfg) . ');',
                'before'
            );
        }
    }

    public static function render_portaal_page(array $attributes, string $content = '', $block = null): string {
        $screen = isset($attributes['screen']) ? sanitize_key((string)$attributes['screen']) : 'dashboard';
        $login_error = self::get_login_error_message();
        $twofa_token = '';
        $twofa_method = '';
        if (class_exists('BP_Core_Loader') && method_exists('BP_Core_Loader', 'get_frontend_2fa_state')) {
            $twofa_state = BP_Core_Loader::get_frontend_2fa_state();
            $twofa_token = isset($twofa_state['token']) ? sanitize_text_field((string) $twofa_state['token']) : '';
            $twofa_method = isset($twofa_state['method']) ? sanitize_key((string) $twofa_state['method']) : '';
        }
        $login_message = '';
        if ($twofa_token !== '') {
            $login_message = $twofa_method === 'totp'
                ? 'Open je authenticator-app op je mobiel en vul de 6-cijferige code in.'
                : 'Er is een verificatiecode naar je e-mailadres gestuurd.';
        }

        switch ($screen) {
            case 'dashboard':
                // zelfde checks als vroeger
                if (!is_user_logged_in()) {
                    return BP_Core_Template_Loader::render('login', [
                        'redirect' => get_permalink() ?: home_url('/'),
                        'error'    => $login_error,
                        'message'  => $login_message,
                        'twofa_token' => $twofa_token,
                        'twofa_method' => $twofa_method,
                    ]);
                }

                $ok = false;
                if (current_user_can('manage_options')) {
                    $ok = true;
                } elseif (class_exists('BP_Core_Roles')) {
                    $u = wp_get_current_user();
                    $ok = BP_Core_Roles::is_client($u) || BP_Core_Roles::is_begeleider($u) || BP_Core_Roles::is_leidinggevende($u);
                }
                if (!$ok && function_exists('bp_core_user_can') && class_exists('BP_Core_Roles')) {
                    $ok = bp_core_user_can(BP_Core_Roles::CAP_VIEW_PORTAAL)
                       || bp_core_user_can(BP_Core_Roles::CAP_VIEW_CLIENTS)
                       || bp_core_user_can(BP_Core_Roles::CAP_MANAGE_TEAM);
                }
                if (!$ok) {
                    return function_exists('bp_core_no_access_message') ? bp_core_no_access_message() : 'Geen toegang.';
                }
                return BP_Core_Template_Loader::render('dashboard');
            case 'account':
                if (!is_user_logged_in()) {
                    return BP_Core_Template_Loader::render('login', [
                        'redirect' => get_permalink() ?: home_url('/'),
                        'error'    => $login_error,
                        'message'  => $login_message,
                        'twofa_token' => $twofa_token,
                        'twofa_method' => $twofa_method,
                    ]);
                }
                return BP_Core_Template_Loader::render('account');
            case 'beroepen':
                // Beroepen portaal tijdelijk uitgeschakeld – wordt een aparte addon.
                return '';
            case 'uitleg':
                return BP_Core_Template_Loader::render('uitleg');
            case 'inbox':
                if (!is_user_logged_in()) {
                    return BP_Core_Template_Loader::render('login', [
                        'redirect' => get_permalink() ?: home_url('/'),
                        'error'    => $login_error,
                        'message'  => $login_message,
                        'twofa_token' => $twofa_token,
                        'twofa_method' => $twofa_method,
                    ]);
                }

                $ok_inbox = false;
                if (current_user_can('manage_options')) {
                    $ok_inbox = true;
                } elseif (class_exists('BP_Core_Roles')) {
                    $u = wp_get_current_user();
                    $ok_inbox = BP_Core_Roles::is_client($u) || BP_Core_Roles::is_begeleider($u) || BP_Core_Roles::is_leidinggevende($u);
                }
                if (!$ok_inbox && function_exists('bp_core_user_can') && class_exists('BP_Core_Roles')) {
                    $ok_inbox = bp_core_user_can(BP_Core_Roles::CAP_VIEW_PORTAAL)
                        || bp_core_user_can(BP_Core_Roles::CAP_VIEW_CLIENTS)
                        || bp_core_user_can(BP_Core_Roles::CAP_MANAGE_TEAM);
                }
                if (!$ok_inbox) {
                    return function_exists('bp_core_no_access_message') ? bp_core_no_access_message() : 'Geen toegang.';
                }
                $addon_render = apply_filters('bp_core_render_inbox', '', $u ?? wp_get_current_user());
                if (is_string($addon_render) && $addon_render !== '') {
                    return $addon_render;
                }
                return '<div class="bp-notice">Berichten inbox is verplaatst naar de Berichten add-on. Activeer de add-on om de inbox te gebruiken.</div>';
            case 'login':
                return BP_Core_Template_Loader::render('login', [
                    'error' => $login_error,
                    'message' => $login_message,
                    'twofa_token' => $twofa_token,
                    'twofa_method' => $twofa_method,
                ]);
            default:
                return BP_Core_Template_Loader::render('dashboard');
        }
    }

    private static function get_login_error_message(): string {
        $code = isset($_GET['bp_login_fout']) ? sanitize_key((string) wp_unslash($_GET['bp_login_fout'])) : '';
        if ($code === '') return '';

        if ($code === '1') return 'E-mailadres of wachtwoord onjuist. Probeer opnieuw.';
        if ($code === '2fa_required') return '';
        if ($code === '2fa_code' || $code === '2fa_format') return 'De verificatiecode is onjuist.';
        if ($code === '2fa_expired') return 'Je verificatiesessie is verlopen. Log opnieuw in.';
        if ($code === '2fa_locked') return 'Te veel foute codes. Log opnieuw in.';
        if ($code === '2fa_mail') return '2FA-code kon niet per e-mail worden verstuurd. Controleer je mailinstellingen.';

        return 'Inloggen mislukt. Probeer opnieuw.';
    }

    public static function render_login_knop(array $attributes, string $content = '', $block = null): string {
        if (is_user_logged_in()) {
            $url   = wp_logout_url(home_url('/'));
            $label = 'Uitloggen';
        } else {
            $raw = isset($attributes['login_url']) ? trim((string)$attributes['login_url']) : '';
            if ($raw) {
                $url = esc_url($raw);
            } else {
                // Fallback: zoek de login-portaal pagina
                $linked = function_exists('bp_core_get_linked_pages') ? bp_core_get_linked_pages() : [];
                $login_id = $linked['login'] ?? 0;
                $url = $login_id ? get_permalink((int)$login_id) : home_url('/login-portaal');
            }
            $label = 'Inloggen';
        }

        return '<a href="' . esc_url($url) . '" class="kb-btn kb-btn-primary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">'
             . esc_html($label) . ' &rarr;</a>';
    }

    public static function render_rechten_per_gebruiker(array $attributes, string $content = '', $block = null): string {
        if (!is_user_logged_in()) {
            return BP_Core_Template_Loader::render('login');
        }

        // Alleen leidinggevende of admin
        $u = wp_get_current_user();
        $ok = current_user_can('manage_options');
        if (!$ok && class_exists('BP_Core_Roles')) {
            $ok = BP_Core_Roles::is_leidinggevende($u);
        }
        if (!$ok) {
            return function_exists('bp_core_no_access_message') ? bp_core_no_access_message() : 'Geen toegang.';
        }

        // We hergebruiken het admin-scherm als template (zelfde velden), maar dan zonder WP admin layout.
        ob_start();
        $page = BP_CORE_DIR . 'admin/pages/user-caps.php';
        if (file_exists($page)) {
            include $page;
        } else {
            echo '<div class="bp-card"><p>Scherm niet gevonden.</p></div>';
        }
        return (string) ob_get_clean();
    }
}
