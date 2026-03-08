<?php
namespace BP_2S_Logboek;

use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class Rest {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        $ns = 'bp-2s-logboek/v1';

        // Client logboek
        register_rest_route($ns, '/logboek', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_logboek'],
            'permission_callback' => [__CLASS__, 'perm_client_or_begeleider'],
            'args'                => [
                'client_id' => ['required' => false],
            ],
        ]);

        register_rest_route($ns, '/logboek', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create_logboek'],
            'permission_callback' => [__CLASS__, 'perm_signature'],
        ]);

        register_rest_route($ns, '/logboek/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [__CLASS__, 'update_logboek'],
            'permission_callback' => [__CLASS__, 'perm_signature'],
        ]);

        register_rest_route($ns, '/logboek/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [__CLASS__, 'delete_logboek'],
            'permission_callback' => [__CLASS__, 'perm_signature'],
        ]);

        // Begeleider logboek
        register_rest_route($ns, '/begel-logboek/(?P<client_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_begel_logboek'],
            'permission_callback' => [__CLASS__, 'perm_begeleider'],
        ]);

        register_rest_route($ns, '/begel-logboek/(?P<client_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create_begel_logboek'],
            'permission_callback' => [__CLASS__, 'perm_begeleider'],
        ]);

        register_rest_route($ns, '/begel-logboek/entry/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [__CLASS__, 'update_begel_logboek'],
            'permission_callback' => [__CLASS__, 'perm_begeleider'],
        ]);

        register_rest_route($ns, '/begel-logboek/entry/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [__CLASS__, 'delete_begel_logboek'],
            'permission_callback' => [__CLASS__, 'perm_begeleider'],
        ]);

        // Client lijst voor begeleiders
        register_rest_route($ns, '/clients', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_clients'],
            'permission_callback' => [__CLASS__, 'perm_begeleider'],
        ]);
        // Handtekening (per gebruiker)
        register_rest_route($ns, '/signature', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_signature'],
            'permission_callback' => [__CLASS__, 'perm_signature'],
        ]);

        register_rest_route($ns, '/signature', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'save_signature'],
            'permission_callback' => [__CLASS__, 'perm_signature'],
        ]);



// Handtekening van een specifieke gebruiker (voor begeleider PDF)
register_rest_route($ns, '/signature-user/(?P<user_id>\d+)', [
    'methods'             => 'GET',
    'callback'            => [__CLASS__, 'get_signature_user'],
    'permission_callback' => [__CLASS__, 'perm_signature_user'],
]);

    }

    // ── Permissions ─────────────────────────────────────────────

    public static function perm_client(): bool {
        if (!is_user_logged_in()) return false;
        if (!Util::user_can_use()) return false;
        $u = wp_get_current_user();
        return in_array('kb_client', (array) $u->roles, true);
    }

    public static function perm_begeleider(): bool {
        if (!is_user_logged_in()) return false;
        if (!Util::user_can_use()) return false;
        return Util::is_begeleider_or_leidinggevende();
    }


public static function perm_signature(): bool {
    if (!is_user_logged_in()) return false;
    if (!Util::user_can_use()) return false;
    $u = wp_get_current_user();
    $roles = (array) $u->roles;
    return in_array('kb_client', $roles, true) || Util::is_begeleider_or_leidinggevende();
}


