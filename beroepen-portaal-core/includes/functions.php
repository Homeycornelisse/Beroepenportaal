<?php
defined('ABSPATH') || exit;

/**
 * Option keys
 */
function bp_core_option_key_pages() {
    return 'bp_core_pages';
}

/**
 * Get linked page IDs (home/dashboard/beroepen)
 */
function bp_core_get_linked_pages(): array {
    $pages = get_option(bp_core_option_key_pages(), []);
    if (!is_array($pages)) {
        $pages = [];
    }
    return wp_parse_args($pages, [
        'home'      => 0,
        'dashboard' => 0,
        'beroepen'  => 0,
        'uitleg'    => 0,
        'login'     => 0,
        'inbox'     => 0,
    ]);
}

/**
 * Update linked pages
 */
function bp_core_set_linked_pages(array $pages): void {
    $current = bp_core_get_linked_pages();
    $new = wp_parse_args($pages, $current);
    update_option(bp_core_option_key_pages(), $new, false);
}

/**
 * IDs van pagina's achter de loginmuur.
 */
function bp_core_get_login_wall_pages(): array {
    $raw = get_option('bp_core_login_wall_pages', []);
    if (!is_array($raw)) $raw = [];
    $ids = array_values(array_unique(array_map('absint', $raw)));
    return array_values(array_filter($ids, static fn($id) => $id > 0));
}

function bp_core_set_login_wall_pages(array $page_ids): void {
    $ids = array_values(array_unique(array_map('absint', $page_ids)));
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    update_option('bp_core_login_wall_pages', $ids, false);
}

function bp_core_is_page_behind_login_wall(int $page_id): bool {
    $page_id = absint($page_id);
    if ($page_id <= 0) return false;
    $pages = bp_core_get_login_wall_pages();
    return in_array($page_id, $pages, true);
}

/**
 * Organisatie naam (compat: oude kb_ optie)
 */
function bp_core_get_org_name(string $fallback = 'Beroepen Portaal'): string {
    $v = get_option('bp_core_org_name', '');
    if (is_string($v) && $v !== '') return $v;

    $old = get_option('kb_organisatie_naam', '');
    if (is_string($old) && $old !== '') return $old;

    $blog = get_bloginfo('name');
    return $blog ? (string)$blog : $fallback;
}

/**
 * Mag gebruiker beheer-acties uitvoeren (zoals gebruikers / rechten / addons)?
 *
 * Regels:
 * - Administrator mag altijd.
 * - Leidinggevende mag ook.
 */
function bp_core_can_manage_portaal(?WP_User $user = null): bool {
    $user = $user ?? wp_get_current_user();
    if (!$user || !$user->exists()) return false;
    if (current_user_can('manage_options')) return true;
    if (class_exists('BP_Core_Roles')) {
        return BP_Core_Roles::is_leidinggevende($user);
    }
    return false;
}

/**
 * Mag de huidige gebruiker een begeleider-notitie aanpassen?
 *
 * Regels (zoals in v3.9.12):
 * - Leidinggevende/admin mag altijd.
 * - Begeleider mag alleen eigen notitie en max 1x aanpassen.
 */
function bp_core_can_edit_begel_notitie(object $entry, ?WP_User $user = null): bool {
    $user = $user ?? wp_get_current_user();
    if (!$user || !$user->exists()) return false;

    if (BP_Core_Roles::is_leidinggevende($user)) {
        return true;
    }

    // Begeleider: alleen eigen entry
    $is_begeleider = in_array(BP_Core_Roles::ROLE_BEGELEIDER, $user->roles, true);
    if (!$is_begeleider) return false;

    if ((int)($entry->begeleider_id ?? 0) !== (int)$user->ID) return false;
    return (int)($entry->bewerkt_count ?? 0) < 1;
}

/**
 * Organisatie logo URL (compat: oude kb_ optie)
 */
