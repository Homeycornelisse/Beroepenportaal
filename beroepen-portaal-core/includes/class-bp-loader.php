<?php
defined('ABSPATH') || exit;

require_once BP_CORE_DIR . 'includes/class-bp-maintenance.php';
require_once BP_CORE_DIR . 'includes/class-bp-roles.php';
require_once BP_CORE_DIR . 'includes/class-bp-audit.php';
require_once BP_CORE_DIR . 'admin/class-bp-admin-menu.php';
require_once BP_CORE_DIR . 'includes/class-bp-template-loader.php';
require_once BP_CORE_DIR . 'includes/class-bp-blocks.php';
require_once BP_CORE_DIR . 'includes/functions.php';
require_once BP_CORE_DIR . 'includes/class-bp-user-caps.php';
require_once BP_CORE_DIR . 'includes/class-bp-addon-access.php';
require_once BP_CORE_DIR . 'includes/class-bp-traject-tracker.php';

/**
 * Centrale loader voor Beroepen Portaal Core.
 */
final class BP_Core_Loader {

    private static ?BP_Core_Loader $instance = null;

    public static function instance(): BP_Core_Loader {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init(): void {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('template_redirect', [__CLASS__, 'enforce_front_login_wall'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'localize_sensitive_guard_auth'], 999);
        add_action('wp_ajax_bp_core_verify_account_password', [__CLASS__, 'ajax_verify_account_password']);
        add_action('wp_ajax_bp_core_verify_admin_password', [__CLASS__, 'ajax_verify_admin_password']);
        add_action('admin_init', [__CLASS__, 'enforce_plugin_remove_guard'], 1);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_plugin_guard_script']);

        // Rollen & rechten
        add_action('init', function(){
            if (class_exists('BP_Core_Roles')) {
                BP_Core_Roles::init();
            }
        }, 1);

        // Login formulier verwerken (front-end login template)
        add_action('init', function() {
            if (empty($_POST['kb_login_nonce'])) return;
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kb_login_nonce'])), 'kb_login')) return;

            $posted_back = isset($_POST['kb_login_back']) ? esc_url_raw((string) wp_unslash($_POST['kb_login_back'])) : '';
            $back = $posted_back ?: wp_get_referer();
            if (!$back) {
                $linked = function_exists('bp_core_get_linked_pages') ? bp_core_get_linked_pages() : [];
                $login_id = isset($linked['login']) ? (int) $linked['login'] : 0;
                $back = $login_id > 0 ? get_permalink($login_id) : home_url('/');
            }
            $back = wp_validate_redirect((string) $back, home_url('/'));
            $twofa_code_posted = isset($_POST['kb_2fa_code']) ? sanitize_text_field((string) wp_unslash($_POST['kb_2fa_code'])) : '';
            $twofa_token = self::get_frontend_2fa_token_from_cookie();
            if ($twofa_token !== '' || $twofa_code_posted !== '') {
                if ($twofa_token === '') {
                    wp_safe_redirect(add_query_arg(['bp_login_fout' => '2fa_expired'], $back));
                    exit;
                }

                $code = isset($_POST['kb_2fa_code']) ? sanitize_text_field((string) wp_unslash($_POST['kb_2fa_code'])) : '';
                $result = self::verify_2fa_challenge($twofa_token, $code);

                if (is_wp_error($result)) {
                    $reason = $result->get_error_code();
                    $args = ['bp_login_fout' => $reason];
                    if (!in_array($reason, ['2fa_code', '2fa_format'], true)) {
                        self::clear_frontend_2fa_cookie();
                    }
                    wp_safe_redirect(add_query_arg($args, $back));
                    exit;
                }

                $user = $result['user'];
                $remember = !empty($result['remember']);
                $redirect_to = (string) ($result['redirect_to'] ?? '');
                if (!$redirect_to) {
                    $linked = function_exists('bp_core_get_linked_pages') ? bp_core_get_linked_pages() : [];
                    $dash_id = $linked['dashboard'] ?? 0;
                    $redirect_to = $dash_id ? get_permalink((int)$dash_id) : home_url('/');
                }

                wp_set_current_user((int) $user->ID);
                wp_set_auth_cookie((int) $user->ID, $remember, is_ssl());
                do_action('wp_login', (string) $user->user_login, $user);
                self::clear_frontend_2fa_cookie();

                wp_safe_redirect($redirect_to);
                exit;
            }

            $email     = sanitize_text_field((string)($_POST['email'] ?? ''));
            $wachtwoord = (string)($_POST['wachtwoord'] ?? '');
            $onthouden  = !empty($_POST['onthouden']);
            $redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';

            $user = wp_authenticate($email, $wachtwoord);

            if (is_wp_error($user)) {
                // Terug naar de login pagina met foutmelding
                wp_safe_redirect(add_query_arg('bp_login_fout', '1', $back));
                exit;
            }

            if (self::requires_2fa_for_user($user)) {
                $method = self::user_has_mobile_2fa($user) ? 'totp' : 'email';
                $token = self::start_2fa_challenge($user, $onthouden, $redirect_to, $method);
                if ($token === '') {
                    wp_safe_redirect(add_query_arg('bp_login_fout', '2fa_mail', $back));
                    exit;
                }
                self::set_frontend_2fa_cookie($token, $method);
                wp_safe_redirect(add_query_arg(['bp_login_fout' => '2fa_required'], $back));
                exit;
            }

            // Succesvol ingelogd: ga naar redirect_to of dashboard
            if (!$redirect_to) {
                $linked = function_exists('bp_core_get_linked_pages') ? bp_core_get_linked_pages() : [];
                $dash_id = $linked['dashboard'] ?? 0;
                $redirect_to = $dash_id ? get_permalink((int)$dash_id) : home_url('/');
            }

            wp_set_current_user((int) $user->ID);
            wp_set_auth_cookie((int) $user->ID, $onthouden, is_ssl());
            do_action('wp_login', (string) $user->user_login, $user);
            self::clear_frontend_2fa_cookie();

            wp_safe_redirect($redirect_to);
            exit;
        }, 10);

        // 2FA afdwingen op standaard WordPress login (wp-login.php), zodat wp-admin geen bypass is.
        add_action('login_form', function() {
            $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : 'login';
            if ($action !== 'login') return;
            ?>
            <p>
                <label for="bp_wp_2fa_code">2FA-code<br>
                    <input type="text" name="bp_wp_2fa_code" id="bp_wp_2fa_code" class="input" value="" size="20" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
                </label>
            </p>
            <p class="description" style="margin-top:-8px;">
                Gebruik je authenticator-app code. Als mobiele 2FA uit staat, ontvang je een code per e-mail.
            </p>
            <?php
        });

        add_filter('authenticate', function($user, $username, $password) {
            global $pagenow;
            if ($pagenow !== 'wp-login.php') return $user;

            $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : 'login';
            if ($action !== 'login') return $user;

            if (is_wp_error($user) || !($user instanceof WP_User)) return $user;
            if (!self::requires_2fa_for_user($user)) return $user;

            $uid = (int) $user->ID;
            $code = isset($_POST['bp_wp_2fa_code']) ? sanitize_text_field((string) wp_unslash($_POST['bp_wp_2fa_code'])) : '';
            $code = self::normalize_2fa_code($code);

            if (self::user_has_mobile_2fa($user)) {
                $secret = (string) get_user_meta($uid, 'bp_2fa_totp_secret', true);
                if ($secret === '' || !self::verify_totp_code($secret, $code)) {
                    return new WP_Error('bp_2fa_required', __('2FA-code uit authenticator-app is verplicht of onjuist.', 'beroepen-portaal-core'));
                }
                return $user;
            }

            $key = 'bp_2fa_wp_login_' . $uid;
            $challenge = get_transient($key);
            if (!is_array($challenge) || (empty($challenge['code_sig']) && empty($challenge['code_hash'])) || empty($challenge['created_at'])) {
                $email_code = (string) wp_rand(100000, 999999);
                $challenge = [
                    'code_sig'   => self::sign_2fa_code($email_code),
                    'attempts'   => 0,
                    'created_at' => time(),
                ];
                set_transient($key, $challenge, 10 * MINUTE_IN_SECONDS);

                $sent = self::send_2fa_email($user, $email_code);
                if (!$sent) {
                    delete_transient($key);
                    return new WP_Error('bp_2fa_mail_failed', __('2FA-code kon niet per e-mail worden verstuurd. Controleer mailinstellingen.', 'beroepen-portaal-core'));
                }
            }

            if (!preg_match('/^\d{6}$/', $code)) {
                return new WP_Error('bp_2fa_required', __('Vul de 2FA-code in die je per e-mail hebt ontvangen.', 'beroepen-portaal-core'));
            }

            $attempts = isset($challenge['attempts']) ? (int) $challenge['attempts'] : 0;
            if ($attempts >= 5) {
                delete_transient($key);
                return new WP_Error('bp_2fa_locked', __('Te veel onjuiste 2FA-pogingen. Probeer opnieuw in te loggen.', 'beroepen-portaal-core'));
            }

            $ok = false;
            if (!empty($challenge['code_sig'])) {
                $ok = hash_equals((string) $challenge['code_sig'], self::sign_2fa_code($code));
            } elseif (!empty($challenge['code_hash'])) {
                // Backward compatibility with already-issued transient data.
                $ok = wp_check_password($code, (string) $challenge['code_hash'], $uid);
            }

            if (!$ok) {
                $challenge['attempts'] = $attempts + 1;
                set_transient($key, $challenge, 10 * MINUTE_IN_SECONDS);
                return new WP_Error('bp_2fa_required', __('2FA-code is onjuist.', 'beroepen-portaal-core'));
            }

            delete_transient($key);
            return $user;
        }, 50, 3);

        // Addon toegang beheer (ajax)
        if (class_exists('BP_Core_Addon_Access')) {
            BP_Core_Addon_Access::init();
        }

        // Traject tracker shortcode/module
        if (class_exists('BP_Core_Traject_Tracker')) {
            BP_Core_Traject_Tracker::init();
        }

        // Admin menu
        if (is_admin()) {
            BP_Core_Admin_Menu::instance()->init();

            $front_redirect = function(array $args) {
                if (empty($_POST['bp_front_redirect'])) return false;
                $ref = wp_get_referer();
                if (!$ref) $ref = home_url('/');
                $url = add_query_arg($args, $ref);
                wp_safe_redirect($url);
                exit;
            };

            // Admin actions (forms)
            add_action('admin_post_bp_core_repair_roles', function(){
                if (!function_exists('bp_core_can_manage_portaal') || !bp_core_can_manage_portaal()) wp_die('Geen rechten.');
                check_admin_referer('bp_core_repair_roles');
                $oud = BP_Core_Roles::snapshot();
                // Dit is een echte reset naar standaard
                BP_Core_Roles::reset_defaults();

                // Belangrijk: oude (user-level) caps opruimen, anders lijken
                // role-wijzigingen soms "niet te werken".
                if (function_exists('bp_core_cleanup_user_caps')) {
                    bp_core_cleanup_user_caps([
                        BP_Core_Roles::CAP_VIEW_PORTAAL,
                        BP_Core_Roles::CAP_VIEW_CLIENTS,
                        BP_Core_Roles::CAP_ADD_CLIENTS,
                        BP_Core_Roles::CAP_EDIT_AANTEKENINGEN,
                        BP_Core_Roles::CAP_MANAGE_TEAM,
                        BP_Core_Roles::CAP_USE_CV,
                    ]);
                }

                $nieuw = BP_Core_Roles::snapshot();
                BP_Core_Audit::log('roles', null, 'reset', $oud, $nieuw);

                if (!empty($_POST['bp_front_redirect'])) {
                    // Bewaar actief tabblad in de redirect (zodat je niet naar Addons springt)
                    $tab = '';
                    if (!empty($_POST['bp_tab'])) {
                        $tab = sanitize_key((string) $_POST['bp_tab']);
                    }
                    if ($tab === '') {
                        $ref_tmp = wp_get_referer();
                        if ($ref_tmp) {
                            $q = wp_parse_url($ref_tmp, PHP_URL_QUERY);
                            if (!empty($q)) {
                                parse_str($q, $params);
                                if (!empty($params['bp_tab'])) {
                                    $tab = sanitize_key((string) $params['bp_tab']);
                                }
                            }
                        }
                    }
                    if ($tab === '') {
                        $tab = 'rechten';
                    }

                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg(['bp_reset_caps' => 1, 'bp_tab' => $tab], $ref));
                    exit;
                }
                wp_safe_redirect(admin_url('admin.php?page=bp-core-roles&updated=1'));
                exit;
            });

            add_action('admin_post_bp_core_copy_templates', function(){
                if (!function_exists('bp_core_can_manage_portaal') || !bp_core_can_manage_portaal()) wp_die('Geen rechten.');
                check_admin_referer('bp_core_copy_templates');

                $overwrite = !empty($_POST['overwrite']);

                $src_dir = BP_CORE_DIR . 'templates/';
                $dst_dir = trailingslashit(get_stylesheet_directory()) . 'beroepen-portaal/';

                if (!is_dir($src_dir)) {
                    wp_safe_redirect(add_query_arg(['page' => 'bp-core-templates', 'bp_done' => '0'], admin_url('admin.php')));
                    exit;
                }

                if (!wp_mkdir_p($dst_dir)) {
                    wp_safe_redirect(add_query_arg(['page' => 'bp-core-templates', 'bp_done' => '0'], admin_url('admin.php')));
                    exit;
                }

                $ok = true;
                foreach (glob($src_dir . '*.php') as $file) {
                    $base = basename($file);
                    $dest = $dst_dir . $base;

                    if (file_exists($dest) && !$overwrite) {
                        continue;
                    }
                    if (!@copy($file, $dest)) {
                        $ok = false;
                    }
                }

                wp_safe_redirect(add_query_arg(['page' => 'bp-core-templates', 'bp_done' => $ok ? '1' : '0'], admin_url('admin.php')));
                exit;
            });

            add_action('admin_post_bp_core_create_user', function(){
                if (!function_exists('bp_core_can_manage_portaal') || !bp_core_can_manage_portaal()) wp_die('Geen rechten.');
                check_admin_referer('bp_core_create_user');

                $naam  = sanitize_text_field((string)($_POST['bp_name'] ?? ''));
                $email = sanitize_email((string)($_POST['bp_email'] ?? ''));
                $rol   = sanitize_text_field((string)($_POST['bp_role'] ?? BP_Core_Roles::ROLE_CLIENT));
                $pass  = (string)($_POST['bp_pass'] ?? '');
                $begeleider_id = (int)($_POST['bp_begeleider_id'] ?? 0);
                $leidinggevende_id = (int)($_POST['bp_leidinggevende_id'] ?? 0);

                if (!$naam || !$email) {
                    wp_safe_redirect(admin_url('admin.php?page=bp-core-users&error=1'));
                    exit;
                }

                if (!$pass) {
                    $pass = wp_generate_password(14, true);
                }

                // Login naam = email (simpel)
                $user_id = wp_insert_user([
                    'user_login'   => $email,
                    'user_email'   => $email,
                    'display_name' => $naam,
                    'user_pass'    => $pass,
                    'role'         => $rol,
                ]);

                if (is_wp_error($user_id) || !$user_id) {
                    wp_safe_redirect(admin_url('admin.php?page=bp-core-users&error=1'));
                    exit;
                }

                BP_Core_Audit::log('user', (int)$user_id, 'create', null, [
                    'naam' => $naam,
                    'email' => $email,
                    'rol' => $rol,
                    'begeleider_id' => $begeleider_id,
                    'leidinggevende_id' => $leidinggevende_id,
                ]);

                if (!empty($_POST['bp_front_redirect'])) {
                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg(['bp_created_user' => 1], $ref));
                    exit;
                }

                // Koppelingen (compat met bestaande meta)
                if ($rol === BP_Core_Roles::ROLE_CLIENT) {
                    if ($begeleider_id > 0) {
                        update_user_meta((int)$user_id, 'kb_begeleider_id', $begeleider_id);
                    }
                    if ($leidinggevende_id > 0) {
                        update_user_meta((int)$user_id, 'kb_leidinggevende_id', $leidinggevende_id);
                    }
                }

                if ($rol === BP_Core_Roles::ROLE_BEGELEIDER && $leidinggevende_id > 0) {
                    update_user_meta((int)$user_id, 'kb_leidinggevende_id', $leidinggevende_id);
                }

                wp_safe_redirect(admin_url('admin.php?page=bp-core-users&created=1'));
                exit;
            });

            add_action('admin_post_bp_core_save_security_settings', function() {
                if (!current_user_can('manage_options')) wp_die('Geen rechten.');
                check_admin_referer('bp_core_save_security_settings');

                $posted = isset($_POST['bp_2fa_roles']) && is_array($_POST['bp_2fa_roles']) ? (array) $_POST['bp_2fa_roles'] : [];
                $allowed = [
                    BP_Core_Roles::ROLE_BEGELEIDER,
                    BP_Core_Roles::ROLE_LEIDINGGEVENDE,
                    'administrator',
                ];
                $roles = [];
                foreach ($posted as $role) {
                    $role = sanitize_key((string) $role);
                    if (in_array($role, $allowed, true)) {
                        $roles[] = $role;
                    }
                }
                $roles = array_values(array_unique($roles));
                update_option('bp_core_2fa_required_roles', $roles, false);

                wp_safe_redirect(add_query_arg('bp_saved_security', '1', admin_url('admin.php?page=bp-core-settings')));
                exit;
            });

            add_action('admin_post_bp_core_save_brand_settings', function() {
                if (!current_user_can('manage_options')) wp_die('Geen rechten.');
                check_admin_referer('bp_core_save_brand_settings');

                $org_name = isset($_POST['bp_core_org_name']) ? sanitize_text_field((string) wp_unslash($_POST['bp_core_org_name'])) : '';
                $org_logo = isset($_POST['bp_core_org_logo']) ? esc_url_raw((string) wp_unslash($_POST['bp_core_org_logo'])) : '';
                $site_icon = isset($_POST['bp_core_site_icon']) ? esc_url_raw((string) wp_unslash($_POST['bp_core_site_icon'])) : '';

                update_option('bp_core_org_name', $org_name, false);
                update_option('bp_core_org_logo', $org_logo, false);
                update_option('bp_core_site_icon', $site_icon, false);

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
                $posted = isset($_POST['bp_core_brand_colors']) && is_array($_POST['bp_core_brand_colors']) ? (array) wp_unslash($_POST['bp_core_brand_colors']) : [];
                $colors = [];
                foreach ($defaults as $key => $fallback) {
                    $raw = isset($posted[$key]) ? (string) $posted[$key] : $fallback;
                    $hex = sanitize_hex_color($raw);
                    $colors[$key] = $hex ?: $fallback;
                }
                update_option('bp_core_brand_colors', $colors, false);

                wp_safe_redirect(add_query_arg('bp_saved_brand', '1', admin_url('admin.php?page=bp-core-settings')));
                exit;
            });

            add_action('admin_post_bp_core_save_login_wall_settings', function() {
                if (!current_user_can('manage_options')) wp_die('Geen rechten.');
                check_admin_referer('bp_core_save_login_wall_settings');

                $posted_pages = isset($_POST['bp_login_wall_pages']) && is_array($_POST['bp_login_wall_pages'])
                    ? (array) wp_unslash($_POST['bp_login_wall_pages'])
                    : [];
                $page_ids = array_values(array_unique(array_filter(array_map('absint', $posted_pages), static fn($id) => $id > 0)));
                if (function_exists('bp_core_set_login_wall_pages')) {
                    bp_core_set_login_wall_pages($page_ids);
                } else {
                    update_option('bp_core_login_wall_pages', $page_ids, false);
                }

                wp_safe_redirect(add_query_arg('bp_saved_loginwall', '1', admin_url('admin.php?page=bp-core-settings&tab=loginwall')));
                exit;
            });

            add_action('admin_post_bp_core_save_role_caps', function(){
                if (!current_user_can('manage_options')) wp_die('Geen rechten.');
                check_admin_referer('bp_core_save_role_caps');

                $oud = BP_Core_Roles::snapshot();

                $roles = [
                    BP_Core_Roles::ROLE_LEIDINGGEVENDE,
                    BP_Core_Roles::ROLE_BEGELEIDER,
                    BP_Core_Roles::ROLE_CLIENT,
                    'administrator',
                ];

                $caps = [
                    BP_Core_Roles::CAP_VIEW_PORTAAL,
                    BP_Core_Roles::CAP_VIEW_CLIENTS,
                    BP_Core_Roles::CAP_ADD_CLIENTS,
                    BP_Core_Roles::CAP_EDIT_AANTEKENINGEN,
                    BP_Core_Roles::CAP_MANAGE_TEAM,
                    BP_Core_Roles::CAP_USE_CV,
                ];

                $posted = (array)($_POST['caps'] ?? []);

                foreach ($roles as $role_key) {
                    $role = get_role($role_key);
                    if (!$role) continue;

                    // read altijd aan laten
                    if (!$role->has_cap('read')) {
                        $role->add_cap('read');
                    }

                    foreach ($caps as $cap_key) {
                        $want = !empty($posted[$role_key][$cap_key]);
                        $has  = $role->has_cap($cap_key);

                        if ($want && !$has) {
                            $role->add_cap($cap_key);
                        } elseif (!$want && $has) {
                            $role->remove_cap($cap_key);
                        }
                    }
                }

                // Ook hier: user-level caps opruimen zodat alleen rol-rechten gelden.
                if (function_exists('bp_core_cleanup_user_caps')) {
                    bp_core_cleanup_user_caps($caps);
                }

                $nieuw = BP_Core_Roles::snapshot();
                BP_Core_Audit::log('roles', null, 'save_caps', $oud, $nieuw);

                if (!empty($_POST['bp_front_redirect'])) {
                    // Bewaar actief tabblad in de redirect (zodat je niet naar Addons springt)
                    $tab = '';
                    if (!empty($_POST['bp_tab'])) {
                        $tab = sanitize_key((string) $_POST['bp_tab']);
                    }
                    if ($tab === '') {
                        $ref_tmp = wp_get_referer();
                        if ($ref_tmp) {
                            $q = wp_parse_url($ref_tmp, PHP_URL_QUERY);
                            if (!empty($q)) {
                                parse_str($q, $params);
                                if (!empty($params['bp_tab'])) {
                                    $tab = sanitize_key((string) $params['bp_tab']);
                                }
                            }
                        }
                    }
                    if ($tab === '') {
                        $tab = 'rechten';
                    }

                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg(['bp_saved_caps' => 1, 'bp_tab' => $tab], $ref));
                    exit;
                }

                wp_safe_redirect(admin_url('admin.php?page=bp-core-roles&saved=1'));
                exit;
            });

