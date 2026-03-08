<?php
namespace BP_CV;

defined('ABSPATH') || exit;

final class Util {

    public static function get_org_name(): string {
        if (function_exists('bp_core_get_org_name')) {
            return (string) bp_core_get_org_name('Beroepen-portaal.nl');
        }

        // Fallback (oude v3 optie)
        $name = (string) get_option('kb_organisatie_naam', '');
        $name = trim($name);
        if ($name !== '') return $name;

        $name = (string) get_bloginfo('name');
        $name = trim($name);
        return $name !== '' ? $name : 'Beroepen-portaal.nl';
    }

    public static function current_user_id(): int {
        return get_current_user_id() ?: 0;
    }

    public static function is_logged_in(): bool {
        return is_user_logged_in();
    }

    public static function get_portal_url(string $fallback_slug = 'dashboard'): string {
        // If Core exposes helpers later, we can use them. For now: try page by slug, else home.
        $page = get_page_by_path($fallback_slug);
        if ($page) return get_permalink($page);
        return home_url('/');
    }


public static function get_cv_page_url(): string {
    // 1) Nieuw: page_id wordt door de addon zelf beheerd
    if (function_exists('bp_core_addon_page_url')) {
        $url = (string) bp_core_addon_page_url('bp_addon_cv_page_id');
        if ($url !== '') return $url;
    }

    $cached = (int) get_option('bp_addon_cv_page_id', 0);
    if ($cached > 0) {
        $p = get_post($cached);
        if ($p && $p->post_status === 'publish') {
            return get_permalink($cached) ?: '';
        }
    }

    // 2) Fallback: zoek een pagina met de shortcode [bp_cv]
    $q = new \WP_Query([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        's'              => '[bp_cv',
        'fields'         => 'ids',
    ]);
    if ($q->have_posts()) {
        $pid = (int) $q->posts[0];
        if ($pid > 0) {
            update_option('bp_addon_cv_page_id', $pid, false);
            return get_permalink($pid) ?: '';
        }
    }

    // 3) Oude fallback
    $page = get_page_by_path('cv');
    if ($page) return get_permalink($page);
    return '';
}

    public static function get_logout_url(): string {
        // Send user back to dashboard if possible.
        $dash = self::get_portal_url('dashboard');
        return wp_logout_url($dash);
    }

    // v3-compatible storage: wp_uploads/kb-cv + table {prefix}kb_cv
    public static function upload_dir(): string {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'kb-cv/';
    }

    public static function ensure_upload_dir(): void {
        $dir = self::upload_dir();
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // Extra privacy: voorkom directory listing / directe toegang waar mogelijk.
        $index = $dir . 'index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }

        $ht = $dir . '.htaccess';
        if (!file_exists($ht)) {
            $rules = "<IfModule mod_authz_core.c>
Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
Deny from all
</IfModule>
";
            @file_put_contents($ht, $rules);
        }
    }

    public static function kb_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'kb_cv';
    }

    public static function ensure_kb_table(): void {
        global $wpdb;
        $table = self::kb_table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            bestandsnaam TEXT NOT NULL,
            pad TEXT NOT NULL,
            tekst LONGTEXT NULL,
            samenvatting LONGTEXT NULL,
            verbeterpunten LONGTEXT NULL,
            beroepen_suggesties LONGTEXT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id)
        ) $charset;";
        dbDelta($sql);
    }

    public static function get_kb_cv_row(int $user_id): ?array {
        global $wpdb;
        $table = self::kb_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE client_id=%d LIMIT 1", $user_id), ARRAY_A);
        return $row ?: null;
    }

    public static function user_has_cv(int $user_id): bool {
        $row = self::get_kb_cv_row($user_id);
        return !empty($row) && !empty($row['pad']);
    }

    public static function upsert_kb_cv(int $user_id, string $bestandsnaam, string $pad, string $tekst = ''): bool {
        self::ensure_upload_dir();
        self::ensure_kb_table();
        global $wpdb;
        $table = self::kb_table();
        return (bool) $wpdb->replace($table, [
            'client_id'           => (int)$user_id,
            'bestandsnaam'        => $bestandsnaam,
            'pad'                 => $pad,
            'tekst'               => $tekst,
            'samenvatting'        => '',
            'verbeterpunten'      => '',
            'beroepen_suggesties' => '',
        ], ['%d','%s','%s','%s','%s','%s','%s']);
    }

    public static function delete_kb_cv(int $user_id): void {
        global $wpdb;
        $table = self::kb_table();
        $row = self::get_kb_cv_row($user_id);
        if ($row && !empty($row['pad']) && file_exists($row['pad'])) {
            @unlink($row['pad']);
        }
        $wpdb->delete($table, ['client_id' => (int)$user_id], ['%d']);
    }

    public static function allowed_mimes(): array {
        // Extra streng: alleen PDF en DOCX.
        return [
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    public static function max_bytes(): int {
        return 5 * 1024 * 1024;
    }

    public static function sanitize_filename(string $name): string {
        $name = wp_strip_all_tags($name);
        $name = preg_replace('/[^a-zA-Z0-9\.\-\_\(\)\s]/', '', $name);
        $name = trim($name);
        if ($name === '') $name = 'cv';
        return $name;
    }

    public static function begeleider_can_access_client(int $begeleider_id, int $client_id): bool {
        if ($begeleider_id <= 0 || $client_id <= 0) return false;
        $linked = (int) get_user_meta($client_id, 'kb_begeleider_id', true);
        return $linked > 0 && $linked === $begeleider_id;
    }

    public static function can_download_cv(int $requester_id, int $owner_id): bool {
        if ($requester_id <= 0 || $owner_id <= 0) return false;
        if ($requester_id === $owner_id) return true;

        // Begeleider route
        if (self::begeleider_can_access_client($requester_id, $owner_id)) return true;

        // Admins can, too.
        if (user_can($requester_id, 'manage_options')) return true;

        return false;
    }

    public static function enqueue_assets(): void {
        wp_enqueue_style('bp-cv', BP_CV_URL . 'assets/css/cv.css', [], BP_CV_VERSION);
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
            wp_add_inline_style('bp-cv', $css);
        }
        wp_enqueue_script('bp-cv-pdf', BP_CV_URL . 'assets/js/cv-pdf.js', [], BP_CV_VERSION, true);
        wp_enqueue_script('bp-cv', BP_CV_URL . 'assets/js/cv.js', ['jquery', 'bp-cv-pdf'], BP_CV_VERSION, true);
        wp_localize_script('bp-cv', 'BPCV', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);

        if (defined('BP_CORE_URL') && defined('BP_CORE_VERSION')) {
            $guard_cfg = [
                'scopeSelector' => '.bp-cv-wrap',
                'blurSelectors' => implode(',', [
                    'input[type="text"]',
                    'input[type="file"]',
                    'textarea',
                    'button',
                    'a.bp-btn',
                ]),
                'redactSelectors' => implode(',', [
                    '.bp-cv-file-name',
                    '.bp-cv-file-date',
                    '.bp-cv-empty',
                    '.bp-alert',
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
