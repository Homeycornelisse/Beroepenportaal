<?php
defined('ABSPATH') || exit;

/**
 * BP_Core_Berichten
 *
 * Berichtensysteem voor Beroepen Portaal Core.
 * Ondersteunt:
 *  - overname_verzoek  : begeleider → leidinggevende(n)
 *  - overname_reactie  : leidinggevende → begeleider (goedkeuring/afwijzing)
 *  - bericht           : client ↔ begeleider (tweerichtingsverkeer)
 *  - systeem           : intern gebruik
 *
 * Beveiligingsmaatregelen:
 *  - Alle SQL via $wpdb->prepare()
 *  - Rate limiting via WordPress transients (max 10 berichten/uur per gebruiker)
 *  - Autorisatie: inbox altijd gefilterd op naar_id = eigen user_id
 *  - Input: sanitize_text_field / sanitize_textarea_field (geen HTML toegestaan)
 *  - Output: altijd esc_html() bij weergave in templates
 */
final class BP_Core_Berichten {

    const RATE_LIMIT        = 10;   // max berichten per uur
    const RATE_WINDOW       = HOUR_IN_SECONDS;
    const MAX_ONDERWERP_LEN = 255;
    const MAX_INHOUD_LEN    = 5000;
    const ENC_PREFIX        = 'enc:v1:';
    const E2E_PREFIX        = 'e2e:v1:';
    const CONTACTS_META_KEY = 'bp_msg_contacts';
    const CONTACT_CODE_META_KEY = 'bp_msg_contact_code';

    // ── Stuur een bericht ──────────────────────────────────────────────────────

    /**
     * Sla een nieuw bericht op in de database.
     *
     * @param int    $van_id     Afzender user ID
     * @param int    $naar_id    Ontvanger user ID
     * @param string $type       bericht|overname_verzoek|overname_reactie|systeem
     * @param string $onderwerp  Max 255 tekens
     * @param string $inhoud     Max 5000 tekens, geen HTML
     * @param int    $client_id  Optioneel: context voor overname verzoek
     * @return int  Bericht ID (0 bij mislukken)
     */
    public static function stuur(
        int    $van_id,
        int    $naar_id,
        string $type,
        string $onderwerp,
        string $inhoud,
        int    $client_id = 0
    ): int {
        global $wpdb;

        if ($van_id <= 0 || $naar_id <= 0) return 0;

        // Valideer type
        $toegestane_types = ['bericht', 'overname_verzoek', 'overname_reactie', 'systeem'];
        if (!in_array($type, $toegestane_types, true)) return 0;

        // Rate limiting (niet voor systeemberichten)
        if ($type !== 'systeem' && !self::check_rate_limit($van_id)) return -1;

        // Sanitize inputs
        $type = sanitize_key($type);
        $is_e2e_bericht = ($type === 'bericht' && self::is_e2e_payload($inhoud));

        if ($is_e2e_bericht) {
            // E2E payload blijft bewust opaque voor de server.
            $onderwerp = '';
            $inhoud = self::normalize_e2e_payload($inhoud);
            if ($inhoud === '') return 0;
        } else {
            $onderwerp_plain = sanitize_text_field(mb_substr($onderwerp, 0, self::MAX_ONDERWERP_LEN));
            $inhoud_plain = sanitize_textarea_field(mb_substr($inhoud, 0, self::MAX_INHOUD_LEN));
            if ($inhoud_plain === '') return 0;
            // Legacy fallback at-rest encryptie.
            $onderwerp = self::encrypt_for_storage($onderwerp_plain);
            $inhoud = self::encrypt_for_storage($inhoud_plain);
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'kb_berichten',
            [
                'van_id'    => $van_id,
                'naar_id'   => $naar_id,
                'type'      => $type,
                'client_id' => $client_id > 0 ? $client_id : null,
                'onderwerp' => $onderwerp,
                'inhoud'    => $inhoud,
                'status'    => 'pending',
                'gelezen'   => 0,
                'aangemaakt'=> current_time('mysql'),
            ],
            ['%d', '%d', '%s', $client_id > 0 ? '%d' : null, '%s', '%s', '%s', '%d', '%s']
        );

        if ($result === false) return 0;

        // Rate limit teller verhogen
        if ($type !== 'systeem') {
            self::increment_rate_limit($van_id);
        }

        return (int) $wpdb->insert_id;
    }