            // Per gebruiker: rol + extra rechten (allow/deny)
            add_action('admin_post_bp_core_save_user_caps', function(){
                if (!current_user_can('manage_options')) wp_die('Geen rechten.');
                check_admin_referer('bp_core_save_user_caps');

                // Bewaar huidig tabblad (front-end dashboard gebruikt dit).
                $tab = '';
                if (!empty($_POST['bp_tab'])) {
                    $tab = sanitize_key((string) $_POST['bp_tab']);
                }
                if ($tab === '') {
                    $ref_tmp = wp_get_referer();
                    if ($ref_tmp) {
                        $q = wp_parse_url($ref_tmp, PHP_URL_QUERY);
                        if (!empty($q)) {
                            parse_str($q, $params);
                            if (!empty($params['bp_tab'])) {
                                $tab = sanitize_key((string) $params['bp_tab']);
                            }
                        }
                    }
                }

                $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
                if ($user_id <= 0) {
                    wp_safe_redirect(admin_url('admin.php?page=bp-core-user-caps'));
                    exit;
                }

                $user = get_user_by('id', $user_id);
                if (!$user) {
                    wp_safe_redirect(admin_url('admin.php?page=bp-core-user-caps'));
                    exit;
                }

                $old_role = !empty($user->roles[0]) ? $user->roles[0] : '';
                $old_overrides = function_exists('bp_user_get_caps_overrides') ? bp_user_get_caps_overrides($user_id) : [];

                $role = isset($_POST['user_role']) ? sanitize_key((string) $_POST['user_role']) : '';
                $allowed_roles = [
                    BP_Core_Roles::ROLE_LEIDINGGEVENDE,
                    BP_Core_Roles::ROLE_BEGELEIDER,
                    BP_Core_Roles::ROLE_CLIENT,
                ];
                if (!in_array($role, $allowed_roles, true)) {
                    $role = $old_role;
                }

                if ($role && $role !== $old_role) {
                    $u = new WP_User($user_id);
                    $u->set_role($role);
                }

                $known_caps = [
                    BP_Core_Roles::CAP_VIEW_PORTAAL,
                    BP_Core_Roles::CAP_VIEW_CLIENTS,
                    BP_Core_Roles::CAP_ADD_CLIENTS,
                    BP_Core_Roles::CAP_EDIT_AANTEKENINGEN,
                    BP_Core_Roles::CAP_MANAGE_TEAM,
                    BP_Core_Roles::CAP_USE_CV,
                ];

                $posted = isset($_POST['user_caps']) && is_array($_POST['user_caps']) ? (array) $_POST['user_caps'] : [];
                $new_overrides = [];
                foreach ($posted as $cap => $state) {
                    $cap = sanitize_key((string) $cap);
                    $state = is_string($state) ? strtolower(trim($state)) : '';
                    if (!in_array($cap, $known_caps, true)) continue;
                    if ($state === 'allow' || $state === 'deny') {
                        $new_overrides[$cap] = $state;
                    }
                }

                if (function_exists('bp_user_set_caps_overrides')) {
                    bp_user_set_caps_overrides($user_id, $new_overrides);
                }

                $new_role = $role ?: $old_role;
                $new_overrides_saved = function_exists('bp_user_get_caps_overrides') ? bp_user_get_caps_overrides($user_id) : $new_overrides;

                if (class_exists('BP_Core_Audit')) {
                    BP_Core_Audit::log('user_caps', $user_id, 'update', [
                        'role' => $old_role,
                        'overrides' => $old_overrides,
                    ], [
                        'role' => $new_role,
                        'overrides' => $new_overrides_saved,
                    ]);
                }

                $ref = wp_get_referer();
                if (!$ref) {
                    $ref = admin_url('admin.php?page=bp-core-user-caps&user_id=' . $user_id);
                }

                // Als dit uit het front-end dashboard komt: hou tab + geselecteerde gebruiker vast.
                $is_front = !empty($_POST['bp_front_redirect']);
                $args = ['bp_saved' => 1, 'user_id' => $user_id];
                if ($is_front) {
                    if ($tab === '') $tab = 'usercaps';
                    $args['bp_tab'] = $tab;
                    $args['bp_caps_user'] = $user_id;
                }

                wp_safe_redirect(add_query_arg($args, $ref));
                exit;
            });

