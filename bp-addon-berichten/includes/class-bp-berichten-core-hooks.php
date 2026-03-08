<?php
namespace BP_Berichten;

defined('ABSPATH') || exit;

final class CoreHooks {

    public static function init(): void {
        add_filter('bp_core_addons_registry', [__CLASS__, 'register_addon']);
        add_filter('bp_core_render_inbox', [__CLASS__, 'render_inbox'], 10, 2);
        add_filter('bp_core_tools_tiles', [__CLASS__, 'filter_tools_tiles'], 20, 2);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 120);
        add_action('wp_head', [__CLASS__, 'output_pwa_head'], 5);
        add_action('init', [__CLASS__, 'serve_pwa_assets']);
    }

    public static function register_addon($addons): array {
        if (!is_array($addons)) $addons = [];
        $addons['berichten'] = [
            'label' => 'Berichten Inbox',
            'cap'   => '',
        ];
        return $addons;
    }

    public static function render_inbox($html, $user): string {
        if (!is_user_logged_in()) return '';
        $user_id = is_object($user) && !empty($user->ID) ? (int) $user->ID : get_current_user_id();
        if ($user_id <= 0) return '';

        if (function_exists('bp_core_user_can_use_addon') && !bp_core_user_can_use_addon('berichten', $user_id)) {
            return '<div class="bp-notice">Je hebt geen toegang tot de Berichten add-on.</div>';
        }

        $file = BP_ADDON_BERICHTEN_DIR . 'templates/inbox.php';
        if (!file_exists($file)) {
            return '<div class="bp-notice">Berichten-template niet gevonden.</div>';
        }

        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    public static function filter_tools_tiles($tiles, $user_id) {
        if (!is_array($tiles)) $tiles = [];
        $user_id = (int) $user_id;
        if ($user_id <= 0) return $tiles;

        if (function_exists('bp_core_user_can_use_addon') && !bp_core_user_can_use_addon('berichten', $user_id)) {
            return $tiles;
        }

        $inbox_url = '';
        if (function_exists('bp_core_get_linked_pages')) {
            $pages = bp_core_get_linked_pages();
            $pid = (int) ($pages['inbox'] ?? 0);
            if ($pid > 0) $inbox_url = (string) get_permalink($pid);
        }
        if ($inbox_url === '') {
            $pid = (int) get_option('bp_addon_berichten_page_id', 0);
            if ($pid > 0) $inbox_url = (string) get_permalink($pid);
        }
        if ($inbox_url === '') return $tiles;

        $found = false;
        foreach ($tiles as $i => $tile) {
            if (!is_array($tile)) continue;
            if (($tile['key'] ?? '') !== 'inbox') continue;
            $tiles[$i]['url'] = $inbox_url;
            $found = true;
        }

        if (!$found) {
            $tiles[] = [
                'key' => 'inbox',
                'title' => 'Berichten inbox',
                'subtitle' => 'Chat met je contacten',
                'url' => $inbox_url,
                'icon' => '✉️',
                'style' => 'info',
            ];
        }

        return $tiles;
    }

    public static function enqueue_assets(): void {
        if (!is_user_logged_in() || !is_singular()) return;
        $pid = get_queried_object_id();
        if ($pid <= 0) return;

        $inbox = (int) get_option('bp_addon_berichten_page_id', 0);
        $contacts = (int) get_option('bp_addon_berichten_contacts_page_id', 0);
        if ($pid !== $inbox && $pid !== $contacts) return;

        wp_enqueue_script(
            'bp-addon-berichten-e2e',
            BP_ADDON_BERICHTEN_URL . 'assets/js/inbox-e2e.js',
            [],
            BP_ADDON_BERICHTEN_VERSION,
            true
        );
    }

    private static function is_messages_page(): bool {
        if (!is_user_logged_in() || !is_singular()) return false;
        $pid = get_queried_object_id();
        if ($pid <= 0) return false;
        $inbox = (int) get_option('bp_addon_berichten_page_id', 0);
        $contacts = (int) get_option('bp_addon_berichten_contacts_page_id', 0);
        return $pid === $inbox || $pid === $contacts;
    }

    public static function output_pwa_head(): void {
        if (!self::is_messages_page()) return;
        $theme = '#0b56c6';
        $mid = '#0b56c6';
        $bg = '#f8fbff';
        $border = '#d7dbe6';
        $text = '#1f2937';
        $muted = '#64748b';
        $link = '#0b56c6';
        if (function_exists('bp_core_get_brand_colors')) {
            $c = bp_core_get_brand_colors();
            $theme = (string) ($c['blue'] ?? $theme);
            $mid = (string) ($c['mid'] ?? $mid);
            $bg = (string) ($c['bg'] ?? $bg);
            $border = (string) ($c['border'] ?? $border);
            $text = (string) ($c['text'] ?? $text);
            $muted = (string) ($c['muted'] ?? $muted);
            $link = (string) ($c['link'] ?? $link);
        }
        $manifest = esc_url(add_query_arg('bp_berichten_manifest', '1', home_url('/')));
        echo '<link rel="manifest" href="' . $manifest . '">' . "\n";
        echo '<meta name="theme-color" content="' . esc_attr($theme) . '">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
        echo '<style>:root{--kb-blue:' . esc_attr($theme) . ';--kb-mid:' . esc_attr($mid) . ';--kb-bg:' . esc_attr($bg) . ';--kb-border:' . esc_attr($border) . ';--kb-text:' . esc_attr($text) . ';--kb-muted:' . esc_attr($muted) . ';--kb-link:' . esc_attr($link) . ';}</style>' . "\n";
    }

    public static function serve_pwa_assets(): void {
        if (empty($_GET['bp_berichten_manifest']) && empty($_GET['bp_berichten_sw'])) {
            return;
        }

        if (!empty($_GET['bp_berichten_manifest'])) {
            $name = 'Berichten inbox';
            $short = 'Berichten';
            $start = (int) get_option('bp_addon_berichten_page_id', 0);
            $start_url = $start > 0 ? get_permalink($start) : home_url('/');
            $theme = '#0b56c6';
            $mid = '#0b56c6';
            $bg = '#f8fbff';
            if (function_exists('bp_core_get_brand_colors')) {
                $c = bp_core_get_brand_colors();
                $theme = (string) ($c['blue'] ?? $theme);
                $mid = (string) ($c['mid'] ?? $mid);
                $bg = (string) ($c['bg'] ?? $bg);
            }
            $data = [
                'name' => $name,
                'short_name' => $short,
                'start_url' => $start_url,
                'scope' => '/',
                'display' => 'standalone',
                'background_color' => $bg,
                'theme_color' => $theme,
                'icons' => [
                    [
                        'src' => 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 192 192"><rect width="192" height="192" rx="36" fill="' . esc_attr($theme) . '"/><text x="96" y="112" text-anchor="middle" font-size="72" font-family="Arial" fill="#fff" font-weight="700">BP</text></svg>'),
                        'sizes' => '192x192',
                        'type' => 'image/svg+xml',
                        'purpose' => 'any',
                    ],
                    [
                        'src' => 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="88" fill="' . esc_attr($theme) . '"/><text x="256" y="308" text-anchor="middle" font-size="196" font-family="Arial" fill="#fff" font-weight="700">BP</text></svg>'),
                        'sizes' => '512x512',
                        'type' => 'image/svg+xml',
                        'purpose' => 'any',
                    ],
                ],
            ];
            nocache_headers();
            header('Content-Type: application/manifest+json; charset=utf-8');
            echo wp_json_encode($data);
            exit;
        }

        if (!empty($_GET['bp_berichten_sw'])) {
            nocache_headers();
            header('Content-Type: application/javascript; charset=utf-8');
            echo "self.addEventListener('install',e=>{self.skipWaiting();});\n";
            echo "self.addEventListener('activate',e=>{e.waitUntil(self.clients.claim());});\n";
            echo "self.addEventListener('fetch',function(event){if(event.request.method!=='GET')return;event.respondWith(fetch(event.request).catch(function(){return caches.match(event.request);}));});\n";
            exit;
        }
    }
}