function bp_core_get_org_logo(string $fallback = ''): string {
    $v = get_option('bp_core_org_logo', '');
    if (is_string($v) && $v !== '') return $v;

    $old = get_option('kb_organisatie_logo', '');
    if (is_string($old) && $old !== '') return $old;

    // Probeer WordPress custom logo
    $custom_logo_id = (int) get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $src = wp_get_attachment_image_src($custom_logo_id, 'full');
        if (is_array($src) && !empty($src[0])) return (string)$src[0];
    }
    return $fallback;
}

/**
 * Site icon URL (favicon) met core-override.
 */
function bp_core_get_site_icon_url(string $fallback = ''): string {
    $v = get_option('bp_core_site_icon', '');
    if (is_string($v) && $v !== '') return $v;

    $wp_icon = get_site_icon_url(192);
    if (is_string($wp_icon) && $wp_icon !== '') return $wp_icon;

    return $fallback;
}

/**
 * Huisstijlkleuren voor front-end portaal.
 *
 * @return array<string,string>
 */
function bp_core_get_brand_colors(): array {
    $defaults = [
        'blue'   => '#0047AB',
        'mid'    => '#003A8C',
        'orange' => '#E85D00',
        'purple' => '#7C3AED',
        'bg'     => '#F4F6FB',
        'border' => '#E2E8F0',
        'text'   => '#1E293B',
        'link'   => '#0047AB',
        'muted'  => '#64748B',
    ];
    $stored = get_option('bp_core_brand_colors', []);
    if (!is_array($stored)) $stored = [];

    $out = $defaults;
    foreach ($defaults as $key => $fallback) {
        $raw = isset($stored[$key]) ? (string) $stored[$key] : '';
        $hex = sanitize_hex_color($raw);
        if (is_string($hex) && $hex !== '') {
            $out[$key] = strtoupper($hex);
        }
    }
    return $out;
}

/**
 * Probeer automatisch pagina's te koppelen op basis van vaste slugs.
 * Handig als pagina's al bestaan, maar nog niet gekoppeld zijn.
 */
function bp_core_autodetect_pages(): array {
    $slugs = [
        'home'      => 'portaal-home',
        'dashboard' => 'portaal-dashboard',
        'beroepen'  => 'portaal-beroepen',
        'uitleg'    => 'hoe-werkt-het',
        'login'     => 'login-portaal',
        'inbox'     => 'portaal-inbox',
    ];

    $pages = bp_core_get_linked_pages();
    $changed = false;

    foreach ($slugs as $key => $slug) {
        if (!empty($pages[$key])) continue;

        $p = get_page_by_path($slug);
        if ($p && !is_wp_error($p) && !empty($p->ID)) {
            $pages[$key] = (int) $p->ID;
            $changed = true;
        }
    }

    if ($changed) {
        bp_core_set_linked_pages($pages);
    }

    return $pages;
}

/**
 * Addon permissie check.
 *
 * Default: true. Addons/maatwerk kunnen dit filteren.
 */
function bp_core_user_has_addon(string $addon_slug, ?int $user_id = null): bool {
    $user_id = $user_id ?: get_current_user_id();
    // Backwards compat: deze functie bestond al en werd door addons gebruikt.
    // Vanaf alpha.23 gebruiken we een centrale check met user-overrides.
    if (function_exists('bp_core_user_can_use_addon')) {
        return (bool) bp_core_user_can_use_addon($addon_slug, (int)$user_id);
    }

    $allowed = true;
    /**
     * Filter: bp_core_user_has_addon
     *
     * @param bool   $allowed
     * @param string $addon_slug
     * @param int    $user_id
     */
    return (bool) apply_filters('bp_core_user_has_addon', $allowed, $addon_slug, (int) $user_id);
}

/**
 * Registry: actieve addons kunnen zich registreren.
 *
 * Structuur per addon:
 * [
 *   'label' => 'CV',
 *   'cap'   => 'kb_use_cv',
 * ]
 */
if (!function_exists('bp_core_get_registered_addons')) {
    function bp_core_get_registered_addons(): array {
        $addons = apply_filters('bp_core_addons_registry', []);
        return is_array($addons) ? $addons : [];
    }
}