            add_action('admin_post_bp_core_save_user_addon_access', function(){
                if (!function_exists('bp_core_can_manage_portaal') || !bp_core_can_manage_portaal()) wp_die('Geen rechten.');
                check_admin_referer('bp_core_save_user_addon_access');

                // Bewaar het huidige tabblad (zodat je niet steeds "springt").
                $tab = '';
                if (!empty($_POST['bp_tab'])) {
                    $tab = sanitize_key((string) $_POST['bp_tab']);
                }
                if ($tab === '') {
                    $ref_tmp = wp_get_referer();
                    if ($ref_tmp) {
                        $q = wp_parse_url($ref_tmp, PHP_URL_QUERY);
                        if (!empty($q)) {
                            parse_str($q, $params);
                            if (!empty($params['bp_tab'])) {
                                $tab = sanitize_key((string) $params['bp_tab']);
                            }
                        }
                    }
                }
                if ($tab === '') {
                    $tab = 'addons';
                }

                $target_user = (int)($_POST['bp_target_user'] ?? 0);
                if ($target_user <= 0) {
                    $ref = wp_get_referer() ?: admin_url('admin.php?page=bp-core-users');
                    wp_safe_redirect(add_query_arg(['bp_addon_access_error' => 1, 'bp_tab' => $tab], $ref));
                    exit;
                }

                $oud = function_exists('bp_core_get_user_addon_access') ? bp_core_get_user_addon_access($target_user) : [];

                $posted = (array)($_POST['bp_addon_access'] ?? []);
                $addons = function_exists('bp_core_get_registered_addons') ? bp_core_get_registered_addons() : [];
                $newmap = [];

                foreach ($addons as $slug => $info) {
                    $slug = sanitize_key((string)$slug);
                    if ($slug === '') continue;
                    $mode = isset($posted[$slug]) ? strtolower(sanitize_text_field((string)$posted[$slug])) : 'inherit';
                    if ($mode === 'allow' || $mode === 'deny') {
                        $newmap[$slug] = $mode;
                    }
                }

                // Opslaan
                if (empty($newmap)) {
                    delete_user_meta($target_user, 'bp_addon_access');
                } else {
                    update_user_meta($target_user, 'bp_addon_access', $newmap);
                }

                // Audit
                if (class_exists('BP_Core_Audit')) {
                    BP_Core_Audit::log('addon_access', $target_user, 'save', $oud, $newmap);
                }

                $ref = wp_get_referer() ?: admin_url('admin.php?page=bp-core-users');
                wp_safe_redirect(add_query_arg(['bp_addon_access_saved' => 1, 'bp_target_user' => $target_user, 'bp_tab' => $tab], $ref));
                exit;
            });

