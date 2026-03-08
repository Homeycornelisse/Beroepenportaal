<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
    wp_die(__('Je hebt geen rechten om deze pagina te bekijken.', 'beroepen-portaal-core'));
}

$notice = '';
$errors = [];

// Eerst laden
$pages = bp_core_get_linked_pages();

function bp_core_pages_get_all_pages(): array {
    // get_pages() kan op sommige installs leeg terugkomen met 'any'
    $all = get_posts([
        'post_type'      => 'page',
        'posts_per_page' => -1,
        'post_status'    => ['publish','draft','private','pending','future'],
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);
    return is_array($all) ? $all : [];
}

function bp_core_pages_find_by_slug(string $slug) {
    if (function_exists('bp_core_find_page_by_slug_any_status')) {
        return bp_core_find_page_by_slug_any_status($slug);
    }
    $p = get_page_by_path($slug);
    return ($p && !is_wp_error($p)) ? $p : null;
}

function bp_core_pages_create_or_get(string $title, string $slug, string $shortcode, array &$errors): int {
    $existing = bp_core_pages_find_by_slug($slug);
    if ($existing && !empty($existing->ID)) {
        if (!empty($existing->post_status) && $existing->post_status === 'trash') {
            wp_update_post(['ID' => (int)$existing->ID, 'post_status' => 'publish']);
        }
        return (int) $existing->ID;
    }

    $by_title = get_page_by_title($title);
    if ($by_title && !empty($by_title->ID)) {
        return (int) $by_title->ID;
    }

    $id = wp_insert_post([
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_author'  => get_current_user_id(),
        'post_content' => $shortcode,
    ], true);

    if (is_wp_error($id)) {
        $errors[] = $title . ': ' . $id->get_error_message();
        return 0;
    }

    return (int) $id;
}

function bp_core_link_actions(int $id): string {
    $edit = get_edit_post_link($id, '');
    $view = get_permalink($id);
    $parts = [];
    if ($edit) $parts[] = '<a href="' . esc_url($edit) . '">Bewerk</a>';
    if ($view) $parts[] = '<a href="' . esc_url($view) . '" target="_blank" rel="noopener">Bekijk</a>';
    return $parts ? implode(' | ', $parts) : '—';
}

// Opslaan
if (isset($_POST['bp_core_pages_save'])) {
    check_admin_referer('bp_core_pages_save');

    $pages['home']      = isset($_POST['bp_page_home']) ? absint($_POST['bp_page_home']) : 0;
    $pages['dashboard'] = isset($_POST['bp_page_dashboard']) ? absint($_POST['bp_page_dashboard']) : 0;
    $pages['beroepen']  = isset($_POST['bp_page_beroepen']) ? absint($_POST['bp_page_beroepen']) : 0;
    $pages['uitleg']    = isset($_POST['bp_page_uitleg']) ? absint($_POST['bp_page_uitleg']) : 0;
    $pages['login']     = isset($_POST['bp_page_login']) ? absint($_POST['bp_page_login']) : 0;
    $pages['inbox']     = isset($_POST['bp_page_inbox']) ? absint($_POST['bp_page_inbox']) : 0;

    bp_core_set_linked_pages($pages);

    // opnieuw laden (zeker weten)
    $pages = bp_core_get_linked_pages();

    $notice = 'Pagina-koppelingen opgeslagen.';
}

// Standaard pagina's maken + koppelen
if (isset($_POST['bp_core_pages_create'])) {
    check_admin_referer('bp_core_pages_create');

    $linked = [];

    $home_id = bp_core_pages_create_or_get('Portaal Home', 'portaal-home', '<!-- wp:bp/portaal-page {"screen":"home"} /-->', $errors);
    if ($home_id) { $pages['home'] = $home_id; $linked[] = 'Portaal Home'; }

    $dash_id = bp_core_pages_create_or_get('Portaal Dashboard', 'portaal-dashboard', '<!-- wp:bp/portaal-page {"screen":"dashboard"} /-->', $errors);
    if ($dash_id) { $pages['dashboard'] = $dash_id; $linked[] = 'Portaal Dashboard'; }

    $beroep_id = bp_core_pages_create_or_get('Portaal Beroepen', 'portaal-beroepen', '<!-- wp:bp/portaal-page {"screen":"beroepen"} /-->', $errors);
    if ($beroep_id) { $pages['beroepen'] = $beroep_id; $linked[] = 'Portaal Beroepen'; }

    $uitleg_id = bp_core_pages_create_or_get('Hoe werkt het', 'hoe-werkt-het', '<!-- wp:bp/portaal-page {"screen":"uitleg"} /-->', $errors);
    if ($uitleg_id) { $pages['uitleg'] = $uitleg_id; $linked[] = 'Hoe werkt het'; }

    $login_id = bp_core_pages_create_or_get('Login Portaal', 'login-portaal', '<!-- wp:bp/portaal-page {"screen":"login"} /-->', $errors);
    if ($login_id) { $pages['login'] = $login_id; $linked[] = 'Login Portaal'; }

    $inbox_id = bp_core_pages_create_or_get('Berichten Inbox', 'portaal-inbox', '<!-- wp:bp/portaal-page {"screen":"inbox"} /-->', $errors);
    if ($inbox_id) { $pages['inbox'] = $inbox_id; $linked[] = 'Berichten Inbox'; }

    bp_core_set_linked_pages($pages);

    // opnieuw laden (zeker weten)
    $pages = bp_core_get_linked_pages();

    $notice = !empty($linked)
        ? 'Pagina\'s aangemaakt/gekoppeld: ' . implode(', ', $linked) . '.'
        : 'Er zijn geen pagina\'s aangemaakt. Zie de foutmeldingen hieronder.';
}

// Als er nog lege koppelingen zijn: probeer ze automatisch te vinden op slug
if (function_exists('bp_core_autodetect_pages')) {
    $pages = bp_core_autodetect_pages();
}

$wp_pages = bp_core_pages_get_all_pages();
$bp_pages_empty = empty($wp_pages);

?>
<div class="wrap">
    <h1>Pagina-koppelingen</h1>
    <p>Koppel je portaal-onderdelen aan normale WordPress pagina's. Je kunt ook automatisch standaard pagina's laten aanmaken.</p>

    <?php if ($notice): ?>
        <div class="updated notice"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="notice notice-error">
            <p><strong>Er ging iets mis:</strong></p>
            <ul style="margin-left:18px; list-style:disc;">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo esc_html($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <h2>Huidige koppelingen</h2>
<?php if ($bp_pages_empty): ?>
  <div class="notice notice-warning"><p>Ik kan geen WordPress pagina's ophalen voor de dropdown. Meestal komt dit door rechten, een cache-plugin, of omdat de pagina's niet van type <code>page</code> zijn. De koppelingen hierboven werken wél, je kunt voorlopig ook via 'Bewerk' naar de pagina's.</p></div>
<?php endif; ?>

    <table class="widefat striped" style="max-width:980px;margin-top:10px;">
        <thead>
            <tr>
                <th>Onderdeel</th>
                <th>Pagina</th>
                <th style="width:180px;">Acties</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $rows = [
                'home'      => 'Home',
                'dashboard' => 'Dashboard',
                'beroepen'  => 'Beroepen',
                'uitleg'    => 'Uitleg',
                'login'     => 'Login',
                'inbox'     => 'Inbox',
            ];
            foreach ($rows as $key => $label):
                $id = (int) ($pages[$key] ?? 0);
                $title = $id ? get_the_title($id) : 'Niet gekoppeld';
            ?>
            <tr>
                <td><?php echo esc_html($label); ?></td>
                <td><?php echo esc_html($title ?: 'Niet gekoppeld'); ?><?php echo $id ? ' (ID ' . esc_html((string)$id) . ')' : ''; ?></td>
                <td><?php echo $id ? bp_core_link_actions($id) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <hr />

    <form method="post" style="margin-top:16px;">
        <?php wp_nonce_field('bp_core_pages_save'); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="bp_page_home">Home pagina</label></th>
                <td>
                    <select name="bp_page_home" id="bp_page_home">
                        <option value="0">- Kies een pagina -</option>
                        <?php foreach ($wp_pages as $p): ?>
                            <option value="<?php echo esc_attr($p->ID); ?>" <?php selected((int)$pages['home'], (int)$p->ID); ?>>
                                <?php echo esc_html($p->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Blok: <code>Beroepen Portaal: Pagina</code> (instelling: Home)</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="bp_page_dashboard">Dashboard pagina</label></th>
                <td>
                    <select name="bp_page_dashboard" id="bp_page_dashboard">
                        <option value="0">- Kies een pagina -</option>
                        <?php foreach ($wp_pages as $p): ?>
                            <option value="<?php echo esc_attr($p->ID); ?>" <?php selected((int)$pages['dashboard'], (int)$p->ID); ?>>
                                <?php echo esc_html($p->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Blok: <code>Beroepen Portaal: Pagina</code> (instelling: Dashboard)</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="bp_page_beroepen">Beroepen pagina</label></th>
                <td>
                    <select name="bp_page_beroepen" id="bp_page_beroepen">
                        <option value="0">- Kies een pagina -</option>
                        <?php foreach ($wp_pages as $p): ?>
                            <option value="<?php echo esc_attr($p->ID); ?>" <?php selected((int)$pages['beroepen'], (int)$p->ID); ?>>
                                <?php echo esc_html($p->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Blok: <code>Beroepen Portaal: Pagina</code> (instelling: Beroepen)</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="bp_page_uitleg">Uitleg pagina</label></th>
                <td>
                    <select name="bp_page_uitleg" id="bp_page_uitleg">
                        <option value="0">- Kies een pagina -</option>
                        <?php foreach ($wp_pages as $p): ?>
                            <option value="<?php echo esc_attr($p->ID); ?>" <?php selected((int)$pages['uitleg'], (int)$p->ID); ?>>
                                <?php echo esc_html($p->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Blok: <code>Beroepen Portaal: Pagina</code> (instelling: Uitleg)</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="bp_page_login">Login pagina</label></th>
                <td>
                    <select name="bp_page_login" id="bp_page_login">
                        <option value="0">- Kies een pagina -</option>
                        <?php foreach ($wp_pages as $p): ?>
                            <option value="<?php echo esc_attr($p->ID); ?>" <?php selected((int)$pages['login'], (int)$p->ID); ?>>
                                <?php echo esc_html($p->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Blok: <code>Beroepen Portaal: Pagina</code> (instelling: Login)</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="bp_page_inbox">Inbox pagina</label></th>
                <td>
                    <select name="bp_page_inbox" id="bp_page_inbox">
                        <option value="0">- Kies een pagina -</option>
                        <?php foreach ($wp_pages as $p): ?>
                            <option value="<?php echo esc_attr($p->ID); ?>" <?php selected((int)$pages['inbox'], (int)$p->ID); ?>>
                                <?php echo esc_html($p->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Blok: <code>Beroepen Portaal: Pagina</code> (instelling: Inbox)</p>
                </td>
            </tr>
        </table>

        <p>
            <button class="button button-primary" type="submit" name="bp_core_pages_save" value="1">Opslaan</button>
        </p>
    </form>

    <hr />

    <form method="post">
        <?php wp_nonce_field('bp_core_pages_create'); ?>
        <p>
            <button class="button" type="submit" name="bp_core_pages_create" value="1">Maak standaard pagina's aan</button>
        </p>
        <p class="description">Maakt (of koppelt) automatisch: Portaal Home, Portaal Dashboard, Portaal Beroepen, Hoe werkt het, Login Portaal, Berichten Inbox.</p>
    </form>
</div>