    // ── Inbox ophalen ──────────────────────────────────────────────────────────

    /**
     * Haal de inbox op voor een gebruiker.
     * Beveiligingsprincipe: ALTIJD naar_id = $user_id in WHERE.
     *
     * @param int    $user_id
     * @param string $type   Optioneel filter op type
     * @param int    $limit
     * @return array
     */
    public static function haal_inbox(int $user_id, string $type = '', int $limit = 50): array {
        global $wpdb;

        if ($user_id <= 0) return [];
        $limit = max(1, min(200, $limit));

        if ($type !== '') {
            $type = sanitize_key($type);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}kb_berichten
                 WHERE naar_id = %d AND type = %s
                 ORDER BY aangemaakt DESC LIMIT %d",
                $user_id, $type, $limit
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}kb_berichten
                 WHERE naar_id = %d
                 ORDER BY aangemaakt DESC LIMIT %d",
                $user_id, $limit
            ));
        }

        if (!is_array($rows)) {
            return [];
        }
        self::markeer_ontvangen_inbox_rows($rows, $user_id);
        return self::decrypt_rows($rows);
    }

    /**
     * Haal verzonden berichten op van een gebruiker (van_id = user_id).
     * Beperkt tot eigen berichten.
     */
    public static function haal_verzonden(int $user_id, int $limit = 50): array {
        global $wpdb;

        if ($user_id <= 0) return [];
        $limit = max(1, min(200, $limit));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}kb_berichten
             WHERE van_id = %d AND type = 'bericht'
             ORDER BY aangemaakt DESC LIMIT %d",
            $user_id, $limit
        ));

        if (!is_array($rows)) {
            return [];
        }
        return self::decrypt_rows($rows);
    }

    // ── Aantal ongelezen ───────────────────────────────────────────────────────

    public static function aantal_ongelezen(int $user_id): int {
        global $wpdb;

        if ($user_id <= 0) return 0;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kb_berichten
             WHERE naar_id = %d AND gelezen = 0",
            $user_id
        ));
    }

    // ── Markeer gelezen ────────────────────────────────────────────────────────

    /**
     * Markeer bericht als gelezen.
     * Autorisatie: alleen de ontvanger (naar_id = user_id) mag dit doen.
     */
    public static function markeer_gelezen(int $bericht_id, int $user_id): bool {
        global $wpdb;

        if ($bericht_id <= 0 || $user_id <= 0) return false;

        $result = $wpdb->update(
            $wpdb->prefix . 'kb_berichten',
            ['gelezen' => 1, 'status' => 'read', 'bijgewerkt' => current_time('mysql')],
            ['id' => $bericht_id, 'naar_id' => $user_id],
            ['%d', '%s', '%s'],
            ['%d', '%d']
        );

        return $result !== false;
    }

    /**
     * Markeer één bericht als ontvangen (recipient heeft het in inbox gezien).
     */
    public static function markeer_ontvangen(int $bericht_id, int $user_id): bool {
        global $wpdb;
        if ($bericht_id <= 0 || $user_id <= 0) return false;

        $result = $wpdb->update(
            $wpdb->prefix . 'kb_berichten',
            ['status' => 'delivered', 'bijgewerkt' => current_time('mysql')],
            ['id' => $bericht_id, 'naar_id' => $user_id, 'type' => 'bericht'],
            ['%s', '%s'],
            ['%d', '%d', '%s']
        );
        return $result !== false;
    }

    // ── Status instellen (overname verzoek) ────────────────────────────────────

    /**
     * Stel status in van een overname_verzoek.
     * Autorisatie: alleen leidinggevende/admin, en het bericht moet naar hen zijn.
     *
     * @param int    $bericht_id
     * @param int    $user_id    De leidinggevende die reageert
     * @param string $status     goedgekeurd | afgewezen
     * @return bool
     */
    public static function stel_status_in(int $bericht_id, int $user_id, string $status): bool {
        global $wpdb;

        if ($bericht_id <= 0 || $user_id <= 0) return false;
        if (!in_array($status, ['goedgekeurd', 'afgewezen'], true)) return false;

        // Autorisatie: bericht moet naar user_id zijn EN type = overname_verzoek
        $bericht = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}kb_berichten
             WHERE id = %d AND naar_id = %d AND type = 'overname_verzoek'",
            $bericht_id, $user_id
        ));

        if (!$bericht) return false;

        $result = $wpdb->update(
            $wpdb->prefix . 'kb_berichten',
            ['status' => $status, 'gelezen' => 1, 'bijgewerkt' => current_time('mysql')],
            ['id' => $bericht_id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Haal een enkel bericht op. Autorisatie: alleen naar_id of van_id.
     */
    public static function haal_bericht(int $bericht_id, int $user_id): ?object {
        global $wpdb;

        if ($bericht_id <= 0 || $user_id <= 0) return null;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}kb_berichten
             WHERE id = %d AND (naar_id = %d OR van_id = %d)",
            $bericht_id, $user_id, $user_id
        ));

        if (!$row) return null;
        return self::decrypt_row($row);
    }

    // ── Verwijder bericht ──────────────────────────────────────────────────────

    /**
     * Verwijder bericht. Autorisatie: alleen de ontvanger of afzender.
     */
    public static function verwijder(int $bericht_id, int $user_id): bool {
        global $wpdb;

        if ($bericht_id <= 0 || $user_id <= 0) return false;

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}kb_berichten
             WHERE id = %d AND (naar_id = %d OR van_id = %d)",
            $bericht_id,
            $user_id,
            $user_id
        ));

        return (int)$result > 0;
    }

    /**
     * Verwijder volledig gesprek tussen huidige gebruiker en één contact.
     * Autorisatie: alleen eigen gesprek (beide richtingen met current user).
     */
    public static function verwijder_gesprek(int $user_id, int $other_user_id): bool {
        global $wpdb;

        if ($user_id <= 0 || $other_user_id <= 0 || $user_id === $other_user_id) {
            return false;
        }

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}kb_berichten
             WHERE
               ((van_id = %d AND naar_id = %d) OR (van_id = %d AND naar_id = %d))",
            $user_id,
            $other_user_id,
            $other_user_id,
            $user_id
        ));

        return $result !== false;
    }

    // ── E-mailnotificatie ──────────────────────────────────────────────────────

    /**
     * Stuur een HTML-e-mailnotificatie met logo en link naar dashboard inbox.
     *
     * @param int    $naar_id      Ontvanger user ID
     * @param string $onderwerp    E-mail onderwerp
     * @param string $inhoud       Berichttekst (plain text, wordt HTML-escaped)
     * @param string $dashboard_url URL van dashboard pagina (zonder query args)
     */
    public static function stuur_email_notificatie(
        int    $naar_id,
        string $onderwerp,
        string $inhoud,
        string $dashboard_url = ''
    ): void {
        $user = get_user_by('id', $naar_id);
        if (!$user || !$user->user_email) return;

        $linked = function_exists('bp_core_get_linked_pages') ? bp_core_get_linked_pages() : [];
        $inbox_id = !empty($linked['inbox']) ? (int)$linked['inbox'] : 0;
        $dash_id = !empty($linked['dashboard']) ? (int)$linked['dashboard'] : 0;
        if (!$dashboard_url) {
            if ($dash_id > 0) {
                $dashboard_url = (string) get_permalink($dash_id);
            } else {
                $dashboard_url = home_url('/');
            }
        }

        if ($inbox_id > 0) {
            $inbox_url = esc_url(get_permalink($inbox_id));
        } else {
            $inbox_url = esc_url(add_query_arg('bp_tab', 'inbox', $dashboard_url));
        }
        $org_naam   = function_exists('bp_core_get_org_name') ? bp_core_get_org_name() : get_bloginfo('name');
        $org_logo   = function_exists('bp_core_get_org_logo') ? bp_core_get_org_logo() : '';
        $site_url   = esc_url(home_url('/'));

        $logo_html = $org_logo
            ? '<img src="' . esc_url($org_logo) . '" style="max-height:40px;max-width:200px;margin-bottom:20px;display:block;" alt="' . esc_attr($org_naam) . '">'
            : '<div style="font-size:20px;font-weight:800;color:#003082;margin-bottom:20px;">' . esc_html($org_naam) . '</div>';

        // Privacy: nooit berichtinhoud in e-mail opnemen.
        $inhoud_html = 'Je hebt een nieuw beveiligd bericht ontvangen. Open de inbox om het bericht veilig te lezen.';

        $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:32px 0;">'
            . '<tr><td align="center">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:32px;max-width:600px;">'
            . '<tr><td>'
            . $logo_html
            . '<h2 style="color:#003082;font-size:20px;margin:0 0 16px;">' . esc_html($onderwerp) . '</h2>'
            . '<p style="color:#334155;font-size:14px;line-height:1.6;margin:0 0 24px;">' . esc_html($inhoud_html) . '</p>'
            . '<a href="' . $inbox_url . '" style="display:inline-block;background:#003082;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:700;font-size:14px;">Bekijk in inbox &rarr;</a>'
            . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:28px 0 16px;">'
            . '<p style="color:#94a3b8;font-size:12px;margin:0;">Dit bericht is automatisch verzonden door <a href="' . $site_url . '" style="color:#003082;">' . esc_html($org_naam) . '</a>. Reageer via je inbox.</p>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . esc_html($org_naam) . ' <' . get_option('admin_email') . '>',
        ];

        wp_mail($user->user_email, '[' . $org_naam . '] ' . $onderwerp, $body, $headers);
    }

    // ── Rate limiting ──────────────────────────────────────────────────────────

    private static function check_rate_limit(int $user_id): bool {
        if ($user_id <= 0) return false;
        $key   = 'bp_msg_rate_' . $user_id;
        $count = (int) get_transient($key);
        return $count < self::RATE_LIMIT;
    }

    private static function increment_rate_limit(int $user_id): void {
        if ($user_id <= 0) return;
        $key   = 'bp_msg_rate_' . $user_id;
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, self::RATE_WINDOW);
    }

    // ── Categorie instellen ────────────────────────────────────────────────────

    /**
     * Stel een categorie in op een bericht.
     * Autorisatie: alleen de ontvanger of afzender.
     *
     * @param string $categorie  ''|werk|persoonlijk|overname|urgent
     */
    public static function stel_categorie_in(int $bericht_id, int $user_id, string $categorie): bool {
        global $wpdb;

        $toegestaan = ['', 'werk', 'persoonlijk', 'overname', 'urgent'];
        if (!in_array($categorie, $toegestaan, true)) return false;

        $b = self::haal_bericht($bericht_id, $user_id);
        if (!$b) return false;

        return $wpdb->update(
            $wpdb->prefix . 'kb_berichten',
            ['categorie' => $categorie, 'bijgewerkt' => current_time('mysql')],
            ['id' => $bericht_id],
            ['%s', '%s'],
            ['%d']
        ) !== false;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Mag de huidige gebruiker berichten sturen naar $naar_id?
     * Client → eigen begeleider
     * Begeleider → eigen clients of leidinggevende
     * Leidinggevende/admin → iedereen
     */
    public static function mag_sturen_naar(int $van_id, int $naar_id): bool {
        if ($van_id <= 0 || $naar_id <= 0 || $van_id === $naar_id) return false;

        $van  = get_user_by('id', $van_id);
        $naar = get_user_by('id', $naar_id);
        if (!$van || !$naar) return false;

        // Admin: alleen naar leidinggevende/begeleider EN alleen als contact toegevoegd.
        if (user_can($van, 'manage_options')) {
            $is_begel = class_exists('BP_Core_Roles') && BP_Core_Roles::is_begeleider($naar);
            $is_leid  = class_exists('BP_Core_Roles') && BP_Core_Roles::is_leidinggevende($naar);
            if (!$is_begel && !$is_leid) return false;
            return in_array($naar_id, self::get_contacts($van_id), true);
        }

        // Leidinggevende: moet contact eerst toevoegen.
        if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_leidinggevende($van)) {
            return in_array($naar_id, self::get_contacts($van_id), true);
        }

        // Client → eigen begeleider + (optioneel) eigen leidinggevende als contact.
        if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_client($van)) {
            $bid = (int) get_user_meta($van_id, 'kb_begeleider_id', true);
            if ($bid === $naar_id) return true;
            $leid = (int) get_user_meta($van_id, 'kb_leidinggevende_id', true);
            if ($leid > 0 && $leid === $naar_id) {
                return in_array($naar_id, self::get_contacts($van_id), true);
            }
            return false;
        }

        // Begeleider → eigen clients
        if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_begeleider($van)) {
            if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_client($naar)) {
                $bid = (int) get_user_meta($naar_id, 'kb_begeleider_id', true);
                return $bid === $van_id;
            }
            // Begeleider mag ook naar leidinggevende sturen
            if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_leidinggevende($naar)) return true;
        }

        return false;
    }

    /**
     * Haal handmatig toegevoegde contacten op.
     *
     * @return int[]
     */
    public static function get_contacts(int $user_id): array {
        $raw = get_user_meta($user_id, self::CONTACTS_META_KEY, true);
        if (!is_array($raw)) return [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static function($v) {
            return $v > 0;
        })));
        return $ids;
    }

    public static function add_contact(int $user_id, int $contact_id): bool {
        if (!self::can_add_contact($user_id, $contact_id)) return false;
        $list = self::get_contacts($user_id);
        if (!in_array($contact_id, $list, true)) {
            $list[] = $contact_id;
        }
        update_user_meta($user_id, self::CONTACTS_META_KEY, array_values($list));
        return true;
    }

    public static function remove_contact(int $user_id, int $contact_id): bool {
        $list = self::get_contacts($user_id);
        $list = array_values(array_filter($list, static function($id) use ($contact_id) {
            return (int) $id !== (int) $contact_id;
        }));
        update_user_meta($user_id, self::CONTACTS_META_KEY, $list);
        return true;
    }

    public static function can_add_contact(int $user_id, int $contact_id): bool {
        if ($user_id <= 0 || $contact_id <= 0 || $user_id === $contact_id) return false;
        $me = get_user_by('id', $user_id);
        $other = get_user_by('id', $contact_id);
        if (!$me || !$other) return false;

        // Admin mag alleen leidinggevende/begeleider toevoegen.
        if (user_can($me, 'manage_options')) {
            $is_begel = class_exists('BP_Core_Roles') && BP_Core_Roles::is_begeleider($other);
            $is_leid  = class_exists('BP_Core_Roles') && BP_Core_Roles::is_leidinggevende($other);
            return $is_begel || $is_leid;
        }

        // Leidinggevende mag teamleden (client + begeleider) toevoegen.
        if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_leidinggevende($me)) {
            $other_is_client = BP_Core_Roles::is_client($other);
            $other_is_begel  = BP_Core_Roles::is_begeleider($other);
            if (!$other_is_client && !$other_is_begel) return false;
            $other_leid = (int) get_user_meta($contact_id, 'kb_leidinggevende_id', true);
            return $other_leid === $user_id;
        }

        // Begeleider mag eigen cliënten en eigen leidinggevende toevoegen.
        if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_begeleider($me)) {
            if (BP_Core_Roles::is_client($other)) {
                $bid = (int) get_user_meta($contact_id, 'kb_begeleider_id', true);
                return $bid === $user_id;
            }
            if (BP_Core_Roles::is_leidinggevende($other)) {
                $my_leid = (int) get_user_meta($user_id, 'kb_leidinggevende_id', true);
                return $my_leid === $contact_id;
            }
        }

        // Client mag eigen begeleider en eigen leidinggevende toevoegen.
        if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_client($me)) {
            $bid = (int) get_user_meta($user_id, 'kb_begeleider_id', true);
            if (BP_Core_Roles::is_begeleider($other) && $bid === $contact_id) {
                return true;
            }
            $leid = (int) get_user_meta($user_id, 'kb_leidinggevende_id', true);
            if (BP_Core_Roles::is_leidinggevende($other) && $leid === $contact_id) {
                return true;
            }
            return false;
        }

        return false;
    }

    public static function normalize_phone(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone);
        if (!is_string($digits)) return '';
        return trim($digits);
    }

    /**
     * Zoek gebruiker op contactcode (case-insensitive, 8 chars).
     */
    public static function find_user_by_contact_code(string $code): int {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if ($code === '' || strlen($code) < 6) return 0;

        $users = get_users([
            'meta_key' => self::CONTACT_CODE_META_KEY,
            'meta_value' => $code,
            'number' => 1,
            'fields' => 'ID',
        ]);
        if (!is_array($users) || empty($users[0])) return 0;
        return (int) $users[0];
    }

    /**
     * Zoek gebruiker op telefoonnummer (exact genormaliseerde match).
     */
    public static function find_user_by_phone(string $phone): int {
        $needle = self::normalize_phone($phone);
        if ($needle === '' || strlen($needle) < 8) return 0;

        $users = get_users([
            'meta_key' => 'kb_telefoon',
            'meta_compare' => 'EXISTS',
            'number' => 5000,
            'fields' => 'ID',
        ]);
        if (!is_array($users) || empty($users)) return 0;
        foreach ($users as $uid_raw) {
            $uid = (int) $uid_raw;
            if ($uid <= 0) continue;
            $raw = (string) get_user_meta($uid, 'kb_telefoon', true);
            if (self::normalize_phone($raw) === $needle) {
                return $uid;
            }
        }
        return 0;
    }

    public static function get_or_create_contact_code(int $user_id): string {
        if ($user_id <= 0) return '';
        $existing = strtoupper((string) get_user_meta($user_id, self::CONTACT_CODE_META_KEY, true));
        $existing = preg_replace('/[^A-Za-z0-9]/', '', $existing);
        if (is_string($existing) && strlen($existing) === 8) {
            return $existing;
        }

        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(wp_generate_password(8, false, false));
            $code = preg_replace('/[^A-Za-z0-9]/', '', $code);
            if (!is_string($code) || strlen($code) !== 8) continue;
            if (self::find_user_by_contact_code($code) > 0) continue;
            update_user_meta($user_id, self::CONTACT_CODE_META_KEY, $code);
            return $code;
        }
        return '';
    }

    public static function is_e2e_payload(string $value): bool {
        return strpos($value, self::E2E_PREFIX) === 0 && strlen($value) <= 40000;
    }

    /**
     * Publieke sleutel (JWK JSON) voor browser encryptie.
     */
    public static function get_public_jwk(int $user_id): string {
        if ($user_id <= 0) return '';
        $raw = (string) get_user_meta($user_id, 'bp_msg_e2e_public_jwk', true);
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 25000) return '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['kty'])) return '';
        return wp_json_encode($decoded);
    }

    /**
     * Zorg dat de tabel en alle kolommen up-to-date zijn.
     * dbDelta is veilig om herhaaldelijk aan te roepen: voegt ontbrekende
     * kolommen toe zonder bestaande data te verwijderen.
     */
    public static function ensure_table(): void {
        if (class_exists('BP_Core_Install')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            BP_Core_Install::install();
        }
    }

    /**
     * Versleutel tekst voor opslag in DB.
     * Fallback naar platte tekst als OpenSSL niet beschikbaar is.
     */
    private static function encrypt_for_storage(string $plain): string {
        if ($plain === '') return $plain;
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) {
            return $plain;
        }

        $key = self::encryption_key();
        if ($key === '') return $plain;

        $iv = openssl_random_pseudo_bytes(12);
        if ($iv === false || strlen($iv) !== 12) return $plain;

        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipher) || $cipher === '' || $tag === '') return $plain;

        return self::ENC_PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Decrypt tekst uit DB.
     * Ondersteunt plain legacy waarden.
     */
    private static function decrypt_from_storage(string $value): string {
        if ($value === '') return '';
        if (strpos($value, self::E2E_PREFIX) === 0) {
            return $value;
        }
        if (strpos($value, self::ENC_PREFIX) !== 0) {
            return $value;
        }

        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $blob = base64_decode(substr($value, strlen(self::ENC_PREFIX)), true);
        if (!is_string($blob) || strlen($blob) < 29) {
            return '';
        }

        $iv = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $cipher = substr($blob, 28);
        if ($cipher === '') return '';

        $key = self::encryption_key();
        if ($key === '') return '';

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($plain) ? $plain : '';
    }

    /**
     * Afgeleid sleutelmateriaal uit WP salts.
     */
    private static function encryption_key(): string {
        return hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth') . '|bp_core_berichten', true);
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, object>
     */
    private static function decrypt_rows(array $rows): array {
        foreach ($rows as $idx => $row) {
            if (!is_object($row)) continue;
            $rows[$idx] = self::decrypt_row($row);
        }
        return $rows;
    }

    private static function decrypt_row(object $row): object {
        if (isset($row->onderwerp) && is_string($row->onderwerp)) {
            $row->onderwerp = self::decrypt_from_storage($row->onderwerp);
        }
        if (isset($row->inhoud) && is_string($row->inhoud)) {
            $row->inhoud = self::decrypt_from_storage($row->inhoud);
        }
        return $row;
    }

    /**
     * @param array<int, object> $rows
     */
    private static function markeer_ontvangen_inbox_rows(array &$rows, int $user_id): void {
        global $wpdb;
        if ($user_id <= 0) return;

        $ids = [];
        foreach ($rows as $idx => $row) {
            if (!is_object($row)) continue;
            $is_incoming = (int)($row->naar_id ?? 0) === $user_id;
            $is_msg_type = (string)($row->type ?? '') === 'bericht';
            $status = (string)($row->status ?? '');
            if ($is_incoming && $is_msg_type && $status !== 'delivered' && $status !== 'read') {
                $id = (int)($row->id ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                    $rows[$idx]->status = 'delivered';
                }
            }
        }

        if (empty($ids)) return;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = array_merge(['delivered', current_time('mysql')], $ids, [$user_id]);
        $sql = "UPDATE {$wpdb->prefix}kb_berichten
                SET status = %s, bijgewerkt = %s
                WHERE id IN ($placeholders) AND naar_id = %d AND type = 'bericht'";
        $prepared = $wpdb->prepare($sql, $params);
        if (is_string($prepared)) {
            $wpdb->query($prepared);
        }
    }

    private static function normalize_e2e_payload(string $value): string {
        $value = trim($value);
        if (!self::is_e2e_payload($value)) return '';
        $blob = substr($value, strlen(self::E2E_PREFIX));
        if ($blob === '') return '';
        $decoded = base64_decode($blob, true);
        if (!is_string($decoded) || $decoded === '') return '';
        $json = json_decode($decoded, true);
        if (!is_array($json) || empty($json['v']) || empty($json['ct']) || empty($json['iv']) || empty($json['keys'])) {
            return '';
        }
        return self::E2E_PREFIX . base64_encode(wp_json_encode($json));
    }
}
