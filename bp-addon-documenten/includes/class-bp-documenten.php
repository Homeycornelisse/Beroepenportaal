<?php
namespace BP_Documenten;

defined('ABSPATH') || exit;

final class Documenten {
    private const FOLDER_TABLE = 'bp_docs_folders';
    private const ITEM_TABLE = 'bp_docs_items';
    private const E2E_PREFIX = 'e2e:v1:';
    private const MAX_PAYLOAD_LEN = 16_000_000;

    public static function init(): void {
        add_shortcode('bp_documenten', [__CLASS__, 'render_shortcode']);

        add_action('wp_ajax_bp_docs_bootstrap', [__CLASS__, 'ajax_bootstrap']);
        add_action('wp_ajax_bp_docs_e2e_public_key', [__CLASS__, 'ajax_save_public_key']);
        add_action('wp_ajax_bp_docs_verify_account_password', [__CLASS__, 'ajax_verify_account_password']);
        add_action('wp_ajax_bp_docs_create_folder', [__CLASS__, 'ajax_create_folder']);
        add_action('wp_ajax_bp_docs_delete_folder', [__CLASS__, 'ajax_delete_folder']);
        add_action('wp_ajax_bp_docs_upload_document', [__CLASS__, 'ajax_upload_document']);
        add_action('wp_ajax_bp_docs_delete_document', [__CLASS__, 'ajax_delete_document']);
        add_action('wp_ajax_bp_docs_get_document_payload', [__CLASS__, 'ajax_get_document_payload']);
    }

    public static function page_url(): string {
        $pid = (int) get_option('bp_addon_documenten_page_id', 0);
        return $pid > 0 ? (string) get_permalink($pid) : '';
    }

    public static function page_url_for_client(int $client_id = 0, string $folder = ''): string {
        $url = self::page_url();
        if ($url === '') return '';
        $args = [];
        if ($client_id > 0) $args['client_id'] = (int) $client_id;
        if ($folder !== '') $args['folder'] = sanitize_key($folder);
        return !empty($args) ? (string) add_query_arg($args, $url) : $url;
    }

    public static function render_shortcode(): string {
        if (!is_user_logged_in()) {
            return '<div class="bp-docs-notice">Je moet ingelogd zijn om de documentenkluis te openen.</div>';
        }

        $uid = (int) get_current_user_id();
        if (function_exists('bp_core_user_can_use_addon') && !bp_core_user_can_use_addon('documenten', $uid)) {
            return '<div class="bp-docs-notice">Je hebt geen toegang tot de documentenkluis add-on.</div>';
        }

        ob_start();
        include BP_DOCS_DIR . 'templates/documenten.php';
        return (string) ob_get_clean();
    }

    public static function ensure_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $folders = self::folder_table();
        $items = self::item_table();