/**
 * Per gebruiker per addon toegang (override) + fallback op rol-cap.
 *
 * Regels:
 * - Administrator: altijd true.
 * - User override: allow/deny wint.
 * - Anders: rol-cap (als die is meegegeven door addon registry).
 * - Anders: legacy filter bp_core_user_has_addon.
 */
function bp_core_user_can_use_addon(string $addon_slug, ?int $user_id = null): bool {
    $addon_slug = sanitize_key($addon_slug);
    if ($addon_slug === '') return false;

    $user_id = $user_id ?: get_current_user_id();
    $user_id = (int) $user_id;
    if ($user_id <= 0) return false;

    $user = get_user_by('id', $user_id);
    if (!$user) return false;

    // Admin altijd alles.
    if (user_can($user, 'manage_options')) return true;

    // User-level override
    $meta = get_user_meta($user_id, 'bp_addon_access', true);
    if (is_array($meta) && array_key_exists($addon_slug, $meta)) {
        $v = $meta[$addon_slug];
        if ($v === 'deny' || $v === 0 || $v === '0') return false;
        if ($v === 'allow' || $v === 1 || $v === '1') return true;
        // anders: behandel als inherit
    }

    // Rol-cap uit registry (indien bekend)
    $addons = bp_core_get_registered_addons();
    $addon  = is_array($addons[$addon_slug] ?? null) ? $addons[$addon_slug] : [];
    $cap    = isset($addon['cap']) ? (string)$addon['cap'] : '';
    $allowed = true;
    if ($cap !== '') {
        $allowed = user_can($user, $cap);
    }

    // Legacy filter blijft bestaan (voor maatwerk)
    $allowed = (bool) apply_filters('bp_core_user_has_addon', (bool)$allowed, $addon_slug, $user_id);

    return (bool) $allowed;
}

/**
 * Helper: zet override voor één gebruiker.
 * $mode: inherit|allow|deny
 */
function bp_core_set_user_addon_access(int $user_id, string $addon_slug, string $mode): void {
    $user_id = (int)$user_id;
    $addon_slug = sanitize_key($addon_slug);
    $mode = strtolower(trim($mode));
    if ($user_id <= 0 || $addon_slug === '') return;

    $map = get_user_meta($user_id, 'bp_addon_access', true);
    if (!is_array($map)) $map = [];

    if ($mode === 'allow' || $mode === 'deny') {
        $map[$addon_slug] = $mode;
    } else {
        unset($map[$addon_slug]);
    }

    if (empty($map)) {
        delete_user_meta($user_id, 'bp_addon_access');
    } else {
        update_user_meta($user_id, 'bp_addon_access', $map);
    }
}

function bp_core_get_user_addon_access(int $user_id): array {
    $m = get_user_meta((int)$user_id, 'bp_addon_access', true);
    return is_array($m) ? $m : [];
}

/**
 * Dashboard statistieken (kaartjes bovenaan).
 *
 * Structuur per item:
 * [
 *   'key'   => 'cv',
 *   'label' => 'CV geüpload',
 *   'value' => 'Ja',
 *   'icon'  => '📄',
 *   'color' => '#10b981',
 * ]
 */
function bp_core_apply_dashboard_stats(array $stats, int $user_id): array {
    /**
     * Filter: bp_core_dashboard_stats
     *
     * @param array $stats
     * @param int   $user_id
     */
    $stats = apply_filters('bp_core_dashboard_stats', $stats, (int) $user_id);
    return is_array($stats) ? $stats : [];
}

/**
 * Mijn tools (tegels op dashboard).
 *
 * Structuur per item:
 * [
 *   'key'      => 'cv',
 *   'title'    => 'Mijn CV',
 *   'subtitle' => '✅ CV aanwezig',
 *   'url'      => 'https://...',
 *   'icon'     => '📄',
 *   'style'    => 'success' | 'info' | 'purple' | 'warning',
 * ]
 */
