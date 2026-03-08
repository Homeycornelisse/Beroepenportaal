<?php
defined('ABSPATH') || exit;

$user         = wp_get_current_user();
$user_id      = $user->ID;
$logout_url   = wp_logout_url(get_permalink(get_page_by_path('login-portaal')) ?: home_url());
$portaal_page = get_page_by_path('portaal');
$traject_tracker_url = (class_exists('BP_Core_Traject_Tracker') && method_exists('BP_Core_Traject_Tracker', 'get_tracker_url'))
  ? (string) BP_Core_Traject_Tracker::get_tracker_url()
  : '';
// CV pagina komt uit addon (filter/option)
$cv_url = function_exists('bp_core_addon_page_url') ? bp_core_addon_page_url('bp_addon_cv_page_id') : '';
$docs_url = function_exists('bp_core_addon_page_url') ? bp_core_addon_page_url('bp_addon_documenten_page_id') : '';
$logboek_page = get_page_by_path('logboek');
$uitleg_page  = get_page_by_path('hoe-werkt-het');
$linked_pages = function_exists('bp_core_get_linked_pages') ? bp_core_get_linked_pages() : [];
$inbox_page_id = isset($linked_pages['inbox']) ? (int) $linked_pages['inbox'] : 0;
$inbox_url = $inbox_page_id > 0 ? get_permalink($inbox_page_id) : '';
$show_legacy_inbox = $inbox_url === '';

// Haal statistieken op
global $wpdb;
$prefix = $wpdb->prefix . 'kb_';

// Account pagina: zoek op optie-id of scan op block-attribuut
$account_url = '';
$account_page_id = (int) get_option('kb_account_page_id', 0);
if ($account_page_id > 0) {
    $ap = get_post($account_page_id);
    if ($ap && $ap->post_status === 'publish') {
        $account_url = get_permalink($ap);
    }
}
if (!$account_url) {
    $found_id = $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type='page' AND post_status='publish'
         AND post_content LIKE '%\"screen\":\"account\"%'
         LIMIT 1"
    );
    if ($found_id) {
        $account_url = get_permalink((int)$found_id);
        update_option('kb_account_page_id', (int)$found_id);
    }
}

$selecties_count = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$prefix}selecties WHERE client_id=%d", $user_id
));
$brieven_count = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$prefix}brieven WHERE client_id=%d", $user_id
));
$aantekeningen_count = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$prefix}aantekeningen WHERE client_id=%d", $user_id
));

// Let op: CV hoort bij de CV-addon. De Core doet hier geen DB-queries voor,
// zodat het dashboard ook netjes werkt als de addon niet geïnstalleerd is.

// Laatste logboek entries (compat: kolommen verschillen per addon-versie)
$logboek_entries = [];
$logboek_table   = $prefix . 'logboek';
$logboek_col     = null;
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logboek_table))) {
    if ($wpdb->get_var("SHOW COLUMNS FROM `{$logboek_table}` LIKE 'inhoud'")) {
        $logboek_col = 'inhoud';
    } elseif ($wpdb->get_var("SHOW COLUMNS FROM `{$logboek_table}` LIKE 'omschrijving'")) {
        $logboek_col = 'omschrijving';
    }
}
if ($logboek_col) {
    $logboek_entries = $wpdb->get_results($wpdb->prepare(
        "SELECT {$logboek_col} AS inhoud, aangemaakt FROM {$logboek_table} WHERE client_id=%d ORDER BY aangemaakt DESC LIMIT 3",
        $user_id
    ));
}

// Begeleider naam
$begeleider_id = get_user_meta($user_id, 'kb_begeleider_id', true);
$begeleider_naam = '';
if ($begeleider_id) {
    $begel_user = get_userdata($begeleider_id);
    if ($begel_user) $begeleider_naam = $begel_user->display_name;
}

// Openstaande review brieven (compat: kolomnaam kan verschillen)
$review_brieven = [];
$brieven_table  = $prefix . 'brieven';
$titel_col      = null;
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $brieven_table))) {
    if ($wpdb->get_var("SHOW COLUMNS FROM `{$brieven_table}` LIKE 'titel'")) {
        $titel_col = 'titel';
    } elseif ($wpdb->get_var("SHOW COLUMNS FROM `{$brieven_table}` LIKE 'vacature_titel'")) {
        $titel_col = 'vacature_titel';
    }
}
if ($titel_col) {
    $review_brieven = $wpdb->get_results($wpdb->prepare(
        "SELECT {$titel_col} AS titel, review_status FROM {$brieven_table} WHERE client_id=%d AND review_aangevraagd=1 ORDER BY aangemaakt DESC LIMIT 3",
        $user_id
    ));
}

$org_naam  = bp_core_get_org_name('Beroepen Portaal');
$ai_actief = (bool) get_option('kb_ai_enabled', 0) && (bool) get_option('kb_anthropic_key', '');

// Berichten inbox
$inbox_count = class_exists('BP_Core_Berichten') ? BP_Core_Berichten::aantal_ongelezen($user_id) : 0;
$mijn_inbox  = class_exists('BP_Core_Berichten') ? BP_Core_Berichten::haal_inbox($user_id, '', 30) : [];
$leid_inbox  = class_exists('BP_Core_Berichten') ? BP_Core_Berichten::haal_inbox($user_id, 'overname_verzoek', 30) : [];

// Rollen bepalen voor dashboard weergave
$is_leidinggevende = class_exists('BP_Core_Roles') ? BP_Core_Roles::is_leidinggevende($user) : current_user_can('manage_options');
$is_client         = class_exists('BP_Core_Roles') ? BP_Core_Roles::is_client($user) : false;

// Let op: is_begeleider() is ook true voor leidinggevende.
$is_begeleider = false;
if (class_exists('BP_Core_Roles')) {
  $is_begeleider = BP_Core_Roles::is_begeleider($user) && !BP_Core_Roles::is_leidinggevende($user);
}

// Beheer-tab in front-end: alleen leidinggevende (en admin)
$can_front_beheer = $is_leidinggevende || current_user_can('manage_options');
$admin_only = current_user_can('manage_options');
if ($can_front_beheer) {
  $admin_notice = '';
  if (!empty($_GET['bp_saved_caps'])) $admin_notice = '✅ Rollen & rechten opgeslagen.';
  if (!empty($_GET['bp_reset_caps'])) $admin_notice = '✅ Rollen & rechten zijn opnieuw ingesteld.';
  if (!empty($_GET['bp_created_user'])) $admin_notice = '✅ Gebruiker aangemaakt.';
  if (!empty($_GET['bp_transfer_done'])) $admin_notice = '✅ Overname uitgevoerd.';
  if (!empty($_GET['bp_addon_access_saved'])) $admin_notice = '✅ Add-ontoegang opgeslagen.';
  if (!empty($_GET['bp_addon_access_error'])) $admin_notice = '⚠️ Kon add-ontoegang niet opslaan.';
  if (!empty($_GET['bp_overname_verzonden'])) $admin_notice = '✅ Overnameverzoek verzonden naar de leidinggevende.';
  if (!empty($_GET['bp_reactie_verzonden'])) $admin_notice = '✅ Reactie verzonden.';
  if (!empty($_GET['bp_bericht_verzonden'])) $admin_notice = '✅ Bericht verzonden.';

  // Data voor admin blok
  $admin_roles = [
    BP_Core_Roles::ROLE_LEIDINGGEVENDE => 'Leidinggevende',
    BP_Core_Roles::ROLE_BEGELEIDER     => 'Begeleider',
    BP_Core_Roles::ROLE_CLIENT         => 'Cliënt',
    'administrator'                    => 'Administrator',
  ];
  $admin_caps = [
    BP_Core_Roles::CAP_VIEW_PORTAAL       => 'Portaal bekijken',
    BP_Core_Roles::CAP_VIEW_CLIENTS       => 'Cliënten bekijken',
    BP_Core_Roles::CAP_ADD_CLIENTS        => 'Cliënten aanmaken',
    BP_Core_Roles::CAP_EDIT_AANTEKENINGEN => 'Notities bewerken',
    BP_Core_Roles::CAP_MANAGE_TEAM        => 'Team beheren',
    BP_Core_Roles::CAP_USE_CV             => 'CV gebruiken',
  ];

  $admin_clients = get_users(['role' => BP_Core_Roles::ROLE_CLIENT, 'orderby' => 'display_name', 'order' => 'ASC']);
  $admin_begeleiders = get_users(['role' => BP_Core_Roles::ROLE_BEGELEIDER, 'orderby' => 'display_name', 'order' => 'ASC']);
  $admin_leidinggevenden = get_users(['role' => BP_Core_Roles::ROLE_LEIDINGGEVENDE, 'orderby' => 'display_name', 'order' => 'ASC']);
  $admin_leidinggevenden_all = get_users(['role__in' => [BP_Core_Roles::ROLE_LEIDINGGEVENDE, 'administrator'], 'orderby' => 'display_name', 'order' => 'ASC']);

  $admin_all_users = get_users([
    'role__in' => [BP_Core_Roles::ROLE_CLIENT, BP_Core_Roles::ROLE_BEGELEIDER, BP_Core_Roles::ROLE_LEIDINGGEVENDE, 'administrator'],
    'orderby' => 'display_name',
    'order' => 'ASC',
    'number' => 5000,
  ]);

  $admin_addons = function_exists('bp_core_get_registered_addons') ? bp_core_get_registered_addons() : [];
  $admin_target_user = (int)($_GET['bp_target_user'] ?? 0);
  if ($admin_target_user <= 0 && !empty($admin_clients) && isset($admin_clients[0]->ID)) {
    $admin_target_user = (int)$admin_clients[0]->ID;
  }
  $admin_user_addon_access = function_exists('bp_core_get_user_addon_access') ? bp_core_get_user_addon_access($admin_target_user) : [];

  // Rechten per gebruiker (front-end admin tab)
  $admin_caps_user = (int)($_GET['bp_caps_user'] ?? 0);
  if ($admin_caps_user <= 0 && !empty($admin_all_users) && isset($admin_all_users[0]->ID)) {
    $admin_caps_user = (int)$admin_all_users[0]->ID;
  }

  $audit_entries = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT id, object_type, object_id, actor_id, actie, oud, nieuw, aangemaakt FROM {$wpdb->prefix}kb_audit_log ORDER BY aangemaakt DESC LIMIT %d",
      20
    )
  );
}
?>