            add_action('admin_post_bp_core_bulk_transfer_leidinggevende', function(){
                if (!function_exists('bp_core_can_manage_portaal') || !bp_core_can_manage_portaal()) wp_die('Geen rechten.');
                check_admin_referer('bp_core_bulk_transfer_leidinggevende');

                $from = (int)($_POST['bp_from_leid'] ?? 0);
                $to   = (int)($_POST['bp_to_leid'] ?? 0);

                if ($from <= 0 || $to <= 0 || $from === $to) {
                    wp_safe_redirect(admin_url('admin.php?page=bp-core-users&bulk_error=1'));
                    exit;
                }

                // Begeleiders overzetten
                $begels = get_users([
                    'role' => BP_Core_Roles::ROLE_BEGELEIDER,
                    'meta_key' => 'kb_leidinggevende_id',
                    'meta_value' => (string)$from,
                    'number' => 2000,
                    'fields' => 'ID',
                ]);

                foreach ($begels as $bid) {
                    update_user_meta((int)$bid, 'kb_leidinggevende_id', $to);
                }

                // Cliënten overzetten
                $clients = get_users([
                    'role' => BP_Core_Roles::ROLE_CLIENT,
                    'meta_key' => 'kb_leidinggevende_id',
                    'meta_value' => (string)$from,
                    'number' => 5000,
                    'fields' => 'ID',
                ]);

                foreach ($clients as $cid) {
                    update_user_meta((int)$cid, 'kb_leidinggevende_id', $to);
                }

                BP_Core_Audit::log('transfer', null, 'bulk_leidinggevende', [
                    'from' => $from,
                ], [
                    'to' => $to,
                    'begeleiders' => count($begels),
                    'clients' => count($clients),
                ]);

                if (!empty($_POST['bp_front_redirect'])) {
                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg(['bp_transfer_done' => 1], $ref));
                    exit;
                }

                wp_safe_redirect(admin_url('admin.php?page=bp-core-users&bulk_done=1'));
                exit;
            });

            add_action('admin_post_bp_core_transfer_clients_begeleider', function(){
                if (!function_exists('bp_core_can_manage_portaal') || !bp_core_can_manage_portaal()) wp_die('Geen rechten.');
                check_admin_referer('bp_core_transfer_clients_begeleider');

                $from = (int)($_POST['bp_from_begel'] ?? 0);
                $to   = (int)($_POST['bp_to_begel'] ?? 0);

                if ($from <= 0 || $to <= 0 || $from === $to) {
                    wp_safe_redirect(admin_url('admin.php?page=bp-core-users&transfer_error=1'));
                    exit;
                }

                $to_leid = (int)get_user_meta($to, 'kb_leidinggevende_id', true);

                $clients = get_users([
                    'role' => BP_Core_Roles::ROLE_CLIENT,
                    'meta_key' => 'kb_begeleider_id',
                    'meta_value' => (string)$from,
                    'number' => 5000,
                    'fields' => 'ID',
                ]);

                foreach ($clients as $cid) {
                    update_user_meta((int)$cid, 'kb_begeleider_id', $to);
                    if ($to_leid > 0) {
                        update_user_meta((int)$cid, 'kb_leidinggevende_id', $to_leid);
                    }
                }

                BP_Core_Audit::log('transfer', null, 'clients_begeleider', [
                    'from' => $from,
                ], [
                    'to' => $to,
                    'clients' => count($clients),
                    'to_leidinggevende' => $to_leid,
                ]);

                if (!empty($_POST['bp_front_redirect'])) {
                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg(['bp_transfer_done' => 1], $ref));
                    exit;
                }

                wp_safe_redirect(admin_url('admin.php?page=bp-core-users&transfer_done=1'));
                exit;
            });

            // Begeleider vraagt overname aan bij leidinggevende (bericht + e-mail)
            add_action('admin_post_bp_overname_verzoek', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');

                $client_id = (int)($_POST['client_id'] ?? 0);
                if ($client_id <= 0) wp_die('Ongeldig verzoek.');

                check_admin_referer('bp_overname_verzoek_' . $client_id, 'bp_overname_nonce');

                $me = wp_get_current_user();
                if (!$me || !$me->ID) wp_die('Niet ingelogd.');

                // Alleen begeleiders mogen dit verzoek sturen
                if (!in_array('kb_begeleider', (array) $me->roles, true)) wp_die('Geen rechten.');

                // Cliënt moet direct gekoppeld zijn OF in hetzelfde team vallen.
                $linked_bid = (int) get_user_meta($client_id, 'kb_begeleider_id', true);
                if ($linked_bid !== (int) $me->ID) {
                    $my_leid     = (int) get_user_meta((int) $me->ID, 'kb_leidinggevende_id', true);
                    $client_leid = (int) get_user_meta($client_id, 'kb_leidinggevende_id', true);
                    if ($my_leid <= 0 || $my_leid !== $client_leid) {
                        wp_die('Geen toegang tot deze cliënt.');
                    }
                }

                $client = get_user_by('id', $client_id);
                if (!$client) wp_die('Cliënt niet gevonden.');

                $reden = sanitize_textarea_field((string)($_POST['reden'] ?? ''));

                $onderwerp = 'Overnameverzoek cliënt: ' . $client->display_name;
                $inhoud    = 'Begeleider ' . $me->display_name . ' verzoekt een overname voor cliënt: ' . $client->display_name . '.'
                           . ($reden ? "\n\nToelichting: " . $reden : '');

                // Stuur bericht + e-mail naar eigen leidinggevende van de begeleider
                $eigen_leid_id = (int) get_user_meta((int) $me->ID, 'kb_leidinggevende_id', true);
                if ($eigen_leid_id > 0) {
                    $ontvangers = [$eigen_leid_id];
                } else {
                    // Fallback: alle leidinggevenden (geen admins-only)
                    $ontvangers = array_map('intval', get_users([
                        'role'   => 'kb_leidinggevende',
                        'number' => 50,
                        'fields' => 'ID',
                    ]));
                }

                foreach ($ontvangers as $leid_id) {
                    if (!$leid_id) continue;
                    if (!class_exists('BP_Core_Berichten')) continue;
                    BP_Core_Berichten::stuur(
                        (int) $me->ID,
                        $leid_id,
                        'overname_verzoek',
                        $onderwerp,
                        $inhoud,
                        $client_id
                    );
                    BP_Core_Berichten::stuur_email_notificatie(
                        $leid_id,
                        $onderwerp,
                        $inhoud
                    );
                }

                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_overname_verzonden', '1', $ref));
                exit;
            });

            // Leidinggevende reageert op overname verzoek (goedkeuren/afwijzen)
            add_action('admin_post_bp_overname_reageren', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');

                $bericht_id = (int)($_POST['bericht_id'] ?? 0);
                if ($bericht_id <= 0) wp_die('Ongeldig verzoek.');

                check_admin_referer('bp_overname_reageren_' . $bericht_id, 'bp_overname_reactie_nonce');

                $me = wp_get_current_user();
                if (!$me || !$me->ID) wp_die('Niet ingelogd.');

                // Alleen leidinggevende of admin
                if (!current_user_can('kb_manage_team') && !current_user_can('manage_options')) {
                    wp_die('Geen rechten.');
                }

                $status  = sanitize_key((string)($_POST['status'] ?? ''));
                $reactie = sanitize_textarea_field((string)($_POST['reactie'] ?? ''));
                if (!in_array($status, ['goedgekeurd', 'afgewezen'], true)) wp_die('Ongeldig verzoek.');

                if (!class_exists('BP_Core_Berichten')) wp_die('Berichtensysteem niet beschikbaar.');

                // Haal bericht op (auth: alleen als naar_id = current user)
                $bericht = BP_Core_Berichten::haal_bericht($bericht_id, (int)$me->ID);
                if (!$bericht || $bericht->type !== 'overname_verzoek') wp_die('Bericht niet gevonden.');

                $begeleider_id = (int) $bericht->van_id;
                $client_id     = (int) $bericht->client_id;

                // Status instellen op het verzoek
                BP_Core_Berichten::stel_status_in($bericht_id, (int)$me->ID, $status);

                // Bij goedkeuring: voer overname uit (reset begeleider_id)
                if ($status === 'goedgekeurd' && $client_id > 0) {
                    delete_user_meta($client_id, 'kb_begeleider_id');
                    if (class_exists('BP_Core_Audit')) {
                        BP_Core_Audit::log('overname', $client_id, 'goedgekeurd', ['begeleider' => $begeleider_id], ['nieuwe_begeleider' => 0]);
                    }
                }

                // Stuur terugkoppeling naar begeleider
                $label      = $status === 'goedgekeurd' ? 'goedgekeurd' : 'afgewezen';
                $client_obj = $client_id > 0 ? get_user_by('id', $client_id) : null;
                $cl_naam    = $client_obj ? $client_obj->display_name : '(onbekende cliënt)';

                $reactie_onderwerp = 'Overnameverzoek ' . $label . ': ' . $cl_naam;
                $reactie_inhoud    = $me->display_name . ' heeft uw overnameverzoek voor cliënt ' . $cl_naam . ' ' . $label . '.'
                                   . ($reactie ? "\n\nToelichting: " . $reactie : '');

                BP_Core_Berichten::stuur(
                    (int) $me->ID,
                    $begeleider_id,
                    'overname_reactie',
                    $reactie_onderwerp,
                    $reactie_inhoud,
                    $client_id
                );
                BP_Core_Berichten::stuur_email_notificatie(
                    $begeleider_id,
                    $reactie_onderwerp,
                    $reactie_inhoud
                );

                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg(['bp_reactie_verzonden' => '1', 'bp_tab' => 'inbox'], $ref));
                exit;
            });

            if (!(bool) apply_filters('bp_core_disable_builtin_berichten_actions', false)) {
            // Stuur bericht (client ↔ begeleider)
            add_action('admin_post_bp_stuur_bericht', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');

                $naar_id = (int)($_POST['naar_id'] ?? 0);
                if ($naar_id <= 0) wp_die('Ongeldig verzoek.');

                check_admin_referer('bp_stuur_bericht', 'bp_bericht_nonce');

                $me = wp_get_current_user();
                if (!$me || !$me->ID) wp_die('Niet ingelogd.');

                if (!class_exists('BP_Core_Berichten') || !BP_Core_Berichten::mag_sturen_naar((int)$me->ID, $naar_id)) {
                    wp_die('U mag geen berichten sturen naar deze gebruiker.');
                }

                $onderwerp = sanitize_text_field((string)($_POST['onderwerp'] ?? ''));
                $inhoud    = sanitize_textarea_field((string)($_POST['inhoud'] ?? ''));

                if (!$inhoud) {
                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg('bp_bericht_fout', '1', $ref));
                    exit;
                }

                $result = BP_Core_Berichten::stuur((int)$me->ID, $naar_id, 'bericht', $onderwerp, $inhoud);

                if ($result === -1) {
                    // Rate limit
                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg('bp_bericht_ratelimit', '1', $ref));
                    exit;
                }

                if ($result > 0) {
                    $mail_subject = $onderwerp !== '' ? $onderwerp : 'Nieuw bericht';
                    BP_Core_Berichten::stuur_email_notificatie($naar_id, $mail_subject, $inhoud);
                }

                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_bericht_verzonden', '1', $ref));
                exit;
            });

            // Markeer bericht gelezen (AJAX-achtige POST)
            add_action('admin_post_bp_markeer_gelezen', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                $bericht_id = (int)($_POST['bericht_id'] ?? 0);
                check_admin_referer('bp_markeer_gelezen_' . $bericht_id, 'bp_gelezen_nonce');
                if ($bericht_id > 0 && class_exists('BP_Core_Berichten')) {
                    BP_Core_Berichten::markeer_gelezen($bericht_id, get_current_user_id());
                }
                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_tab', 'inbox', $ref));
                exit;
            });

            // Verwijder bericht (ontvanger kan eigen bericht verwijderen)
            add_action('admin_post_bp_verwijder_bericht', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                $bericht_id = (int)($_POST['bericht_id'] ?? 0);
                if ($bericht_id <= 0) wp_die('Ongeldig verzoek.');
                check_admin_referer('bp_verwijder_bericht_' . $bericht_id, 'bp_verwijder_nonce');
                global $wpdb;
                $me = (int) get_current_user_id();
                if (class_exists('BP_Core_Berichten')) {
                    $row = $wpdb->get_row($wpdb->prepare(
                        "SELECT van_id, naar_id, type, client_id, onderwerp, inhoud, status, gelezen, categorie, aangemaakt, bijgewerkt
                         FROM {$wpdb->prefix}kb_berichten
                         WHERE id = %d AND (naar_id = %d OR van_id = %d)
                         LIMIT 1",
                        $bericht_id,
                        $me,
                        $me
                    ), ARRAY_A);
                    BP_Core_Berichten::verwijder($bericht_id, $me);
                    $undo_token = self::store_message_undo_payload($me, $row ? [$row] : []);
                }
                $ref = wp_get_referer() ?: home_url('/');
                $args = ['bp_bericht_verwijderd' => '1'];
                if (!empty($undo_token)) {
                    $args['bp_undo'] = $undo_token;
                    $args['bp_undo_kind'] = 'bericht';
                }
                wp_safe_redirect(add_query_arg($args, $ref));
                exit;
            });

            // Verwijder volledig gesprek (alle bericht-type records tussen 2 gebruikers)
            add_action('admin_post_bp_verwijder_gesprek', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                $other_id = (int)($_POST['other_user_id'] ?? 0);
                if ($other_id <= 0) wp_die('Ongeldig verzoek.');
                check_admin_referer('bp_verwijder_gesprek_' . $other_id, 'bp_verwijder_gesprek_nonce');
                global $wpdb;

                $me = (int) get_current_user_id();
                $rows = [];
                if (class_exists('BP_Core_Berichten')) {
                    $rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT van_id, naar_id, type, client_id, onderwerp, inhoud, status, gelezen, categorie, aangemaakt, bijgewerkt
                         FROM {$wpdb->prefix}kb_berichten
                         WHERE ((van_id = %d AND naar_id = %d) OR (van_id = %d AND naar_id = %d))",
                        $me,
                        $other_id,
                        $other_id,
                        $me
                    ), ARRAY_A);
                }
                if (class_exists('BP_Core_Berichten')) {
                    BP_Core_Berichten::verwijder_gesprek($me, $other_id);
                }
                $undo_token = self::store_message_undo_payload($me, is_array($rows) ? $rows : []);
                $ref = wp_get_referer() ?: home_url('/');
                $args = ['bp_gesprek_verwijderd' => '1'];
                if (!empty($undo_token)) {
                    $args['bp_undo'] = $undo_token;
                    $args['bp_undo_kind'] = 'gesprek';
                }
                wp_safe_redirect(add_query_arg($args, $ref));
                exit;
            });

            add_action('admin_post_bp_undo_verwijderen', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                check_admin_referer('bp_undo_verwijderen', 'bp_undo_nonce');

                $token = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['bp_undo'] ?? ''));
                $me = (int) get_current_user_id();
                $ok = self::restore_message_undo_payload($me, $token);

                $ref = wp_get_referer() ?: home_url('/');
                $args = $ok ? ['bp_undo_done' => '1'] : ['bp_undo_expired' => '1'];
                wp_safe_redirect(add_query_arg($args, $ref));
                exit;
            });

            add_action('admin_post_bp_add_contact', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                check_admin_referer('bp_add_contact', 'bp_contact_nonce');

                $contact_id = (int) ($_POST['contact_id'] ?? 0);
                $me = (int) get_current_user_id();
                $ok = false;
                if (class_exists('BP_Core_Berichten')) {
                    $ok = BP_Core_Berichten::add_contact($me, $contact_id);
                }

                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg($ok ? 'bp_contact_added' : 'bp_contact_error', '1', $ref));
                exit;
            });

            add_action('admin_post_bp_remove_contact', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                check_admin_referer('bp_remove_contact', 'bp_contact_nonce');

                $contact_id = (int) ($_POST['contact_id'] ?? 0);
                $me = (int) get_current_user_id();
                $ok = false;
                if (class_exists('BP_Core_Berichten')) {
                    $ok = BP_Core_Berichten::remove_contact($me, $contact_id);
                }

                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg($ok ? 'bp_contact_removed' : 'bp_contact_error', '1', $ref));
                exit;
            });

            // Categorie instellen op een bericht
            add_action('admin_post_bp_categoriseer_bericht', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                $bericht_id = (int)($_POST['bericht_id'] ?? 0);
                if ($bericht_id <= 0) wp_die('Ongeldig verzoek.');
                check_admin_referer('bp_categoriseer_bericht_' . $bericht_id, 'bp_categoriseer_nonce');
                $categorie = sanitize_key((string)($_POST['categorie'] ?? ''));
                if (class_exists('BP_Core_Berichten')) {
                    BP_Core_Berichten::stel_categorie_in($bericht_id, get_current_user_id(), $categorie);
                }
                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_gecategoriseerd', '1', $ref));
                exit;
            });
            }

            // Koppel client handmatig aan andere begeleider (leidinggevende/admin)
            add_action('admin_post_bp_koppel_client_begeleider', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                if (!current_user_can('kb_manage_team') && !current_user_can('manage_options')) {
                    wp_die('Geen rechten.');
                }
                check_admin_referer('bp_koppel_client_begeleider', 'bp_koppel_nonce');

                $client_id    = (int)($_POST['bp_koppel_client_id'] ?? 0);
                $nieuwe_bid   = (int)($_POST['bp_nieuwe_begel_id'] ?? 0);

                $ref = wp_get_referer() ?: home_url('/');

                if ($client_id <= 0 || $nieuwe_bid <= 0) {
                    wp_safe_redirect(add_query_arg('bp_koppel_fout', '1', $ref));
                    exit;
                }

                $client    = get_user_by('id', $client_id);
                $begeleider = get_user_by('id', $nieuwe_bid);

                if (!$client || !in_array(class_exists('BP_Core_Roles') ? BP_Core_Roles::ROLE_CLIENT : 'kb_client', (array)$client->roles, true)) {
                    wp_safe_redirect(add_query_arg('bp_koppel_fout', '2', $ref));
                    exit;
                }
                if (!$begeleider || !in_array(class_exists('BP_Core_Roles') ? BP_Core_Roles::ROLE_BEGELEIDER : 'kb_begeleider', (array)$begeleider->roles, true)) {
                    wp_safe_redirect(add_query_arg('bp_koppel_fout', '3', $ref));
                    exit;
                }

                $oude_bid = (int) get_user_meta($client_id, 'kb_begeleider_id', true);
                update_user_meta($client_id, 'kb_begeleider_id', $nieuwe_bid);

                // Leidinggevende van nieuwe begeleider meegeven
                $nieuwe_leid = (int) get_user_meta($nieuwe_bid, 'kb_leidinggevende_id', true);
                if ($nieuwe_leid > 0) {
                    update_user_meta($client_id, 'kb_leidinggevende_id', $nieuwe_leid);
                }

                if (class_exists('BP_Core_Audit')) {
                    BP_Core_Audit::log('koppeling', $client_id, 'begeleider_gewijzigd',
                        ['begeleider' => $oude_bid],
                        ['begeleider' => $nieuwe_bid, 'leidinggevende' => $nieuwe_leid]
                    );
                }

                wp_safe_redirect(add_query_arg(['bp_gekoppeld' => '1', 'bp_tab' => 'gebruikers'], $ref));
                exit;
            });

            // Zet 1 begeleider over naar een andere leidinggevende (leidinggevende/admin)
            add_action('admin_post_bp_koppel_begeleider_leidinggevende', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                if (!current_user_can('kb_manage_team') && !current_user_can('manage_options')) {
                    wp_die('Geen rechten.');
                }
                check_admin_referer('bp_koppel_begeleider_leidinggevende', 'bp_koppel_begel_leid_nonce');

                $begeleider_id = (int)($_POST['bp_koppel_begeleider_id'] ?? 0);
                $leidinggevende_id = (int)($_POST['bp_nieuwe_leid_id'] ?? 0);
                $ref = wp_get_referer() ?: home_url('/');

                if ($begeleider_id <= 0 || $leidinggevende_id <= 0) {
                    wp_safe_redirect(add_query_arg(['bp_begel_leid_fout' => '1', 'bp_tab' => 'gebruikers'], $ref));
                    exit;
                }

                $begeleider = get_user_by('id', $begeleider_id);
                $leidinggevende = get_user_by('id', $leidinggevende_id);
                $begeleider_role = class_exists('BP_Core_Roles') ? BP_Core_Roles::ROLE_BEGELEIDER : 'kb_begeleider';

                if (!$begeleider || !in_array($begeleider_role, (array)$begeleider->roles, true)) {
                    wp_safe_redirect(add_query_arg(['bp_begel_leid_fout' => '2', 'bp_tab' => 'gebruikers'], $ref));
                    exit;
                }
                if (!$leidinggevende) {
                    wp_safe_redirect(add_query_arg(['bp_begel_leid_fout' => '3', 'bp_tab' => 'gebruikers'], $ref));
                    exit;
                }

                $is_ok_target = current_user_can('manage_options') || in_array('administrator', (array)$leidinggevende->roles, true);
                if (!$is_ok_target && class_exists('BP_Core_Roles')) {
                    $is_ok_target = BP_Core_Roles::is_leidinggevende($leidinggevende);
                }
                if (!$is_ok_target) {
                    wp_safe_redirect(add_query_arg(['bp_begel_leid_fout' => '4', 'bp_tab' => 'gebruikers'], $ref));
                    exit;
                }

                $oude_leidinggevende = (int) get_user_meta($begeleider_id, 'kb_leidinggevende_id', true);
                update_user_meta($begeleider_id, 'kb_leidinggevende_id', $leidinggevende_id);

                if (class_exists('BP_Core_Audit')) {
                    BP_Core_Audit::log(
                        'koppeling',
                        $begeleider_id,
                        'begeleider_leidinggevende_gewijzigd',
                        ['leidinggevende' => $oude_leidinggevende],
                        ['leidinggevende' => $leidinggevende_id]
                    );
                }

                wp_safe_redirect(add_query_arg(['bp_begel_leid_gekoppeld' => '1', 'bp_tab' => 'gebruikers'], $ref));
                exit;
            });

            // Account: foto uploaden
            add_action('admin_post_bp_update_account_foto', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                check_admin_referer('bp_update_account_foto', 'bp_foto_nonce');

                $me = wp_get_current_user();

                if (empty($_FILES['bp_foto']) || $_FILES['bp_foto']['error'] !== UPLOAD_ERR_OK) {
                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg('bp_foto_fout', '1', $ref));
                    exit;
                }

                $file = $_FILES['bp_foto'];

                // Max 2 MB
                if ($file['size'] > 2 * 1024 * 1024) {
                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg('bp_foto_fout', 'size', $ref));
                    exit;
                }

                // Whitelist MIME
                $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif']);
                if (empty($check['type'])) {
                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg('bp_foto_fout', 'type', $ref));
                    exit;
                }

                // Upload via WordPress
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $overrides = ['test_form' => false, 'mimes' => ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif']];
                $uploaded  = wp_handle_upload($file, $overrides);

                if (isset($uploaded['error'])) {
                    $ref = wp_get_referer() ?: home_url('/');
                    wp_safe_redirect(add_query_arg('bp_foto_fout', '1', $ref));
                    exit;
                }

                // Verwijder oude foto als die door de plugin is geüpload
                $oude_url = (string) get_user_meta($me->ID, 'kb_profielfoto', true);
                if ($oude_url) {
                    $upload_dir = wp_upload_dir();
                    $oude_path  = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $oude_url);
                    if (file_exists($oude_path) && strpos($oude_path, $upload_dir['basedir']) === 0) {
                        @unlink($oude_path);
                    }
                }

                update_user_meta($me->ID, 'kb_profielfoto', esc_url_raw($uploaded['url']));

                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_foto_opgeslagen', '1', $ref));
                exit;
            });

            // Account: NAW wijzigen
            add_action('admin_post_bp_update_account_naw', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                check_admin_referer('bp_update_account_naw', 'bp_naw_nonce');

                $me = wp_get_current_user();
                $is_client = class_exists('BP_Core_Roles') && BP_Core_Roles::is_client($me);

                $display_name = sanitize_text_field((string)($_POST['display_name'] ?? ''));
                $telefoon     = sanitize_text_field((string)($_POST['kb_telefoon'] ?? ''));
                $geboortedatum = sanitize_text_field((string)($_POST['kb_geboortedatum'] ?? ''));
                $adres        = sanitize_text_field((string)($_POST['kb_adres'] ?? ''));
                $postcode     = sanitize_text_field((string)($_POST['kb_postcode'] ?? ''));
                $woonplaats   = sanitize_text_field((string)($_POST['kb_woonplaats'] ?? ''));

                if ($display_name) {
                    wp_update_user(['ID' => $me->ID, 'display_name' => $display_name]);
                }
                update_user_meta($me->ID, 'kb_telefoon', $telefoon);
                if ($is_client) {
                    update_user_meta($me->ID, 'kb_geboortedatum', $geboortedatum);
                    update_user_meta($me->ID, 'kb_adres', $adres);
                    update_user_meta($me->ID, 'kb_postcode', $postcode);
                    update_user_meta($me->ID, 'kb_woonplaats', $woonplaats);
                }

                $ref = wp_get_referer() ?: home_url('/');
                wp_safe_redirect(add_query_arg('bp_naw_opgeslagen', '1', $ref));
                exit;
            });

            // Account: wachtwoord wijzigen
            add_action('admin_post_bp_update_account_pw', function(){
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                check_admin_referer('bp_update_account_pw', 'bp_pw_nonce');

                $me = wp_get_current_user();

                $huidig    = (string)($_POST['bp_pw_huidig'] ?? '');
                $nieuw     = (string)($_POST['bp_pw_nieuw'] ?? '');
                $bevestig  = (string)($_POST['bp_pw_bevestig'] ?? '');

                $ref = wp_get_referer() ?: home_url('/');

                if (!wp_check_password($huidig, $me->user_pass, $me->ID)) {
                    wp_safe_redirect(add_query_arg('bp_pw_fout', 'huidig', $ref));
                    exit;
                }
                if (strlen($nieuw) < 8) {
                    wp_safe_redirect(add_query_arg('bp_pw_fout', 'kort', $ref));
                    exit;
                }
                if ($nieuw !== $bevestig) {
                    wp_safe_redirect(add_query_arg('bp_pw_fout', 'match', $ref));
                    exit;
                }

                wp_update_user(['ID' => $me->ID, 'user_pass' => $nieuw]);

                wp_safe_redirect(add_query_arg('bp_pw_opgeslagen', '1', $ref));
                exit;
            });

            add_action('admin_post_bp_update_account_2fa_mobile', function() {
                if (!is_user_logged_in()) wp_die('Niet ingelogd.');
                check_admin_referer('bp_update_account_2fa_mobile', 'bp_2fa_nonce');

                $me = wp_get_current_user();
                $uid = (int) $me->ID;
                $mode = sanitize_key((string) ($_POST['bp_2fa_mode'] ?? ''));
                $pw = (string) ($_POST['bp_2fa_huidig_pw'] ?? '');
                $code = sanitize_text_field((string) ($_POST['bp_2fa_code'] ?? ''));
                $ref = wp_get_referer() ?: home_url('/');

                if (!wp_check_password($pw, $me->user_pass, $uid)) {
                    wp_safe_redirect(add_query_arg('bp_2fa_status', 'pw', $ref));
                    exit;
                }

                if ($mode === 'disable') {
                    delete_user_meta($uid, 'bp_2fa_totp_enabled');
                    delete_user_meta($uid, 'bp_2fa_totp_secret');
                    delete_user_meta($uid, 'bp_2fa_totp_pending_secret');
                    wp_safe_redirect(add_query_arg('bp_2fa_status', 'disabled', $ref));
                    exit;
                }

                if ($mode === 'regen') {
                    $pending = self::generate_totp_secret();
                    update_user_meta($uid, 'bp_2fa_totp_pending_secret', $pending);
                    delete_user_meta($uid, 'bp_2fa_totp_enabled');
                    delete_user_meta($uid, 'bp_2fa_totp_secret');
                    wp_safe_redirect(add_query_arg('bp_2fa_status', 'regen', $ref));
                    exit;
                }

                if ($mode === 'enable') {
                    $pending = (string) get_user_meta($uid, 'bp_2fa_totp_pending_secret', true);
                    if ($pending === '') {
                        $pending = self::generate_totp_secret();
                        update_user_meta($uid, 'bp_2fa_totp_pending_secret', $pending);
                    }

                    if (!self::verify_totp_code($pending, $code)) {
                        wp_safe_redirect(add_query_arg('bp_2fa_status', 'code', $ref));
                        exit;
                    }

                    update_user_meta($uid, 'bp_2fa_totp_secret', $pending);
                    update_user_meta($uid, 'bp_2fa_totp_enabled', 1);
                    delete_user_meta($uid, 'bp_2fa_totp_pending_secret');
                    wp_safe_redirect(add_query_arg('bp_2fa_status', 'enabled', $ref));
                    exit;
                }

                wp_safe_redirect(add_query_arg('bp_2fa_status', 'invalid', $ref));
                exit;
            });
        }
        // Gutenberg blokken (front-end + editor)
        if (class_exists('BP_Core_Blocks')) {
            BP_Core_Blocks::init();
        }



        // Auto-koppelen van pagina's (alleen admin)
        if (is_admin() && function_exists('bp_core_autodetect_pages')) {
            add_action('admin_init', function(){ bp_core_autodetect_pages(); });
        }

        // Onderhoudsmodus (front-end)
        BP_Core_Maintenance::instance()->init();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('beroepen-portaal-core', false, dirname(plugin_basename(BP_CORE_FILE)) . '/languages');
    }

    /**
     * Sla verwijderde berichten tijdelijk op voor undo (10 seconden).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private static function store_message_undo_payload(int $user_id, array $rows): string {
        if ($user_id <= 0 || empty($rows)) return '';

        $clean_rows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $clean_rows[] = [
                'van_id'    => (int)($row['van_id'] ?? 0),
                'naar_id'   => (int)($row['naar_id'] ?? 0),
                'type'      => sanitize_key((string)($row['type'] ?? 'bericht')),
                'client_id' => !isset($row['client_id']) || $row['client_id'] === null ? null : (int)$row['client_id'],
                'onderwerp' => (string)($row['onderwerp'] ?? ''),
                'inhoud'    => (string)($row['inhoud'] ?? ''),
                'status'    => sanitize_key((string)($row['status'] ?? 'pending')),
                'gelezen'   => (int)($row['gelezen'] ?? 0),
                'categorie' => sanitize_key((string)($row['categorie'] ?? '')),
                'aangemaakt'=> (string)($row['aangemaakt'] ?? current_time('mysql')),
                'bijgewerkt'=> !empty($row['bijgewerkt']) ? (string)$row['bijgewerkt'] : null,
            ];
        }

        if (empty($clean_rows)) return '';

        $token = wp_generate_password(20, false, false);
        $key = 'bp_msg_undo_' . $user_id . '_' . $token;
        set_transient($key, $clean_rows, 10);
        return $token;
    }

    private static function restore_message_undo_payload(int $user_id, string $token): bool {
        global $wpdb;

        $token = preg_replace('/[^A-Za-z0-9]/', '', $token);
        if ($user_id <= 0 || $token === '') return false;

        $key = 'bp_msg_undo_' . $user_id . '_' . $token;
        $rows = get_transient($key);
        if (!is_array($rows) || empty($rows)) return false;

        $table = $wpdb->prefix . 'kb_berichten';
        $restored = false;

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $ok = $wpdb->insert($table, [
                'van_id'     => (int)($row['van_id'] ?? 0),
                'naar_id'    => (int)($row['naar_id'] ?? 0),
                'type'       => sanitize_key((string)($row['type'] ?? 'bericht')),
                'client_id'  => isset($row['client_id']) ? (int)$row['client_id'] : null,
                'onderwerp'  => (string)($row['onderwerp'] ?? ''),
                'inhoud'     => (string)($row['inhoud'] ?? ''),
                'status'     => sanitize_key((string)($row['status'] ?? 'pending')),
                'gelezen'    => (int)($row['gelezen'] ?? 0),
                'categorie'  => sanitize_key((string)($row['categorie'] ?? '')),
                'aangemaakt' => (string)($row['aangemaakt'] ?? current_time('mysql')),
                'bijgewerkt' => !empty($row['bijgewerkt']) ? (string)$row['bijgewerkt'] : null,
            ]);
            if ($ok !== false) {
                $restored = true;
            }
        }

        delete_transient($key);
        return $restored;
    }

    private static function requires_2fa_for_user($user): bool {
        if (!$user || !($user instanceof WP_User)) return false;
        if (self::user_has_mobile_2fa($user)) return true;

        $roles = array_map('sanitize_key', (array) $user->roles);
        // Voor alle portaalrollen geldt: geen mobiele 2FA = e-mail 2FA.
        $default_required_roles = [
            BP_Core_Roles::ROLE_CLIENT,
            BP_Core_Roles::ROLE_BEGELEIDER,
            BP_Core_Roles::ROLE_LEIDINGGEVENDE,
            'administrator',
        ];
        foreach ($default_required_roles as $required_role) {
            if (in_array($required_role, $roles, true)) {
                return true;
            }
        }

        // Optioneel extra rollen via instelling.
        $required = get_option('bp_core_2fa_required_roles', []);
        $required = is_array($required) ? array_map('sanitize_key', $required) : [];

        foreach ($roles as $role) {
            if (in_array($role, $required, true)) {
                return true;
            }
        }

        return false;
    }

    private static function start_2fa_challenge(WP_User $user, bool $remember, string $redirect_to, string $method = 'email'): string {
        $method = in_array($method, ['email', 'totp'], true) ? $method : 'email';
        $token = wp_generate_password(48, false, false);
        $code  = $method === 'email' ? (string) wp_rand(100000, 999999) : '';
        $sig   = $method === 'email' ? self::sign_2fa_code($code) : '';

        $payload = [
            'user_id'     => (int) $user->ID,
            'remember'    => $remember ? 1 : 0,
            'redirect_to' => $redirect_to,
            'code_sig'    => $sig,
            'method'      => $method,
            'attempts'    => 0,
            'created_at'  => time(),
        ];

        set_transient('bp_2fa_login_' . $token, $payload, 10 * MINUTE_IN_SECONDS);

        if ($method === 'totp') {
            return $token;
        }

        $sent = self::send_2fa_email($user, $code);
        if (!$sent) {
            delete_transient('bp_2fa_login_' . $token);
            return '';
        }
        return $token;
    }

    private static function verify_2fa_challenge(string $token, string $code) {
        $token = sanitize_text_field($token);
        if ($token === '') {
            return new WP_Error('2fa_expired', 'Sessie verlopen.');
        }

        $key = 'bp_2fa_login_' . $token;
        $payload = get_transient($key);
        if (!is_array($payload) || empty($payload['user_id'])) {
            return new WP_Error('2fa_expired', 'Sessie verlopen.');
        }

        $attempts = isset($payload['attempts']) ? (int) $payload['attempts'] : 0;
        if ($attempts >= 5) {
            delete_transient($key);
            return new WP_Error('2fa_locked', 'Te veel pogingen.');
        }

        $code = self::normalize_2fa_code($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            $payload['attempts'] = $attempts + 1;
            set_transient($key, $payload, 10 * MINUTE_IN_SECONDS);
            return new WP_Error('2fa_format', 'Ongeldige code.');
        }

        $user_id = (int) $payload['user_id'];
        $method = isset($payload['method']) ? sanitize_key((string) $payload['method']) : 'email';
        if ($method === 'totp') {
            $user = get_user_by('id', $user_id);
            $secret = $user ? (string) get_user_meta($user_id, 'bp_2fa_totp_secret', true) : '';
            if ($secret === '' || !self::verify_totp_code($secret, $code)) {
                $payload['attempts'] = $attempts + 1;
                set_transient($key, $payload, 10 * MINUTE_IN_SECONDS);
                return new WP_Error('2fa_code', 'Ongeldige code.');
            }
        } else {
            $ok = false;
            if (!empty($payload['code_sig'])) {
                $ok = hash_equals((string) $payload['code_sig'], self::sign_2fa_code($code));
            } elseif (!empty($payload['code_hash'])) {
                // Backward compatibility with earlier transient data.
                $ok = wp_check_password($code, (string) $payload['code_hash'], $user_id);
            }

            if (!$ok) {
                $payload['attempts'] = $attempts + 1;
                set_transient($key, $payload, 10 * MINUTE_IN_SECONDS);
                return new WP_Error('2fa_code', 'Ongeldige code.');
            }
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            delete_transient($key);
            return new WP_Error('2fa_expired', 'Gebruiker niet gevonden.');
        }

        delete_transient($key);
        return [
            'user'        => $user,
            'remember'    => !empty($payload['remember']),
            'redirect_to' => (string) ($payload['redirect_to'] ?? ''),
        ];
    }

    private static function get_2fa_method_for_token(string $token): string {
        $token = sanitize_text_field($token);
        if ($token === '') return '';
        $payload = get_transient('bp_2fa_login_' . $token);
        if (!is_array($payload)) return '';
        $method = isset($payload['method']) ? sanitize_key((string) $payload['method']) : '';
        return in_array($method, ['email', 'totp'], true) ? $method : '';
    }

    public static function get_frontend_2fa_state(): array {
        $raw = isset($_COOKIE['bp_front_2fa']) ? (string) wp_unslash($_COOKIE['bp_front_2fa']) : '';
        if ($raw === '') {
            return ['token' => '', 'method' => ''];
        }

        $parts = explode('|', $raw);
        if (count($parts) !== 4) {
            return ['token' => '', 'method' => ''];
        }

        $token = sanitize_text_field((string) $parts[0]);
        $method = sanitize_key((string) $parts[1]);
        $exp = (int) $parts[2];
        $sig = (string) $parts[3];

        if ($token === '' || !in_array($method, ['email', 'totp'], true) || $exp <= 0 || $sig === '') {
            return ['token' => '', 'method' => ''];
        }
        if ($exp < time()) {
            return ['token' => '', 'method' => ''];
        }

        $payload = $token . '|' . $method . '|' . $exp;
        $expected = hash_hmac('sha256', $payload, wp_salt('auth'));
        if (!hash_equals($expected, $sig)) {
            return ['token' => '', 'method' => ''];
        }

        return ['token' => $token, 'method' => $method];
    }

    private static function get_frontend_2fa_token_from_cookie(): string {
        $state = self::get_frontend_2fa_state();
        return is_string($state['token'] ?? '') ? (string) $state['token'] : '';
    }

    private static function set_frontend_2fa_cookie(string $token, string $method): void {
        $token = sanitize_text_field($token);
        $method = sanitize_key($method);
        if ($token === '' || !in_array($method, ['email', 'totp'], true)) {
            return;
        }

        $exp = time() + (10 * MINUTE_IN_SECONDS);
        $payload = $token . '|' . $method . '|' . $exp;
        $sig = hash_hmac('sha256', $payload, wp_salt('auth'));
        $value = $payload . '|' . $sig;

        setcookie('bp_front_2fa', $value, [
            'expires'  => $exp,
            'path'     => '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['bp_front_2fa'] = $value;
    }

    private static function clear_frontend_2fa_cookie(): void {
        setcookie('bp_front_2fa', '', [
            'expires'  => time() - HOUR_IN_SECONDS,
            'path'     => '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE['bp_front_2fa']);
    }

    private static function user_has_mobile_2fa(WP_User $user): bool {
        $uid = (int) $user->ID;
        if ($uid <= 0) return false;
        $enabled = (int) get_user_meta($uid, 'bp_2fa_totp_enabled', true);
        $secret = (string) get_user_meta($uid, 'bp_2fa_totp_secret', true);
        return $enabled === 1 && $secret !== '';
    }

    public static function generate_totp_secret(int $length = 32): string {
        $length = max(16, min(64, $length));
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    private static function verify_totp_code(string $secret, string $code, int $window = 1): bool {
        $secret = preg_replace('/[^A-Z2-7]/', '', strtoupper($secret));
        if ($secret === '' || !preg_match('/^\d{6}$/', $code)) return false;
        $counter = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $test = self::totp_at_counter($secret, $counter + $i);
            if (hash_equals($test, $code)) return true;
        }
        return false;
    }

    private static function totp_at_counter(string $secret, int $counter): string {
        $key = self::base32_decode($secret);
        if ($key === '') return '000000';

        $counter = max(0, $counter);
        $binCounter = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $segment = substr($hash, $offset, 4);
        $value = unpack('N', $segment)[1] & 0x7FFFFFFF;
        $otp = $value % 1000000;
        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    private static function base32_decode(string $input): string {
        $input = preg_replace('/[^A-Z2-7]/', '', strtoupper($input));
        if ($input === '') return '';
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $bits = '';
        $out = '';
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];
            if (!isset($alphabet[$char])) return '';
            $bits .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
        }
        $bitLen = strlen($bits);
        for ($i = 0; $i + 8 <= $bitLen; $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }

    private static function normalize_2fa_code(string $code): string {
        return (string) preg_replace('/\D+/', '', trim($code));
    }

    private static function sign_2fa_code(string $code): string {
        return hash_hmac('sha256', $code, wp_salt('auth'));
    }

    public static function enforce_front_login_wall(): void {
        if (is_admin()) return;
        if (wp_doing_ajax()) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;
        if (is_user_logged_in()) return;
        if (!is_singular()) return;

        $page_id = (int) get_queried_object_id();
        if ($page_id <= 0) return;
        if (!function_exists('bp_core_is_page_behind_login_wall') || !bp_core_is_page_behind_login_wall($page_id)) return;

        $linked = function_exists('bp_core_get_linked_pages') ? bp_core_get_linked_pages() : [];
        $login_id = isset($linked['login']) ? (int) $linked['login'] : 0;
        if ($login_id > 0 && $page_id === $login_id) return;

        $target = get_permalink($page_id);
        if (!is_string($target) || $target === '') {
            $target = home_url('/');
        }
        $login_url = $login_id > 0 ? get_permalink($login_id) : home_url('/login-portaal');
        if (!is_string($login_url) || $login_url === '') {
            $login_url = wp_login_url($target);
        }

        $login_url = add_query_arg(['redirect' => $target], $login_url);
        wp_safe_redirect($login_url);
        exit;
    }

    public static function localize_sensitive_guard_auth(): void {
        if (is_admin()) return;
        if (!wp_script_is('bp-sensitive-guard', 'enqueued')) return;

        wp_localize_script('bp-sensitive-guard', 'BPGuardAuth', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bp_core_verify_account_password'),
        ]);
    }

    public static function ajax_verify_account_password(): void {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Niet ingelogd.'], 403);
        }
        check_ajax_referer('bp_core_verify_account_password', 'nonce');
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            wp_send_json_error(['message' => 'Niet ingelogd.'], 403);
        }
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ($password === '') {
            wp_send_json_error(['message' => 'Wachtwoord ontbreekt.'], 400);
        }
        if (!wp_check_password($password, (string) $user->user_pass, (int) $user->ID)) {
            wp_send_json_error(['message' => 'Wachtwoord is onjuist.'], 403);
        }

        $token = wp_generate_password(24, false, false);
        $key = self::plugin_guard_key((int) $user->ID, $token);
        set_transient($key, 1, 5 * MINUTE_IN_SECONDS);

        wp_send_json_success([
            'ok' => 1,
            'guard_token' => $token,
            'expires_in' => 300,
        ]);
    }

    public static function ajax_verify_admin_password(): void {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Niet ingelogd.'], 403);
        }
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(['message' => 'Geen rechten.'], 403);
        }
        check_ajax_referer('bp_core_verify_admin_password', 'nonce');

        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ($password === '') {
            wp_send_json_error(['message' => 'Wachtwoord ontbreekt.'], 400);
        }

        if (!self::matches_any_administrator_password($password)) {
            wp_send_json_error(['message' => 'Administrator-wachtwoord is onjuist.'], 403);
        }

        $uid = (int) get_current_user_id();
        $token = wp_generate_password(24, false, false);
        $key = self::plugin_guard_key($uid, $token);
        set_transient($key, 1, 5 * MINUTE_IN_SECONDS);

        wp_send_json_success([
            'ok' => 1,
            'guard_token' => $token,
            'expires_in' => 300,
        ]);
    }

    public static function enqueue_plugin_guard_script(string $hook): void {
        if ($hook !== 'plugins.php') return;
        if (!is_user_logged_in() || !current_user_can('activate_plugins')) return;

        wp_enqueue_script(
            'bp-plugin-guard',
            BP_CORE_URL . 'assets/js/bp-plugin-guard.js',
            [],
            BP_CORE_VERSION,
            true
        );
        wp_localize_script('bp-plugin-guard', 'BPPluginGuard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bp_core_verify_admin_password'),
            'confirmText' => 'Voer het administrator-wachtwoord in om deze addon te verwijderen of uit te schakelen.',
            'invalidPasswordText' => 'Administrator-wachtwoord is onjuist.',
            'protectedPrefixes' => ['bp-addon-', 'beroepen-portaal-core'],
        ]);
    }

    public static function enforce_plugin_remove_guard(): void {
        if (!is_admin() || !is_user_logged_in()) return;
        if (!current_user_can('activate_plugins')) return;

        global $pagenow;
        if ($pagenow !== 'plugins.php') return;

        $action = '';
        if (isset($_REQUEST['action']) && $_REQUEST['action'] !== '-1') {
            $action = sanitize_key((string) wp_unslash($_REQUEST['action']));
        } elseif (isset($_REQUEST['action2']) && $_REQUEST['action2'] !== '-1') {
            $action = sanitize_key((string) wp_unslash($_REQUEST['action2']));
        }
        if (!in_array($action, ['deactivate', 'deactivate-selected', 'delete-selected'], true)) return;

        $plugins = self::get_requested_plugins_for_action($action);
        if (empty($plugins)) return;

        $protected = array_values(array_filter($plugins, [__CLASS__, 'is_protected_plugin_basename']));
        if (empty($protected)) return;

        $uid = (int) get_current_user_id();
        $guard_token = isset($_REQUEST['bp_guard_token']) ? sanitize_text_field((string) wp_unslash($_REQUEST['bp_guard_token'])) : '';
        if ($guard_token !== '' && self::consume_plugin_guard_token($uid, $guard_token)) {
            return;
        }

        wp_die(
            esc_html__('Wachtwoordbevestiging ontbreekt of is verlopen. Probeer opnieuw.', 'beroepen-portaal-core'),
            esc_html__('Actie geblokkeerd', 'beroepen-portaal-core'),
            ['response' => 403]
        );
    }

    /**
     * @return array<int,string>
     */
    private static function get_requested_plugins_for_action(string $action): array {
        $out = [];
        if ($action === 'deactivate') {
            $single = isset($_REQUEST['plugin']) ? sanitize_text_field((string) wp_unslash($_REQUEST['plugin'])) : '';
            if ($single !== '') {
                $out[] = $single;
            }
        } else {
            $raw = isset($_REQUEST['checked']) ? wp_unslash($_REQUEST['checked']) : [];
            if (is_array($raw)) {
                foreach ($raw as $item) {
                    $plugin = sanitize_text_field((string) $item);
                    if ($plugin !== '') $out[] = $plugin;
                }
            }
        }
        return array_values(array_unique($out));
    }

    private static function is_protected_plugin_basename(string $plugin): bool {
        $plugin = ltrim(trim($plugin), '/\\');
        if ($plugin === '') return false;
        if (str_starts_with($plugin, 'bp-addon-')) return true;
        if (str_starts_with($plugin, 'beroepen-portaal-core/')) return true;
        return false;
    }

    private static function plugin_guard_key(int $user_id, string $token): string {
        return 'bp_plugin_guard_' . $user_id . '_' . $token;
    }

    private static function matches_any_administrator_password(string $password): bool {
        if ($password === '') return false;
        $admin_ids = get_users([
            'role' => 'administrator',
            'fields' => 'ID',
            'number' => 200,
        ]);
        if (!is_array($admin_ids) || empty($admin_ids)) return false;

        foreach ($admin_ids as $aid_raw) {
            $admin_id = (int) $aid_raw;
            if ($admin_id <= 0) continue;
            $admin = get_user_by('id', $admin_id);
            if (!$admin || !($admin instanceof WP_User)) continue;
            $admin_hash = (string) $admin->user_pass;
            if ($admin_hash === '') continue;
            if (wp_check_password($password, $admin_hash, $admin_id)) {
                return true;
            }
        }
        return false;
    }

    private static function consume_plugin_guard_token(int $user_id, string $token): bool {
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token);
        if ($user_id <= 0 || $token === '') return false;
        $key = self::plugin_guard_key($user_id, $token);
        $ok = (int) get_transient($key) === 1;
        if ($ok) {
            delete_transient($key);
        }
        return $ok;
    }

    private static function send_2fa_email(WP_User $user, string $code): bool {
        $to = (string) ($user->user_email ?? '');
        if ($to === '' || !is_email($to)) return false;

        $org_name = function_exists('bp_core_get_org_name') ? (string) bp_core_get_org_name('Beroepen Portaal') : 'Beroepen Portaal';
        $org_logo = function_exists('bp_core_get_org_logo') ? (string) bp_core_get_org_logo() : '';
        $subject  = '[' . $org_name . '] Je 2FA verificatiecode';
        $safe_code = esc_html($code);
        $safe_name = esc_html((string) ($user->display_name ?: $user->user_login));
        $safe_org  = esc_html($org_name);
        $site_url  = esc_url(home_url('/'));

        $logo_html = $org_logo
            ? '<img src="' . esc_url($org_logo) . '" alt="' . $safe_org . '" style="max-height:40px;max-width:200px;display:block;margin-bottom:18px;">'
            : '<div style="font-size:20px;font-weight:800;color:#003082;margin-bottom:18px;">' . $safe_org . '</div>';

        $body = '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#0f172a;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:28px 0;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">'
            . '<tr><td style="padding:26px 28px;">'
            . $logo_html
            . '<h1 style="margin:0 0 10px;font-size:22px;line-height:1.3;color:#0f172a;">Verificatiecode voor inloggen</h1>'
            . '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#334155;">Hoi ' . $safe_name . ', gebruik onderstaande code om in te loggen.</p>'
            . '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;text-align:center;margin:0 0 14px;">'
            . '<div style="font-size:30px;letter-spacing:4px;font-weight:800;color:#003082;">' . $safe_code . '</div>'
            . '</div>'
            . '<p style="margin:0 0 8px;font-size:13px;color:#475569;">Deze code is 10 minuten geldig en slechts één keer te gebruiken.</p>'
            . '<p style="margin:0;font-size:13px;color:#475569;">Heb je dit niet zelf aangevraagd? Negeer deze e-mail en wijzig je wachtwoord.</p>'
            . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0 12px;">'
            . '<p style="margin:0;font-size:12px;color:#94a3b8;">Verzonden door <a href="' . $site_url . '" style="color:#003082;text-decoration:none;">' . $safe_org . '</a>.</p>'
            . '</td></tr></table>'
            . '</td></tr></table></body></html>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $safe_org . ' <' . get_option('admin_email') . '>',
        ];

        return (bool) wp_mail($to, $subject, $body, $headers);
    }
}
