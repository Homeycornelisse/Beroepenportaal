<?php
namespace BP_Berichten;

defined('ABSPATH') || exit;

final class Actions {

    public static function init(): void {
        add_filter('bp_core_disable_builtin_berichten_actions', '__return_true');

        add_action('admin_post_bp_stuur_bericht', [__CLASS__, 'stuur_bericht'], 1);
        add_action('admin_post_bp_markeer_gelezen', [__CLASS__, 'markeer_gelezen'], 1);
        add_action('admin_post_bp_verwijder_bericht', [__CLASS__, 'verwijder_bericht'], 1);
        add_action('admin_post_bp_verwijder_gesprek', [__CLASS__, 'verwijder_gesprek'], 1);
        add_action('admin_post_bp_undo_verwijderen', [__CLASS__, 'undo_verwijderen'], 1);
        add_action('admin_post_bp_add_contact', [__CLASS__, 'add_contact'], 1);
        add_action('admin_post_bp_remove_contact', [__CLASS__, 'remove_contact'], 1);
        add_action('admin_post_bp_categoriseer_bericht', [__CLASS__, 'categoriseer_bericht'], 1);
        add_action('admin_post_bp_e2e_public_key', [__CLASS__, 'save_public_key'], 1);
        add_action('admin_post_bp_e2e_settings', [__CLASS__, 'save_e2e_settings'], 1);
        add_action('admin_post_bp_contact_qr', [__CLASS__, 'contact_qr'], 1);
    }

