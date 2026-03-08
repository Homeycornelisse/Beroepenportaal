<?php
defined('ABSPATH') || exit;

/**
 * Traject tracker (2e spoor / begeleiding naar werk)
 *
 * Scheiding van verantwoordelijkheden:
 * - Data:   get_default_phases() + get_phases()
 * - Markup: render_shortcode()
 * - Styling/JS: assets/css/traject-tracker.css + assets/js/traject-tracker.js
 */
final class BP_Core_Traject_Tracker {

    private const META_CURRENT_PHASE = 'bp_traject_current_phase';
    private const SHORTCODE = 'bp_traject_tracker';
    private const OPTION_PAGE_ID = 'bp_core_traject_tracker_page_id';
    private const PAGE_TITLE = 'Traject Tracker';
    private const PAGE_SLUG = 'portaal-traject-tracker';

    public static function init(): void {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('init', [__CLASS__, 'ensure_page'], 30);

        // Front-end form submit voor fasewijziging door begeleider/leidinggevende.
        add_action('admin_post_bp_core_update_traject_phase', [__CLASS__, 'handle_phase_update']);
    }

    /**
     * Zorg dat er een vaste pagina bestaat voor de traject tracker.
     * Deze methode is idempotent en veilig om vaker aan te roepen.
     */
    public static function ensure_page(): void {
        if (!function_exists('bp_core_addon_ensure_page')) {
            return;
        }

        $page_id = bp_core_addon_ensure_page(
            self::OPTION_PAGE_ID,
            self::PAGE_TITLE,
            '[' . self::SHORTCODE . ']',
            self::PAGE_SLUG
        );

        if ($page_id > 0) {
            update_option(self::OPTION_PAGE_ID, (int) $page_id, false);
        }
    }

