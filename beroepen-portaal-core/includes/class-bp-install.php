<?php
defined('ABSPATH') || exit;

/**
 * Installatie: maakt tabellen aan die de core nodig heeft.
 *
 * Belangrijk:
 * - We gebruiken dezelfde tabelnamen als v3.9.12 waar mogelijk.
 * - dbDelta is veilig om vaker te draaien.
 */
final class BP_Core_Install {

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();

        // Logboek van begeleider (voor notities/gesprekken) + bewerk-limiet
        dbDelta("CREATE TABLE {$wpdb->prefix}kb_begel_logboek (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            begeleider_id BIGINT UNSIGNED NOT NULL,
            client_id     BIGINT UNSIGNED NOT NULL,
            datum         DATE            NOT NULL,
            type          VARCHAR(60)     NOT NULL DEFAULT 'gesprek',
            omschrijving  TEXT            NOT NULL,
            vervolg       TEXT,
            aangemaakt    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            bewerkt_op    DATETIME        NULL,
            bewerkt_count TINYINT         NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY idx_begel (begeleider_id),
            KEY idx_client (client_id)
        ) $c;");

        // Berichten inbox (overname verzoeken, client ↔ begeleider berichten)
        dbDelta("CREATE TABLE {$wpdb->prefix}kb_berichten (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            van_id      BIGINT UNSIGNED NOT NULL,
            naar_id     BIGINT UNSIGNED NOT NULL,
            type        VARCHAR(50)     NOT NULL DEFAULT 'bericht',
            client_id   BIGINT UNSIGNED NULL,
            onderwerp   VARCHAR(255)    NOT NULL DEFAULT '',
            inhoud      TEXT            NOT NULL,
            status      VARCHAR(30)     NOT NULL DEFAULT 'pending',
            gelezen     TINYINT(1)      NOT NULL DEFAULT 0,
            categorie   VARCHAR(50)     NOT NULL DEFAULT '',
            aangemaakt  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            bijgewerkt  DATETIME        NULL,
            PRIMARY KEY (id),
            KEY idx_naar   (naar_id),
            KEY idx_van    (van_id),
            KEY idx_type   (type),
            KEY idx_status (status)
        ) $c;");

        // Audit log: wie heeft wat aangepast
        dbDelta("CREATE TABLE {$wpdb->prefix}kb_audit_log (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type  VARCHAR(60)     NOT NULL,
            object_id    BIGINT UNSIGNED NULL,
            actor_id     BIGINT UNSIGNED NULL,
            actie        VARCHAR(60)     NOT NULL,
            oud          LONGTEXT        NULL,
            nieuw        LONGTEXT        NULL,
            aangemaakt   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_obj (object_type, object_id),
            KEY idx_actor (actor_id),
            KEY idx_dt (aangemaakt)
        ) $c;");
    }

    /**
     * Maak de vereiste portaal-pagina's automatisch aan als ze nog niet bestaan.
     * Veilig om herhaaldelijk aan te roepen: doet niets als de pagina al bestaat.
     */
    public static function ensure_pages(): void {
        // Account pagina voor cliënten
        $existing_id = (int) get_option('kb_account_page_id', 0);
        if ($existing_id > 0) {
            $p = get_post($existing_id);
            if ($p && $p->post_status === 'publish') {
                return; // bestaat al
            }
        }

        // Zoek op slug (hergebruik na deactivatie/herinstallatie)
        $by_slug = get_page_by_path('mijn-account');
        if ($by_slug && $by_slug->post_status === 'publish') {
            update_option('kb_account_page_id', $by_slug->ID);
            return;
        }

        // Aanmaken
        $block_content = '<!-- wp:bp/portaal-page {"screen":"account"} /-->';
        $page_id = wp_insert_post([
            'post_title'   => 'Mijn Account',
            'post_name'    => 'mijn-account',
            'post_content' => $block_content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ]);

        if ($page_id && !is_wp_error($page_id)) {
            update_option('kb_account_page_id', $page_id);
        }
    }
}
