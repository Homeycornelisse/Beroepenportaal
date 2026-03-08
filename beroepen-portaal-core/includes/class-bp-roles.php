<?php
defined('ABSPATH') || exit;

/**
 * Rollen + rechten (caps) voor Beroepen Portaal.
 *
 * Let op: we houden de bestaande KB-rolnamen aan voor compatibiliteit
 * met oudere data/meta velden (kb_client, kb_begeleider, kb_leidinggevende).
 */
final class BP_Core_Roles {

    public const ROLE_LEIDINGGEVENDE = 'kb_leidinggevende';
    public const ROLE_BEGELEIDER     = 'kb_begeleider';
    public const ROLE_CLIENT         = 'kb_client';

    // Caps
    public const CAP_VIEW_PORTAAL       = 'kb_view_portaal';
    public const CAP_VIEW_CLIENTS       = 'kb_view_clients';
    public const CAP_ADD_CLIENTS        = 'kb_add_clients';
    public const CAP_EDIT_AANTEKENINGEN = 'kb_edit_aantekeningen';
    public const CAP_MANAGE_TEAM        = 'kb_manage_team';
    public const CAP_USE_CV             = 'kb_use_cv';

    public static function init(): void {
        // Rollen kunnen verdwijnen door imports/migraties.
        // Daarom checken we elke init of ze bestaan.
        self::ensure_roles();
        self::ensure_admin_caps();
    }

    /**
     * Zet rollen + rechten terug naar de standaard instellingen.
     * Dit is de "reset" knop in wp-admin.
     */
    public static function reset_defaults(): void {
        // Rollen altijd (opnieuw) aanmaken als ze ontbreken
        self::ensure_roles();

        $defaults = [
            self::ROLE_LEIDINGGEVENDE => [
                'read' => true,
                self::CAP_VIEW_CLIENTS => true,
                self::CAP_ADD_CLIENTS => true,
                self::CAP_EDIT_AANTEKENINGEN => true,
                self::CAP_MANAGE_TEAM => true,
                self::CAP_USE_CV => true,
            ],
            self::ROLE_BEGELEIDER => [
                'read' => true,
                self::CAP_VIEW_CLIENTS => true,
                self::CAP_ADD_CLIENTS => true,
                self::CAP_EDIT_AANTEKENINGEN => true,
                self::CAP_USE_CV => true,
            ],
            self::ROLE_CLIENT => [
                'read' => true,
                self::CAP_VIEW_PORTAAL => true,
                self::CAP_USE_CV => true,
            ],
        ];

        foreach ($defaults as $role_key => $caps) {
            $role = get_role($role_key);
            if (!$role) continue;

            // Eerst alle bekende caps uitzetten
            foreach ([
                self::CAP_VIEW_PORTAAL,
                self::CAP_VIEW_CLIENTS,
                self::CAP_ADD_CLIENTS,
                self::CAP_EDIT_AANTEKENINGEN,
                self::CAP_MANAGE_TEAM,
                self::CAP_USE_CV,
            ] as $cap_key) {
                if ($role->has_cap($cap_key)) {
                    $role->remove_cap($cap_key);
                }
            }

            // Dan weer de standaard caps aanzetten
            foreach ($caps as $cap_key => $enabled) {
                if ($cap_key === 'read') continue;
                if ($enabled) $role->add_cap($cap_key);
            }

            // read altijd aan
            if (!$role->has_cap('read')) {
                $role->add_cap('read');
            }
        }

        // Admin altijd alles
        self::ensure_admin_caps();
    }

    /**
     * Handig voor het logboek: laat in 1 array zien welke rol welke caps heeft.
     */
    public static function snapshot(): array {
        $roles = [
            self::ROLE_LEIDINGGEVENDE,
            self::ROLE_BEGELEIDER,
            self::ROLE_CLIENT,
            'administrator',
        ];

        $caps = [
            self::CAP_VIEW_PORTAAL,
            self::CAP_VIEW_CLIENTS,
            self::CAP_ADD_CLIENTS,
            self::CAP_EDIT_AANTEKENINGEN,
            self::CAP_MANAGE_TEAM,
            self::CAP_USE_CV,
        ];

        $out = [];
        foreach ($roles as $rk) {
            $r = get_role($rk);
            if (!$r) {
                $out[$rk] = ['bestaat' => false];
                continue;
            }
            $row = ['bestaat' => true, 'read' => $r->has_cap('read') ? 1 : 0];
            foreach ($caps as $ck) {
                $row[$ck] = $r->has_cap($ck) ? 1 : 0;
            }
            $out[$rk] = $row;
        }
        return $out;
    }

    public static function ensure_roles(): void {
        // Leidinggevende
        if (!get_role(self::ROLE_LEIDINGGEVENDE)) {
            add_role(self::ROLE_LEIDINGGEVENDE, 'Leidinggevende', ['read' => true]);
        }

        // Begeleider
        if (!get_role(self::ROLE_BEGELEIDER)) {
            add_role(self::ROLE_BEGELEIDER, 'Begeleider', ['read' => true]);
        }

        // Cliënt
        if (!get_role(self::ROLE_CLIENT)) {
            add_role(self::ROLE_CLIENT, 'Cliënt', ['read' => true]);
        }

        // Minimale veiligheid: portaal cap voor cliënt
        $client = get_role(self::ROLE_CLIENT);
        if ($client && !$client->has_cap(self::CAP_VIEW_PORTAAL)) {
            $client->add_cap(self::CAP_VIEW_PORTAAL);
        }
    }

    public static function ensure_admin_caps(): void {
        // Admin mag alles. We voegen caps toe zodat checks consistent zijn.
        $admin = get_role('administrator');
        if (!$admin) return;
        foreach ([
            self::CAP_VIEW_PORTAAL,
            self::CAP_VIEW_CLIENTS,
            self::CAP_ADD_CLIENTS,
            self::CAP_EDIT_AANTEKENINGEN,
            self::CAP_MANAGE_TEAM,
            self::CAP_USE_CV,
        ] as $cap) {
            if (!$admin->has_cap($cap)) {
                $admin->add_cap($cap);
            }
        }
    }

    public static function remove_roles(): void {
        remove_role(self::ROLE_LEIDINGGEVENDE);
        remove_role(self::ROLE_BEGELEIDER);
        remove_role(self::ROLE_CLIENT);
    }

    public static function is_leidinggevende(?WP_User $user = null): bool {
        $user = $user ?? wp_get_current_user();
        return $user->exists() && (
            in_array(self::ROLE_LEIDINGGEVENDE, $user->roles, true) ||
            in_array('administrator', $user->roles, true)
        );
    }

    public static function is_begeleider(?WP_User $user = null): bool {
        $user = $user ?? wp_get_current_user();
        return $user->exists() && (
            in_array(self::ROLE_BEGELEIDER, $user->roles, true) ||
            in_array(self::ROLE_LEIDINGGEVENDE, $user->roles, true) ||
            in_array('administrator', $user->roles, true)
        );
    }

    public static function is_client(?WP_User $user = null): bool {
        $user = $user ?? wp_get_current_user();
        return $user->exists() && in_array(self::ROLE_CLIENT, $user->roles, true);
    }
}