        $sql_folders = "CREATE TABLE {$folders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            name_payload LONGTEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_client (client_id),
            KEY idx_sort (client_id, sort_order)
        ) {$charset};";

        $sql_items = "CREATE TABLE {$items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            folder_id BIGINT UNSIGNED NULL,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            meta_payload LONGTEXT NOT NULL,
            file_payload LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_client (client_id),
            KEY idx_folder (folder_id),
            KEY idx_created (client_id, created_at)
        ) {$charset};";

        dbDelta($sql_folders);
        dbDelta($sql_items);
    }

    public static function ensure_caps(): void {
        $cap = 'kb_use_documenten';
        foreach (['kb_client', 'kb_begeleider', 'kb_leidinggevende', 'administrator'] as $role_key) {
            $role = get_role($role_key);
            if ($role && !$role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }
    }

    public static function ajax_bootstrap(): void {
        $uid = self::require_auth_or_die();
        self::verify_nonce_or_die();

        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $allowed_clients = self::allowed_clients_for_user($uid);
        if (empty($allowed_clients)) {
            wp_send_json_error(['message' => 'Geen cliënten beschikbaar.'], 403);
        }

        if ($client_id <= 0 || !isset($allowed_clients[$client_id])) {
            $client_id = (int) array_key_first($allowed_clients);
        }

        $payload = self::build_state_payload($uid, $client_id, $allowed_clients);
        wp_send_json_success($payload);
    }

    public static function ajax_create_folder(): void {
        global $wpdb;

        $uid = self::require_auth_or_die();
        self::verify_nonce_or_die();

        $client_id = (int) ($_POST['client_id'] ?? 0);
        $name_payload = isset($_POST['name_payload']) ? (string) wp_unslash($_POST['name_payload']) : '';

        self::assert_client_access($uid, $client_id);
        if (!self::is_valid_e2e_payload($name_payload)) {
            wp_send_json_error(['message' => 'Ongeldige map payload.'], 400);
        }

        $sort_order = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(MAX(sort_order),0)+1 FROM ' . self::folder_table() . ' WHERE client_id = %d',
            $client_id
        ));

        $ok = $wpdb->insert(
            self::folder_table(),
            [
                'client_id' => $client_id,
                'created_by' => $uid,
                'name_payload' => $name_payload,
                'sort_order' => $sort_order,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%d', '%s']
        );

        if ($ok === false) {
            wp_send_json_error(['message' => 'Map kon niet worden opgeslagen.'], 500);
        }

        wp_send_json_success(['id' => (int) $wpdb->insert_id]);
    }

    public static function ajax_save_public_key(): void {
        $uid = self::require_auth_or_die();
        self::verify_nonce_or_die();

        $raw = isset($_POST['public_key']) ? (string) wp_unslash($_POST['public_key']) : '';
        $canonical = self::canonicalize_public_jwk($raw);
        if ($canonical === '') {
            wp_send_json_error(['message' => 'Ongeldige publieke sleutel.'], 400);
        }

        update_user_meta($uid, 'bp_docs_e2e_public_jwk', $canonical);
        update_user_meta($uid, 'bp_docs_e2e_public_jwk_fp', hash('sha256', $canonical));

        wp_send_json_success(['ok' => 1]);
    }

    public static function ajax_verify_account_password(): void {
        $uid = self::require_auth_or_die();
        self::verify_nonce_or_die();

        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ($password === '') {
            wp_send_json_error(['message' => 'Wachtwoord ontbreekt.'], 400);
        }

        $user = get_user_by('id', $uid);
        if (!$user || !wp_check_password($password, (string) $user->user_pass, $uid)) {
            wp_send_json_error(['message' => 'Wachtwoord is onjuist.'], 403);
        }

        wp_send_json_success(['ok' => 1]);
    }

    public static function ajax_delete_folder(): void {
        global $wpdb;

        $uid = self::require_auth_or_die();
        self::verify_nonce_or_die();

        $folder_id = (int) ($_POST['folder_id'] ?? 0);
        if ($folder_id <= 0) wp_send_json_error(['message' => 'Ongeldige map.'], 400);

        $folder = self::get_folder($folder_id);
        if (!$folder) wp_send_json_error(['message' => 'Map niet gevonden.'], 404);

        self::assert_client_access($uid, (int) $folder['client_id']);

        $wpdb->query($wpdb->prepare('UPDATE ' . self::item_table() . ' SET folder_id = NULL, updated_at = %s WHERE folder_id = %d', current_time('mysql'), $folder_id));
        $deleted = $wpdb->delete(self::folder_table(), ['id' => $folder_id], ['%d']);

        if ($deleted === false) {
            wp_send_json_error(['message' => 'Map kon niet worden verwijderd.'], 500);
        }

        wp_send_json_success(['ok' => 1]);
    }

    public static function ajax_upload_document(): void {
        global $wpdb;

        $uid = self::require_auth_or_die();
        self::verify_nonce_or_die();

        $client_id = (int) ($_POST['client_id'] ?? 0);
        $folder_id = (int) ($_POST['folder_id'] ?? 0);
        $meta_payload = isset($_POST['meta_payload']) ? (string) wp_unslash($_POST['meta_payload']) : '';
        $file_payload = isset($_POST['file_payload']) ? (string) wp_unslash($_POST['file_payload']) : '';

        self::assert_client_access($uid, $client_id);

        if ($folder_id > 0) {
            $folder = self::get_folder($folder_id);
            if (!$folder || (int) $folder['client_id'] !== $client_id) {
                wp_send_json_error(['message' => 'Map bestaat niet voor deze cliënt.'], 400);
            }
        } else {
            $folder_id = 0;
        }

        if (!self::is_valid_e2e_payload($meta_payload) || !self::is_valid_e2e_payload($file_payload)) {
            wp_send_json_error(['message' => 'Ongeldige encryptie payload.'], 400);
        }

        $insert_data = [
            'client_id' => $client_id,
            'uploaded_by' => $uid,
            'meta_payload' => $meta_payload,
            'file_payload' => $file_payload,
            'created_at' => current_time('mysql'),
        ];
        $insert_formats = ['%d', '%d', '%s', '%s', '%s'];

        if ($folder_id > 0) {
            $insert_data['folder_id'] = $folder_id;
            $insert_formats[] = '%d';
        }

        $ok = $wpdb->insert(self::item_table(), $insert_data, $insert_formats);

        if ($ok === false) {
            wp_send_json_error(['message' => 'Document kon niet worden opgeslagen.'], 500);
        }

        wp_send_json_success(['id' => (int) $wpdb->insert_id]);
    }

    public static function ajax_delete_document(): void {
        global $wpdb;

        $uid = self::require_auth_or_die();
        self::verify_nonce_or_die();

        $doc_id = (int) ($_POST['doc_id'] ?? 0);
        if ($doc_id <= 0) {
            wp_send_json_error(['message' => 'Extern document kan hier niet worden verwijderd. Ververs de pagina.'], 400);
        }

        $doc = self::get_document($doc_id);
        if (!$doc) wp_send_json_error(['message' => 'Document niet gevonden.'], 404);

        self::assert_client_access($uid, (int) $doc['client_id']);

        $deleted = $wpdb->delete(self::item_table(), ['id' => $doc_id], ['%d']);
        if ($deleted === false) {
            wp_send_json_error(['message' => 'Document kon niet worden verwijderd.'], 500);
        }

        wp_send_json_success(['ok' => 1]);
    }

    public static function ajax_get_document_payload(): void {
        $uid = self::require_auth_or_die();
        self::verify_nonce_or_die();

        $doc_id = (int) ($_POST['doc_id'] ?? 0);
        if ($doc_id <= 0) {
            wp_send_json_error(['message' => 'Extern document kan direct worden gedownload. Ververs de pagina.'], 400);
        }

        $doc = self::get_document($doc_id);
        if (!$doc) wp_send_json_error(['message' => 'Document niet gevonden.'], 404);

        self::assert_client_access($uid, (int) $doc['client_id']);

        wp_send_json_success([
            'id' => (int) $doc['id'],
            'filePayload' => (string) $doc['file_payload'],
            'metaPayload' => (string) $doc['meta_payload'],
        ]);
    }

    private static function build_state_payload(int $uid, int $client_id, array $allowed_clients): array {
        global $wpdb;

        $folders = $wpdb->get_results($wpdb->prepare(
            'SELECT id, client_id, name_payload, sort_order, created_by, created_at, updated_at
             FROM ' . self::folder_table() . ' WHERE client_id = %d ORDER BY sort_order ASC, id ASC',
            $client_id
        ), ARRAY_A);

        $docs = $wpdb->get_results($wpdb->prepare(
            'SELECT id, client_id, folder_id, uploaded_by, meta_payload, created_at, updated_at
             FROM ' . self::item_table() . ' WHERE client_id = %d ORDER BY created_at DESC, id DESC',
            $client_id
        ), ARRAY_A);

        $cv_bridge = self::cv_bridge_item($uid, $client_id);
        if (is_array($cv_bridge)) {
            array_unshift($folders, [
                'id' => -1,
                'client_id' => $client_id,
                'name_payload' => '',
                'sort_order' => -9999,
                'created_by' => $uid,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'virtual' => 1,
                'plain_name' => 'Curriculum Vitae',
            ]);

            $docs[] = [
                'id' => -1000 - (int) $client_id,
                'client_id' => $client_id,
                'folder_id' => -1,
                'uploaded_by' => (int) ($cv_bridge['uploaded_by'] ?? 0),
                'meta_payload' => '',
                'created_at' => (string) ($cv_bridge['created_at'] ?? current_time('mysql')),
                'updated_at' => (string) ($cv_bridge['updated_at'] ?? current_time('mysql')),
                'is_external' => 1,
                'plain_name' => (string) ($cv_bridge['name'] ?? 'CV'),
                'plain_size' => (int) ($cv_bridge['size'] ?? 0),
                'download_url' => (string) ($cv_bridge['download_url'] ?? ''),
            ];
        }

        $external_context = [
            'viewer_id' => $uid,
            'client_id' => $client_id,
        ];

        $external_folders = apply_filters('bp_docs_external_folders', [], $external_context);
        if (is_array($external_folders)) {
            foreach ($external_folders as $folder_row) {
                if (!is_array($folder_row)) continue;
                $folders[] = $folder_row;
            }
        }

        $external_docs = apply_filters('bp_docs_external_documents', [], $external_context);
        if (is_array($external_docs)) {
            foreach ($external_docs as $doc_row) {
                if (!is_array($doc_row)) continue;
                $docs[] = $doc_row;
            }
        }

        foreach ($folders as &$frow) {
            if (!is_array($frow)) continue;
            if (!empty($frow['owner_type'])) continue;
            $frow['owner_type'] = self::owner_type_for_user((int) ($frow['created_by'] ?? 0));
        }
        unset($frow);

        $viewer_role = self::viewer_role($uid);
        $is_begeleider_only = ($viewer_role === 'begeleider');
        if ($is_begeleider_only) {
            $folders = array_values(array_filter($folders, static function ($f) use ($uid) {
                if (!is_array($f)) return false;
                $fid = (int) ($f['id'] ?? 0);
                if ($fid === -1) return true; // Curriculum Vitae
                if ((int) ($f['is_external'] ?? 0) === 1) return true;
                return (int) ($f['created_by'] ?? 0) === $uid;
            }));

            $allowed_folder_ids = array_fill_keys(array_map(static fn($f) => (int) ($f['id'] ?? 0), $folders), true);
            $docs = array_values(array_filter($docs, static function ($d) use ($uid, $allowed_folder_ids) {
                if (!is_array($d)) return false;
                if ((int) ($d['is_external'] ?? 0) === 1) return true;
                $fid = (int) ($d['folder_id'] ?? 0);
                if ($fid > 0) return isset($allowed_folder_ids[$fid]);
                return false;
            }));
        }

        $participants = self::recipient_ids_for_client($client_id, $uid);
        $public_keys = [];
        $fps = [];
        $missing = [];

        foreach ($participants as $pid) {
            $raw = (string) get_user_meta($pid, 'bp_docs_e2e_public_jwk', true);
            $fp = (string) get_user_meta($pid, 'bp_docs_e2e_public_jwk_fp', true);
            $arr = json_decode($raw, true);
            if (!is_array($arr) || empty($arr['kty']) || $fp === '') {
                $missing[] = $pid;
                continue;
            }
            $public_keys[(string) $pid] = $arr;
            $fps[(string) $pid] = strtolower($fp);
        }

        return [
            'me' => $uid,
            'selectedClientId' => $client_id,
            'canSelectClient' => count($allowed_clients) > 1,
            'viewerRole' => $viewer_role,
            'clients' => array_values(array_map(static function ($id, $name) {
                return ['id' => (int) $id, 'name' => (string) $name];
            }, array_keys($allowed_clients), array_values($allowed_clients))),
            'folders' => is_array($folders) ? $folders : [],
            'documents' => is_array($docs) ? $docs : [],
            'recipientIds' => $participants,
            'publicKeys' => $public_keys,
            'publicKeyFingerprints' => $fps,
            'missingKeyUsers' => $missing,
            'adminPost' => admin_url('admin-post.php'),
        ];
    }

    private static function cv_bridge_item(int $viewer_id, int $client_id): ?array {
        if ($viewer_id <= 0 || $client_id <= 0) return null;
        if (!class_exists('\\BP_CV\\Util') || !class_exists('\\BP_CV\\Download')) return null;
        if (!\BP_CV\Util::user_has_cv($client_id)) return null;
        if (!\BP_CV\Util::can_download_cv($viewer_id, $client_id)) return null;

        $row = \BP_CV\Util::get_kb_cv_row($client_id);
        if (!is_array($row) || empty($row['pad'])) return null;

        $path = (string) $row['pad'];
        $name = !empty($row['bestandsnaam']) ? (string) $row['bestandsnaam'] : basename($path);
        $size = (is_file($path) && is_readable($path)) ? (int) @filesize($path) : 0;
        $mtime = (is_file($path) && is_readable($path)) ? (int) @filemtime($path) : 0;
        $created = $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : current_time('mysql');

        return [
            'uploaded_by' => $client_id,
            'name' => $name,
            'size' => $size,
            'created_at' => $created,
            'updated_at' => $created,
            'download_url' => \BP_CV\Download::download_url($client_id),
        ];
    }

    private static function viewer_role(int $user_id): string {
        $user = get_user_by('id', $user_id);
        if (!$user) return 'unknown';
        if (user_can($user, 'manage_options')) return 'admin';
        if (class_exists('BP_Core_Roles') && \BP_Core_Roles::is_leidinggevende($user)) return 'leidinggevende';
        if (class_exists('BP_Core_Roles') && \BP_Core_Roles::is_begeleider($user) && !\BP_Core_Roles::is_leidinggevende($user)) return 'begeleider';
        if (class_exists('BP_Core_Roles') && \BP_Core_Roles::is_client($user)) return 'client';
        return 'unknown';
    }

    private static function owner_type_for_user(int $user_id): string {
        if ($user_id <= 0) return 'unknown';
        $user = get_user_by('id', $user_id);
        if (!$user) return 'unknown';
        if (class_exists('BP_Core_Roles') && \BP_Core_Roles::is_begeleider($user) && !\BP_Core_Roles::is_leidinggevende($user)) return 'begeleider';
        if (class_exists('BP_Core_Roles') && \BP_Core_Roles::is_client($user)) return 'client';
        if (class_exists('BP_Core_Roles') && \BP_Core_Roles::is_leidinggevende($user)) return 'leidinggevende';
        if (user_can($user, 'manage_options')) return 'admin';
        return 'unknown';
    }

    private static function require_auth_or_die(): int {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Niet ingelogd.'], 403);
        }

        $uid = (int) get_current_user_id();
        if ($uid <= 0) {
            wp_send_json_error(['message' => 'Niet ingelogd.'], 403);
        }

        if (function_exists('bp_core_user_can_use_addon') && !bp_core_user_can_use_addon('documenten', $uid)) {
            wp_send_json_error(['message' => 'Geen addon-toegang.'], 403);
        }

        return $uid;
    }

    private static function verify_nonce_or_die(): void {
        check_ajax_referer('bp_docs_nonce', 'nonce');
    }

    private static function assert_client_access(int $user_id, int $client_id): void {
        if ($client_id <= 0) {
            wp_send_json_error(['message' => 'Ongeldige cliënt.'], 400);
        }

        $allowed = self::allowed_clients_for_user($user_id);
        if (!isset($allowed[$client_id])) {
            wp_send_json_error(['message' => 'Geen toegang tot deze cliënt.'], 403);
        }
    }

    /**
     * @return array<int,string>
     */
    private static function allowed_clients_for_user(int $user_id): array {
        if ($user_id <= 0) return [];

        $me = get_user_by('id', $user_id);
        if (!$me) return [];

        $is_admin = user_can($me, 'manage_options');
        $is_leid = class_exists('BP_Core_Roles') && \BP_Core_Roles::is_leidinggevende($me);
        $is_begeleider = class_exists('BP_Core_Roles') && \BP_Core_Roles::is_begeleider($me);
        $is_client = class_exists('BP_Core_Roles') && \BP_Core_Roles::is_client($me);

        if ($is_client) {
            return [$user_id => (string) $me->display_name];
        }

        if ($is_admin) {
            $users = get_users([
                'role' => 'kb_client',
                'fields' => ['ID', 'display_name'],
                'number' => 2000,
                'orderby' => 'display_name',
                'order' => 'ASC',
            ]);
            $out = [];
            foreach ($users as $u) {
                $out[(int) $u->ID] = (string) $u->display_name;
            }
            return $out;
        }

        if ($is_leid) {
            $users = get_users([
                'role' => 'kb_client',
                'meta_key' => 'kb_leidinggevende_id',
                'meta_value' => (string) $user_id,
                'fields' => ['ID', 'display_name'],
                'number' => 2000,
                'orderby' => 'display_name',
                'order' => 'ASC',
            ]);
            $out = [];
            foreach ($users as $u) {
                $out[(int) $u->ID] = (string) $u->display_name;
            }
            return $out;
        }

        if ($is_begeleider) {
            $users = get_users([
                'role' => 'kb_client',
                'meta_key' => 'kb_begeleider_id',
                'meta_value' => (string) $user_id,
                'fields' => ['ID', 'display_name'],
                'number' => 2000,
                'orderby' => 'display_name',
                'order' => 'ASC',
            ]);
            $out = [];
            foreach ($users as $u) {
                $out[(int) $u->ID] = (string) $u->display_name;
            }
            return $out;
        }

        return [];
    }

    /**
     * @return int[]
     */
    private static function recipient_ids_for_client(int $client_id, int $actor_id): array {
        $ids = [$client_id, $actor_id];

        $begeleider = (int) get_user_meta($client_id, 'kb_begeleider_id', true);
        if ($begeleider > 0) $ids[] = $begeleider;

        $leidinggevende = (int) get_user_meta($client_id, 'kb_leidinggevende_id', true);
        if ($leidinggevende > 0) $ids[] = $leidinggevende;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
        return $ids;
    }

    private static function get_folder(int $folder_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::folder_table() . ' WHERE id = %d',
            $folder_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function get_document(int $doc_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::item_table() . ' WHERE id = %d',
            $doc_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function is_valid_e2e_payload(string $payload): bool {
        $payload = trim($payload);
        if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD_LEN) return false;
        if (strpos($payload, self::E2E_PREFIX) !== 0) return false;
        if (!preg_match('/\Ae2e:v1:[A-Za-z0-9+\/]+={0,2}\z/', $payload)) return false;
        $blob = base64_decode(substr($payload, strlen(self::E2E_PREFIX)), true);
        if (!is_string($blob) || $blob === '') return false;
        $decoded = json_decode($blob, true);
        return is_array($decoded) && !empty($decoded['v']) && !empty($decoded['ct']) && !empty($decoded['iv']) && !empty($decoded['keys']);
    }

    private static function canonicalize_public_jwk(string $raw): string {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 25000) return '';
        $arr = json_decode($raw, true);
        if (!is_array($arr)) return '';

        $required = ['kty', 'n', 'e'];
        foreach ($required as $k) {
            if (!isset($arr[$k]) || !is_string($arr[$k]) || $arr[$k] === '') return '';
        }
        if (strtoupper((string) $arr['kty']) !== 'RSA') return '';

        $clean = [
            'kty' => 'RSA',
            'n' => (string) $arr['n'],
            'e' => (string) $arr['e'],
            'alg' => 'RSA-OAEP-256',
            'ext' => true,
            'key_ops' => ['encrypt'],
        ];

        $canonical = wp_json_encode($clean);
        return is_string($canonical) ? $canonical : '';
    }

    private static function folder_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::FOLDER_TABLE;
    }

    private static function item_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::ITEM_TABLE;
    }
}