function bp_core_apply_tools_tiles(array $tiles, int $user_id): array {
    /**
     * Filter: bp_core_tools_tiles
     *
     * @param array $tiles
     * @param int   $user_id
     */
    $tiles = apply_filters('bp_core_tools_tiles', $tiles, (int) $user_id);
    return is_array($tiles) ? $tiles : [];
}

/**
 * Navigatie items voor het portaal (client/begeleider).
 *
 * Structuur per item:
 * [
 *   'key'   => 'cv',
 *   'label' => '📄 CV',
 *   'url'   => 'https://...',
 *   'active_slug' => 'cv',
 * ]
 */
function bp_core_apply_nav_items(array $items, string $context, int $user_id): array {
    /**
     * Filter: bp_core_nav_items
     *
     * @param array  $items
     * @param string $context  client|begeleider|guest
     * @param int    $user_id
     */
    $items = apply_filters('bp_core_nav_items', $items, $context, (int) $user_id);
    return is_array($items) ? $items : [];
}

/**
 * Addon pagina helper: maak of herstel een pagina en bewaar de page_id in een option.
 *
 * @param string      $option_key  Bijvoorbeeld: bp_addon_cv_page_id
 * @param string      $title       Paginatitel
 * @param string      $content     Pagina-inhoud (bijv. shortcode)
 * @param string|null $slug        Optioneel: gewenste slug
 * @return int  Page ID (0 bij mislukken)
 */
function bp_core_addon_ensure_page(string $option_key, string $title, string $content, ?string $slug = null): int {
    $option_key = trim($option_key);
    if ($option_key === '') return 0;

    $page_id = (int) get_option($option_key, 0);

    if ($page_id > 0) {
        $p = get_post($page_id);
        if ($p && $p->post_type === 'page') {
            if ($p->post_status === 'trash') {
                wp_untrash_post($page_id);
            }
            return (int) $page_id;
        }
    }

    $args = [
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => wp_strip_all_tags($title),
        'post_content' => $content,
    ];

    if ($slug) {
        $args['post_name'] = sanitize_title($slug);
    }

    $new_id = wp_insert_post($args, true);
    if (is_wp_error($new_id) || !$new_id) {
        return 0;
    }

    update_option($option_key, (int) $new_id, false);
    return (int) $new_id;
}

/**
 * Addon pagina helper: haal de URL op via opgeslagen page_id.
 */
function bp_core_addon_page_url(string $option_key): string {
    $page_id = (int) get_option($option_key, 0);
    if ($page_id <= 0) return '';

    $p = get_post($page_id);
    if (!$p || $p->post_type !== 'page' || $p->post_status === 'trash') return '';

    $url = get_permalink($page_id);
    return $url ? (string) $url : '';
}

/**
 * Rechten helper (caps).
 *
 * Gebruik dit overal (Core + addons), zodat de checks overal hetzelfde zijn.
 */
function bp_core_user_can(string $cap, ?int $user_id = null): bool {
    $user_id = $user_id ?: get_current_user_id();
    $user = $user_id ? get_user_by('id', (int)$user_id) : null;
    if (!$user) return false;
    return user_can($user, $cap);
}

/**
 * Opschonen van user-specifieke caps.
 *
 * Soms blijven er na migraties/oude versies caps "plakken" op gebruikersniveau.
 * Dan helpt het niet als je alleen de rol-rechten aanpast.
 *
 * Deze helper verwijdert de opgegeven caps van individuele gebruikers,
 * zodat alleen de rol-caps nog bepalen wat iemand mag.
 */
function bp_core_cleanup_user_caps(array $caps, ?array $roles = null): int {
    $caps = array_values(array_filter(array_map('strval', $caps)));
    if (!$caps) return 0;

    $roles = $roles ?: [
        BP_Core_Roles::ROLE_CLIENT,
        BP_Core_Roles::ROLE_BEGELEIDER,
        BP_Core_Roles::ROLE_LEIDINGGEVENDE,
    ];

    // Alle users met deze rollen ophalen (ids)
    $users = get_users([
        'role__in' => $roles,
        'fields'   => 'ID',
        'number'   => 5000,
    ]);

    $changed = 0;
    foreach ($users as $uid) {
        $u = new WP_User((int)$uid);
        if (!$u || !$u->exists()) continue;
        if (in_array('administrator', (array)$u->roles, true)) continue;

        $did = false;
        foreach ($caps as $cap) {
            // remove_cap werkt alleen op user-level caps, niet op rol-caps.
            if (!empty($u->caps[$cap])) {
                $u->remove_cap($cap);
                $did = true;
            }
        }
        if ($did) $changed++;
    }

    return $changed;
}

