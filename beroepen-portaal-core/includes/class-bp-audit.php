<?php
defined('ABSPATH') || exit;

/**
 * Simpel audit log.
 * Hiermee kunnen we later laten zien: wie heeft wat aangepast.
 */
final class BP_Core_Audit {

    public static function log(string $object_type, ?int $object_id, string $actie, $oud = null, $nieuw = null, ?int $actor_id = null): void {
        global $wpdb;

        $actor_id = $actor_id ?? get_current_user_id();

        // Probeer netjes JSON op te slaan
        $oud_json  = is_null($oud) ? null : wp_json_encode($oud, JSON_UNESCAPED_UNICODE);
        $nieuw_json = is_null($nieuw) ? null : wp_json_encode($nieuw, JSON_UNESCAPED_UNICODE);

        $wpdb->insert(
            $wpdb->prefix . 'kb_audit_log',
            [
                'object_type' => sanitize_text_field($object_type),
                'object_id'   => $object_id ? (int)$object_id : null,
                'actor_id'    => $actor_id ? (int)$actor_id : null,
                'actie'       => sanitize_text_field($actie),
                'oud'         => $oud_json,
                'nieuw'       => $nieuw_json,
                'aangemaakt'  => current_time('mysql'),
            ],
            [
                '%s','%d','%d','%s','%s','%s','%s'
            ]
        );
    }
}
