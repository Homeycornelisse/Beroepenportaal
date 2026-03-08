<?php
defined('ABSPATH') || exit;

/**
 * Per-user capability overrides (allow/deny) bovenop rolrechten.
 *
 * Opslag in user_meta:
 *   key: bp_user_caps_override
 *   value: array( 'capability' => 'allow'|'deny' )
 */

if (!function_exists('bp_user_caps_override_meta_key')) {
    function bp_user_caps_override_meta_key(): string {
        return 'bp_user_caps_override';
    }
}

if (!function_exists('bp_user_get_caps_overrides')) {
    /**
     * Haal overrides op voor een gebruiker.
     *
     * @return array<string,string>  ['kb_use_cv' => 'allow', 'kb_view_clients' => 'deny']
     */
    function bp_user_get_caps_overrides(int $user_id): array {
        if ($user_id <= 0) return [];

        $raw = get_user_meta($user_id, bp_user_caps_override_meta_key(), true);

        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $cap => $state) {
            $cap = is_string($cap) ? sanitize_key($cap) : '';
            $state = is_string($state) ? strtolower(trim($state)) : '';
            if ($cap === '') continue;
            if ($state !== 'allow' && $state !== 'deny') continue;
            $out[$cap] = $state;
        }
        return $out;
    }
}

if (!function_exists('bp_user_set_caps_overrides')) {
    /**
     * Sla een complete override-array op (vervangt bestaande).
     *
     * @param array<string,string> $overrides
     */
    function bp_user_set_caps_overrides(int $user_id, array $overrides): bool {
        if ($user_id <= 0) return false;

        $clean = [];
        foreach ($overrides as $cap => $state) {
            $cap = is_string($cap) ? sanitize_key($cap) : '';
            $state = is_string($state) ? strtolower(trim($state)) : '';
            if ($cap === '') continue;

            if ($state === 'allow' || $state === 'deny') {
                $clean[$cap] = $state;
            }
        }

        if (empty($clean)) {
            // als er niks meer over is: meta opruimen
            delete_user_meta($user_id, bp_user_caps_override_meta_key());
            return true;
        }

        return (bool) update_user_meta($user_id, bp_user_caps_override_meta_key(), $clean);
    }
}

if (!function_exists('bp_user_set_cap_override')) {
    /**
     * Zet 1 override.
     *
     * $state:
     * - 'allow' => altijd toestaan
     * - 'deny'  => altijd blokkeren
     * - 'inherit' of '' => override verwijderen (volg rol)
     */
    function bp_user_set_cap_override(int $user_id, string $cap, string $state): bool {
        if ($user_id <= 0) return false;

        $cap = sanitize_key($cap);
        if ($cap === '') return false;

        $state = strtolower(trim($state));
        $overrides = bp_user_get_caps_overrides($user_id);

        if ($state === 'allow' || $state === 'deny') {
            $overrides[$cap] = $state;
            return bp_user_set_caps_overrides($user_id, $overrides);
        }

        // inherit / leeg / onbekend => verwijderen
        if (isset($overrides[$cap])) {
            unset($overrides[$cap]);
            return bp_user_set_caps_overrides($user_id, $overrides);
        }

        return true;
    }
}

if (!function_exists('bp_user_has_cap')) {
    /**
     * Centrale check voor een capability, met per-user overrides.
     *
     * Logica:
     * - Admin (manage_options of multisite super admin) => true
     * - Override allow => true
     * - Override deny  => false
     * - Anders => fallback op rol via user_can()
     */
    function bp_user_has_cap(int $user_id, string $cap): bool {
        if ($user_id <= 0) return false;

        $cap = sanitize_key($cap);
        if ($cap === '') return false;

        // Admins mogen altijd alles
        if (function_exists('is_super_admin') && is_multisite() && is_super_admin($user_id)) {
            return true;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        // Override check
        $overrides = bp_user_get_caps_overrides($user_id);
        if (isset($overrides[$cap])) {
            if ($overrides[$cap] === 'allow') return true;
            if ($overrides[$cap] === 'deny') return false;
        }

        // Fallback naar rol/capability systeem
        return user_can($user_id, $cap);
    }
}
