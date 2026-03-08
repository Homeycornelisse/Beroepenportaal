<?php
namespace BP_CV;

defined('ABSPATH') || exit;

final class Shortcodes {

    public static function init(): void {
        add_shortcode('bp_cv', [__CLASS__, 'render_cv']);
        add_shortcode('bp_cv_download', [__CLASS__, 'render_download_button']);

        add_action('wp_enqueue_scripts', function () {
            if (is_singular() && isset($GLOBALS['post']) && is_object($GLOBALS['post'])) {
                if (has_shortcode($GLOBALS['post']->post_content, 'bp_cv')) {
                    Util::enqueue_assets();
                }
            }
        }, 100);
    }

    public static function render_download_button($atts = []): string {
        $atts = shortcode_atts([
            'client_id' => 0,
            'label'     => 'Download CV',
            'class'     => 'bp-btn bp-btn-primary',
        ], (array)$atts);

        $client_id = (int) $atts['client_id'];
        if ($client_id <= 0) return '';

        if (!Util::is_logged_in()) return '';

        $requester = Util::current_user_id();
        if (!Util::can_download_cv($requester, $client_id)) return '';

        if (!Util::user_has_cv($client_id)) return '';

        $url = esc_url(Download::download_url($client_id));
        $label = esc_html($atts['label']);

        return '<a class="'.esc_attr($atts['class']).'" href="'.$url.'">'.$label.'</a>';
    }

    public static function render_cv($atts = [], $content = ''): string {
        $atts = shortcode_atts([
            'shell' => '1',
        ], (array)$atts);

        if (!Util::is_logged_in()) {
            $login = wp_login_url(get_permalink());
            return '<div class="bp-card"><div class="bp-card-body">Je moet ingelogd zijn om je CV te beheren. <a class="bp-btn bp-btn-primary" href="'.esc_url($login).'">Inloggen</a></div></div>';
        }

        // Rechten check via Core (rol + per-user override)
        if (function_exists('bp_core_user_can_use_addon')) {
            if (!bp_core_user_can_use_addon('cv', Util::current_user_id())) {
                return '<div class="bp-card"><div class="bp-card-body">Je hebt geen rechten om de CV-module te gebruiken.</div></div>';
            }
        } elseif (function_exists('bp_core_user_can')) {
            if (!bp_core_user_can('kb_use_cv', Util::current_user_id())) {
                return '<div class="bp-card"><div class="bp-card-body">Je hebt geen rechten om de CV-module te gebruiken.</div></div>';
            }
        }

        // If shell=1, Shell class will intercept template and render full width.
        // Here we only output the inner block so it can also work without shell.
        return self::render_cv_block();
    }

    public static function render_cv_block(): string {
        $user_id = Util::current_user_id();

        // Veiligheidsnet: deze functie wordt ook direct gebruikt door de shell.
        // Daarom hier óók de login + rechten check.
        if (!Util::is_logged_in()) {
            $login = wp_login_url(get_permalink());
            return '<div class="bp-card"><div class="bp-card-body">Je moet ingelogd zijn om je CV te beheren. <a class="bp-btn bp-btn-primary" href="'.esc_url($login).'">Inloggen</a></div></div>';
        }

        if (function_exists('bp_core_user_can_use_addon')) {
            if (!bp_core_user_can_use_addon('cv', $user_id)) {
                return '<div class="bp-card"><div class="bp-card-body">Je hebt geen rechten om de CV-module te gebruiken.</div></div>';
            }
        } elseif (function_exists('bp_core_user_can')) {
            if (!bp_core_user_can('kb_use_cv', $user_id)) {
                return '<div class="bp-card"><div class="bp-card-body">Je hebt geen rechten om de CV-module te gebruiken.</div></div>';
            }
        }

        $msg = '';
        $err = '';

        // Handle actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bp_cv_action'])) {
            $action = sanitize_text_field((string) wp_unslash($_POST['bp_cv_action']));
            $nonce  = isset($_POST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_POST['_wpnonce'])) : '';