    /**
     * Centrale URL-resolutie voor links naar de traject tracker.
     * Volgorde:
     * 1) opgeslagen page_id option
     * 2) pagina op slug
     * 3) lege string
     */
    public static function get_tracker_url(): string {
        $page_id = (int) get_option(self::OPTION_PAGE_ID, 0);
        if ($page_id > 0) {
            $page = get_post($page_id);
            if ($page && $page->post_type === 'page' && $page->post_status !== 'trash') {
                $url = get_permalink($page_id);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        $fallback = get_page_by_path(self::PAGE_SLUG, OBJECT, 'page');
        if ($fallback && !is_wp_error($fallback) && !empty($fallback->ID)) {
            $url = get_permalink((int) $fallback->ID);
            if (is_string($url) && $url !== '') {
                update_option(self::OPTION_PAGE_ID, (int) $fallback->ID, false);
                return $url;
            }
        }

        return '';
    }

    public static function register_assets(): void {
        wp_register_style(
            'bp-core-traject-tracker',
            BP_CORE_URL . 'assets/css/traject-tracker.css',
            [],
            (string) (file_exists(BP_CORE_DIR . 'assets/css/traject-tracker.css') ? filemtime(BP_CORE_DIR . 'assets/css/traject-tracker.css') : BP_CORE_VERSION)
        );

        wp_register_script(
            'bp-core-traject-tracker',
            BP_CORE_URL . 'assets/js/traject-tracker.js',
            [],
            (string) (file_exists(BP_CORE_DIR . 'assets/js/traject-tracker.js') ? filemtime(BP_CORE_DIR . 'assets/js/traject-tracker.js') : BP_CORE_VERSION),
            true
        );
    }

    /**
     * DEFAULT FASE-INHOUD
     *
     * Hier kun je later makkelijk titels, beschrijvingen, mijlpalen en taken wijzigen.
     * Structuur per fase:
     * - key        string (stabiele sleutel)
     * - title      string
     * - description string
     * - milestones string[]
     * - tasks      string[]
     */
    private static function get_default_phases(): array {
        return [
            [
                'key' => 'intake',
                'title' => 'Intake',
                'description' => 'We starten met een helder beeld van de situatie, doelen en randvoorwaarden van de client.',
                'milestones' => [
                    'Profiel ingevuld',
                    'Documenten geupload',
                    'Eerste gesprek gehad',
                ],
                'tasks' => [
                    'Profiel invullen',
                    'Documenten uploaden',
                    'Eerste gesprek gehad',
                ],
            ],
            [
                'key' => 'orientatie',
                'title' => 'Orientatie',
                'description' => 'De client onderzoekt interesses, mogelijkheden en kansrijke richtingen.',
                'milestones' => [
                    'Interesses in kaart',
                    'Passende beroepen bekeken',
                    'Kansrijke beroepen opgeslagen',
                ],
                'tasks' => [
                    'Interesses invullen',
                    'Passende beroepen bekijken',
                    'Beroepen opslaan',
                ],
            ],
            [
                'key' => 'voorbereiden',
                'title' => 'Voorbereiden',
                'description' => 'In deze fase worden profiel, cv en presentatie richting werkgevers voorbereid.',
                'milestones' => [
                    'CV gereed',
                    'Sollicitatiebrief basis klaar',
                    'Online profiel bijgewerkt',
                ],
                'tasks' => [
                    'CV maken of uploaden',
                    'Sollicitatiebrief opstellen',
                    'LinkedIn of profiel aanvullen',
                ],
            ],
            [
                'key' => 'solliciteren',
                'title' => 'Solliciteren',
                'description' => 'Actief reageren op passende vacatures en sollicitaties bijhouden.',
                'milestones' => [
                    'Vacatures geselecteerd',
                    'Favorietenlijst opgebouwd',
                    'Eerste sollicitaties verstuurd',
                ],
                'tasks' => [
                    'Vacatures bekijken',
                    'Vacatures opslaan',
                    'Sollicitaties versturen',
                ],
            ],
            [
                'key' => 'gesprekken',
                'title' => 'Gesprekken',
                'description' => 'Voorbereiden, voeren en evalueren van sollicitatiegesprekken.',
                'milestones' => [
                    'Uitnodigingen gevolgd',
                    'Gesprekken voorbereid',
                    'Terugkoppeling vastgelegd',
                ],
                'tasks' => [
                    'Uitnodigingen volgen',
                    'Gesprekken voorbereiden',
                    'Terugkoppeling invullen',
                ],
            ],
            [
                'key' => 'werk_gevonden_nazorg',
                'title' => 'Werk gevonden / nazorg',
                'description' => 'Afronding van het traject met borging, evaluatie en nazorgafspraken.',
                'milestones' => [
                    'Startdatum geregistreerd',
                    'Evaluatie uitgevoerd',
                    'Nazorgacties gepland',
                ],
                'tasks' => [
                    'Startdatum registreren',
                    'Evaluatie',
                    'Nazorgacties',
                ],
            ],
        ];
    }

    /**
     * Gecombineerde faseconfiguratie.
     *
     * Volgorde van overrides:
     * 1) defaults in PHP
     * 2) option bp_core_traject_tracker_phases (optioneel, array)
     * 3) filter bp_core_traject_tracker_phases
     */
    private static function get_phases(): array {
        $phases = self::get_default_phases();

        $stored = get_option('bp_core_traject_tracker_phases', null);
        if (is_array($stored) && !empty($stored)) {
            $sanitized = self::sanitize_phases($stored);
            if (!empty($sanitized)) {
                $phases = $sanitized;
            }
        }

        $phases = apply_filters('bp_core_traject_tracker_phases', $phases);
        if (!is_array($phases)) {
            return self::get_default_phases();
        }

        $sanitized = self::sanitize_phases($phases);
        return !empty($sanitized) ? $sanitized : self::get_default_phases();
    }

    private static function sanitize_phases(array $phases): array {
        $out = [];
        foreach ($phases as $phase) {
            if (!is_array($phase)) {
                continue;
            }

            $key = isset($phase['key']) ? sanitize_key((string) $phase['key']) : '';
            $title = isset($phase['title']) ? sanitize_text_field((string) $phase['title']) : '';
            $description = isset($phase['description']) ? sanitize_textarea_field((string) $phase['description']) : '';

            if ($key === '' || $title === '') {
                continue;
            }

            $milestones = [];
            if (!empty($phase['milestones']) && is_array($phase['milestones'])) {
                foreach ($phase['milestones'] as $item) {
                    $item = sanitize_text_field((string) $item);
                    if ($item !== '') {
                        $milestones[] = $item;
                    }
                }
            }

            $tasks = [];
            if (!empty($phase['tasks']) && is_array($phase['tasks'])) {
                foreach ($phase['tasks'] as $item) {
                    $item = sanitize_text_field((string) $item);
                    if ($item !== '') {
                        $tasks[] = $item;
                    }
                }
            }

            $out[] = [
                'key' => $key,
                'title' => $title,
                'description' => $description,
                'milestones' => $milestones,
                'tasks' => $tasks,
            ];
        }

        return $out;
    }

    /**
     * Bepaal voor welke client de tracker moet worden getoond.
     *
     * - client: altijd eigen ID
     * - begeleider/leidinggevende/admin: client_id attribuut (of ?bp_client_id=) als die beheerd mag worden
     */
    private static function resolve_target_client_id(array $atts, WP_User $viewer): int {
        if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_client($viewer)) {
            return (int) $viewer->ID;
        }

        $candidate = 0;
        if (!empty($atts['client_id'])) {
            $candidate = absint($atts['client_id']);
        }
        if ($candidate <= 0 && !empty($_GET['bp_client_id'])) {
            $candidate = absint(wp_unslash((string) $_GET['bp_client_id']));
        }

        if ($candidate > 0 && self::can_manage_client($viewer, $candidate)) {
            return $candidate;
        }

        return 0;
    }

    private static function can_manage_client(WP_User $viewer, int $client_id): bool {
        if ($client_id <= 0) {
            return false;
        }

        $client = get_user_by('id', $client_id);
        if (!$client || !class_exists('BP_Core_Roles') || !BP_Core_Roles::is_client($client)) {
            return false;
        }

        if (current_user_can('manage_options') || BP_Core_Roles::is_leidinggevende($viewer)) {
            return true;
        }

        // Begeleider mag alleen eigen clienten beheren.
        if (BP_Core_Roles::is_begeleider($viewer)) {
            $linked_begeleider = (int) get_user_meta($client_id, 'kb_begeleider_id', true);
            return $linked_begeleider > 0 && $linked_begeleider === (int) $viewer->ID;
        }

        return false;
    }

    private static function get_manageable_clients(WP_User $viewer): array {
        if (!class_exists('BP_Core_Roles')) {
            return [];
        }

        if (current_user_can('manage_options') || BP_Core_Roles::is_leidinggevende($viewer)) {
            return get_users([
                'role' => BP_Core_Roles::ROLE_CLIENT,
                'orderby' => 'display_name',
                'order' => 'ASC',
                'number' => 2000,
            ]);
        }

        if (BP_Core_Roles::is_begeleider($viewer)) {
            $ids = get_users([
                'role' => BP_Core_Roles::ROLE_CLIENT,
                'meta_key' => 'kb_begeleider_id',
                'meta_value' => (string) $viewer->ID,
                'orderby' => 'display_name',
                'order' => 'ASC',
                'number' => 2000,
                'fields' => 'ID',
            ]);

            $ids = array_values(array_filter(array_map('absint', (array) $ids)));
            if (empty($ids)) {
                return [];
            }

            return get_users([
                'include' => $ids,
                'orderby' => 'display_name',
                'order' => 'ASC',
                'number' => 2000,
            ]);
        }

        return [];
    }

    private static function get_client_phase_index(int $client_id, int $phase_count): int {
        $stored = (int) get_user_meta($client_id, self::META_CURRENT_PHASE, true);
        if ($stored < 0) {
            $stored = 0;
        }
        if ($stored > max(0, $phase_count - 1)) {
            $stored = max(0, $phase_count - 1);
        }
        return $stored;
    }

    private static function update_client_phase_index(int $client_id, int $phase_index, int $phase_count): void {
        $phase_index = max(0, min($phase_index, max(0, $phase_count - 1)));
        update_user_meta($client_id, self::META_CURRENT_PHASE, $phase_index);
    }

    public static function handle_phase_update(): void {
        if (!is_user_logged_in()) {
            wp_die('Niet ingelogd.');
        }

        check_admin_referer('bp_core_update_traject_phase', 'bp_traject_nonce');

        $viewer = wp_get_current_user();
        $client_id = isset($_POST['bp_client_id']) ? absint(wp_unslash((string) $_POST['bp_client_id'])) : 0;
        $phase_index = isset($_POST['bp_phase_index']) ? (int) wp_unslash((string) $_POST['bp_phase_index']) : -1;

        if (!$client_id || $phase_index < 0 || !self::can_manage_client($viewer, $client_id)) {
            wp_die('Geen rechten om deze fase te wijzigen.');
        }

        $phases = self::get_phases();
        self::update_client_phase_index($client_id, $phase_index, count($phases));

        if (class_exists('BP_Core_Audit')) {
            BP_Core_Audit::log('traject_tracker', $client_id, 'phase_update', null, [
                'phase_index' => $phase_index,
                'phase_key' => $phases[$phase_index]['key'] ?? '',
            ]);
        }

        $redirect = wp_get_referer() ?: home_url('/');
        $redirect = add_query_arg([
            'bp_traject_updated' => 1,
            'bp_client_id' => $client_id,
        ], $redirect);

        wp_safe_redirect($redirect);
        exit;
    }

    public static function render_shortcode(array $atts = []): string {
        if (!is_user_logged_in()) {
            return function_exists('bp_core_no_access_message')
                ? bp_core_no_access_message()
                : '<p>Geen toegang.</p>';
        }

        $atts = shortcode_atts([
            'client_id' => 0,
            'title' => 'Trajecttracker',
        ], $atts, self::SHORTCODE);

        $viewer = wp_get_current_user();
        $phases = self::get_phases();
        $phase_count = count($phases);

        if ($phase_count === 0) {
            return '<div class="bp-traject-tracker"><p>Geen fases geconfigureerd.</p></div>';
        }

        $target_client_id = self::resolve_target_client_id($atts, $viewer);
        $can_manage = self::can_manage_client($viewer, $target_client_id);

        // Begeleider/leidinggevende zonder geselecteerde client krijgt eerst een clientkeuze.
        if ($target_client_id <= 0 && (class_exists('BP_Core_Roles') && (BP_Core_Roles::is_begeleider($viewer) || BP_Core_Roles::is_leidinggevende($viewer) || current_user_can('manage_options')))) {
            $clients = self::get_manageable_clients($viewer);
            ob_start();
            ?>
            <section class="bp-traject-tracker bp-traject-tracker--chooser" aria-label="Traject tracker clientkeuze">
                <div class="bp-traject-card">
                    <h2 class="bp-traject-title">Selecteer een client</h2>
                    <p class="bp-traject-text">Kies een client om de trajectfase, mijlpalen en taken te bekijken.</p>
                    <?php if (empty($clients)): ?>
                        <p class="bp-traject-empty">Er zijn geen clienten gevonden voor jouw account.</p>
                    <?php else: ?>
                        <form method="get" class="bp-traject-chooser-form">
                            <label for="bp-traject-client-id" class="bp-traject-label">Client</label>
                            <select id="bp-traject-client-id" name="bp_client_id" class="bp-traject-select">
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo (int) $client->ID; ?>"><?php echo esc_html($client->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="bp-traject-btn">Open tracker</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
            <?php
            wp_enqueue_style('bp-core-traject-tracker');
            wp_enqueue_script('bp-core-traject-tracker');
            return (string) ob_get_clean();
        }

        if ($target_client_id <= 0) {
            return '<div class="bp-traject-tracker"><p>Geen client geselecteerd.</p></div>';
        }

        $client = get_user_by('id', $target_client_id);
        if (!$client) {
            return '<div class="bp-traject-tracker"><p>Client niet gevonden.</p></div>';
        }

        $current_phase_index = self::get_client_phase_index($target_client_id, $phase_count);
        $current_phase = $phases[$current_phase_index];
        $progress_percentage = $phase_count > 1 ? round(($current_phase_index / ($phase_count - 1)) * 100) : 100;

        wp_enqueue_style('bp-core-traject-tracker');
        wp_enqueue_script('bp-core-traject-tracker');

        ob_start();
        ?>
        <section class="bp-traject-tracker" data-progress="<?php echo esc_attr((string) $progress_percentage); ?>" aria-label="Traject tracker">
            <header class="bp-traject-header">
                <h2 class="bp-traject-title"><?php echo esc_html((string) $atts['title']); ?></h2>
                <p class="bp-traject-subtitle">
                    Client: <strong><?php echo esc_html((string) $client->display_name); ?></strong>
                    <?php if (!empty($_GET['bp_traject_updated'])): ?>
                        <span class="bp-traject-flash">Fase bijgewerkt.</span>
                    <?php endif; ?>
                </p>
            </header>

            <div class="bp-traject-progress" role="group" aria-label="Voortgang fases">
                <div class="bp-traject-progress-line" aria-hidden="true">
                    <span class="bp-traject-progress-fill" style="width: <?php echo esc_attr((string) $progress_percentage); ?>%;"></span>
                </div>

                <ol class="bp-traject-phases">
                    <?php foreach ($phases as $index => $phase):
                        $state = 'future';
                        if ($index < $current_phase_index) {
                            $state = 'done';
                        } elseif ($index === $current_phase_index) {
                            $state = 'active';
                        }
                        ?>
                        <li class="bp-traject-phase is-<?php echo esc_attr($state); ?>" aria-current="<?php echo $state === 'active' ? 'step' : 'false'; ?>">
                            <span class="bp-traject-phase-dot" aria-hidden="true"></span>
                            <span class="bp-traject-phase-title"><?php echo esc_html((string) $phase['title']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <?php if ($can_manage): ?>
                <section class="bp-traject-card" aria-label="Fase wijzigen">
                    <h3 class="bp-traject-card-title">Fase beheren</h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bp-traject-form">
                        <input type="hidden" name="action" value="bp_core_update_traject_phase">
                        <input type="hidden" name="bp_client_id" value="<?php echo (int) $target_client_id; ?>">
                        <?php wp_nonce_field('bp_core_update_traject_phase', 'bp_traject_nonce'); ?>

                        <label for="bp-phase-index-<?php echo (int) $target_client_id; ?>" class="bp-traject-label">Huidige fase</label>
                        <select id="bp-phase-index-<?php echo (int) $target_client_id; ?>" name="bp_phase_index" class="bp-traject-select">
                            <?php foreach ($phases as $index => $phase): ?>
                                <option value="<?php echo (int) $index; ?>" <?php selected($current_phase_index, $index); ?>>
                                    <?php echo esc_html((string) ($index + 1) . '. ' . (string) $phase['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="bp-traject-btn">Fase opslaan</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="bp-traject-card" aria-label="Mijlpalen huidige fase">
                <h3 class="bp-traject-card-title">Mijlpalen: <?php echo esc_html((string) $current_phase['title']); ?></h3>
                <?php if (!empty($current_phase['milestones'])): ?>
                    <ul class="bp-traject-list bp-traject-list--milestones">
                        <?php foreach ($current_phase['milestones'] as $milestone): ?>
                            <li><?php echo esc_html((string) $milestone); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="bp-traject-empty">Geen mijlpalen ingesteld voor deze fase.</p>
                <?php endif; ?>
            </section>

            <section class="bp-traject-card" aria-label="Uitleg huidige fase">
                <h3 class="bp-traject-card-title">Uitleg huidige fase</h3>
                <p class="bp-traject-text"><?php echo esc_html((string) $current_phase['description']); ?></p>
            </section>

            <section class="bp-traject-card" aria-label="Taken huidige fase">
                <h3 class="bp-traject-card-title">Takenlijst</h3>
                <?php if (!empty($current_phase['tasks'])): ?>
                    <ul class="bp-traject-list bp-traject-list--tasks">
                        <?php foreach ($current_phase['tasks'] as $task): ?>
                            <li><?php echo esc_html((string) $task); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="bp-traject-empty">Geen taken ingesteld voor deze fase.</p>
                <?php endif; ?>
            </section>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}
