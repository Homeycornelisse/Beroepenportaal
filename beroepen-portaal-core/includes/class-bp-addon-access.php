<?php
defined('ABSPATH') || exit;

/**
 * Beheer: per gebruiker addons wel/niet mogen gebruiken.
 *
 * Opslag: user_meta 'bp_addon_access'
 *   [ 'addon-slug' => 'allow'|'deny' ]
 */
final class BP_Core_Addon_Access {

    public static function init(): void {
        add_action('wp_ajax_bp_core_get_addon_access', [__CLASS__, 'ajax_get']);
        add_action('wp_ajax_bp_core_save_addon_access', [__CLASS__, 'ajax_save']);
    }

    private static function can_manage(): bool {
        if (!is_user_logged_in()) return false;

        // Admin altijd.
        if (current_user_can('manage_options')) return true;

        // Leidinggevende/team beheer.
        if (class_exists('BP_Core_Roles') && function_exists('bp_core_user_can')) {
            return bp_core_user_can(BP_Core_Roles::CAP_MANAGE_TEAM);
        }

        return false;
    }

    /**
     * Niet-admin mag alleen users beheren uit zijn/haar team (via kb_leidinggevende_id).
     */
    private static function can_manage_user(int $target_user_id): bool {
        $target_user_id = (int)$target_user_id;
        if ($target_user_id <= 0) return false;

        if (!self::can_manage()) return false;

        if (current_user_can('manage_options')) return true;

        $me = get_current_user_id();
        if ($me <= 0) return false;

        // Jezelf mag je altijd bekijken (handig).
        if ($me === $target_user_id) return true;

        // Alleen teamleden.
        $lid = (int) get_user_meta($target_user_id, 'kb_leidinggevende_id', true);
        return $lid > 0 && $lid === $me;
    }

    public static function get_manageable_users(): array {
        if (!self::can_manage()) return [];

        $me = get_current_user_id();

        // Admin: iedereen met onze portaal-rollen.
        if (current_user_can('manage_options')) {
            return get_users([
                'role__in' => [
                    BP_Core_Roles::ROLE_CLIENT,
                    BP_Core_Roles::ROLE_BEGELEIDER,
                    BP_Core_Roles::ROLE_LEIDINGGEVENDE,
                    'administrator',
                ],
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'number'  => 2000,
            ]);
        }

        // Leidinggevende: alleen eigen team (clients + begeleiders) en jezelf.
        $team_ids = get_users([
            'role__in'   => [BP_Core_Roles::ROLE_CLIENT, BP_Core_Roles::ROLE_BEGELEIDER],
            'meta_key'   => 'kb_leidinggevende_id',
            'meta_value' => (string)$me,
            'orderby'    => 'display_name',
            'order'      => 'ASC',
            'number'     => 5000,
            'fields'     => 'ID',
        ]);

        $ids = array_unique(array_merge([(int)$me], array_map('intval', (array)$team_ids)));
        if (!$ids) return [];

        return get_users([
            'include' => $ids,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'number'  => 5000,
        ]);
    }

    public static function ajax_get(): void {
        if (!self::can_manage()) {
            wp_send_json_error(['message' => 'Geen rechten.'], 403);
        }

        check_ajax_referer('bp_core_addon_access', 'nonce');

        $target_user = (int)($_POST['user_id'] ?? 0);
        if (!self::can_manage_user($target_user)) {
            wp_send_json_error(['message' => 'Je mag deze gebruiker niet beheren.'], 403);
        }

        $addons = function_exists('bp_core_get_registered_addons') ? bp_core_get_registered_addons() : [];
        $access = function_exists('bp_core_get_user_addon_access') ? bp_core_get_user_addon_access($target_user) : [];

        $out_addons = [];
        foreach ($addons as $slug => $info) {
            $slug = sanitize_key((string)$slug);
            if ($slug === '') continue;

            $label = is_array($info) && !empty($info['label']) ? (string)$info['label'] : $slug;
            $cap   = is_array($info) && !empty($info['cap']) ? (string)$info['cap'] : '';

            // default via rol-cap (als cap bekend is)
            $default_allowed = true;
            if ($cap !== '') {
                $default_allowed = user_can($target_user, $cap);
            }

            $out_addons[] = [
                'slug' => $slug,
                'label' => $label,
                'cap' => $cap,
                'default_allowed' => (bool)$default_allowed,
            ];
        }

        wp_send_json_success([
            'addons' => $out_addons,
            'access' => is_array($access) ? $access : [],
        ]);
    }

    public static function ajax_save(): void {
        if (!self::can_manage()) {
            wp_send_json_error(['message' => 'Geen rechten.'], 403);
        }

        check_ajax_referer('bp_core_addon_access', 'nonce');

        $target_user = (int)($_POST['user_id'] ?? 0);
        if (!self::can_manage_user($target_user)) {
            wp_send_json_error(['message' => 'Je mag deze gebruiker niet beheren.'], 403);
        }

        $posted_raw = $_POST['access'] ?? [];
        if (is_string($posted_raw)) {
            $decoded = json_decode(wp_unslash($posted_raw), true);
            $posted = is_array($decoded) ? $decoded : [];
        } else {
            $posted = (array)$posted_raw;
        }
        $addons = function_exists('bp_core_get_registered_addons') ? bp_core_get_registered_addons() : [];

        $oud = function_exists('bp_core_get_user_addon_access') ? bp_core_get_user_addon_access($target_user) : [];

        $newmap = [];
        foreach ($addons as $slug => $info) {
            $slug = sanitize_key((string)$slug);
            if ($slug === '') continue;

            $mode = isset($posted[$slug]) ? strtolower(sanitize_text_field((string)$posted[$slug])) : 'inherit';
            if ($mode === 'allow' || $mode === 'deny') {
                $newmap[$slug] = $mode;
            }
        }

        if (empty($newmap)) {
            delete_user_meta($target_user, 'bp_addon_access');
        } else {
            update_user_meta($target_user, 'bp_addon_access', $newmap);
        }

        if (class_exists('BP_Core_Audit')) {
            BP_Core_Audit::log('addon_access', $target_user, 'save', $oud, $newmap);
        }

        wp_send_json_success([
            'saved' => true,
            'access' => $newmap,
        ]);
    }
}