            if (!wp_verify_nonce($nonce, 'bp_cv_form')) {
                $err = 'Beveiligingscontrole mislukt. Probeer opnieuw.';
            } else {
                if ($action === 'upload') {
                    $result = self::handle_upload($user_id);
                    if (is_wp_error($result)) $err = $result->get_error_message();
                    else $msg = 'CV opgeslagen ✅';
                } elseif ($action === 'delete') {
                    $result = self::handle_delete($user_id);
                    if (is_wp_error($result)) $err = $result->get_error_message();
                    else $msg = 'CV verwijderd ✅';
                }
            }
        }

        $row = Util::get_kb_cv_row($user_id);
        $has_cv = (bool) ($row && !empty($row['pad']) && file_exists($row['pad']));
        $file_name = $has_cv ? (string) ($row['bestandsnaam'] ?? basename((string)$row['pad'])) : '';
        $date = $has_cv ? date('Y-m-d H:i:s', @filemtime((string)$row['pad']) ?: time()) : '';
        $download_url = $has_cv ? Download::download_url($user_id) : '';

        $user = wp_get_current_user();
        $first_name = trim((string) get_user_meta($user_id, 'first_name', true));
        $last_name = trim((string) get_user_meta($user_id, 'last_name', true));
        if ($first_name === '' && $last_name === '') {
            $display = trim((string) ($user->display_name ?? ''));
            if ($display !== '') {
                $parts = preg_split('/\s+/', $display, 2);
                $first_name = (string) ($parts[0] ?? '');
                $last_name = (string) ($parts[1] ?? '');
            }
        }

        $email = trim((string) ($user->user_email ?? ''));
        $functie = trim((string) get_user_meta($user_id, 'kb_functie', true));
        if ($functie === '') {
            $functie = 'Marketing manager';
        }

        $profile_default = 'Ik ben een ervaren en gedreven professional met een sterke focus op resultaat en samenwerking. Ik zoek een rol waarin ik impact kan maken, processen kan verbeteren en mezelf verder kan ontwikkelen.';
        $profile_text = trim((string) get_user_meta($user_id, 'kb_cv_profiel', true));
        if ($profile_text === '') {
            $profile_text = $profile_default;
        }

        $full_name = trim($first_name . ' ' . $last_name);
        if ($full_name === '') {
            $full_name = trim((string) ($user->display_name ?? ''));
        }
        if ($full_name === '') {
            $full_name = 'Jouw naam';
        }

        $defaults = [
            'experience' => [
                ['role' => 'Chief Marketing Officer', 'company' => 'Koninklijke Philips N.V.', 'period' => 'feb 2017 - heden', 'description' => 'Verantwoordelijk voor marketingstrategie en duurzame groei.'],
                ['role' => 'Marketing Coordinator', 'company' => 'Booking', 'period' => 'mrt 2011 - jan 2017', 'description' => 'Aansturing van campagnes en kanaalmanagers.'],
            ],
            'education' => [
                ['title' => 'MSC. Management', 'school' => 'Nyenrode Business Universiteit', 'period' => 'aug 2005 - jun 2008', 'description' => 'Focus op bedrijfsvoering en strategie.'],
            ],
            'skills' => [
                ['name' => 'ROI-berekeningen', 'level' => 5],
                ['name' => 'Online Marketing', 'level' => 4],
                ['name' => 'Leiderschap', 'level' => 4],
            ],
            'certificates' => [
                ['name' => 'Advanced Management Program', 'issuer' => 'Nyenrode', 'year' => '2019'],
                ['name' => 'Digital Leadership', 'issuer' => 'Erasmus', 'year' => '2018'],
            ],
        ];

        $defaults_json = wp_json_encode($defaults);

        ob_start();
        ?>
        <div class="bp-cv-wrap bp-cv-builder" data-bp-cv-defaults="<?php echo esc_attr($defaults_json ?: '{}'); ?>">
            <div class="bp-cv-app">
                <div class="bp-cv-sidebar">
                    <button type="button" class="bp-cv-nav is-active" data-bp-cv-tab="inhoud"><span class="bp-cv-nav-ico">E</span><span>Inhoud</span></button>
                    <button type="button" class="bp-cv-nav" data-bp-cv-tab="template"><span class="bp-cv-nav-ico">D</span><span>CV details</span></button>
                </div>
                <div class="bp-cv-main">
                    <div class="bp-cv-panel is-active" data-bp-cv-panel="inhoud">
                        <div class="bp-cv-grid">
                            <div class="bp-cv-editor">
                                <section class="bp-cv-card">
                                    <h2>Persoonlijke gegevens</h2>
                                    <div class="bp-cv-row">
                                        <label class="bp-cv-field"><span>Voornaam</span><input type="text" value="<?php echo esc_attr($first_name); ?>" data-bp-cv-bind="first_name" /></label>
                                        <label class="bp-cv-field"><span>Achternaam</span><input type="text" value="<?php echo esc_attr($last_name); ?>" data-bp-cv-bind="last_name" /></label>
                                    </div>
                                    <label class="bp-cv-field"><span>Foto</span><span class="bp-cv-photo-upload"><input type="file" accept="image/*" data-bp-cv-photo /><strong data-bp-cv-photo-label>Upload een bestand of sleep het hierheen</strong></span></label>
                                    <label class="bp-cv-field"><span>E-mailadres</span><input type="email" value="<?php echo esc_attr($email); ?>" data-bp-cv-bind="email" /></label>
                                    <label class="bp-cv-field"><span>Functie</span><input type="text" value="<?php echo esc_attr($functie); ?>" data-bp-cv-bind="functie" /></label>
                                    <button type="button" class="bp-cv-more" data-bp-cv-toggle-extra>Meer persoonlijke gegevens <span>v</span></button>
                                    <div class="bp-cv-extra" hidden>
                                        <div class="bp-cv-row">
                                            <label class="bp-cv-field"><span>Telefoon</span><input type="text" placeholder="+31 6 12345678" data-bp-cv-bind="phone" /></label>
                                            <label class="bp-cv-field"><span>Plaats</span><input type="text" placeholder="Amsterdam" data-bp-cv-bind="plaats" /></label>
                                        </div>
                                    </div>
                                </section>

                            </div>
                            <aside class="bp-cv-preview">
                                <div class="bp-cv-paper" data-bp-template="modern">
                                    <div class="bp-cv-paper-main">
                                        <div class="bp-cv-paper-header">
                                            <div class="bp-cv-avatar" data-bp-cv-avatar><?php echo esc_html(strtoupper((string) substr($full_name, 0, 1))); ?></div>
                                            <div><h3 data-bp-cv-target="full_name"><?php echo esc_html($full_name); ?></h3><p data-bp-cv-target="functie"><?php echo esc_html($functie); ?></p></div>
                                        </div>
                                        <p class="bp-cv-paper-profile" data-bp-cv-target="profile"><?php echo esc_html($profile_text); ?></p>
                                        <h4>Werkervaring</h4>
                                        <div data-bp-cv-list="experience"></div>
                                        <h4>Opleiding</h4>
                                        <div data-bp-cv-list="education"></div>
                                    </div>
                                    <div class="bp-cv-paper-side">
                                        <h5>Personalia</h5>
                                        <dl>
                                            <dt>Naam</dt><dd data-bp-cv-target="full_name"><?php echo esc_html($full_name); ?></dd>
                                            <dt>E-mail</dt><dd class="bp-cv-email" data-bp-cv-target="email"><?php echo esc_html($email); ?></dd>
                                            <dt>Telefoon</dt><dd data-bp-cv-target="phone">-</dd>
                                            <dt>Plaats</dt><dd data-bp-cv-target="plaats">-</dd>
                                        </dl>
                                        <h5>Vaardigheden</h5>
                                        <div data-bp-cv-list="skills"></div>
                                        <h5>Certificaten</h5>
                                        <div data-bp-cv-list="certificates"></div>
                                    </div>
                                </div>
                            </aside>
                        </div>
                    </div>
                    <div class="bp-cv-panel" data-bp-cv-panel="template" hidden>
                        <div class="bp-cv-details-grid">
                            <section class="bp-cv-card bp-cv-card-preview">
                                <aside class="bp-cv-preview">
                                    <div class="bp-cv-paper" data-bp-template="modern">
                                        <div class="bp-cv-paper-main">
                                            <div class="bp-cv-paper-header">
                                                <div class="bp-cv-avatar" data-bp-cv-avatar><?php echo esc_html(strtoupper((string) substr($full_name, 0, 1))); ?></div>
                                                <div><h3 data-bp-cv-target="full_name"><?php echo esc_html($full_name); ?></h3><p data-bp-cv-target="functie"><?php echo esc_html($functie); ?></p></div>
                                            </div>
                                            <p class="bp-cv-paper-profile" data-bp-cv-target="profile"><?php echo esc_html($profile_text); ?></p>
                                            <h4>Werkervaring</h4>
                                            <div data-bp-cv-list="experience"></div>
                                            <h4>Opleiding</h4>
                                            <div data-bp-cv-list="education"></div>
                                        </div>
                                        <div class="bp-cv-paper-side">
                                            <h5>Personalia</h5>
                                            <dl>
                                                <dt>Naam</dt><dd data-bp-cv-target="full_name"><?php echo esc_html($full_name); ?></dd>
                                                <dt>E-mail</dt><dd class="bp-cv-email" data-bp-cv-target="email"><?php echo esc_html($email); ?></dd>
                                                <dt>Telefoon</dt><dd data-bp-cv-target="phone">-</dd>
                                                <dt>Plaats</dt><dd data-bp-cv-target="plaats">-</dd>
                                            </dl>
                                            <h5>Vaardigheden</h5>
                                            <div data-bp-cv-list="skills"></div>
                                            <h5>Certificaten</h5>
                                            <div data-bp-cv-list="certificates"></div>
                                        </div>
                                    </div>
                                </aside>
                            </section>

                            <section class="bp-cv-card">
                                <h2>Persoonlijk profiel</h2>
                                <p>Korte alinea die boven aan je cv komt te staan.</p>
                                <textarea rows="5" data-bp-cv-bind="profile"><?php echo esc_textarea($profile_text); ?></textarea>
                            </section>

                            <section class="bp-cv-card" data-bp-repeater="experience">
                                <div class="bp-cv-repeat-head"><h2>Werkervaring</h2><button type="button" class="bp-btn bp-btn-light" data-bp-repeater-add>+ Toevoegen</button></div>
                                <div class="bp-cv-repeat-list"></div>
                            </section>

                            <section class="bp-cv-card" data-bp-repeater="education">
                                <div class="bp-cv-repeat-head"><h2>Opleidingen</h2><button type="button" class="bp-btn bp-btn-light" data-bp-repeater-add>+ Toevoegen</button></div>
                                <div class="bp-cv-repeat-list"></div>
                            </section>

                            <section class="bp-cv-card" data-bp-repeater="skills">
                                <div class="bp-cv-repeat-head"><h2>Skills</h2><button type="button" class="bp-btn bp-btn-light" data-bp-repeater-add>+ Toevoegen</button></div>
                                <div class="bp-cv-repeat-list"></div>
                            </section>

                            <section class="bp-cv-card" data-bp-repeater="certificates">
                                <div class="bp-cv-repeat-head"><h2>Certificaten</h2><button type="button" class="bp-btn bp-btn-light" data-bp-repeater-add>+ Toevoegen</button></div>
                                <div class="bp-cv-repeat-list"></div>
                            </section>

                            <section class="bp-cv-card">
                                <div class="bp-cv-template-picker">
                                    <h2>Kies een template</h2>
                                    <div class="bp-cv-template-grid" data-bp-template-grid>
                                        <button type="button" class="is-active" data-bp-template="modern">Modern links accent</button>
                                        <button type="button" data-bp-template="classic">Klassiek zakelijk</button>
                                        <button type="button" data-bp-template="compact">Compact strak</button>
                                    </div>
                                    <div class="bp-cv-template-controls">
                                        <label>Hoofdkleur <input type="color" value="#15776a" data-bp-template-color-main /></label>
                                        <label>Zijkleur <input type="color" value="#5caea3" data-bp-template-color-side /></label>
                                        <label>Tekstkleur <input type="color" value="#111827" data-bp-template-color-text /></label>
                                    </div>
                                    <div class="bp-cv-template-add">
                                        <input type="text" placeholder="Naam nieuw template" data-bp-template-name />
                                        <button type="button" class="bp-btn bp-btn-primary" data-bp-template-add>Template toevoegen</button>
                                    </div>
                                </div>
                            </section>

                            <section class="bp-cv-card">
                                <div class="bp-cv-upload-head">
                                    <div><h2>CV bestand</h2><p>Upload PDF of DOCX (max 5MB)</p></div>
                                    <div class="bp-cv-status"><?php if ($has_cv): ?><span class="bp-pill bp-pill-ok">CV aanwezig</span><?php else: ?><span class="bp-pill bp-pill-warn">Nog geen CV</span><?php endif; ?></div>
                                </div>
                                <?php if ($err): ?><div class="bp-alert bp-alert-error"><?php echo esc_html($err); ?></div><?php elseif ($msg): ?><div class="bp-alert bp-alert-ok"><?php echo esc_html($msg); ?></div><?php endif; ?>
                                <form method="post" enctype="multipart/form-data" class="bp-cv-form">
                                    <?php wp_nonce_field('bp_cv_form'); ?>
                                    <input type="hidden" name="bp_cv_action" value="upload" />
                                    <label class="bp-cv-upload-zone">
                                        <input type="file" name="bp_cv_file" accept=".pdf,.docx" />
                                        <strong class="bp-drop-title">Klik of sleep je CV-bestand hier</strong>
                                        <small>PDF of DOCX</small>
                                    </label>
                                    <button type="submit" class="bp-btn bp-btn-primary" style="display:none">Upload</button>
                                </form>
                                <?php if ($has_cv): ?>
                                    <div class="bp-cv-file">
                                        <div class="bp-cv-file-row"><div class="bp-cv-file-name"><?php echo esc_html($file_name); ?></div><div class="bp-cv-file-date"><?php echo esc_html($date); ?></div></div>
                                        <div class="bp-cv-file-buttons">
                                            <a class="bp-btn bp-btn-primary" href="<?php echo esc_url($download_url); ?>">Download bestand</a>
                                            <button type="button" class="bp-btn bp-btn-light" data-bp-cv-replace="1">Vervangen</button>
                                            <form method="post" class="bp-cv-delete-form">
                                                <?php wp_nonce_field('bp_cv_form'); ?>
                                                <input type="hidden" name="bp_cv_action" value="delete" />
                                                <button type="submit" class="bp-btn bp-btn-danger" onclick="return confirm('CV verwijderen?');">Verwijderen</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bp-cv-bottom">
                <span>1 van 1</span>
                <button type="button" class="bp-btn bp-btn-primary" data-bp-cv-download>Download PDF</button>
                <span>65%</span>
                <button type="button" class="bp-btn bp-btn-light" data-bp-cv-zoom="in">+</button>
                <button type="button" class="bp-btn bp-btn-light" data-bp-cv-zoom="out">-</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function handle_upload(int $user_id) {
        if (!isset($_FILES['bp_cv_file']) || empty($_FILES['bp_cv_file']['name'])) {
            return new \WP_Error('no_file', 'Kies een bestand.');
        }

        $file = $_FILES['bp_cv_file'];

        if (!empty($file['error'])) {
            return new \WP_Error('upload_error', 'Uploadfout: ' . (int)$file['error']);
        }

        if ((int)$file['size'] > Util::max_bytes()) {
            return new \WP_Error('too_big', 'Bestand is te groot (max 5MB).');
        }
        $allowed = Util::allowed_mimes();

        // Extra check: bestand moet echt een PDF of DOCX zijn (niet alleen de extensie).
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed);
        if (empty($check['ext']) || empty($check['type'])) {
            return new \WP_Error('bad_type', 'Alleen PDF of DOCX toegestaan.');
        }

        // Remove old CV (v3 table + file)
        Util::delete_kb_cv($user_id);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        Util::ensure_upload_dir();
        $safe_name = sanitize_file_name($file['name']);
        $upload_filter = static function(array $dirs): array {
            $dirs['subdir'] = '/kb-cv';
            $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        };
        add_filter('upload_dir', $upload_filter);
        $uploaded = wp_handle_upload($file, [
            'test_form' => false,
            'mimes'     => $allowed,
            'unique_filename_callback' => static function($dir, $name, $ext) use ($user_id) {
                return 'cv_' . (int) $user_id . '_' . time() . $ext;
            },
        ]);
        remove_filter('upload_dir', $upload_filter);

        if (!is_array($uploaded) || !empty($uploaded['error']) || empty($uploaded['file'])) {
            return new \WP_Error('move_fail', 'Opslaan mislukt. Controleer of uploads schrijfbaar is.');
        }
        $target = (string) $uploaded['file'];

        // Nettere rechten op server (niet wereld-leesbaar).
        @chmod($target, 0640);

        $ok = Util::upsert_kb_cv($user_id, $safe_name, $target, '');
        if (!$ok) {
            // Clean up file if DB failed
            @unlink($target);
            return new \WP_Error('db_fail', 'Upload opgeslagen, maar kon niet registreren in de database.');
        }

        return true;
    }

    private static function handle_delete(int $user_id) {
        Util::delete_kb_cv($user_id);
        return true;
    }
}