    public static function stuur_bericht(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();

        $naar_id = (int) ($_POST['naar_id'] ?? 0);
        if ($naar_id <= 0) wp_die('Ongeldig verzoek.');

        check_admin_referer('bp_stuur_bericht', 'bp_bericht_nonce');
        $me = wp_get_current_user();
        if (!$me || !$me->ID) wp_die('Niet ingelogd.');

        if (!class_exists('BP_Core_Berichten') || !\BP_Core_Berichten::mag_sturen_naar((int) $me->ID, $naar_id)) {
            wp_die('U mag geen berichten sturen naar deze gebruiker.');
        }

        $onderwerp = sanitize_text_field((string) ($_POST['onderwerp'] ?? ''));
        $inhoud = (string) ($_POST['inhoud'] ?? '');
        $inhoud = wp_unslash($inhoud);

        if ($inhoud === '') {
            $ref = wp_get_referer() ?: home_url('/');
            wp_safe_redirect(add_query_arg('bp_bericht_fout', '1', $ref));
            exit;
        }

        if (self::is_valid_e2e_payload($inhoud)) {
            // Basiscontrole payload-structuur en sleutelkoppeling.
            $payload = self::parse_e2e_payload($inhoud);
            if (!$payload) {
                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_bericht_e2e', 'invalid_payload', $ref));
                exit;
            }
            $my_id = (int) $me->ID;
            if (empty($payload['keys'][(string) $my_id]) || empty($payload['keys'][(string) $naar_id])) {
                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_bericht_e2e', 'missing_keys', $ref));
                exit;
            }

            $sender_expected_fp = is_array($payload['keyfps'] ?? null) ? (string) ($payload['keyfps'][(string) $my_id] ?? '') : '';
            $recipient_expected_fp = is_array($payload['keyfps'] ?? null) ? (string) ($payload['keyfps'][(string) $naar_id] ?? '') : '';
            if (!self::is_valid_fingerprint($sender_expected_fp) || !self::is_valid_fingerprint($recipient_expected_fp)) {
                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_bericht_e2e', 'missing_fingerprint', $ref));
                exit;
            }

            // Vingerafdrukcheck: elk uitgaand bericht moet matchen met de DB-hash (MITM-bescherming).
            if (!self::verify_public_key_fingerprint($my_id, $sender_expected_fp) || !self::verify_public_key_fingerprint($naar_id, $recipient_expected_fp)) {
                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_bericht_e2e', 'fingerprint', $ref));
                exit;
            }
        }

        $result = \BP_Core_Berichten::stuur((int) $me->ID, $naar_id, 'bericht', $onderwerp, $inhoud);
        if ($result === -1) {
            $ref = wp_get_referer() ?: home_url('/');
            wp_safe_redirect(add_query_arg('bp_bericht_ratelimit', '1', $ref));
            exit;
        }

        if ($result > 0) {
            \BP_Core_Berichten::stuur_email_notificatie($naar_id, 'Nieuw bericht', 'Je hebt een nieuw beveiligd bericht ontvangen.');
        }

        $ref = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('bp_bericht_verzonden', '1', $ref));
        exit;
    }

    public static function markeer_gelezen(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();
        $bericht_id = (int) ($_POST['bericht_id'] ?? 0);
        check_admin_referer('bp_markeer_gelezen_' . $bericht_id, 'bp_gelezen_nonce');
        if ($bericht_id > 0 && class_exists('BP_Core_Berichten')) {
            \BP_Core_Berichten::markeer_gelezen($bericht_id, get_current_user_id());
        }
        $ref = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('bp_tab', 'inbox', $ref));
        exit;
    }

    public static function verwijder_bericht(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();
        $bericht_id = (int) ($_POST['bericht_id'] ?? 0);
        if ($bericht_id <= 0) wp_die('Ongeldig verzoek.');
        check_admin_referer('bp_verwijder_bericht_' . $bericht_id, 'bp_verwijder_nonce');
        global $wpdb;
        $me = (int) get_current_user_id();
        $undo_token = '';
        if (class_exists('BP_Core_Berichten')) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT van_id, naar_id, type, client_id, onderwerp, inhoud, status, gelezen, categorie, aangemaakt, bijgewerkt
                 FROM {$wpdb->prefix}kb_berichten
                 WHERE id = %d AND (naar_id = %d OR van_id = %d)
                 LIMIT 1",
                $bericht_id,
                $me,
                $me
            ), ARRAY_A);
            \BP_Core_Berichten::verwijder($bericht_id, $me);
            $undo_token = self::store_message_undo_payload($me, $row ? [$row] : []);
        }
        $ref = wp_get_referer() ?: home_url('/');
        $args = ['bp_bericht_verwijderd' => '1'];
        if ($undo_token !== '') {
            $args['bp_undo'] = $undo_token;
            $args['bp_undo_kind'] = 'bericht';
        }
        wp_safe_redirect(add_query_arg($args, $ref));
        exit;
    }

    public static function verwijder_gesprek(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();
        $other_id = (int) ($_POST['other_user_id'] ?? 0);
        if ($other_id <= 0) wp_die('Ongeldig verzoek.');
        check_admin_referer('bp_verwijder_gesprek_' . $other_id, 'bp_verwijder_gesprek_nonce');
        global $wpdb;
        $me = (int) get_current_user_id();
        $rows = [];
        if (class_exists('BP_Core_Berichten')) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT van_id, naar_id, type, client_id, onderwerp, inhoud, status, gelezen, categorie, aangemaakt, bijgewerkt
                 FROM {$wpdb->prefix}kb_berichten
                 WHERE ((van_id = %d AND naar_id = %d) OR (van_id = %d AND naar_id = %d))",
                $me,
                $other_id,
                $other_id,
                $me
            ), ARRAY_A);
            \BP_Core_Berichten::verwijder_gesprek($me, $other_id);
        }
        $undo_token = self::store_message_undo_payload($me, is_array($rows) ? $rows : []);
        $ref = wp_get_referer() ?: home_url('/');
        $args = ['bp_gesprek_verwijderd' => '1'];
        if ($undo_token !== '') {
            $args['bp_undo'] = $undo_token;
            $args['bp_undo_kind'] = 'gesprek';
        }
        wp_safe_redirect(add_query_arg($args, $ref));
        exit;
    }

    public static function undo_verwijderen(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();
        check_admin_referer('bp_undo_verwijderen', 'bp_undo_nonce');
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['bp_undo'] ?? ''));
        $me = (int) get_current_user_id();
        $ok = self::restore_message_undo_payload($me, $token);
        $ref = wp_get_referer() ?: home_url('/');
        $args = $ok ? ['bp_undo_done' => '1'] : ['bp_undo_expired' => '1'];
        wp_safe_redirect(add_query_arg($args, $ref));
        exit;
    }

    public static function add_contact(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();
        check_admin_referer('bp_add_contact', 'bp_contact_nonce');
        $contact_id = (int) ($_POST['contact_id'] ?? 0);
        $contact_code = strtoupper((string) ($_POST['contact_code'] ?? ''));
        $contact_phone = (string) ($_POST['contact_phone'] ?? '');
        $me = (int) get_current_user_id();
        $error = '';
        if (class_exists('BP_Core_Berichten')) {
            if ($contact_id <= 0 && $contact_code !== '') {
                $contact_id = \BP_Core_Berichten::find_user_by_contact_code($contact_code);
            }
            if ($contact_id <= 0 && trim($contact_phone) !== '') {
                $contact_id = \BP_Core_Berichten::find_user_by_phone($contact_phone);
            }
            if ($contact_id <= 0) {
                $error = 'not_found';
            } elseif ((int) $contact_id === $me) {
                $error = 'self';
            } elseif (!\BP_Core_Berichten::can_add_contact($me, $contact_id)) {
                $error = 'not_allowed';
            } elseif (!\BP_Core_Berichten::add_contact($me, $contact_id)) {
                $error = 'failed';
            }
        }
        $ref = wp_get_referer() ?: home_url('/');
        if ($error === '') {
            $inbox_page_id = (int) get_option('bp_addon_berichten_page_id', 0);
            $base_url = ($inbox_page_id > 0) ? (string) get_permalink($inbox_page_id) : $ref;
            if ($base_url === '') $base_url = $ref;
            $args = [
                'bp_contact_added' => '1',
                'thread' => (int) $contact_id,
                'to' => (int) $contact_id,
            ];
            wp_safe_redirect(add_query_arg($args, remove_query_arg('view', $base_url)));
        } else {
            wp_safe_redirect(add_query_arg('bp_contact_error', $error, $ref));
        }
        exit;
    }

    public static function remove_contact(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();
        check_admin_referer('bp_remove_contact', 'bp_contact_nonce');
        $contact_id = (int) ($_POST['contact_id'] ?? 0);
        $me = (int) get_current_user_id();
        $ok = false;
        if (class_exists('BP_Core_Berichten')) {
            $ok = \BP_Core_Berichten::remove_contact($me, $contact_id);
        }
        $ref = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg($ok ? 'bp_contact_removed' : 'bp_contact_error', '1', $ref));
        exit;
    }

    public static function categoriseer_bericht(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();
        $bericht_id = (int) ($_POST['bericht_id'] ?? 0);
        if ($bericht_id <= 0) wp_die('Ongeldig verzoek.');
        check_admin_referer('bp_categoriseer_bericht_' . $bericht_id, 'bp_categoriseer_nonce');
        $categorie = sanitize_key((string) ($_POST['categorie'] ?? ''));
        if (class_exists('BP_Core_Berichten')) {
            \BP_Core_Berichten::stel_categorie_in($bericht_id, get_current_user_id(), $categorie);
        }
        $ref = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('bp_gecategoriseerd', '1', $ref));
        exit;
    }

    public static function save_public_key(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();
        check_admin_referer('bp_e2e_public_key', 'bp_e2e_nonce');
        $pub = (string) ($_POST['public_key'] ?? '');
        $pub = wp_unslash($pub);
        $pub = trim($pub);
        if ($pub === '' || strlen($pub) > 25000) {
            wp_send_json_error(['message' => 'Ongeldige publieke sleutel.'], 400);
        }

        $json = json_decode($pub, true);
        if (!is_array($json) || empty($json['kty'])) {
            wp_send_json_error(['message' => 'Sleutel is geen geldig JWK-formaat.'], 400);
        }

        $canonical = wp_json_encode($json);
        if (!is_string($canonical) || $canonical === '') {
            wp_send_json_error(['message' => 'Sleutel kon niet worden opgeslagen.'], 400);
        }
        update_user_meta(get_current_user_id(), 'bp_msg_e2e_public_jwk', $canonical);
        update_user_meta(get_current_user_id(), 'bp_msg_e2e_public_jwk_fp', hash('sha256', $canonical));
        wp_send_json_success(['ok' => 1]);
    }

    public static function save_e2e_settings(): void {
        if (!is_user_logged_in()) wp_die('Niet ingelogd.');
        self::require_post_request();
        check_admin_referer('bp_e2e_settings', 'bp_e2e_settings_nonce');

        $days = (int) ($_POST['rotation_days'] ?? 90);
        $days = max(7, min(365, $days));
        update_user_meta(get_current_user_id(), 'bp_msg_e2e_rotation_days', $days);

        $ref = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('bp_e2e_settings_saved', '1', $ref));
        exit;
    }

    public static function contact_qr(): void {
        if (!is_user_logged_in()) {
            wp_die('Niet ingelogd.');
        }
        $nonce = isset($_GET['bp_qr_nonce']) ? sanitize_text_field((string) wp_unslash($_GET['bp_qr_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'bp_contact_qr')) {
            wp_die('Ongeldige QR nonce.');
        }
        if (!class_exists('BP_Core_Berichten')) {
            wp_die('Berichten niet beschikbaar.');
        }

        $uid = (int) get_current_user_id();
        $code = (string) \BP_Core_Berichten::get_or_create_contact_code($uid);
        if ($code === '') {
            wp_die('Geen contactcode beschikbaar.');
        }

        $payload = wp_json_encode([
            'type' => 'bp_contact_code',
            'code' => $code,
            'site' => home_url('/'),
        ]);
        if (!is_string($payload) || $payload === '') {
            wp_die('Kon QR payload niet opbouwen.');
        }

        $remote_urls = [
            'https://quickchart.io/qr?size=180&margin=0&text=' . rawurlencode($payload),
            'https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=0&data=' . rawurlencode($payload),
            'https://chart.googleapis.com/chart?cht=qr&chs=180x180&chl=' . rawurlencode($payload),
        ];

        foreach ($remote_urls as $url) {
            $res = wp_remote_get($url, [
                'timeout' => 8,
                'redirection' => 2,
                'sslverify' => true,
            ]);
            if (is_wp_error($res)) continue;
            $code_http = (int) wp_remote_retrieve_response_code($res);
            $body = (string) wp_remote_retrieve_body($res);
            $ctype = (string) wp_remote_retrieve_header($res, 'content-type');
            if ($code_http !== 200 || $body === '') continue;
            if (stripos($ctype, 'image/') !== 0) continue;

            nocache_headers();
            header('Content-Type: ' . $ctype);
            header('Content-Length: ' . strlen($body));
            header('X-Content-Type-Options: nosniff');
            echo $body;
            exit;
        }

        // Fallback als externe QR-service niet bereikbaar is.
        nocache_headers();
        header('Content-Type: image/svg+xml; charset=UTF-8');
        $safe_code = esc_html($code);
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180">'
            . '<rect width="180" height="180" fill="#ffffff"/>'
            . '<rect x="8" y="8" width="164" height="164" fill="#f8fafc" stroke="#cbd5e1"/>'
            . '<text x="90" y="78" text-anchor="middle" font-size="12" fill="#0f2f67" font-family="Arial, sans-serif">QR niet beschikbaar</text>'
            . '<text x="90" y="100" text-anchor="middle" font-size="11" fill="#334155" font-family="Arial, sans-serif">' . $safe_code . '</text>'
            . '</svg>';
        exit;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function store_message_undo_payload(int $user_id, array $rows): string {
        if ($user_id <= 0 || empty($rows)) return '';
        $clean_rows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $clean_rows[] = [
                'van_id' => (int) ($row['van_id'] ?? 0),
                'naar_id' => (int) ($row['naar_id'] ?? 0),
                'type' => sanitize_key((string) ($row['type'] ?? 'bericht')),
                'client_id' => !isset($row['client_id']) || $row['client_id'] === null ? null : (int) $row['client_id'],
                'onderwerp' => (string) ($row['onderwerp'] ?? ''),
                'inhoud' => (string) ($row['inhoud'] ?? ''),
                'status' => sanitize_key((string) ($row['status'] ?? 'pending')),
                'gelezen' => (int) ($row['gelezen'] ?? 0),
                'categorie' => sanitize_key((string) ($row['categorie'] ?? '')),
                'aangemaakt' => (string) ($row['aangemaakt'] ?? current_time('mysql')),
                'bijgewerkt' => !empty($row['bijgewerkt']) ? (string) $row['bijgewerkt'] : null,
            ];
        }
        if (empty($clean_rows)) return '';
        $token = wp_generate_password(20, false, false);
        $key = 'bp_msg_undo_' . $user_id . '_' . $token;
        set_transient($key, $clean_rows, 10);
        return $token;
    }

    private static function restore_message_undo_payload(int $user_id, string $token): bool {
        global $wpdb;
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token);
        if ($user_id <= 0 || $token === '') return false;
        $key = 'bp_msg_undo_' . $user_id . '_' . $token;
        $rows = get_transient($key);
        if (!is_array($rows) || empty($rows)) return false;
        $table = $wpdb->prefix . 'kb_berichten';
        $restored = false;

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $ok = $wpdb->insert($table, [
                'van_id' => (int) ($row['van_id'] ?? 0),
                'naar_id' => (int) ($row['naar_id'] ?? 0),
                'type' => sanitize_key((string) ($row['type'] ?? 'bericht')),
                'client_id' => isset($row['client_id']) ? (int) $row['client_id'] : null,
                'onderwerp' => (string) ($row['onderwerp'] ?? ''),
                'inhoud' => (string) ($row['inhoud'] ?? ''),
                'status' => sanitize_key((string) ($row['status'] ?? 'pending')),
                'gelezen' => (int) ($row['gelezen'] ?? 0),
                'categorie' => sanitize_key((string) ($row['categorie'] ?? '')),
                'aangemaakt' => (string) ($row['aangemaakt'] ?? current_time('mysql')),
                'bijgewerkt' => !empty($row['bijgewerkt']) ? (string) $row['bijgewerkt'] : null,
            ]);
            if ($ok !== false) $restored = true;
        }
        delete_transient($key);
        return $restored;
    }

    private static function is_valid_e2e_payload(string $payload): bool {
        if ($payload === '') return false;
        if (strlen($payload) > 200000) return false;
        if (!preg_match('/\Ae2e:v1:[A-Za-z0-9+\/]+={0,2}\z/', $payload)) return false;
        $b64 = substr($payload, 7);
        if ($b64 === '' || strlen($b64) % 4 !== 0) return false;
        $decoded = base64_decode($b64, true);
        if (!is_string($decoded) || $decoded === '') return false;
        if (!hash_equals(base64_encode($decoded), $b64)) return false;
        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function parse_e2e_payload(string $payload): ?array {
        if (!self::is_valid_e2e_payload($payload)) return null;
        $decoded = base64_decode(substr($payload, 7), true);
        if (!is_string($decoded) || $decoded === '') return null;
        $json = json_decode($decoded, true);
        if (!is_array($json)) return null;
        if (empty($json['v']) || empty($json['ct']) || empty($json['iv']) || empty($json['keys']) || !is_array($json['keys'])) return null;
        if (!isset($json['keyfps']) || !is_array($json['keyfps'])) return null;
        if (!is_string($json['ct']) || !preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $json['ct'])) return null;
        if (!is_string($json['iv']) || !preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $json['iv'])) return null;
        foreach ($json['keys'] as $uid => $wrapped) {
            if (!preg_match('/\A\d+\z/', (string) $uid)) return null;
            if (!is_string($wrapped) || !preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $wrapped)) return null;
        }
        foreach ($json['keyfps'] as $uid => $fp) {
            if (!preg_match('/\A\d+\z/', (string) $uid)) return null;
            if (!self::is_valid_fingerprint((string) $fp)) return null;
        }
        return $json;
    }

    private static function verify_public_key_fingerprint(int $user_id, string $expected_fp = ''): bool {
        if ($user_id <= 0) return false;
        $stored_key = (string) get_user_meta($user_id, 'bp_msg_e2e_public_jwk', true);
        $stored_fp = (string) get_user_meta($user_id, 'bp_msg_e2e_public_jwk_fp', true);
        if ($stored_key === '' || $stored_fp === '') return false;
        if (!self::is_valid_fingerprint($stored_fp)) return false;

        $decoded = json_decode($stored_key, true);
        if (!is_array($decoded) || empty($decoded['kty'])) return false;
        $canonical = wp_json_encode($decoded);
        if (!is_string($canonical) || $canonical === '') return false;
        $actual_fp = strtolower(hash('sha256', $canonical));
        $stored_fp = strtolower($stored_fp);

        if (!hash_equals($stored_fp, $actual_fp)) return false;
        if ($expected_fp !== '') {
            $expected_fp = strtolower(trim($expected_fp));
            if (!self::is_valid_fingerprint($expected_fp)) return false;
            if (!hash_equals($expected_fp, $actual_fp)) return false;
        }

        return true;
    }

    private static function is_valid_fingerprint(string $fp): bool {
        return (bool) preg_match('/\A[a-f0-9]{64}\z/i', trim($fp));
    }

    private static function require_post_request(): void {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            wp_die('Ongeldige methode.', 'Method Not Allowed', ['response' => 405]);
        }
    }
}
