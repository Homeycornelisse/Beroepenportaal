<?php
namespace BP_Beroepen;

use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class Rest {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        $ns = 'bp-beroepen/v1';

        register_rest_route($ns, '/selecties', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_selecties'],
            'permission_callback' => [__CLASS__, 'perm_read'],
            'args'                => [
                'client_id' => ['required' => false],
            ],
        ]);

        register_rest_route($ns, '/selecties', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'save_selectie'],
            'permission_callback' => [__CLASS__, 'perm_write'],
        ]);

        register_rest_route($ns, '/clients', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_clients'],
            'permission_callback' => [__CLASS__, 'perm_begeleider'],
        ]);

        register_rest_route($ns, '/aantekeningen/(?P<client_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_aantekeningen'],
            'permission_callback' => [__CLASS__, 'perm_begeleider'],
        ]);

        register_rest_route($ns, '/aantekeningen/(?P<client_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'save_aantekening'],
            'permission_callback' => [__CLASS__, 'perm_begeleider'],
        ]);
    }

    public static function perm_read(WP_REST_Request $request): bool {
        if (!is_user_logged_in()) return false;
        if (!Util::user_can_use()) return false;

        $target_id = self::resolve_client_id_for_read($request);
        return $target_id > 0;
    }

    public static function perm_write(WP_REST_Request $request): bool {
        if (!is_user_logged_in()) return false;
        if (!Util::user_can_use()) return false;

        $user = wp_get_current_user();
        if (!$user || !$user->ID) return false;

        if (user_can($user, 'manage_options')) return true;
        return in_array('kb_client', (array) $user->roles, true);
    }

    public static function perm_begeleider(WP_REST_Request $request): bool {
        if (!is_user_logged_in()) return false;
        if (!Util::user_can_use()) return false;
        return Util::is_begeleider_or_leidinggevende();
    }

    public static function get_selecties(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $client_id = self::resolve_client_id_for_read($request);
        if ($client_id <= 0) {
            return new WP_REST_Response(['error' => 'Geen toegang.'], 403);
        }

        $table = $wpdb->prefix . 'kb_selecties';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE client_id = %d", $client_id),
            ARRAY_A
        );

        $out = [];
        foreach ((array) $rows as $row) {
            $name = (string) ($row['beroep_naam'] ?? '');
            if ($name === '') continue;
            $out[$name] = $row;
        }

        return new WP_REST_Response($out, 200);
    }

    public static function save_selectie(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            return new WP_REST_Response(['error' => 'Niet ingelogd.'], 401);
        }

        $body = (array) $request->get_json_params();

        $beroep_naam = sanitize_text_field((string) ($body['beroep_naam'] ?? ''));
        if ($beroep_naam === '') {
            return new WP_REST_Response(['error' => 'beroep_naam is verplicht.'], 400);
        }

        $sector       = sanitize_text_field((string) ($body['sector'] ?? ''));
        $niveau       = sanitize_text_field((string) ($body['niveau'] ?? ''));
        $vind_ik_leuk = !empty($body['vind_ik_leuk']) ? 1 : 0;
        $doelgroep    = !empty($body['doelgroep']) ? 1 : 0;
        $notitie      = sanitize_textarea_field((string) ($body['notitie'] ?? ''));

        $table = $wpdb->prefix . 'kb_selecties';

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE client_id = %d AND beroep_naam = %s",
            $user_id,
            $beroep_naam
        ));

        $payload = [
            'sector'       => $sector,
            'niveau'       => $niveau,
            'vind_ik_leuk' => $vind_ik_leuk,
            'doelgroep'    => $doelgroep,
            'notitie'      => $notitie,
        ];

        if ($existing_id > 0) {
            $ok = $wpdb->update(
                $table,
                $payload,
                ['id' => $existing_id],
                ['%s', '%s', '%d', '%d', '%s'],
                ['%d']
            );
            if ($ok === false) {
                return new WP_REST_Response(['error' => 'Opslaan mislukt.'], 500);
            }
            return new WP_REST_Response(['ok' => true], 200);
        }

        $ok = $wpdb->insert(
            $table,
            array_merge($payload, [
                'client_id'   => $user_id,
                'beroep_naam' => $beroep_naam,
            ]),
            ['%s', '%s', '%d', '%d', '%s', '%d', '%s']
        );

        if (!$ok) {
            return new WP_REST_Response(['error' => 'Opslaan mislukt.'], 500);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function get_clients(WP_REST_Request $request): WP_REST_Response {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new WP_REST_Response(['error' => 'Niet ingelogd.'], 401);
        }

        $is_admin_or_leid = user_can($user, 'manage_options') || in_array('kb_leidinggevende', (array) $user->roles, true);
        if ($is_admin_or_leid) {
            $clients = get_users([
                'role'    => 'kb_client',
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'fields'  => ['ID', 'display_name', 'user_email'],
            ]);
        } else {
            // Begeleider: eigen clients + teamclients via dezelfde leidinggevende (zoals oude code)
            $direct = get_users([
                'role'       => 'kb_client',
                'meta_key'   => 'kb_begeleider_id',
                'meta_value' => (string) $user->ID,
                'orderby'    => 'display_name',
                'order'      => 'ASC',
                'fields'     => ['ID', 'display_name', 'user_email'],
            ]);

            $team = [];
            $leid_id = (int) get_user_meta($user->ID, 'kb_leidinggevende_id', true);
            if ($leid_id > 0) {
                $team = get_users([
                    'role'       => 'kb_client',
                    'meta_key'   => 'kb_leidinggevende_id',
                    'meta_value' => (string) $leid_id,
                    'orderby'    => 'display_name',
                    'order'      => 'ASC',
                    'fields'     => ['ID', 'display_name', 'user_email'],
                ]);
            }

            $seen = [];
            $clients = [];
            foreach (array_merge((array) $direct, (array) $team) as $c) {
                $cid = (int) ($c->ID ?? 0);
                if ($cid <= 0 || isset($seen[$cid])) continue;
                $seen[$cid] = true;
                $clients[] = $c;
            }
        }

        $out = [];
        foreach ((array) $clients as $client) {
            $cid = (int) $client->ID;
            $out[] = [
                'id'              => $cid,
                'naam'            => (string) $client->display_name,
                'email'           => (string) $client->user_email,
                'cv_download_url' => self::cv_download_url($cid),
            ];
        }

        return new WP_REST_Response(['items' => $out], 200);
    }

    public static function get_aantekeningen(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $client_id = (int) $request->get_param('client_id');
        if ($client_id <= 0) {
            return new WP_REST_Response(['error' => 'client_id ontbreekt.'], 400);
        }

        if (!self::begeleider_has_client_access($client_id)) {
            return new WP_REST_Response(['error' => 'Geen toegang tot deze client.'], 403);
        }

        $rows_selecties = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}kb_selecties WHERE client_id = %d",
            $client_id
        ), ARRAY_A);

        $selecties = [];
        foreach ((array) $rows_selecties as $row) {
            $key = (string) ($row['beroep_naam'] ?? '');
            if ($key === '') continue;
            $selecties[$key] = $row;
        }

        $current_begeleider = (int) get_current_user_id();
        $rows_aant = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}kb_aantekeningen WHERE begeleider_id = %d AND client_id = %d",
            $current_begeleider,
            $client_id
        ), ARRAY_A);

        $aantekeningen = [];
        foreach ((array) $rows_aant as $row) {
            $key = (string) ($row['beroep_naam'] ?? '');
            if ($key === '') continue;
            $aantekeningen[$key] = $row;
        }

        $client = get_user_by('id', $client_id);

        return new WP_REST_Response([
            'selecties'     => $selecties,
            'aantekeningen' => $aantekeningen,
            'client'        => [
                'id'              => $client_id,
                'naam'            => $client ? (string) $client->display_name : '',
                'email'           => $client ? (string) $client->user_email : '',
                'cv_download_url' => self::cv_download_url($client_id),
            ],
        ], 200);
    }

    public static function save_aantekening(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $client_id = (int) $request->get_param('client_id');
        if ($client_id <= 0) {
            return new WP_REST_Response(['error' => 'client_id ontbreekt.'], 400);
        }

        if (!self::begeleider_has_client_access($client_id)) {
            return new WP_REST_Response(['error' => 'Geen toegang tot deze client.'], 403);
        }

        $body = (array) $request->get_json_params();
        $beroep_naam = sanitize_text_field((string) ($body['beroep_naam'] ?? ''));
        if ($beroep_naam === '') {
            return new WP_REST_Response(['error' => 'beroep_naam is verplicht.'], 400);
        }

        $begeleider_id     = (int) get_current_user_id();
        $sterren           = max(0, min(5, (int) ($body['sterren'] ?? 0)));
        $doelgroep_functie = !empty($body['doelgroep_functie']) ? 1 : 0;
        $lks_raw           = isset($body['lks_percentage']) ? trim((string) $body['lks_percentage']) : '';
        $lks_percentage    = ($lks_raw === '') ? null : (float) $lks_raw;
        $advies            = sanitize_textarea_field((string) ($body['advies'] ?? ''));
        $vervolgstappen    = sanitize_textarea_field((string) ($body['vervolgstappen'] ?? ''));

        $table = $wpdb->prefix . 'kb_aantekeningen';
        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE begeleider_id = %d AND client_id = %d AND beroep_naam = %s",
            $begeleider_id,
            $client_id,
            $beroep_naam
        ));

        $payload = [
            'sterren'           => $sterren,
            'doelgroep_functie' => $doelgroep_functie,
            'lks_percentage'    => $lks_percentage,
            'advies'            => $advies,
            'vervolgstappen'    => $vervolgstappen,
        ];

        if ($existing_id > 0) {
            $ok = $wpdb->update(
                $table,
                $payload,
                ['id' => $existing_id],
                ['%d', '%d', ($lks_percentage === null ? '%s' : '%f'), '%s', '%s'],
                ['%d']
            );
            if ($ok === false) return new WP_REST_Response(['error' => 'Opslaan mislukt.'], 500);
            return new WP_REST_Response(['ok' => true], 200);
        }

        $ok = $wpdb->insert(
            $table,
            array_merge($payload, [
                'begeleider_id' => $begeleider_id,
                'client_id'     => $client_id,
                'beroep_naam'   => $beroep_naam,
            ]),
            ['%d', '%d', '%s', '%d', '%d', ($lks_percentage === null ? '%s' : '%f'), '%s', '%s']
        );

        if (!$ok) return new WP_REST_Response(['error' => 'Opslaan mislukt.'], 500);

        return new WP_REST_Response(['ok' => true], 200);
    }

    private static function resolve_client_id_for_read(WP_REST_Request $request): int {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) return 0;

        if (in_array('kb_client', (array) $user->roles, true)) {
            return (int) $user->ID;
        }

        $requested = (int) $request->get_param('client_id');

        if (user_can($user, 'manage_options') || in_array('kb_leidinggevende', (array) $user->roles, true)) {
            return $requested > 0 ? $requested : (int) $user->ID;
        }

        if (in_array('kb_begeleider', (array) $user->roles, true)) {
            if ($requested <= 0) return 0;
            $linked_begeleider = (int) get_user_meta($requested, 'kb_begeleider_id', true);
            if ($linked_begeleider === (int) $user->ID) {
                return $requested;
            }
        }

        return 0;
    }

    private static function begeleider_has_client_access(int $client_id): bool {
        $client_id = (int) $client_id;
        if ($client_id <= 0) return false;

        $user = wp_get_current_user();
        if (!$user || !$user->ID) return false;

        if (user_can($user, 'manage_options') || in_array('kb_leidinggevende', (array) $user->roles, true)) {
            return true;
        }

        if (!in_array('kb_begeleider', (array) $user->roles, true)) {
            return false;
        }

        $linked_begeleider = (int) get_user_meta($client_id, 'kb_begeleider_id', true);
        return $linked_begeleider === (int) $user->ID;
    }

    private static function cv_download_url(int $client_id): string {
        if ($client_id <= 0) return '';

        if (class_exists('\\BP_CV\\Download') && method_exists('\\BP_CV\\Download', 'download_url')) {
            return (string) \BP_CV\Download::download_url($client_id);
        }
        return '';
    }
}
