<?php
defined('ABSPATH') || exit;

final class BP_Core_Admin_Menu {

    private static ?BP_Core_Admin_Menu $instance = null;

    public static function instance(): BP_Core_Admin_Menu {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init(): void {
        add_action('admin_menu', [$this, 'register_menu']);
    }

    public function register_menu(): void {
    $cap = 'manage_options';

    // Hoofdmenu: dagelijks gebruik
    add_menu_page(
        'Beroepen Portaal',
        'Beroepen Portaal',
        $cap,
        'bp-core',
        [$this, 'render_dashboard'],
        'dashicons-networking',
        58
    );

    add_submenu_page('bp-core', 'Overzicht', 'Overzicht', $cap, 'bp-core', [$this, 'render_dashboard']);
    add_submenu_page('bp-core', 'Gebruikers', 'Gebruikers', $cap, 'bp-core-users', [$this, 'render_users']);
    add_submenu_page('bp-core', 'Rechten per gebruiker', 'Rechten per gebruiker', $cap, 'bp-core-user-caps', [$this, 'render_user_caps']);
    add_submenu_page('bp-core', 'Rollen & Rechten', 'Rollen & Rechten', $cap, 'bp-core-roles', [$this, 'render_roles']);
    add_submenu_page('bp-core', 'Logboek', 'Logboek', $cap, 'bp-core-audit', [$this, 'render_audit']);

    // Tools menu: instellen & techniek
    add_menu_page(
        'Beroepen Portaal Tools',
        'Beroepen Portaal Tools',
        $cap,
        'bp-core-tools',
        [$this, 'render_tools'],
        'dashicons-admin-tools',
        59
    );

    add_submenu_page('bp-core-tools', 'Tools', 'Tools', $cap, 'bp-core-tools', [$this, 'render_tools']);
    add_submenu_page('bp-core-tools', 'Pagina-koppelingen', 'Pagina-koppelingen', $cap, 'bp-core-pages', [$this, 'render_pages_link']);
    add_submenu_page('bp-core-tools', 'Instellingen', 'Instellingen', $cap, 'bp-core-settings', [$this, 'render_settings']);
    add_submenu_page('bp-core-tools', 'Add-ons', 'Add-ons', $cap, 'bp-core-addons', [$this, 'render_addons']);
    // Legacy slug (geen apart menu-item meer): blijft werken voor oude links.
    add_submenu_page('bp-core-tools', 'Add-ontoegang', 'Add-ontoegang', $cap, 'bp-core-addon-access', [$this, 'render_addon_access']);
    add_submenu_page('bp-core-tools', 'Templates', 'Templates', $cap, 'bp-core-templates', [$this, 'render_templates']);
    add_submenu_page('bp-core-tools', 'Onderhoud', 'Onderhoud', $cap, 'bp-core-maintenance', [$this, 'render_maintenance']);

    // Verberg het oude submenu "Add-ontoegang" (we tonen dit nu als tab binnen Add-ons)
    remove_submenu_page('bp-core-tools', 'bp-core-addon-access');
}

    private function include_admin_page(string $file): void {
        $path = BP_CORE_DIR . 'admin/pages/' . $file;
        if (file_exists($path)) {
            include $path;
            return;
        }
        echo '<div class="wrap"><h1>Pagina ontbreekt</h1><p>Bestand niet gevonden: ' . esc_html($file) . '</p></div>';
    }

    public function render_dashboard(): void { $this->include_admin_page('dashboard.php'); }
    public function render_users(): void { $this->include_admin_page('users.php'); }
    public function render_user_caps(): void { $this->include_admin_page('user-caps.php'); }
    public function render_roles(): void { $this->include_admin_page('roles.php'); }
    public function render_dataset(): void { $this->include_admin_page('dataset.php'); }
    public function render_pages_link(): void { $this->include_admin_page('pages-link.php'); }
    public function render_settings(): void { $this->include_admin_page('settings.php'); }
    public function render_tools(): void { $this->include_admin_page('tools.php'); }
    public function render_addons(): void { $this->include_admin_page('addons.php'); }
    public function render_addon_access(): void {
        // Oude link support: stuur door naar Add-ons tab
        if (!isset($_GET['tab'])) {
            wp_safe_redirect(admin_url('admin.php?page=bp-core-addons&tab=toegang'));
            exit;
        }
        $this->include_admin_page('addon-access.php');
    }
    public function render_templates(): void { $this->include_admin_page('templates.php'); }
    public function render_maintenance(): void { $this->include_admin_page('maintenance.php'); }
    public function render_audit(): void { $this->include_admin_page('audit.php'); }
}