<div class="kb-wrap" id="kb-dashboard-root">

  <!-- Geen plugin-header: theme header/footer worden gebruikt. -->

  <!-- Hero -->
  <?php $eigen_foto = (string) get_user_meta($user_id, 'kb_profielfoto', true); ?>
  <div class="kb-hero">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
      <?php if ($eigen_foto): ?>
        <img src="<?= esc_url($eigen_foto) ?>" alt="" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.4);flex-shrink:0;">
      <?php else: ?>
        <div style="width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;">👤</div>
      <?php endif; ?>
      <div>
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;opacity:.6;">Mijn Dashboard</div>
      <div class="kb-hero-title">Welkom, <?= esc_html($user->display_name) ?>!</div>
      <?php
        $sub = 'Hier vind je een overzicht van al jouw activiteiten en tools';
        if ($is_client) $sub = 'Hier vind je jouw tools, voortgang en logboek';
        if ($is_begeleider) $sub = 'Hier vind je jouw cliënten en snelle acties';
        if ($is_leidinggevende) $sub = 'Hier vind je overzicht en beheer voor je team';
      ?>
      <div class="kb-hero-sub"><?php echo esc_html($sub); ?></div>

      <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
        <?php if ($inbox_url): ?>
          <a href="<?= esc_url($inbox_url) ?>" class="kb-btn kb-btn-primary kb-btn-sm">Berichten inbox</a>
        <?php endif; ?>
        <a href="<?= esc_url($logout_url) ?>" class="kb-btn kb-btn-ghost kb-btn-sm">Uitloggen</a>
      </div>
      </div><!-- /tekst -->
    </div><!-- /foto+tekst flex -->
    <?php if ($begeleider_naam): ?>
    <?php $begel_foto = $begeleider_id ? (string)get_user_meta((int)$begeleider_id, 'kb_profielfoto', true) : ''; ?>
    <div style="background:rgba(255,255,255,.15);border-radius:12px;padding:14px 20px;text-align:right;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;opacity:.7;margin-bottom:8px;">Jouw begeleider</div>
      <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;">
        <div style="font-size:15px;font-weight:800;"><?= esc_html($begeleider_naam) ?></div>
        <?php if ($begel_foto): ?>
          <img src="<?= esc_url($begel_foto) ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.5);">
        <?php else: ?>
          <div style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:16px;">👤</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($is_client): ?>
  <!-- Statistieken balk -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:28px;">
    <?php
    $stats = [
      ['key'=>'selecties', 'label'=>'Beroepen aangevinkt', 'value'=>$selecties_count, 'icon'=>'⭐', 'color'=>'#f59e0b'],
      ['key'=>'brieven',   'label'=>'Sollicitatiebrieven', 'value'=>$brieven_count, 'icon'=>'✉️', 'color'=>'#3b82f6'],
      ['key'=>'notities',  'label'=>'Notities van begeleider', 'value'=>$aantekeningen_count, 'icon'=>'📝', 'color'=>'#8b5cf6'],
    ];
    $stats = bp_core_apply_dashboard_stats($stats, $user_id);
    foreach ($stats as $s):
      if (!is_array($s)) continue;
      $label = $s['label'] ?? '';
      $value = $s['value'] ?? '';
      $icon  = $s['icon']  ?? '';
      $color = $s['color'] ?? '#334155';
    ?>
    <div style="background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06);">
      <div style="font-size:26px;margin-bottom:6px;"><?= esc_html($icon) ?></div>
      <div style="font-size:24px;font-weight:800;color:<?= esc_attr($color) ?>;"><?= esc_html((string)$value) ?></div>
      <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:2px;"><?= esc_html((string)$label) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Hoofd grid: snelkoppelingen + activiteit -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;">

    <!-- Linker kolom: tools + logboek -->
    <div style="display:flex;flex-direction:column;gap:16px;">
      <!-- Mijn tools -->
      <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
      <div style="font-weight:800;color:#003082;font-size:15px;margin-bottom:16px;">🔧 Mijn tools</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">

        <?php
          $tiles = [];
          if ($portaal_page) {
            $tiles[] = [
              'key' => 'beroepen',
              'title' => 'Beroepen verkennen',
              'subtitle' => $selecties_count . ' beroepen aangevinkt',
              'url' => get_permalink($portaal_page),
              'icon' => '⭐',
              'style' => 'info',
            ];
          }

          if ($logboek_page) {
            $tiles[] = [
              'key' => 'logboek',
              'title' => 'Mijn logboek',
              'subtitle' => 'Jouw voortgang bijhouden',
              'url' => get_permalink($logboek_page),
              'icon' => '📋',
              'style' => 'purple',
            ];
          }

          if ($uitleg_page) {
            $tiles[] = [
              'key' => 'uitleg',
              'title' => 'Hoe werkt het?',
              'subtitle' => 'Uitleg over het platform',
              'url' => get_permalink($uitleg_page),
              'icon' => '💡',
              'style' => 'warning',
            ];
          }

          if ($account_url) {
            $tiles[] = [
              'key' => 'account',
              'title' => 'Mijn account',
              'subtitle' => 'Profiel, NAW en wachtwoord',
              'url' => $account_url,
              'icon' => '👤',
              'style' => 'success',
            ];
          }

          if ($inbox_url) {
            $tiles[] = [
              'key' => 'inbox',
              'title' => 'Berichten inbox',
              'subtitle' => $inbox_count > 0 ? ($inbox_count . ' ongelezen') : 'Geen ongelezen berichten',
              'url' => $inbox_url,
              'icon' => '✉️',
              'style' => 'info',
            ];
          }

          $tiles = bp_core_apply_tools_tiles($tiles, $user_id);

          $styles = [
            'info' =>    ['bg'=>'#f0f9ff','hover'=>'#e0f2fe','border'=>'#bae6fd','color'=>'#0369a1'],
            'success' => ['bg'=>'#f0fdf4','hover'=>'#dcfce7','border'=>'#86efac','color'=>'#166534'],
            'purple' =>  ['bg'=>'#faf5ff','hover'=>'#f3e8ff','border'=>'#d8b4fe','color'=>'#6d28d9'],
            'warning' => ['bg'=>'#fff7ed','hover'=>'#ffedd5','border'=>'#fed7aa','color'=>'#c2410c'],
          ];

          foreach ($tiles as $t):
            if (!is_array($t)) continue;
            $url = $t['url'] ?? '';
            if (!$url) continue;
            $title = $t['title'] ?? '';
            $subtitle = $t['subtitle'] ?? '';
            $icon = $t['icon'] ?? '•';
            $style_key = $t['style'] ?? 'info';
            $st = $styles[$style_key] ?? $styles['info'];
        ?>
        <a href="<?= esc_url($url) ?>" style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;padding:12px 14px;background:<?= esc_attr($st['bg']) ?>;border:1px solid <?= esc_attr($st['border']) ?>;border-radius:10px;text-decoration:none;color:<?= esc_attr($st['color']) ?>;font-weight:700;transition:all .15s;min-height:96px;"
           onmouseover="this.style.background='<?= esc_js($st['hover']) ?>'" onmouseout="this.style.background='<?= esc_js($st['bg']) ?>'">
          <div style="width:100%;display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:20px;line-height:1;"><?= esc_html($icon) ?></span>
            <span>→</span>
          </div>
          <div style="width:100%;">
            <div style="font-size:13px;font-weight:800;"><?= esc_html((string)$title) ?></div>
            <div style="font-size:11px;font-weight:400;color:#64748b;margin-top:1px;"><?= esc_html((string)$subtitle) ?></div>
          </div>
        </a>
        <?php endforeach; ?>

      </div>
    </div>

      <!-- Recente logboek entries -->
      <?php if (!empty($logboek_entries)): ?>
      <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
        <div style="font-weight:800;color:#003082;font-size:15px;margin-bottom:14px;">📋 Recente logboekaantekeningen</div>
        <?php foreach ($logboek_entries as $entry): ?>
        <div style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
          <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:2px;"><?= date('d-m-Y', strtotime($entry->aangemaakt)) ?></div>
          <div style="font-size:13px;color:#334155;line-height:1.4;"><?= esc_html(mb_substr($entry->inhoud, 0, 100)) ?><?= mb_strlen($entry->inhoud) > 100 ? '…' : '' ?></div>
        </div>
        <?php endforeach; ?>
        <?php if ($logboek_page): ?>
        <a href="<?= esc_url(get_permalink($logboek_page)) ?>" style="display:inline-block;margin-top:10px;font-size:12px;color:#003082;font-weight:700;">Volledig logboek →</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Activiteit kolom -->
    <div style="display:flex;flex-direction:column;gap:16px;">

      <!-- Review brieven status -->
      <?php if (!empty($review_brieven)): ?>
      <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
        <div style="font-weight:800;color:#003082;font-size:15px;margin-bottom:14px;">📬 Mijn brieven (review)</div>
        <?php foreach ($review_brieven as $b):
          $status = $b->review_status ?: 'aangevraagd';
          $badge  = match($status) {
            'goedgekeurd' => ['bg'=>'#f0fdf4','border'=>'#86efac','color'=>'#166534','icon'=>'✅','label'=>'Goedgekeurd'],
            'aanpassen'   => ['bg'=>'#fffbeb','border'=>'#fde68a','color'=>'#92400e','icon'=>'📝','label'=>'Aanpassen'],
            default       => ['bg'=>'#eff6ff','border'=>'#bfdbfe','color'=>'#1e40af','icon'=>'⏳','label'=>'In review'],
          };
        ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;">
          <span style="font-size:14px;"><?= $badge['icon'] ?></span>
          <span style="font-size:13px;font-weight:600;flex:1;"><?= esc_html($b->titel ?: 'Sollicitatiebrief') ?></span>
          <span style="background:<?= $badge['bg'] ?>;border:1px solid <?= $badge['border'] ?>;color:<?= $badge['color'] ?>;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;"><?= $badge['label'] ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($cv_url): ?>
        <a href="<?= esc_url($cv_url) ?>" style="display:inline-block;margin-top:10px;font-size:12px;color:#003082;font-weight:700;">Alle brieven bekijken →</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Leeg state als geen activiteit -->
      <?php if (empty($review_brieven)): ?>
      <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:28px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06);">
        <div style="font-size:36px;margin-bottom:10px;">🌱</div>
        <div style="font-weight:700;color:#334155;margin-bottom:6px;">Je bent net begonnen!</div>
        <div style="font-size:13px;color:#64748b;">Verken beroepen, upload je CV of schrijf een sollicitatiebrief om te starten.</div>
        <?php if ($traject_tracker_url): ?>
        <a href="<?= esc_url($traject_tracker_url) ?>" class="kb-btn kb-btn-primary" style="margin-top:14px;display:inline-block;">Open trajecttracker →</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <?php if ($ai_actief): ?>
  <!-- AI tip banner -->
  <div style="background:linear-gradient(135deg,#003082,#1d4ed8);border-radius:14px;padding:20px 24px;color:white;display:flex;align-items:center;gap:16px;margin-bottom:20px;">
    <span style="font-size:30px;">🤖</span>
    <div>
      <div style="font-weight:800;font-size:14px;margin-bottom:4px;">AI-functies zijn actief</div>
      <div style="font-size:13px;opacity:.85;">Via CV & Brieven kun je je CV laten analyseren en automatisch sollicitatiebrieven genereren.</div>
    </div>
    <?php if ($cv_url): ?>
    <a href="<?= esc_url($cv_url) ?>" style="margin-left:auto;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);padding:8px 16px;border-radius:8px;color:white;text-decoration:none;font-weight:700;font-size:13px;white-space:nowrap;">Aan de slag →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Berichten (client ↔ begeleider) -->
  <?php
    $client_bericht_fout = isset($_GET['bp_bericht_fout']) ? sanitize_key($_GET['bp_bericht_fout']) : '';
    $client_bericht_ok   = !empty($_GET['bp_bericht_verzonden']);
    $client_ratelimit    = !empty($_GET['bp_bericht_ratelimit']);
    $cl_begeleider_id    = (int) get_user_meta($user_id, 'kb_begeleider_id', true);
    $cl_begeleider       = $cl_begeleider_id > 0 ? get_user_by('id', $cl_begeleider_id) : null;
  ?>
  <?php if ($show_legacy_inbox && $is_client && $cl_begeleider): ?>
  <div id="bp-client-berichten" style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-top:18px;">
    <div style="font-weight:900;color:#003082;font-size:16px;margin-bottom:14px;">&#128140; Berichten</div>

    <?php if ($client_bericht_ok): ?>
      <div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:10px 16px;margin-bottom:12px;color:#166534;font-size:13px;font-weight:600;">Bericht verzonden.</div>
    <?php elseif ($client_ratelimit): ?>
      <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 16px;margin-bottom:12px;color:#b91c1c;font-size:13px;">U heeft het maximum aantal berichten per uur bereikt. Probeer het later opnieuw.</div>
    <?php elseif ($client_bericht_fout): ?>
      <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 16px;margin-bottom:12px;color:#b91c1c;font-size:13px;">Vul een onderwerp en bericht in.</div>
    <?php endif; ?>

    <!-- Inbox client -->
    <?php if (!empty($mijn_inbox)): ?>
    <div style="margin-bottom:18px;">
      <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Ontvangen berichten</div>
      <?php foreach ($mijn_inbox as $b):
        $bid    = (int)$b->id;
        $van    = get_user_by('id', (int)$b->van_id);
        $van_naam = $van ? $van->display_name : 'Systeem';
        $ongel  = (int)$b->gelezen === 0;
        $lang   = mb_strlen($b->inhoud) > 200;
        $cat    = esc_attr($b->categorie ?? '');
        $cat_labels = ['' => '— Categorie —', 'werk' => 'Werk', 'persoonlijk' => 'Persoonlijk', 'overname' => 'Overname', 'urgent' => 'Urgent'];
      ?>
      <div style="background:<?= $ongel ? '#eff6ff' : '#f8fafc' ?>;border:1px solid <?= $ongel ? '#bfdbfe' : '#e2e8f0' ?>;border-radius:10px;padding:12px 14px;margin-bottom:8px;">
        <div style="display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap;">
          <div style="flex:1;min-width:0;">
            <?php if ($ongel): ?><span style="background:#ef4444;color:#fff;border-radius:4px;font-size:9px;font-weight:800;padding:1px 5px;margin-bottom:4px;display:inline-block;">Nieuw</span><?php endif; ?>
            <?php if ($cat && isset($cat_labels[$b->categorie])): ?><span style="background:#e0f2fe;color:#0369a1;border-radius:4px;font-size:9px;font-weight:800;padding:1px 5px;margin-bottom:4px;display:inline-block;"><?= esc_html($cat_labels[$b->categorie]) ?></span><?php endif; ?>
            <div style="font-weight:800;font-size:13px;color:#1e293b;"><?= esc_html($b->onderwerp) ?></div>
            <div style="font-size:11px;color:#64748b;margin-bottom:6px;">Van <?= esc_html($van_naam) ?> &nbsp;|&nbsp; <?= esc_html(date('d-m-Y H:i', strtotime($b->aangemaakt))) ?></div>
            <div class="bp-msg-preview-<?= $bid ?>" style="font-size:13px;color:#334155;white-space:pre-wrap;"><?= esc_html(mb_substr($b->inhoud, 0, 200)) ?><?= $lang ? '…' : '' ?></div>
            <?php if ($lang): ?>
            <div class="bp-msg-full-<?= $bid ?>" style="display:none;font-size:13px;color:#334155;white-space:pre-wrap;"><?= esc_html($b->inhoud) ?></div>
            <button type="button" class="bp-msg-toggle" data-id="<?= $bid ?>" style="font-size:11px;color:#003082;background:none;border:none;padding:2px 0;cursor:pointer;font-weight:700;margin-top:2px;">▼ Toon alles</button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Acties -->
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;align-items:center;">
          <button type="button" class="bp-reply-toggle" data-id="<?= $bid ?>" style="font-size:11px;font-weight:700;color:#003082;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:4px 10px;cursor:pointer;">↩ Reageer</button>

          <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;">
            <?php wp_nonce_field('bp_verwijder_bericht_' . $bid, 'bp_verwijder_nonce'); ?>
            <input type="hidden" name="action" value="bp_verwijder_bericht">
            <input type="hidden" name="bericht_id" value="<?= $bid ?>">
            <button type="submit" style="font-size:11px;font-weight:700;color:#b91c1c;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:4px 10px;cursor:pointer;" onclick="return confirm('Bericht verwijderen?');">🗑 Verwijder</button>
          </form>

          <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;margin-left:auto;">
            <?php wp_nonce_field('bp_categoriseer_bericht_' . $bid, 'bp_categoriseer_nonce'); ?>
            <input type="hidden" name="action" value="bp_categoriseer_bericht">
            <input type="hidden" name="bericht_id" value="<?= $bid ?>">
            <select name="categorie" onchange="this.form.submit()" style="font-size:11px;border:1px solid #cbd5e1;border-radius:6px;padding:3px 6px;color:#475569;">
              <?php foreach ($cat_labels as $cval => $clabel): ?>
              <option value="<?= esc_attr($cval) ?>" <?= $cat === $cval ? 'selected' : '' ?>><?= esc_html($clabel) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>

        <!-- Inline reply form -->
        <div class="bp-reply-form-<?= $bid ?>" style="display:none;margin-top:10px;border-top:1px solid #e2e8f0;padding-top:10px;">
          <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field('bp_stuur_bericht', 'bp_bericht_nonce'); ?>
            <input type="hidden" name="action" value="bp_stuur_bericht">
            <input type="hidden" name="naar_id" value="<?= (int)$b->van_id ?>">
            <input type="text" name="onderwerp" value="Re: <?= esc_attr($b->onderwerp) ?>" maxlength="255" required style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;font-size:12px;margin-bottom:6px;box-sizing:border-box;">
            <textarea name="inhoud" rows="3" maxlength="5000" required placeholder="Jouw reactie…" style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;font-size:12px;box-sizing:border-box;resize:vertical;margin-bottom:6px;"></textarea>
            <button type="submit" style="background:#003082;color:#fff;border:none;border-radius:8px;padding:7px 18px;font-size:12px;font-weight:700;cursor:pointer;">Versturen</button>
            <button type="button" class="bp-reply-toggle" data-id="<?= $bid ?>" style="background:none;border:1px solid #e2e8f0;border-radius:8px;padding:7px 14px;font-size:12px;color:#64748b;cursor:pointer;margin-left:6px;">Annuleren</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Stuur bericht formulier -->
    <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Stuur bericht naar <?= esc_html($cl_begeleider->display_name) ?></div>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
      <?php wp_nonce_field('bp_stuur_bericht', 'bp_bericht_nonce'); ?>
      <input type="hidden" name="action" value="bp_stuur_bericht">
      <input type="hidden" name="naar_id" value="<?= (int)$cl_begeleider_id ?>">
      <div style="margin-bottom:10px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#64748b;margin-bottom:4px;">Onderwerp</label>
        <input type="text" name="onderwerp" maxlength="255" required style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:13px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:10px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#64748b;margin-bottom:4px;">Bericht</label>
        <textarea name="inhoud" rows="4" maxlength="5000" required style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:13px;box-sizing:border-box;resize:vertical;"></textarea>
      </div>
      <button type="submit" style="background:#003082;color:#fff;border:none;border-radius:8px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;">Versturen</button>
    </form>
  </div>
  <?php endif; ?>

  <?php endif; // einde: client content ?>

  <?php if ($is_begeleider): ?>
  <?php
    $begel_notice = '';
    if (!empty($_GET['bp_overname_verzonden'])) $begel_notice = '✅ Overnameverzoek verzonden naar de leidinggevende.';
    if (!empty($_GET['bp_bericht_verzonden']))   $begel_notice = '✅ Bericht verzonden.';
    if (!empty($_GET['bp_bericht_ratelimit']))   $begel_notice = '⚠️ Maximum berichten per uur bereikt. Probeer later.';
    // Begeleider logboek pagina (voorkeur: logboek-begeleider, fallback: logboek)
    $logboek_begel_page = get_page_by_path('logboek-begeleider') ?: get_page_by_path('logboek');
    // Begeleider dashboard: cliëntenlijst (gekoppeld via meta kb_begeleider_id)
    $mijn_clienten = get_users([
      'role'       => BP_Core_Roles::ROLE_CLIENT,
      'meta_key'   => 'kb_begeleider_id',
      'meta_value' => $user_id,
      'orderby'    => 'display_name',
      'order'      => 'ASC',
      'number'     => 500,
    ]);
    $beroepen_url_base = function_exists('bp_core_addon_page_url') ? (string) bp_core_addon_page_url('bp_addon_beroepen_page_id') : '';
    $selecties_per_client = [];
    if (!empty($mijn_clienten)) {
      $ids = array_map(static fn($u) => (int) $u->ID, $mijn_clienten);
      $ids = array_values(array_filter($ids));
      if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT client_id, COUNT(*) AS cnt
                FROM {$prefix}selecties
                WHERE client_id IN ({$placeholders}) AND vind_ik_leuk = 1
                GROUP BY client_id";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$ids), ARRAY_A);
        foreach ((array) $rows as $row) {
          $selecties_per_client[(int) ($row['client_id'] ?? 0)] = (int) ($row['cnt'] ?? 0);
        }
      }
    }
    // NAW meta per client ophalen
    $client_naw = [];
    foreach ($mijn_clienten as $c) {
      $cid = (int) $c->ID;
      $client_naw[$cid] = [
        'telefoon'      => (string) get_user_meta($cid, 'kb_telefoon', true),
        'geboortedatum' => (string) get_user_meta($cid, 'kb_geboortedatum', true),
        'adres'         => (string) get_user_meta($cid, 'kb_adres', true),
        'postcode'      => (string) get_user_meta($cid, 'kb_postcode', true),
        'woonplaats'    => (string) get_user_meta($cid, 'kb_woonplaats', true),
        'foto'          => (string) get_user_meta($cid, 'kb_profielfoto', true),
      ];
    }

    $all_team_clients = get_users([
      'role'    => BP_Core_Roles::ROLE_CLIENT,
      'orderby' => 'display_name',
      'order'   => 'ASC',
      'number'  => 5000,
      'fields'  => ['ID', 'display_name'],
    ]);
    $my_team_leid = (int) get_user_meta($user_id, 'kb_leidinggevende_id', true);
    $team_groups = [];
    foreach ((array) $all_team_clients as $tc) {
      $tcid = (int) ($tc->ID ?? 0);
      if ($tcid <= 0) continue;
      $leid = (int) get_user_meta($tcid, 'kb_leidinggevende_id', true);
      $leid_user = $leid > 0 ? get_user_by('id', $leid) : null;
      $team_key = $leid > 0 ? ('team_' . $leid) : 'team_onbekend';
      $team_name = $leid_user ? (string) $leid_user->display_name : 'Geen team';
      if (!isset($team_groups[$team_key])) {
        $team_groups[$team_key] = [
          'team_name' => $team_name,
          'team_id' => $leid,
          'is_current_team' => ($my_team_leid > 0 && $my_team_leid === $leid),
          'clients' => [],
        ];
      }
      $team_groups[$team_key]['clients'][] = $tc;
    }
    uasort($team_groups, static function ($a, $b) {
      $a_current = !empty($a['is_current_team']) ? 1 : 0;
      $b_current = !empty($b['is_current_team']) ? 1 : 0;
      if ($a_current !== $b_current) return $b_current <=> $a_current;
      return strcmp((string) ($a['team_name'] ?? ''), (string) ($b['team_name'] ?? ''));
    });
  ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px;">
    <div style="background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06);">
      <div style="font-size:26px;margin-bottom:6px;">👥</div>
      <div style="font-size:24px;font-weight:800;color:#003082;"><?php echo esc_html((string) count($mijn_clienten)); ?></div>
      <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:2px;">Mijn cliënten</div>
    </div>
    <div style="background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
      <div style="font-weight:900;color:#003082;">Snelle links</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
        <?php if ($logboek_page): ?><a class="kb-btn kb-btn-primary kb-btn-sm" href="<?php echo esc_url(get_permalink($logboek_page)); ?>">Logboek</a><?php endif; ?>
        <?php if (!empty($docs_url)): ?><a class="kb-btn kb-btn-ghost kb-btn-sm" href="<?php echo esc_url($docs_url); ?>">Documenten</a><?php endif; ?>
        <?php if ($uitleg_page): ?><a class="kb-btn kb-btn-ghost kb-btn-sm" href="<?php echo esc_url(get_permalink($uitleg_page)); ?>">Uitleg</a><?php endif; ?>
      </div>
      <div style="font-size:12px;color:#64748b;margin-top:10px;">Tip: gebruik het admin menu (wp-admin) voor uitgebreid beheer.</div>
    </div>
  </div>

  <?php if ($begel_notice): ?>
  <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:12px 16px;color:#166534;font-size:13px;font-weight:600;margin-bottom:14px;"><?php echo esc_html($begel_notice); ?></div>
  <?php endif; ?>

  <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:18px;">
    <div style="font-weight:900;color:#003082;font-size:16px;margin-bottom:10px;">👥 Mijn cliënten</div>
    <?php if (empty($mijn_clienten)): ?>
      <div style="font-size:13px;color:#64748b;">Je hebt nog geen cliënten gekoppeld.</div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;">
        <?php foreach ($mijn_clienten as $c):
          $cid  = (int) $c->ID;
          $naw  = $client_naw[$cid] ?? [];
          $tel  = $naw['telefoon']      ?? '';
          $geb  = $naw['geboortedatum'] ?? '';
          $adr  = $naw['adres']         ?? '';
          $pc   = $naw['postcode']      ?? '';
          $wpl  = $naw['woonplaats']    ?? '';
          $logboek_url = $logboek_begel_page ? add_query_arg('client_id', $cid, get_permalink($logboek_begel_page)) : '';
          $docs_client_url = $docs_url ? add_query_arg('client_id', $cid, $docs_url) : '';
          $beroepen_client_url = $beroepen_url_base ? add_query_arg('client_id', $cid, $beroepen_url_base) : '';
          $aantal_beroepen = (int) ($selecties_per_client[$cid] ?? 0);
          $client_foto = $naw['foto'] ?? '';
        ?>
          <div style="border:1px solid #e2e8f0;border-radius:14px;padding:16px;background:white;box-shadow:0 1px 4px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;gap:10px;">
              <?php if ($client_foto): ?>
                <img src="<?php echo esc_url($client_foto); ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;flex-shrink:0;">
              <?php else: ?>
                <div style="width:36px;height:36px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">👤</div>
              <?php endif; ?>
              <div style="font-weight:800;font-size:14px;color:#1e293b;flex:1;"><?php echo esc_html($c->display_name); ?></div>
              <?php if ($aantal_beroepen > 0): ?>
                <div style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;"><?php echo (int) $aantal_beroepen; ?> beroepen</div>
              <?php endif; ?>
              <button type="button" class="kb-naw-toggle" data-target="kb-naw-<?php echo $cid; ?>" aria-expanded="false"
                style="background:none;border:1px solid #e2e8f0;border-radius:6px;padding:3px 10px;font-size:11px;color:#64748b;cursor:pointer;white-space:nowrap;">
                &#9660; NAW
              </button>
            </div>

            <div id="kb-naw-<?php echo $cid; ?>" style="display:none;margin-top:10px;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:12px;">
                <?php if (function_exists('bp_core_can_view_user_email') && bp_core_can_view_user_email($cid)): ?>
                <div><span style="color:#94a3b8;font-weight:700;text-transform:uppercase;font-size:10px;">E-mail</span><div style="color:#334155;"><?php echo esc_html($c->user_email); ?></div></div>
                <?php endif; ?>
                <?php if ($tel): ?><div><span style="color:#94a3b8;font-weight:700;text-transform:uppercase;font-size:10px;">Telefoon</span><div style="color:#334155;"><?php echo esc_html($tel); ?></div></div><?php endif; ?>
                <?php if ($geb): ?><div><span style="color:#94a3b8;font-weight:700;text-transform:uppercase;font-size:10px;">Geboortedatum</span><div style="color:#334155;"><?php echo esc_html(date('d-m-Y', strtotime($geb)) ?: $geb); ?></div></div><?php endif; ?>
                <?php if ($adr): ?><div style="grid-column:1/-1;"><span style="color:#94a3b8;font-weight:700;text-transform:uppercase;font-size:10px;">Adres</span><div style="color:#334155;"><?php echo esc_html($adr . ($pc || $wpl ? ', ' . trim($pc . ' ' . $wpl) : '')); ?></div></div><?php endif; ?>
              </div>
            </div>

            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;">
              <?php if ($logboek_url): ?>
                <a class="kb-btn kb-btn-primary kb-btn-sm" href="<?php echo esc_url($logboek_url); ?>">📝 Logboek</a>
              <?php endif; ?>
              <?php if ($beroepen_client_url): ?>
                <a class="kb-btn kb-btn-ghost kb-btn-sm" href="<?php echo esc_url($beroepen_client_url); ?>">⭐ Beroepen</a>
              <?php endif; ?>
              <?php if ($docs_client_url): ?>
                <a class="kb-btn kb-btn-ghost kb-btn-sm" href="<?php echo esc_url($docs_client_url); ?>">🗂 Documenten</a>
              <?php endif; ?>
            </div>

            <div style="margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9;">
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kb-overname-form" data-client-name="<?php echo esc_attr($c->display_name); ?>" style="display:inline;">
                <?php wp_nonce_field('bp_overname_verzoek_' . $cid, 'bp_overname_nonce'); ?>
                <input type="hidden" name="action" value="bp_overname_verzoek">
                <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
                <input type="hidden" name="bp_front_redirect" value="1">
                <input type="hidden" name="reden" value="">
                <button type="submit" class="kb-btn kb-btn-ghost kb-btn-sm kb-overname-popup-btn" style="font-size:11px;border-color:#fde68a;color:#92400e;">📋 Overname aanvragen</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-top:18px;">
    <div style="font-weight:900;color:#003082;font-size:16px;margin-bottom:10px;">🗂 Team cliënten</div>
    <div style="font-size:13px;color:#64748b;margin-bottom:12px;">Gegroepeerd per team. Alleen naam en overnameknop.</div>
    <?php if (empty($team_groups)): ?>
      <div style="font-size:13px;color:#64748b;">Geen teams gevonden.</div>
    <?php else: ?>
      <div style="display:grid;gap:12px;">
        <?php foreach ($team_groups as $tg): ?>
          <div style="border:1px solid #e2e8f0;border-radius:12px;padding:12px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;">
              <div style="font-weight:800;color:#1e3a8a;font-size:14px;"><?php echo esc_html((string) ($tg['team_name'] ?? 'Geen team')); ?></div>
              <?php if (!empty($tg['is_current_team'])): ?>
                <span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;">Jouw team</span>
              <?php endif; ?>
            </div>
            <div style="display:grid;gap:6px;">
              <?php foreach ((array) ($tg['clients'] ?? []) as $tc): ?>
                <?php $tcid = (int) ($tc->ID ?? 0); if ($tcid <= 0) continue; ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px;">
                  <div style="font-size:13px;color:#0f172a;font-weight:600;"><?php echo esc_html((string) ($tc->display_name ?? ('Client #' . $tcid))); ?></div>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kb-overname-form" data-client-name="<?php echo esc_attr((string) ($tc->display_name ?? 'Client')); ?>" style="margin:0;">
                    <?php wp_nonce_field('bp_overname_verzoek_' . $tcid, 'bp_overname_nonce'); ?>
                    <input type="hidden" name="action" value="bp_overname_verzoek">
                    <input type="hidden" name="client_id" value="<?php echo (int) $tcid; ?>">
                    <input type="hidden" name="bp_front_redirect" value="1">
                    <input type="hidden" name="reden" value="">
                    <button type="submit" class="kb-btn kb-btn-ghost kb-btn-sm kb-overname-popup-btn" style="font-size:11px;border-color:#fde68a;color:#92400e;">📋 Overname</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-top:18px;">
    <div style="font-weight:900;color:#003082;font-size:16px;margin-bottom:10px;">Berichten</div>
    <div style="font-size:13px;color:#64748b;margin-bottom:12px;">
      De oude inbox in het dashboard is uitgezet. Gebruik de losse Berichten add-on pagina.
    </div>
    <?php if (!empty($inbox_url)): ?>
      <a class="kb-btn kb-btn-primary kb-btn-sm" href="<?php echo esc_url($inbox_url); ?>">Open Berichten pagina</a>
    <?php endif; ?>
  </div>

  <?php endif; // einde: begeleider content ?>

  <?php if ($is_leidinggevende): ?>
  <?php
    // Leidinggevende dashboard: team overzicht
    $team_begeleiders = get_users([
      'role'    => BP_Core_Roles::ROLE_BEGELEIDER,
      'orderby' => 'display_name',
      'order'   => 'ASC',
      'number'  => 500,
    ]);
    $team_clienten = get_users([
      'role'    => BP_Core_Roles::ROLE_CLIENT,
      'orderby' => 'display_name',
      'order'   => 'ASC',
      'number'  => 5000,
    ]);

    // Clienten per begeleider: naam + ID
    $clienten_per_begeleider = [];
    foreach ($team_clienten as $cobj) {
      $cid = (int) $cobj->ID;
      if ($cid <= 0) continue;
      $bid = (int) get_user_meta($cid, 'kb_begeleider_id', true);
      if ($bid <= 0) continue;
      if (!isset($clienten_per_begeleider[$bid])) $clienten_per_begeleider[$bid] = [];
      $clienten_per_begeleider[$bid][] = $cobj->display_name;
    }
  ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px;">
    <div style="background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06);">
      <div style="font-size:26px;margin-bottom:6px;">🧑‍🏫</div>
      <div style="font-size:24px;font-weight:800;color:#003082;"><?php echo esc_html((string) count($team_begeleiders)); ?></div>
      <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:2px;">Begeleiders</div>
    </div>
    <div style="background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06);">
      <div style="font-size:26px;margin-bottom:6px;">👥</div>
      <div style="font-size:24px;font-weight:800;color:#003082;"><?php echo esc_html((string) count($team_clienten)); ?></div>
      <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:2px;">Totaal cliënten</div>
    </div>
  </div>

  <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:18px;">
    <div style="font-weight:900;color:#003082;font-size:16px;margin-bottom:10px;">👥 Team overzicht</div>
    <?php if (empty($team_begeleiders)): ?>
      <div style="font-size:13px;color:#64748b;">Er zijn nog geen begeleiders.</div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
        <?php foreach ($team_begeleiders as $b): ?>
          <?php $b_clienten = $clienten_per_begeleider[$b->ID] ?? []; ?>
          <div style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;background:white;">
            <div style="font-weight:800;font-size:13px;">🧑‍🏫 <?php echo esc_html($b->display_name); ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo esc_html($b->user_email); ?></div>
            <?php if (!empty($b_clienten)): ?>
            <div style="margin-top:8px;">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:4px;">Cliënten (<?php echo count($b_clienten); ?>)</div>
              <?php foreach ($b_clienten as $cnaam): ?>
              <div style="font-size:12px;color:#334155;padding:2px 0;border-bottom:1px solid #f8fafc;">👤 <?php echo esc_html($cnaam); ?></div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="font-size:12px;color:#94a3b8;margin-top:6px;">Geen cliënten gekoppeld</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; // einde: leidinggevende content ?>

  <?php if ($can_front_beheer): ?>
  <div style="background:white;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-top:18px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <div style="font-weight:900;color:#003082;font-size:16px;">🛠️ Admin beheer</div>
      <div style="margin-left:auto;font-size:12px;color:#64748b;">(Alleen zichtbaar voor leidinggevende/admin)</div>
    </div>

    <?php if (!empty($admin_notice)): ?>
      <div style="margin-top:12px;background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:10px 12px;border-radius:10px;font-weight:700;font-size:13px;">
        <?php echo esc_html($admin_notice); ?>
      </div>
    <?php endif; ?>

    <div class="bp-admin-block">

      <div class="bp-tabs" role="tablist" aria-label="Admin beheer tabs">
        <button type="button" class="bp-tab-button active" data-tab="gebruikers">Gebruikers</button>
        <?php if ($show_legacy_inbox): ?>
          <button type="button" class="bp-tab-button" data-tab="inbox" style="position:relative;">
            Inbox
            <?php if ($inbox_count > 0): ?>
              <span style="display:inline-flex;align-items:center;justify-content:center;background:#ef4444;color:#fff;border-radius:999px;font-size:10px;font-weight:800;min-width:16px;height:16px;padding:0 4px;margin-left:4px;vertical-align:middle;"><?php echo (int)$inbox_count; ?></span>
            <?php endif; ?>
          </button>
        <?php endif; ?>
        <button type="button" class="bp-tab-button" data-tab="overnames">Overnames</button>
        <?php if ($admin_only): ?>
        <button type="button" class="bp-tab-button" data-tab="rechten">Rollen & Rechten</button>
        <button type="button" class="bp-tab-button" data-tab="usercaps">Rechten per gebruiker</button>
        <?php endif; ?>
        <button type="button" class="bp-tab-button" data-tab="addons">Add-ons</button>
        <button type="button" class="bp-tab-button" data-tab="log">Logboek</button>
      </div>

      <!-- TAB: Gebruikers -->
      <div class="bp-tab-content active" id="bp-tab-gebruikers">
        <div class="bp-admin-card">
          <div style="font-weight:900;margin-bottom:10px;">Gebruikers</div>
          <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Nieuwe gebruiker aanmaken.</div>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:14px;">
            <?php wp_nonce_field('bp_core_create_user'); ?>
            <input type="hidden" name="action" value="bp_core_create_user">
            <input type="hidden" name="bp_front_redirect" value="1">

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Naam</div>
              <input type="text" name="bp_name" required />
            </div>

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">E-mail</div>
              <input type="email" name="bp_email" required />
            </div>

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Rol</div>
              <select name="bp_role">
                <option value="<?php echo esc_attr(BP_Core_Roles::ROLE_CLIENT); ?>">Cliënt</option>
                <option value="<?php echo esc_attr(BP_Core_Roles::ROLE_BEGELEIDER); ?>">Begeleider</option>
                <option value="<?php echo esc_attr(BP_Core_Roles::ROLE_LEIDINGGEVENDE); ?>">Leidinggevende</option>
              </select>
            </div>

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Wachtwoord</div>
              <input type="text" name="bp_pass" placeholder="(leeg = automatisch)" />
            </div>

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Begeleider (voor cliënt)</div>
              <select name="bp_begeleider_id">
                <option value="0">— Geen —</option>
                <?php foreach ($admin_begeleiders as $b): ?>
	                  <option value="<?php echo (int)$b->ID; ?>"><?php echo esc_html(function_exists('bp_core_user_label') ? bp_core_user_label($b) : (string)$b->display_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Leidinggevende (optioneel)</div>
              <select name="bp_leidinggevende_id">
                <option value="0">— Geen —</option>
                <?php foreach ($admin_leidinggevenden_all as $l): ?>
	                  <option value="<?php echo (int)$l->ID; ?>"><?php echo esc_html(function_exists('bp_core_user_label') ? bp_core_user_label($l) : (string)$l->display_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="margin-top:10px;">
              <button class="kb-btn kb-btn-primary" type="submit">Gebruiker aanmaken</button>
            </div>
          </form>

          <div style="margin-top:10px;font-size:12px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=bp-core-users')); ?>" style="color:#003082;font-weight:800;text-decoration:none;">Open volledige gebruikerslijst in wp-admin →</a>
          </div>
        </div>

        <!-- Koppel cliënt aan andere begeleider -->
        <div class="bp-admin-card" style="margin-top:14px;">
          <div style="font-weight:900;margin-bottom:6px;">Cliënt koppelen aan begeleider</div>
          <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Wijs een cliënt handmatig toe aan een (andere) begeleider.</div>

          <?php if (!empty($_GET['bp_gekoppeld'])): ?>
            <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:10px;">✅ Cliënt succesvol gekoppeld.</div>
          <?php endif; ?>
          <?php if (!empty($_GET['bp_koppel_fout'])): ?>
            <div style="background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:10px;">⚠️ Kon niet koppelen. Controleer je keuze.</div>
          <?php endif; ?>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:14px;">
            <?php wp_nonce_field('bp_koppel_client_begeleider', 'bp_koppel_nonce'); ?>
            <input type="hidden" name="action" value="bp_koppel_client_begeleider">
            <input type="hidden" name="bp_front_redirect" value="1">

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Cliënt</div>
              <select name="bp_koppel_client_id" required>
                <option value="">— Kies cliënt —</option>
                <?php foreach ($admin_clients as $cl): ?>
                  <?php
                    $cl_huid_bid = (int) get_user_meta($cl->ID, 'kb_begeleider_id', true);
                    $cl_huid_begel = $cl_huid_bid > 0 ? get_user_by('id', $cl_huid_bid) : null;
                    $huid_label = $cl_huid_begel ? ' (nu: ' . $cl_huid_begel->display_name . ')' : ' (geen begeleider)';
                  ?>
                  <option value="<?php echo (int)$cl->ID; ?>"><?php echo esc_html($cl->display_name . $huid_label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Nieuwe begeleider</div>
              <select name="bp_nieuwe_begel_id" required>
                <option value="">— Kies begeleider —</option>
                <?php foreach ($admin_begeleiders as $b): ?>
                  <option value="<?php echo (int)$b->ID; ?>"><?php echo esc_html(function_exists('bp_core_user_label') ? bp_core_user_label($b) : (string)$b->display_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="margin-top:10px;">
              <button class="kb-btn kb-btn-primary" type="submit">Koppelen</button>
            </div>
          </form>
        </div>

        <!-- Koppel begeleider aan andere leidinggevende -->
        <div class="bp-admin-card" style="margin-top:14px;">
          <div style="font-weight:900;margin-bottom:6px;">Begeleider overzetten naar leidinggevende</div>
          <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Wijs een begeleider toe aan een andere leidinggevende.</div>

          <?php if (!empty($_GET['bp_begel_leid_gekoppeld'])): ?>
            <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:10px;">✅ Begeleider succesvol overgezet.</div>
          <?php endif; ?>
          <?php if (!empty($_GET['bp_begel_leid_fout'])): ?>
            <div style="background:#fee2e2;border:1px solid #fca5a5;color:#b91c1c;padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:10px;">⚠️ Kon begeleider niet overzetten. Controleer je keuze.</div>
          <?php endif; ?>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:14px;">
            <?php wp_nonce_field('bp_koppel_begeleider_leidinggevende', 'bp_koppel_begel_leid_nonce'); ?>
            <input type="hidden" name="action" value="bp_koppel_begeleider_leidinggevende">
            <input type="hidden" name="bp_front_redirect" value="1">

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Begeleider</div>
              <select name="bp_koppel_begeleider_id" required>
                <option value="">— Kies begeleider —</option>
                <?php foreach ($admin_begeleiders as $b): ?>
                  <?php
                    $huid_leid_id = (int) get_user_meta($b->ID, 'kb_leidinggevende_id', true);
                    $huid_leid = $huid_leid_id > 0 ? get_user_by('id', $huid_leid_id) : null;
                    $huid_leid_label = $huid_leid ? ' (nu: ' . $huid_leid->display_name . ')' : ' (geen leidinggevende)';
                  ?>
                  <option value="<?php echo (int)$b->ID; ?>"><?php echo esc_html((function_exists('bp_core_user_label') ? bp_core_user_label($b) : (string)$b->display_name) . $huid_leid_label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="bp-form-row">
              <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Nieuwe leidinggevende</div>
              <select name="bp_nieuwe_leid_id" required>
                <option value="">— Kies leidinggevende —</option>
                <?php foreach ($admin_leidinggevenden_all as $l): ?>
                  <option value="<?php echo (int)$l->ID; ?>"><?php echo esc_html(function_exists('bp_core_user_label') ? bp_core_user_label($l) : (string)$l->display_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="margin-top:10px;">
              <button class="kb-btn kb-btn-primary" type="submit">Overzetten</button>
            </div>
          </form>
        </div>
      </div>

      <!-- TAB: Overnames -->
      <div class="bp-tab-content" id="bp-tab-overnames">
        <div class="bp-admin-card">
          <div style="font-weight:900;margin-bottom:10px;">Overnames</div>
          <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Cliënten overzetten of bulk leidinggevende.</div>

          <div class="bp-bulk-grid">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:14px;">
              <?php wp_nonce_field('bp_core_bulk_transfer_leidinggevende'); ?>
              <input type="hidden" name="action" value="bp_core_bulk_transfer_leidinggevende">
              <input type="hidden" name="bp_front_redirect" value="1">
              <div style="font-weight:900;margin-bottom:8px;">Bulk leidinggevende</div>
              <div class="bp-form-row">
                <select name="bp_from_leid" required>
                  <option value="">Van…</option>
                  <?php foreach ($admin_leidinggevenden_all as $l): ?>
                    <option value="<?php echo (int)$l->ID; ?>"><?php echo esc_html($l->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="bp-form-row">
                <select name="bp_to_leid" required>
                  <option value="">Naar…</option>
                  <?php foreach ($admin_leidinggevenden_all as $l): ?>
                    <option value="<?php echo (int)$l->ID; ?>"><?php echo esc_html($l->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="kb-btn kb-btn-ghost kb-btn-sm" type="submit">Overdragen</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px;">
              <?php wp_nonce_field('bp_core_transfer_clients_begeleider'); ?>
              <input type="hidden" name="action" value="bp_core_transfer_clients_begeleider">
              <input type="hidden" name="bp_front_redirect" value="1">
              <div style="font-weight:900;margin-bottom:8px;">Cliënten overzetten</div>
              <div class="bp-form-row">
                <select name="bp_from_begel" required>
                  <option value="">Van…</option>
                  <?php foreach ($admin_begeleiders as $b): ?>
                    <option value="<?php echo (int)$b->ID; ?>"><?php echo esc_html($b->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="bp-form-row">
                <select name="bp_to_begel" required>
                  <option value="">Naar…</option>
                  <?php foreach ($admin_begeleiders as $b): ?>
                    <option value="<?php echo (int)$b->ID; ?>"><?php echo esc_html($b->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="kb-btn kb-btn-ghost kb-btn-sm" type="submit">Overzetten</button>
            </form>
          </div>
        </div>
      </div>

      <?php if ($admin_only): ?>
      <!-- TAB: Rollen & Rechten -->
      <div class="bp-tab-content" id="bp-tab-rechten">
        <div class="bp-admin-card">
          <div style="font-weight:900;margin-bottom:10px;">Rollen & rechten</div>
          <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Hier kun je vinkjes aanpassen. Admin heeft altijd alles.</div>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
            <?php wp_nonce_field('bp_core_repair_roles'); ?>
            <input type="hidden" name="action" value="bp_core_repair_roles">
            <input type="hidden" name="bp_front_redirect" value="1">
            <input type="hidden" name="bp_tab" value="<?php echo esc_attr(sanitize_key($_GET['bp_tab'] ?? 'rechten')); ?>">
            <button class="kb-btn kb-btn-ghost kb-btn-sm" type="submit">Reset naar standaard</button>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="overflow:auto;">
            <?php wp_nonce_field('bp_core_save_role_caps'); ?>
            <input type="hidden" name="action" value="bp_core_save_role_caps">
            <input type="hidden" name="bp_front_redirect" value="1">
            <input type="hidden" name="bp_tab" value="<?php echo esc_attr(sanitize_key($_GET['bp_tab'] ?? 'rechten')); ?>">

            <table style="border-collapse:collapse;width:100%;min-width:520px;font-size:12px;">
              <thead>
                <tr>
                  <th style="text-align:left;padding:6px;border-bottom:1px solid #e5e7eb;">Rol</th>
                  <?php foreach ($admin_caps as $cap_key => $cap_label): ?>
                    <th style="text-align:center;padding:6px;border-bottom:1px solid #e5e7eb;white-space:nowrap;"><?php echo esc_html($cap_label); ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($admin_roles as $role_key => $role_label):
                  $r = get_role($role_key);
                ?>
                  <tr>
                    <td style="padding:6px;border-bottom:1px solid #f1f5f9;">
                      <strong><?php echo esc_html($role_label); ?></strong><br>
                      <span style="color:#64748b;"><code><?php echo esc_html($role_key); ?></code></span>
                    </td>
                    <?php foreach ($admin_caps as $cap_key => $cap_label):
                      $checked = ($r && $r->has_cap($cap_key));
                    ?>
                      <td style="text-align:center;padding:6px;border-bottom:1px solid #f1f5f9;">
                        <input type="checkbox" name="caps[<?php echo esc_attr($role_key); ?>][<?php echo esc_attr($cap_key); ?>]" value="1" <?php echo $checked ? 'checked' : ''; ?> <?php echo !$r ? 'disabled' : ''; ?> />
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <div style="margin-top:10px;display:flex;gap:10px;align-items:center;">
              <button class="kb-btn kb-btn-primary" type="submit">Opslaan</button>
              <span style="font-size:12px;color:#64748b;">Tip: <code>read</code> blijft altijd aan.</span>
            </div>
          </form>
        </div>
      </div>

      <!-- TAB: Rechten per gebruiker -->
      <div class="bp-tab-content" id="bp-tab-usercaps">
        <div class="bp-admin-card">
          <div style="font-weight:900;margin-bottom:10px;">Rechten per gebruiker</div>
          <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Kies een gebruiker en stel rol + extra rechten in. Admin heeft altijd alles.</div>

          <?php
            $caps_labels = $admin_caps; // zelfde labels als boven
            $selected_caps_user = $admin_caps_user ? get_user_by('id', $admin_caps_user) : null;
          ?>

          <form method="get" action="" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
            <input type="hidden" name="bp_tab" value="usercaps" />
            <select name="bp_caps_user" style="min-width:280px;max-width:100%;">
              <?php foreach ($admin_all_users as $u): ?>
                <option value="<?php echo (int)$u->ID; ?>" <?php selected((int)$admin_caps_user, (int)$u->ID); ?>>
	                  <?php echo esc_html(function_exists('bp_core_user_label') ? bp_core_user_label($u) : (string)$u->display_name); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="kb-btn kb-btn-ghost kb-btn-sm" type="submit">Open</button>
            <?php if (!empty($_GET['bp_saved'])): ?>
              <span style="font-size:12px;font-weight:800;color:#166534;">✅ Opgeslagen</span>
            <?php endif; ?>
          </form>

          <?php if (!$selected_caps_user): ?>
            <div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:10px 12px;border-radius:10px;font-weight:700;font-size:13px;">Kies hierboven een gebruiker.</div>
          <?php else:
            $wp_user = new WP_User($selected_caps_user->ID);
            $current_role = !empty($wp_user->roles[0]) ? $wp_user->roles[0] : '';
            $overrides = function_exists('bp_user_get_caps_overrides') ? bp_user_get_caps_overrides((int)$selected_caps_user->ID) : [];
          ?>

            <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Gebruiker: <strong style="color:#0f172a;"><?php echo esc_html($selected_caps_user->display_name); ?></strong></div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="overflow:auto;">
              <input type="hidden" name="action" value="bp_core_save_user_caps" />
              <?php wp_nonce_field('bp_core_save_user_caps'); ?>
              <input type="hidden" name="user_id" value="<?php echo (int)$selected_caps_user->ID; ?>" />
              <input type="hidden" name="bp_front_redirect" value="1" />
              <input type="hidden" name="bp_tab" value="usercaps" />

              <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:12px;">
                <div style="flex:1 1 260px;min-width:260px;">
                  <div style="font-size:12px;font-weight:800;margin-bottom:6px;">Rol</div>
                  <select name="user_role" style="width:100%;max-width:360px;">
                    <option value="<?php echo esc_attr(BP_Core_Roles::ROLE_LEIDINGGEVENDE); ?>" <?php selected($current_role, BP_Core_Roles::ROLE_LEIDINGGEVENDE); ?>>Leidinggevende</option>
                    <option value="<?php echo esc_attr(BP_Core_Roles::ROLE_BEGELEIDER); ?>" <?php selected($current_role, BP_Core_Roles::ROLE_BEGELEIDER); ?>>Begeleider</option>
                    <option value="<?php echo esc_attr(BP_Core_Roles::ROLE_CLIENT); ?>" <?php selected($current_role, BP_Core_Roles::ROLE_CLIENT); ?>>Cliënt</option>
                  </select>
                  <div style="font-size:12px;color:#64748b;margin-top:6px;">Rol is de basis. Hieronder kun je per recht: overnemen, aan of uit kiezen.</div>
                </div>
              </div>

              <table style="border-collapse:collapse;width:100%;min-width:520px;font-size:12px;">
                <thead>
                  <tr>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #e5e7eb;">Recht</th>
                    <th style="text-align:left;padding:6px;border-bottom:1px solid #e5e7eb;">Instelling</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($caps_labels as $cap_key => $cap_label):
                  $val = isset($overrides[$cap_key]) ? $overrides[$cap_key] : 'inherit';
                  if ($val !== 'allow' && $val !== 'deny') $val = 'inherit';
                ?>
                  <tr>
                    <td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;">
                      <strong><?php echo esc_html($cap_label); ?></strong><br>
                      <span style="color:#64748b;"><code><?php echo esc_html($cap_key); ?></code></span>
                    </td>
                    <td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;">
                      <select name="user_caps[<?php echo esc_attr($cap_key); ?>]">
                        <option value="inherit" <?php selected($val, 'inherit'); ?>>Overnemen</option>
                        <option value="allow" <?php selected($val, 'allow'); ?>>Aan</option>
                        <option value="deny" <?php selected($val, 'deny'); ?>>Uit</option>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>

              <div style="margin-top:10px;display:flex;gap:10px;align-items:center;">
                <button class="kb-btn kb-btn-primary" type="submit">Opslaan</button>
                <span style="font-size:12px;color:#64748b;">Tip: admin heeft altijd alles, ook als je hier “Uit” kiest.</span>
              </div>
            </form>

          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- TAB: Add-ons per gebruiker -->
      <div class="bp-tab-content" id="bp-tab-addons">
        <div class="bp-admin-card">
          <div style="font-weight:900;margin-bottom:10px;">Add-ons per gebruiker</div>
          <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Hier zet je per gebruiker een add-on aan of uit. "Volg rol" betekent: gebruik de standaard rolrechten.</div>

          <?php if (empty($admin_addons)): ?>
            <div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:10px 12px;border-radius:10px;font-weight:700;font-size:13px;">
              Er zijn nog geen add-ons geregistreerd.
            </div>
          <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:14px;">
              <?php wp_nonce_field('bp_core_save_user_addon_access'); ?>
              <input type="hidden" name="action" value="bp_core_save_user_addon_access">
              <input type="hidden" name="bp_front_redirect" value="1">
              <input type="hidden" name="bp_tab" value="<?php echo esc_attr(sanitize_key($_GET['bp_tab'] ?? 'addons')); ?>">

              <div class="bp-form-row">
                <div style="font-size:12px;font-weight:800;margin-bottom:4px;">Gebruiker</div>
                <select name="bp_target_user" onchange="window.location = '<?php echo esc_url(remove_query_arg(['bp_addon_access_saved','bp_addon_access_error'])); ?>' + (this.value ? ('&bp_target_user=' + this.value) : '');">
                  <option value="0">— Kies —</option>
                  <?php foreach ($admin_all_users as $u): ?>
						<option value="<?php echo (int)$u->ID; ?>" <?php selected($admin_target_user, (int)$u->ID); ?>><?php echo esc_html(function_exists('bp_core_user_label') ? bp_core_user_label($u) : (string)$u->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <?php if ($admin_target_user > 0): ?>
                <table style="border-collapse:collapse;width:100%;min-width:520px;font-size:12px;">
                  <thead>
                    <tr>
                      <th style="text-align:left;padding:6px;border-bottom:1px solid #e5e7eb;">Add-on</th>
                      <th style="text-align:left;padding:6px;border-bottom:1px solid #e5e7eb;">Toegang</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($admin_addons as $slug => $info):
                      $slug = sanitize_key((string)$slug);
                      if ($slug === '') continue;
                      $label = is_array($info) && !empty($info['label']) ? (string)$info['label'] : $slug;
                      $cur = $admin_user_addon_access[$slug] ?? 'inherit';
                    ?>
                      <tr>
                        <td style="padding:6px;border-bottom:1px solid #f1f5f9;">
                          <strong><?php echo esc_html($label); ?></strong><br>
                          <span style="color:#64748b;"><code><?php echo esc_html($slug); ?></code></span>
                        </td>
                        <td style="padding:6px;border-bottom:1px solid #f1f5f9;">
                          <select name="bp_addon_access[<?php echo esc_attr($slug); ?>]">
                            <option value="inherit" <?php selected($cur, 'inherit'); ?>>Volg rol</option>
                            <option value="allow" <?php selected($cur, 'allow'); ?>>Aan</option>
                            <option value="deny" <?php selected($cur, 'deny'); ?>>Uit</option>
                          </select>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>

                <div style="margin-top:10px;display:flex;gap:10px;align-items:center;">
                  <button class="kb-btn kb-btn-primary" type="submit">Opslaan</button>
                  <span style="font-size:12px;color:#64748b;">Tip: Admin heeft altijd alles, ook als je hier "Uit" kiest.</span>
                </div>
              <?php else: ?>
                <div style="font-size:12px;color:#64748b;">Kies eerst een gebruiker.</div>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- TAB: Inbox (overname verzoeken + berichten) -->
      <?php if ($show_legacy_inbox): ?>
      <div class="bp-tab-content" id="bp-tab-inbox">
        <div class="bp-admin-card">
          <div style="font-weight:900;margin-bottom:10px;">Inbox</div>
          <div style="font-size:12px;color:#64748b;margin-bottom:14px;">Overnameverzoeken en berichten van begeleiders.</div>

          <?php
          $leid_cat_labels = ['' => '— Categorie —', 'werk' => 'Werk', 'persoonlijk' => 'Persoonlijk', 'overname' => 'Overname', 'urgent' => 'Urgent'];
          // Overnameverzoeken
          if (!empty($leid_inbox)):
            foreach ($leid_inbox as $bericht):
              $lbid       = (int)$bericht->id;
              $van_user   = get_user_by('id', (int)$bericht->van_id);
              $van_naam   = $van_user ? $van_user->display_name : 'Onbekend';
              $cl_user    = $bericht->client_id ? get_user_by('id', (int)$bericht->client_id) : null;
              $cl_naam    = $cl_user ? $cl_user->display_name : '—';
              $is_gelezen = (int)$bericht->gelezen === 1;
              $status     = $bericht->status ?? 'pending';
              $card_bg    = $is_gelezen ? '#fff' : '#eff6ff';
              $card_border = $is_gelezen ? '#e2e8f0' : '#bfdbfe';
              $lbcat      = $bericht->categorie ?? '';
              $llang      = mb_strlen($bericht->inhoud) > 200;
          ?>
            <div style="background:<?= esc_attr($card_bg) ?>;border:1px solid <?= esc_attr($card_border) ?>;border-radius:12px;padding:16px;margin-bottom:12px;">
              <?php if (!$is_gelezen): ?>
                <span style="background:#ef4444;color:#fff;border-radius:4px;font-size:9px;font-weight:800;padding:1px 6px;text-transform:uppercase;margin-bottom:4px;display:inline-block;">Nieuw</span>
              <?php endif; ?>
              <?php if ($lbcat && isset($leid_cat_labels[$lbcat])): ?><span style="background:#e0f2fe;color:#0369a1;border-radius:4px;font-size:9px;font-weight:800;padding:1px 5px;margin-bottom:4px;display:inline-block;"><?= esc_html($leid_cat_labels[$lbcat]) ?></span><?php endif; ?>
              <div style="font-weight:800;font-size:13px;color:#1e293b;margin-bottom:2px;"><?= esc_html($bericht->onderwerp) ?></div>
              <div style="font-size:11px;color:#64748b;margin-bottom:8px;">
                Van: <strong><?= esc_html($van_naam) ?></strong>
                &nbsp;|&nbsp; Cliënt: <strong><?= esc_html($cl_naam) ?></strong>
                &nbsp;|&nbsp; <?= esc_html(date('d-m-Y H:i', strtotime($bericht->aangemaakt))) ?>
              </div>
              <div class="bp-msg-preview-<?= $lbid ?>" style="font-size:13px;color:#334155;background:#f8fafc;border-radius:8px;padding:10px;margin-bottom:10px;white-space:pre-wrap;"><?= esc_html(mb_substr($bericht->inhoud, 0, 200)) ?><?= $llang ? '…' : '' ?></div>
              <?php if ($llang): ?>
              <div class="bp-msg-full-<?= $lbid ?>" style="display:none;font-size:13px;color:#334155;background:#f8fafc;border-radius:8px;padding:10px;margin-bottom:10px;white-space:pre-wrap;"><?= esc_html($bericht->inhoud) ?></div>
              <button type="button" class="bp-msg-toggle" data-id="<?= $lbid ?>" style="font-size:11px;color:#003082;background:none;border:none;padding:2px 0;cursor:pointer;font-weight:700;margin-bottom:8px;">▼ Toon alles</button>
              <?php endif; ?>

              <?php if ($status === 'pending'): ?>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-bottom:10px;">
                  <?php wp_nonce_field('bp_overname_reageren_' . $lbid, 'bp_overname_reactie_nonce'); ?>
                  <input type="hidden" name="action" value="bp_overname_reageren">
                  <input type="hidden" name="bericht_id" value="<?= $lbid ?>">
                  <div style="margin-bottom:8px;">
                    <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">Toelichting (optioneel)</label>
                    <textarea name="reactie" rows="2" style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:8px;font-size:13px;box-sizing:border-box;resize:vertical;" placeholder="Voeg een toelichting toe..."></textarea>
                  </div>
                  <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" name="status" value="goedgekeurd" style="background:#16a34a;color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:13px;font-weight:700;cursor:pointer;">Goedkeuren</button>
                    <button type="submit" name="status" value="afgewezen" style="background:#dc2626;color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:13px;font-weight:700;cursor:pointer;">Afwijzen</button>
                  </div>
                </form>
              <?php else: ?>
                <div style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;background:<?= $status === 'goedgekeurd' ? '#dcfce7' : '#fee2e2' ?>;color:<?= $status === 'goedgekeurd' ? '#166534' : '#b91c1c' ?>;margin-bottom:10px;">
                  <?= $status === 'goedgekeurd' ? 'Goedgekeurd' : 'Afgewezen' ?>
                </div>
              <?php endif; ?>

              <!-- Acties: reply / verwijder / categoriseer -->
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;border-top:1px solid #f1f5f9;padding-top:8px;">
                <button type="button" class="bp-reply-toggle" data-id="<?= $lbid ?>" style="font-size:11px;font-weight:700;color:#003082;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:4px 10px;cursor:pointer;">↩ Reageer</button>

                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;">
                  <?php wp_nonce_field('bp_verwijder_bericht_' . $lbid, 'bp_verwijder_nonce'); ?>
                  <input type="hidden" name="action" value="bp_verwijder_bericht">
                  <input type="hidden" name="bericht_id" value="<?= $lbid ?>">
                  <button type="submit" style="font-size:11px;font-weight:700;color:#b91c1c;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:4px 10px;cursor:pointer;" onclick="return confirm('Bericht verwijderen?');">🗑 Verwijder</button>
                </form>

                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;margin-left:auto;">
                  <?php wp_nonce_field('bp_categoriseer_bericht_' . $lbid, 'bp_categoriseer_nonce'); ?>
                  <input type="hidden" name="action" value="bp_categoriseer_bericht">
                  <input type="hidden" name="bericht_id" value="<?= $lbid ?>">
                  <select name="categorie" onchange="this.form.submit()" style="font-size:11px;border:1px solid #cbd5e1;border-radius:6px;padding:3px 6px;color:#475569;">
                    <?php foreach ($leid_cat_labels as $cval => $clabel): ?>
                    <option value="<?= esc_attr($cval) ?>" <?= $lbcat === $cval ? 'selected' : '' ?>><?= esc_html($clabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </div>

              <!-- Inline reply form -->
              <div class="bp-reply-form-<?= $lbid ?>" style="display:none;margin-top:10px;border-top:1px solid #e2e8f0;padding-top:10px;">
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                  <?php wp_nonce_field('bp_stuur_bericht', 'bp_bericht_nonce'); ?>
                  <input type="hidden" name="action" value="bp_stuur_bericht">
                  <input type="hidden" name="naar_id" value="<?= (int)$bericht->van_id ?>">
                  <input type="text" name="onderwerp" value="Re: <?= esc_attr($bericht->onderwerp) ?>" maxlength="255" required style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;font-size:12px;margin-bottom:6px;box-sizing:border-box;">
                  <textarea name="inhoud" rows="3" maxlength="5000" required placeholder="Jouw reactie…" style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;font-size:12px;box-sizing:border-box;resize:vertical;margin-bottom:6px;"></textarea>
                  <button type="submit" style="background:#003082;color:#fff;border:none;border-radius:8px;padding:7px 18px;font-size:12px;font-weight:700;cursor:pointer;">Versturen</button>
                  <button type="button" class="bp-reply-toggle" data-id="<?= $lbid ?>" style="background:none;border:1px solid #e2e8f0;border-radius:8px;padding:7px 14px;font-size:12px;color:#64748b;cursor:pointer;margin-left:6px;">Annuleren</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          <?php else: ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;text-align:center;font-size:13px;color:#64748b;">Geen berichten in de inbox.</div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- TAB: Logboek -->
      <div class="bp-tab-content" id="bp-tab-log">
        <div class="bp-admin-card">
          <div style="font-weight:900;margin-bottom:10px;">Logboek (wijzigingen)</div>
          <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Laatste 20 wijzigingen.</div>

          <div style="overflow:auto;">
            <table style="border-collapse:collapse;width:100%;min-width:900px;font-size:12px;background:white;border:1px solid #e2e8f0;border-radius:12px;">
              <thead>
                <tr>
                  <th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0;">Datum</th>
                  <th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0;">Wie</th>
                  <th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0;">Wat</th>
                  <th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0;">Actie</th>
                  <th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0;">Oud</th>
                  <th style="text-align:left;padding:8px;border-bottom:1px solid #e2e8f0;">Nieuw</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($audit_entries)): ?>
                  <tr><td colspan="6" style="padding:10px;">Nog geen logregels.</td></tr>
                <?php else: foreach ($audit_entries as $e):
                  $actor = $e->actor_id ? get_user_by('id', (int)$e->actor_id) : null;
                  $who = $actor ? ($actor->display_name) : '—';
                  $old = $e->oud ? wp_strip_all_tags((string)$e->oud) : '';
                  $new = $e->nieuw ? wp_strip_all_tags((string)$e->nieuw) : '';
                  if (mb_strlen($old) > 120) $old = mb_substr($old, 0, 120) . '…';
                  if (mb_strlen($new) > 120) $new = mb_substr($new, 0, 120) . '…';
                ?>
                  <tr>
                    <td style="padding:8px;border-bottom:1px solid #f1f5f9;white-space:nowrap;"><?php echo esc_html(date('d-m-Y H:i', strtotime($e->aangemaakt))); ?></td>
                    <td style="padding:8px;border-bottom:1px solid #f1f5f9;"><?php echo esc_html($who); ?></td>
                    <td style="padding:8px;border-bottom:1px solid #f1f5f9;"><code><?php echo esc_html($e->object_type); ?></code><?php echo $e->object_id ? ' #' . esc_html((string)$e->object_id) : ''; ?></td>
                    <td style="padding:8px;border-bottom:1px solid #f1f5f9;"><code><?php echo esc_html($e->actie); ?></code></td>
                    <td style="padding:8px;border-bottom:1px solid #f1f5f9;color:#334155;"><?php echo esc_html($old ?: '—'); ?></td>
                    <td style="padding:8px;border-bottom:1px solid #f1f5f9;color:#334155;"><?php echo esc_html($new ?: '—'); ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <div style="margin-top:10px;font-size:12px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=bp-core-audit')); ?>" style="color:#003082;font-weight:800;text-decoration:none;">Open volledige lijst in wp-admin →</a>
          </div>
        </div>
      </div>

    </div>
  </div>
  <?php endif; ?>

</div>

<style>
#kb-dashboard-root .kb-hero {
  background: linear-gradient(135deg, #003082 0%, #1d4ed8 100%);
  color: white;
  border-radius: 16px;
  padding: 28px 32px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  gap: 20px;
  flex-wrap: wrap;
}
#kb-dashboard-root .kb-hero-title {
  font-size: clamp(22px, 3vw, 30px);
  font-weight: 800;
  margin: 6px 0 4px;
}
#kb-dashboard-root .kb-hero-sub {
  font-size: 14px;
  opacity: .75;
}
@media (max-width: 640px) {
  #kb-dashboard-root > div[style*="grid-template-columns:1fr 1fr"] {
    grid-template-columns: 1fr !important;
  }
}
</style>
<script>
(function(){
  document.addEventListener('submit', function(ev){
    const form = ev.target && ev.target.closest ? ev.target.closest('.kb-overname-form') : null;
    if (!form) return;
    const clientName = String(form.getAttribute('data-client-name') || 'deze client');
    const redenInput = form.querySelector('input[name="reden"]');
    const reden = window.prompt('Reden voor overname (optioneel) voor "' + clientName + '"', '');
    if (reden === null) {
      ev.preventDefault();
      return;
    }
    if (redenInput) redenInput.value = String(reden || '').trim();
  });
})();
</script>

<script>
(function(){
  // Inklapbare NAW client-cards
  document.querySelectorAll('.kb-naw-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var targetId = btn.getAttribute('data-target');
      var el = document.getElementById(targetId);
      if (!el) return;
      var open = el.style.display !== 'none';
      el.style.display = open ? 'none' : 'block';
      btn.setAttribute('aria-expanded', !open);
      btn.innerHTML = open ? '&#9660; NAW' : '&#9650; NAW';
    });
  });

  // Berichten: toon volledige tekst toggle
  document.querySelectorAll('.bp-msg-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.getAttribute('data-id');
      var preview = document.querySelector('.bp-msg-preview-' + id);
      var full    = document.querySelector('.bp-msg-full-' + id);
      if (!full) return;
      var open = full.style.display !== 'none';
      if (preview) preview.style.display = open ? '' : 'none';
      full.style.display = open ? 'none' : '';
      btn.textContent = open ? '\u25BC Toon alles' : '\u25B2 Verberg';
    });
  });

  // Berichten: inline reply form toggle
  document.querySelectorAll('.bp-reply-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.getAttribute('data-id');
      var form = document.querySelector('.bp-reply-form-' + id);
      if (!form) return;
      form.style.display = form.style.display === 'none' ? '' : 'none';
    });
  });
})();
</script>