/**
 * Voor shortcodes: geef nette melding als iemand geen toegang heeft.
 */
function bp_core_no_access_message(): string {
    return '<div class="kb-wrap" style="max-width:900px;margin:0 auto;">'
        . '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.06);">'
        . '<div style="font-weight:800;color:#003082;font-size:16px;margin-bottom:6px;">Geen toegang</div>'
        . '<div style="color:#475569;">Je hebt geen rechten om dit onderdeel te openen.</div>'
        . '</div></div>';
}

/**
 * Addon pagina helper: verwijder de pagina en ruim de option op.
 * Wordt meestal gebruikt in uninstall.php van een addon.
 */
function bp_core_addon_delete_page(string $option_key, bool $force_delete = true): void {
    $page_id = (int) get_option($option_key, 0);
    if ($page_id > 0) {
        wp_delete_post($page_id, $force_delete);
    }
    delete_option($option_key);
}


/**
 * Zoek een pagina op slug, ook als hij in concept/prive/prullenbak staat.
 */
function bp_core_find_page_by_slug_any_status(string $slug): ?WP_Post {
    $q = new WP_Query([
        'post_type'      => 'page',
        'post_status'    => ['publish','draft','private','pending','future','trash'],
        'name'           => $slug,
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);

    if (!empty($q->posts) && $q->posts[0] instanceof WP_Post) {
        return $q->posts[0];
    }

    // Fallback: sommige sites gebruiken path i.p.v. name
    $p = get_page_by_path($slug, OBJECT, 'page');
    if ($p && !is_wp_error($p)) return $p;

    return null;
}

/**
 * Maak (of herstel) de standaard portaal-pagina's en koppel ze aan de Core.
 * - Herstelt pagina's uit de prullenbak als de slug al bestaat.
 * - Overschrijft bestaande content NIET (tenzij $force=true).
 */
function bp_core_ensure_default_pages(bool $force = false): array {
    if (!function_exists('wp_insert_post')) {
        return bp_core_get_linked_pages();
    }

    // Geen shortcodes: standaardpagina's krijgen Gutenberg blokken.
    $defaults = [
        'home'      => ['Portaal Home',      'portaal-home',      '<!-- wp:bp/portaal-page {"screen":"home"} /-->'],
        'dashboard' => ['Dashboard', 'portaal-dashboard', '<!-- wp:bp/portaal-page {"screen":"dashboard"} /-->'],
        'beroepen'  => ['Portaal Beroepen',  'portaal-beroepen',  '<!-- wp:bp/portaal-page {"screen":"beroepen"} /-->'],
        'uitleg'    => ['Hoe werkt het',     'hoe-werkt-het',     '<!-- wp:bp/portaal-page {"screen":"uitleg"} /-->'],
        'login'     => ['Login Portaal',     'login-portaal',     '<!-- wp:bp/portaal-page {"screen":"login"} /-->'],
        'inbox'     => ['Berichten Inbox',   'portaal-inbox',     '<!-- wp:bp/portaal-page {"screen":"inbox"} /-->'],
    ];

    $pages = bp_core_get_linked_pages();
    $changed = false;

    foreach ($defaults as $key => $def) {
        [$title, $slug, $block_markup] = $def;

        $id = !empty($pages[$key]) ? (int) $pages[$key] : 0;
        $post = $id ? get_post($id) : null;

        // Als de gekoppelde pagina niet bestaat: zoek op slug (ook prullenbak)
        if (!$post || !($post instanceof WP_Post)) {
            $found = bp_core_find_page_by_slug_any_status($slug);

            // Fallback op titel
            if (!$found) {
                $by_title = get_page_by_title($title);
                if ($by_title && !is_wp_error($by_title) && !empty($by_title->ID)) {
                    $found = $by_title;
                }
            }

            if ($found && !empty($found->ID)) {
                // Als hij in prullenbak staat: herstellen
                if ($found->post_status === 'trash') {
                    wp_update_post([
                        'ID'          => (int) $found->ID,
                        'post_status' => 'publish',
                    ]);
                }
                $pages[$key] = (int) $found->ID;
                $changed = true;
                $post = get_post((int)$found->ID);
            }
        }

        // Als nog steeds geen pagina: maak hem aan
        if (!$post || !($post instanceof WP_Post)) {
            $new_id = wp_insert_post([
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id(),
                'post_content' => $block_markup,
            ], true);

            if (!is_wp_error($new_id) && $new_id) {
                $pages[$key] = (int) $new_id;
                $changed = true;
                $post = get_post((int)$new_id);
            }
        }

        // Content zetten (alleen als leeg, of als force)
        if ($post && ($force || trim((string)$post->post_content) === '')) {
            if (strpos((string)$post->post_content, 'wp:bp/portaal-page') === false) {
                wp_update_post([
                    'ID'           => (int) $post->ID,
                    'post_content' => $block_markup,
                ]);
            }
        }
    }

    if ($changed) {
        bp_core_set_linked_pages($pages);
    }

    return $pages;
}


// ─────────────────────────────────────────────────────────────
// Privacy helpers (e-mail tonen alleen waar nodig)
// ─────────────────────────────────────────────────────────────

/**
 * Haal begeleider_id op die aan een cliënt gekoppeld is (user_meta).
 */
function bp_core_get_client_begeleider_id(int $client_id): int {
    if ($client_id <= 0) return 0;
    return (int) get_user_meta($client_id, 'kb_begeleider_id', true);
}

/**
 * Is deze cliënt gekoppeld aan deze begeleider?
 */
function bp_core_is_client_of_begeleider(int $client_id, int $begeleider_id): bool {
    if ($client_id <= 0 || $begeleider_id <= 0) return false;
    return bp_core_get_client_begeleider_id($client_id) === $begeleider_id;
}

/**
 * Mag de kijker het e-mailadres van de doelgebruiker zien?
 *
 * Regels:
 * - Admin/leidinggevende mag altijd.
 * - Begeleider mag e-mail zien van eigen gekoppelde cliënten.
 * - Iedereen mag z'n eigen e-mail zien.
 */
function bp_core_can_view_user_email(int $target_user_id, ?WP_User $viewer = null): bool {
    $viewer = $viewer ?? wp_get_current_user();
    if (!$viewer || !$viewer->exists()) return false;

    if ((int)$viewer->ID === (int)$target_user_id) return true;

    if (current_user_can('manage_options')) return true;

    // Begeleider: alleen gekoppelde cliënten
    if (in_array('kb_begeleider', (array) $viewer->roles, true)) {
        $target = get_user_by('id', $target_user_id);
        if ($target && !is_wp_error($target) && class_exists('BP_Core_Roles') && BP_Core_Roles::is_client($target)) {
            return bp_core_is_client_of_begeleider($target_user_id, (int)$viewer->ID);
        }
    }

    return false;
}

/**
 * Net label voor select-lijsten: "Naam" of "Naam (email)" als dat mag.
 */
function bp_core_user_label($user, ?WP_User $viewer = null): string {
    if (is_numeric($user)) {
        $user = get_user_by('id', (int)$user);
    }
    if (!$user || is_wp_error($user)) return '';

    $viewer = $viewer ?? wp_get_current_user();
    $name = (string) ($user->display_name ?? '');
    $name = $name !== '' ? $name : (string) ($user->user_login ?? '');

    $email = (string) ($user->user_email ?? '');
    if ($email !== '' && bp_core_can_view_user_email((int)$user->ID, $viewer)) {
        return $name . ' (' . $email . ')';
    }
    return $name;
}