public static function perm_signature_user(WP_REST_Request $r): bool {
    if (!is_user_logged_in()) return false;
    if (!Util::user_can_use()) return false;

    $target_id = (int) $r->get_param('user_id');
    if ($target_id <= 0) return false;

    $me = wp_get_current_user();
    if (!$me || !$me->ID) return false;

    // Je eigen handtekening mag altijd
    if ((int) $me->ID === $target_id) return true;

    // Leidinggevende mag altijd
    if (Util::is_leidinggevende()) return true;

    // Begeleider mag alleen de handtekening van zijn/haar gekoppelde cliënt zien
    if (in_array('kb_begeleider', (array) $me->roles, true)) {
        $client_begeleider_id = (int) get_user_meta($target_id, 'kb_begeleider_id', true);
        if ($client_begeleider_id > 0 && $client_begeleider_id === (int) $me->ID) return true;
    }

    return false;
}


    public static function perm_client_or_begeleider(WP_REST_Request $r): bool {
        if (!is_user_logged_in()) return false;
        if (!Util::user_can_use()) return false;
        $u = wp_get_current_user();

        // Client: alleen eigen logboek
        if (in_array('kb_client', (array) $u->roles, true)) return true;

        // Begeleider: mag logboek van gekoppelde cliënt lezen (via client_id)
        if (Util::is_begeleider_or_leidinggevende()) {
            $cid = (int) $r->get_param('client_id');
            if ($cid <= 0) return false;

            // Leidinggevende mag alles. Begeleider alleen gekoppelde cliënt.
            if (Util::is_leidinggevende()) return true;
            $me = wp_get_current_user();
            $linked = (int) get_user_meta($cid, 'kb_begeleider_id', true);
            return $linked > 0 && $linked === (int) ($me->ID ?? 0);
        }

        return false;
    }

    // ── Client logboek ──────────────────────────────────────────

    public static function get_logboek(WP_REST_Request $r): WP_REST_Response {
        global $wpdb;
        $u = wp_get_current_user();

        $client_id = 0;
        if (in_array('kb_client', (array) $u->roles, true)) {
            $client_id = (int) $u->ID;
        } else {
            $client_id = (int) $r->get_param('client_id');
        }

        if ($client_id <= 0) {
            return new WP_REST_Response(['error' => 'client_id ontbreekt.'], 400);
        }

        // Begeleider check: client moet gekoppeld zijn
        if (Util::is_begeleider_or_leidinggevende() && !in_array('kb_client', (array)$u->roles, true)) {
            $bid = (int) $u->ID;
            $linked = (int) get_user_meta($client_id, 'kb_begeleider_id', true);
            if ($linked !== $bid && !Util::is_leidinggevende()) {
                return new WP_REST_Response(['error' => 'Geen toegang tot deze cliënt.'], 403);
            }
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}kb_logboek WHERE client_id=%d ORDER BY datum DESC, id DESC", $client_id),
            ARRAY_A
        );

        return new WP_REST_Response(['items' => $rows ?: []], 200);
    }

    public static function create_logboek(WP_REST_Request $r): WP_REST_Response {
        global $wpdb;
        $uid = (int) get_current_user_id();
        $b   = (array) $r->get_json_params();

        $datum = sanitize_text_field((string)($b['datum'] ?? ''));
        $type  = sanitize_key((string)($b['type'] ?? ''));
        $omsch = wp_kses_post((string)($b['omschrijving'] ?? ''));
        $res   = wp_kses_post((string)($b['resultaat'] ?? ''));
        $uren  = isset($b['uren']) && $b['uren'] !== '' ? (float)$b['uren'] : null;

        if ($datum === '' || $type === '' || trim($omsch) === '') {
            return new WP_REST_Response(['error' => 'Vul datum, type en omschrijving in.'], 400);
        }

        $ok = $wpdb->insert($wpdb->prefix.'kb_logboek', [
            'client_id'    => $uid,
            'datum'        => $datum,
            'type'         => $type,
            'omschrijving' => $omsch,
            'resultaat'    => $res,
            'uren'         => $uren,
            'aangemaakt'   => current_time('mysql'),
        ], [
            '%d','%s','%s','%s','%s', ($uren===null? '%s':'%f'), '%s'
        ]);

        if (!$ok) {
            return new WP_REST_Response(['error' => 'Opslaan mislukt.'], 500);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

// ── Begeleider logboek ──────────────────────────────────────

    public static function get_begel_logboek(WP_REST_Request $r): WP_REST_Response {
        global $wpdb;
        $cid = (int) $r['client_id'];
        $bid = (int) get_current_user_id();

        if ($cid <= 0) return new WP_REST_Response(['error' => 'client_id ontbreekt.'], 400);

        // Check koppeling, leidinggevende mag alles
        if (!Util::is_leidinggevende()) {
            $linked = (int) get_user_meta($cid, 'kb_begeleider_id', true);
            if ($linked !== $bid) {
                return new WP_REST_Response(['error' => 'Geen toegang tot deze cliënt.'], 403);
            }
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}kb_begel_logboek WHERE client_id=%d ORDER BY datum DESC, id DESC", $cid),
            ARRAY_A
        );

        return new WP_REST_Response(['items' => $rows ?: []], 200);
    }

    public static function create_begel_logboek(WP_REST_Request $r): WP_REST_Response {
        global $wpdb;
        $cid = (int) $r['client_id'];
        $bid = (int) get_current_user_id();

        if ($cid <= 0) return new WP_REST_Response(['error' => 'client_id ontbreekt.'], 400);

        if (!Util::is_leidinggevende()) {
            $linked = (int) get_user_meta($cid, 'kb_begeleider_id', true);
            if ($linked !== $bid) {
                return new WP_REST_Response(['error' => 'Geen toegang tot deze cliënt.'], 403);
            }
        }

        $b = (array) $r->get_json_params();
        $datum = sanitize_text_field((string)($b['datum'] ?? ''));
        $type  = sanitize_key((string)($b['type'] ?? ''));
        $omsch = wp_kses_post((string)($b['omschrijving'] ?? ''));
        $verv  = sanitize_text_field((string)($b['vervolg'] ?? ''));

        if ($datum === '' || $type === '' || trim($omsch) === '') {
            return new WP_REST_Response(['error' => 'Vul datum, type en omschrijving in.'], 400);
        }

        $ok = $wpdb->insert($wpdb->prefix.'kb_begel_logboek', [
            'begeleider_id' => $bid,
            'client_id'     => $cid,
            'datum'         => $datum,
            'type'          => $type,
            'omschrijving'  => $omsch,
            'vervolg'       => $verv,
            'aangemaakt'    => current_time('mysql'),
            'bewerkt'       => 0,
        ]);

        if (!$ok) return new WP_REST_Response(['error' => 'Opslaan mislukt.'], 500);
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function update_logboek(WP_REST_Request $r): WP_REST_Response {
        global $wpdb;
        $uid = (int) get_current_user_id();
        $id  = (int) $r['id'];

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}kb_logboek WHERE id=%d", $id),
            ARRAY_A
        );
        if (!$row) {
            return new WP_REST_Response(['error' => 'Niet gevonden.'], 404);
        }
        if ((int) $row['client_id'] !== $uid && !Util::is_leidinggevende()) {
            return new WP_REST_Response(['error' => 'Geen toegang.'], 403);
        }

        $b    = (array) $r->get_json_params();
        $datum = sanitize_text_field((string) ($b['datum'] ?? $row['datum']));
        $type  = sanitize_key((string) ($b['type'] ?? $row['type']));
        $omsch = wp_kses_post((string) ($b['omschrijving'] ?? $row['omschrijving']));
        $res   = wp_kses_post((string) ($b['resultaat'] ?? $row['resultaat']));
        $uren  = (array_key_exists('uren', $b) && $b['uren'] !== '') ? (float) $b['uren'] : null;

        $wpdb->update(
            $wpdb->prefix . 'kb_logboek',
            [
                'datum'        => $datum,
                'type'         => $type,
                'omschrijving' => $omsch,
                'resultaat'    => $res,
                'uren'         => $uren,
                'bijgewerkt'   => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s', ($uren === null ? '%s' : '%f'), '%s'],
            ['%d']
        );

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function delete_logboek(WP_REST_Request $r): WP_REST_Response {
        global $wpdb;
        $uid = (int) get_current_user_id();
        $id  = (int) $r['id'];

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT client_id FROM {$wpdb->prefix}kb_logboek WHERE id=%d", $id),
            ARRAY_A
        );
        if (!$row) {
            return new WP_REST_Response(['error' => 'Niet gevonden.'], 404);
        }
        if ((int) $row['client_id'] !== $uid && !Util::is_leidinggevende()) {
            return new WP_REST_Response(['error' => 'Geen toegang.'], 403);
        }

        $wpdb->delete($wpdb->prefix . 'kb_logboek', ['id' => $id], ['%d']);
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function update_begel_logboek(WP_REST_Request $r): WP_REST_Response {
        global $wpdb;
        $id  = (int) $r['id'];
        $bid = (int) get_current_user_id();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}kb_begel_logboek WHERE id=%d", $id),
            ARRAY_A
        );
        if (!$row) {
            return new WP_REST_Response(['error' => 'Niet gevonden.'], 404);
        }

        $is_leid = Util::is_leidinggevende();

        if (!$is_leid) {
            if ((int) $row['begeleider_id'] !== $bid) {
                return new WP_REST_Response(['error' => 'Geen toegang.'], 403);
            }
            if ((int) $row['bewerkt'] >= 1) {
                return new WP_REST_Response(['error' => 'Je kunt deze notitie niet meer bewerken.'], 403);
            }
        }

        $b    = (array) $r->get_json_params();
        $omsch = wp_kses_post((string) ($b['omschrijving'] ?? $row['omschrijving']));
        $verv  = sanitize_text_field((string) ($b['vervolg'] ?? $row['vervolg']));

        $wpdb->update(
            $wpdb->prefix . 'kb_begel_logboek',
            [
                'omschrijving' => $omsch,
                'vervolg'      => $verv,
                'bewerkt'      => (int) $row['bewerkt'] + 1,
            ],
            ['id' => $id],
            ['%s', '%s', '%d'],
            ['%d']
        );

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function delete_begel_logboek(WP_REST_Request $r): WP_REST_Response {
        global $wpdb;
        $id  = (int) $r['id'];

        if (!Util::is_leidinggevende()) {
            return new WP_REST_Response(['error' => 'Alleen een leidinggevende kan verwijderen.'], 403);
        }

        $wpdb->delete($wpdb->prefix.'kb_begel_logboek', ['id' => $id], ['%d']);
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function get_clients(WP_REST_Request $r): WP_REST_Response {
        $u = wp_get_current_user();
        $bid = (int) $u->ID;

        $args = [
            'role'    => 'kb_client',
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => ['ID', 'display_name'],
        ];

        // Begeleider: alleen gekoppelde clients. Leidinggevende: alles.
        if (!Util::is_leidinggevende()) {
            $args['meta_key']   = 'kb_begeleider_id';
            $args['meta_value'] = $bid;
        }

        $clients = get_users($args);
        $out = [];
        foreach ($clients as $c) {
            $out[] = [
                'id'   => (int) $c->ID,
                'naam' => (string) $c->display_name,
            ];
        }

        return new WP_REST_Response(['items' => $out], 200);
    }

// ── Handtekening (per gebruiker) ───────────────────────────

public static function get_signature(WP_REST_Request $r): WP_REST_Response {
    $uid  = (int) get_current_user_id();
    $sig  = (string) get_user_meta($uid, 'kb_handtekening', true);
    $name = (string) get_user_meta($uid, 'kb_handtekening_naam', true);

    if ($name === '') {
        $u = wp_get_current_user();
        $name = $u ? (string) $u->display_name : '';
    }

    // datum wordt niet apart opgeslagen; we tonen voor de UI de huidige datum
    $date = date_i18n('d-m-Y');

    return new WP_REST_Response([
        'ok'        => true,
        'dataUrl'   => $sig,
        'signature' => $sig,
        'name'      => $name,
        'date'      => $date,
    ], 200);
}

public static function get_signature_user(WP_REST_Request $r): WP_REST_Response {
    $uid  = (int) $r->get_param('user_id');
    if ($uid <= 0) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Ongeldige gebruiker.'], 400);
    }

    $sig  = (string) get_user_meta($uid, 'kb_handtekening', true);
    $name = (string) get_user_meta($uid, 'kb_handtekening_naam', true);

    if ($name === '') {
        $u = get_user_by('id', $uid);
        $name = ($u && !is_wp_error($u)) ? (string) $u->display_name : '';
    }

    return new WP_REST_Response([
        'ok'        => true,
        'dataUrl'   => $sig,
        'signature' => $sig,
        'name'      => $name,
        'date'      => date_i18n('d-m-Y'),
    ], 200);
}

    public static function save_signature(WP_REST_Request $r): WP_REST_Response {
    $uid = (int) get_current_user_id();
    $b   = (array) $r->get_json_params();

    $sig  = (string) ($b['signature'] ?? ($b['dataUrl'] ?? ''));
    $name = sanitize_text_field((string) ($b['name'] ?? ''));

    // Leeg = verwijderen
    if ($sig === '') {
        delete_user_meta($uid, 'kb_handtekening');
        delete_user_meta($uid, 'kb_handtekening_naam');
        return new WP_REST_Response(['ok' => true, 'cleared' => true], 200);
    }

    // Alleen data URLs toestaan (canvas of upload)
    if (!preg_match('/^data:image\/(png|jpeg);base64,/', $sig)) {
        return new WP_REST_Response(['error' => 'Ongeldig formaat. Gebruik PNG of JPG.'], 400);
    }

    // Simpele grootte limiet (± 600KB base64)
    if (strlen($sig) > 900000) {
        return new WP_REST_Response(['error' => 'Afbeelding is te groot.'], 400);
    }

    update_user_meta($uid, 'kb_handtekening', $sig);
    if ($name !== '') {
        update_user_meta($uid, 'kb_handtekening_naam', $name);
    }

    return new WP_REST_Response(['ok' => true, 'dataUrl' => $sig, 'signature' => $sig, 'name' => ($name !== '' ? $name : (string) wp_get_current_user()->display_name), 'date' => date_i18n('d-m-Y')], 200);
}
}