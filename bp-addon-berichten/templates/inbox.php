<?php
defined('ABSPATH') || exit;

$user = wp_get_current_user();
$user_id = (int) $user->ID;

if (!$user_id || !class_exists('BP_Core_Berichten')) {
    echo '<div class="bp-notice">Inbox is niet beschikbaar.</div>';
    return;
}

$incoming = BP_Core_Berichten::haal_inbox($user_id, '', 200);
$outgoing = BP_Core_Berichten::haal_verzonden($user_id, 200);

$all_messages = array_merge(is_array($incoming) ? $incoming : [], is_array($outgoing) ? $outgoing : []);

usort($all_messages, static function($a, $b) {
    $a_time = strtotime((string) ($a->aangemaakt ?? '')) ?: 0;
    $b_time = strtotime((string) ($b->aangemaakt ?? '')) ?: 0;
    return $b_time <=> $a_time;
});

$threads = [];
$allowed_recipients = [];
$candidates = [];
$is_admin = current_user_can('manage_options');
$is_leidinggevende = class_exists('BP_Core_Roles') && BP_Core_Roles::is_leidinggevende($user);
$manual_contacts = BP_Core_Berichten::get_contacts($user_id);
$my_contact_code = BP_Core_Berichten::get_or_create_contact_code($user_id);
$rotation_days = (int) get_user_meta($user_id, 'bp_msg_e2e_rotation_days', true);
if ($rotation_days <= 0) $rotation_days = 90;
if ($rotation_days < 7) $rotation_days = 7;
if ($rotation_days > 365) $rotation_days = 365;

$client_begeleider_id = (int) get_user_meta($user_id, 'kb_begeleider_id', true);
if ($client_begeleider_id > 0) {
    $candidates[$client_begeleider_id] = $client_begeleider_id;
}

$my_leidinggevende_id = (int) get_user_meta($user_id, 'kb_leidinggevende_id', true);
if ($my_leidinggevende_id > 0) {
    $candidates[$my_leidinggevende_id] = $my_leidinggevende_id;
}

if (class_exists('BP_Core_Roles') && BP_Core_Roles::is_begeleider($user)) {
    $client_ids = get_users([
        'role'       => BP_Core_Roles::ROLE_CLIENT,
        'meta_key'   => 'kb_begeleider_id',
        'meta_value' => (string) $user_id,
        'number'     => 500,
        'fields'     => 'ID',
    ]);
    if (is_array($client_ids)) {
        foreach ($client_ids as $cid) {
            $cid = (int) $cid;
            if ($cid > 0) $candidates[$cid] = $cid;
        }
    }
}

// Voor admin/leidinggevende geen volledige gebruikerslijst laden.
// Alleen handmatig toegevoegde contacten tellen als startpunt.
if ($is_admin || $is_leidinggevende) {
    foreach ($manual_contacts as $rid) {
        $rid = (int) $rid;
        if ($rid > 0 && $rid !== $user_id) $candidates[$rid] = $rid;
    }
}

foreach ($candidates as $rid) {
    if (BP_Core_Berichten::mag_sturen_naar($user_id, (int) $rid)) {
        $allowed_recipients[(int) $rid] = (int) $rid;
    }
}

foreach ($all_messages as $msg) {
    $van_id = (int) ($msg->van_id ?? 0);
    $naar_id = (int) ($msg->naar_id ?? 0);
    $other_id = $van_id === $user_id ? $naar_id : $van_id;
    if ($other_id <= 0) continue;

    if (!isset($threads[$other_id])) {
        $other_user = get_user_by('id', $other_id);
        $threads[$other_id] = [
            'user_id' => $other_id,
            'name' => $other_user ? (string) $other_user->display_name : ('Gebruiker #' . $other_id),
            'last_at' => 0,
            'preview' => '',
            'unread' => 0,
            'messages' => [],
        ];
    }

    $time = strtotime((string) ($msg->aangemaakt ?? '')) ?: 0;
    if ($time > (int) $threads[$other_id]['last_at']) {
        $threads[$other_id]['last_at'] = $time;
        $preview = trim((string) ($msg->inhoud ?? ''));
        if ($preview === '') {
            $preview = trim((string) ($msg->onderwerp ?? ''));
        }
        $preview_l = ltrim($preview);
        if (strpos($preview_l, 'e2e:v1:') === 0 || strpos($preview, 'e2e:v1:') !== false) {
            $preview = '[Versleuteld bericht]';
        }
        $threads[$other_id]['preview'] = $preview;
    }

    if ($naar_id === $user_id && (int) ($msg->gelezen ?? 0) === 0) {
        $threads[$other_id]['unread']++;
    }

    $threads[$other_id]['messages'][] = $msg;
}

uasort($threads, static function($a, $b) {
    return ((int) $b['last_at']) <=> ((int) $a['last_at']);
});

$thread_ids = array_keys($threads);
$requested_thread = isset($_GET['thread']) ? absint((int) $_GET['thread']) : 0;
$selected_thread_id = 0;

if ($requested_thread > 0 && isset($threads[$requested_thread])) {
    $selected_thread_id = $requested_thread;
} elseif (!empty($thread_ids)) {
    $selected_thread_id = (int) $thread_ids[0];
}

$selected_to = isset($_GET['to']) ? absint((int) $_GET['to']) : 0;
if ($selected_to <= 0) {
    $selected_to = $selected_thread_id;
}
if ($selected_to > 0 && !BP_Core_Berichten::mag_sturen_naar($user_id, $selected_to)) {
    $selected_to = 0;
}
if ($selected_to <= 0 && !empty($allowed_recipients)) {
    $allowed_ids = array_keys($allowed_recipients);
    $selected_to = (int) $allowed_ids[0];
}

$selected_messages = [];
if ($selected_thread_id > 0 && isset($threads[$selected_thread_id]['messages'])) {
    $selected_messages = $threads[$selected_thread_id]['messages'];

    // Auto-markeer ontvangen berichten als gelezen zodra gesprek wordt geopend.
    foreach ($selected_messages as $idx => $m) {
        if (!is_object($m)) continue;
        $is_incoming_unread = ((int) ($m->naar_id ?? 0) === $user_id) && ((int) ($m->gelezen ?? 0) === 0);
        if ($is_incoming_unread && !empty($m->id)) {
            BP_Core_Berichten::markeer_gelezen((int) $m->id, $user_id);
            $selected_messages[$idx]->gelezen = 1;
        }
    }

    usort($selected_messages, static function($a, $b) {
        $a_time = strtotime((string) ($a->aangemaakt ?? '')) ?: 0;
        $b_time = strtotime((string) ($b->aangemaakt ?? '')) ?: 0;
        return $a_time <=> $b_time;
    });
}

$notice = '';
if (!empty($_GET['bp_bericht_verzonden'])) {
    $notice = 'Bericht verzonden.';
} elseif (!empty($_GET['bp_bericht_e2e'])) {
    $notice = 'Bericht kon niet als E2E worden verwerkt (ongeldige payload).';
} elseif (!empty($_GET['bp_bericht_ratelimit'])) {
    $notice = 'Maximum berichten per uur bereikt. Probeer later opnieuw.';
} elseif (!empty($_GET['bp_bericht_fout'])) {
    $notice = 'Berichttekst is verplicht.';
} elseif (!empty($_GET['bp_bericht_verwijderd'])) {
    $notice = 'Bericht verwijderd.';
} elseif (!empty($_GET['bp_gesprek_verwijderd'])) {
    $notice = 'Gesprek verwijderd.';
} elseif (!empty($_GET['bp_undo_done'])) {
    $notice = 'Verwijderen ongedaan gemaakt.';
} elseif (!empty($_GET['bp_undo_expired'])) {
    $notice = 'Undo is verlopen.';
} elseif (!empty($_GET['bp_contact_added'])) {
    $notice = 'Contact toegevoegd.';
} elseif (!empty($_GET['bp_contact_removed'])) {
    $notice = 'Contact verwijderd.';
} elseif (!empty($_GET['bp_contact_error'])) {
    $err = sanitize_key((string) $_GET['bp_contact_error']);
    if ($err === 'not_found') {
        $notice = 'Contact niet gevonden. Controleer code of telefoonnummer.';
    } elseif ($err === 'not_allowed') {
        $notice = 'Je mag dit contact niet toevoegen.';
    } elseif ($err === 'self') {
        $notice = 'Je kunt jezelf niet als contact toevoegen.';
    } elseif ($err === '1') {
        $notice = 'Contactactie mislukt. Controleer code/telefoon en je rechten.';
    } else {
        $notice = 'Contactactie mislukt.';
    }
} elseif (!empty($_GET['bp_e2e_settings_saved'])) {
    $notice = 'E2E-instellingen opgeslagen.';
}
$undo_token = isset($_GET['bp_undo']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['bp_undo']) : '';
$undo_kind = isset($_GET['bp_undo_kind']) ? sanitize_key((string) $_GET['bp_undo_kind']) : '';
$self_url = get_permalink() ?: home_url('/');
$view = isset($_GET['view']) ? sanitize_key((string) $_GET['view']) : '';
$contacts_page_id = (int) get_option('bp_addon_berichten_contacts_page_id', 0);
$inbox_page_id = (int) get_option('bp_addon_berichten_page_id', 0);
$current_page_id = get_the_ID() ? (int) get_the_ID() : 0;
$is_contacts_view = ($view === 'contacts') || ($contacts_page_id > 0 && $current_page_id === $contacts_page_id);
$is_settings_view = ($view === 'settings');
$contacts_url = $contacts_page_id > 0 ? (string) get_permalink($contacts_page_id) : add_query_arg('view', 'contacts', $self_url);
$inbox_url = $inbox_page_id > 0 ? (string) get_permalink($inbox_page_id) : remove_query_arg('view', $self_url);
$settings_url = add_query_arg('view', 'settings', $inbox_url);
$lock_enabled = true;
if (function_exists('bp_core_is_page_behind_login_wall')) {
    $lock_enabled = bp_core_is_page_behind_login_wall((int) get_the_ID());
}

$e2e_key_users = [$user_id => $user_id];
foreach ($threads as $tid => $_t) {
    $tid = (int) $tid;
    if ($tid > 0) $e2e_key_users[$tid] = $tid;
}
foreach ($allowed_recipients as $rid) {
    $rid = (int) $rid;
    if ($rid > 0) $e2e_key_users[$rid] = $rid;
}
$e2e_public_keys = [];
$e2e_public_fingerprints = [];
foreach ($e2e_key_users as $kid) {
    $jwk = BP_Core_Berichten::get_public_jwk((int) $kid);
    if ($jwk === '') continue;
    $arr = json_decode($jwk, true);
    if (!is_array($arr)) continue;
    $uid = (int) $kid;
    $fp = (string) get_user_meta($uid, 'bp_msg_e2e_public_jwk_fp', true);
    if (!preg_match('/\A[a-f0-9]{64}\z/i', $fp)) {
        $canonical = wp_json_encode($arr);
        $fp = is_string($canonical) && $canonical !== '' ? hash('sha256', $canonical) : '';
    }
    if (!preg_match('/\A[a-f0-9]{64}\z/i', $fp)) continue;
    $e2e_public_keys[(string) $uid] = $arr;
    $e2e_public_fingerprints[(string) $uid] = strtolower($fp);
}
?>

<style>
/* Dwing de website-kaders naar de volledige breedte */
html, body, .main-container, .content-area { 
    max-width: 100% !important; 
    width: 100% !important; 
    margin: 0 !important; 
    padding: 0 !important; 
}

.bp-inbox-wrap{--mac-bg:var(--kb-bg,#f2f3f7);--mac-panel:#ffffffcc;--mac-border:var(--kb-border,#d7dbe6);--mac-text:var(--kb-text,#1f2937);--mac-sub:var(--kb-muted,#64748b);--mac-blue:var(--kb-blue,#0a84ff);--mac-green:#30d158;--mac-red:#ff453a;display:block !important;margin:0 !important;width:100% !important;max-width:none !important;padding:10px !important;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","SF Pro Display","Helvetica Neue",Helvetica,Arial,sans-serif}
.bp-inbox-wrap *{box-sizing:border-box}
.bp-inbox-wrap a{color:var(--kb-link,var(--kb-blue,#0a84ff))}
.bp-inbox-hero{background:linear-gradient(160deg,var(--kb-blue,#0a84ff) 0%,var(--kb-mid,#0267d8) 100%);border:1px solid rgba(255,255,255,.35);border-radius:18px;padding:18px 22px;color:#fff;margin-bottom:14px;width:100% !important}
.bp-inbox-hero-title{margin:0;font-size:28px;line-height:1.15;font-weight:750;letter-spacing:-.01em}
.bp-inbox-hero-sub{margin-top:7px;font-size:14px;opacity:.94}
.bp-inbox-shell{display:grid;grid-template-columns:350px 1fr;gap:16px;min-height: calc(100vh - 300px);;width:100% !important}
.bp-inbox-card{background:var(--mac-panel);backdrop-filter:saturate(1.2) blur(10px);border:1px solid var(--mac-border);border-radius:18px;overflow:hidden;width:100% !important;height:100% !important}
.bp-inbox-head{padding:16px 18px;border-bottom:1px solid #e7ebf3;background:linear-gradient(180deg,#fff 0%,#f8f9fc 100%)}
.bp-inbox-title{margin:0;font-size:29px;line-height:1.2;color:#0f172a;font-weight:750;letter-spacing:-.01em}
.bp-inbox-sub{margin-top:4px;font-size:13px;color:var(--mac-sub)}
.bp-inbox-list{max-height:none;height:90%;overflow:auto;background:var(--mac-bg)}
.bp-contact-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.bp-contact-chip{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #d7e2f2;border-radius:999px;padding:4px 8px;font-size:11px}
.bp-contact-status{display:inline-flex;align-items:center;border-radius:999px;padding:1px 6px;font-size:10px;font-weight:700}
.bp-contact-status.ok{background:#dcfce7;color:#166534}
.bp-contact-status.no{background:#fee2e2;color:#991b1b}
.bp-contact-chip form{margin:0}
.bp-contact-book{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}
.bp-contact-card{display:grid;grid-template-columns:56px 1fr auto;align-items:center;gap:12px;padding:12px;border:1px solid #d7e2f2;border-radius:14px;background:#fff}
.bp-contact-avatar{width:56px;height:56px;border-radius:999px;object-fit:cover;border:1px solid #dbe4f0}
.bp-contact-main{min-width:0}
.bp-contact-name{display:block;font-size:15px;color:#0f172a;font-weight:700;line-height:1.2;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bp-contact-sub{margin-top:4px;font-size:12px;color:#5b6d87}
.bp-inbox-thread{display:block;padding:12px 14px;border-bottom:1px solid #e6ebf4;text-decoration:none;color:#1f2a3d;background:#fff}
.bp-inbox-thread:hover{background:#f3f8ff}
.bp-inbox-thread.is-active{background:#e9f2ff}
.bp-inbox-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.bp-inbox-name{font-size:14px;font-weight:700;color:#0f172a}
.bp-inbox-time{font-size:11px;color:#6b7b93;white-space:nowrap}
.bp-inbox-preview{margin-top:4px;font-size:12px;color:#55657d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bp-inbox-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:var(--mac-red);color:#fff;font-size:10px;font-weight:800}
.bp-chat{display:grid;grid-template-rows:auto 1fr auto;height:100%;width:100% !important}
.bp-chat-head-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
.bp-chat-head-actions{display:flex;align-items:center;gap:8px}
.bp-chat-log{padding:16px;overflow:auto;max-height:none;height:100%;background:radial-gradient(circle at top left,#f4f8ff 0%,#eef3fb 46%,#e8eef7 100%);width:100% !important}
.bp-msg{max-width:85% !important;margin:0 0 12px;padding:10px 12px;border-radius:14px;border:1px solid #dce6f4;background:#fff}
.bp-msg.is-me{margin-left:auto;background:#d8edff;border-color:#bcdfff}
.bp-msg-row{display:flex;align-items:flex-end;gap:8px;margin:0 0 12px}
.bp-msg-row.is-me{justify-content:flex-end}
.bp-msg-avatar{width:30px;height:30px;border-radius:999px;object-fit:cover;border:1px solid #cbd5e1;flex-shrink:0;background:#fff}
.bp-msg-meta{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;font-size:11px;color:#51617a}
.bp-msg-meta-left{display:flex;align-items:center;gap:6px}
.bp-msg-check{font-size:12px;line-height:1;letter-spacing:-1px;color:#94a3b8}
.bp-msg-check.is-read{color:#0ea5e9}
.bp-msg-title{font-size:12px;font-weight:700;color:#17376f;margin-bottom:4px}
.bp-msg-body{font-size:13px;line-height:1.45;color:#1f2a3d;white-space:pre-wrap;word-break:break-word}
.bp-chat-compose{padding:14px 16px;border-top:1px solid #e8eef6;background:#f8fafe}
.bp-chat-compose form{width:100% !important;max-width:100% !important}
.bp-field{display:block;width:100%;border:1px solid #cbd5e1;border-radius:12px;padding:11px 13px;font-size:14px;line-height:1.3;background:#fff}
.bp-field:focus{outline:none;border-color:#8fc1ff;box-shadow:none}
.bp-field + .bp-field{margin-top:10px}
.bp-select{height:42px;background:#fff}
.bp-compose-actions{margin-top:10px;display:flex;justify-content:flex-end}
.bp-btn-send{display:inline-flex;align-items:center;justify-content:center;padding:11px 20px;border:1px solid #0f5cc0;border-radius:12px;background:#0f5cc0;color:#ffffff !important;font-weight:700;cursor:pointer;box-shadow:none}
.bp-mini-btn{display:inline-flex;align-items:center;justify-content:center;padding:7px 11px;border-radius:10px;border:1px solid #cfd9e8;background:#fff;color:var(--kb-text,#1f2937) !important;font-size:11px;font-weight:650;cursor:pointer;white-space:nowrap;text-decoration:none}
.bp-mini-btn.bp-mini-btn-danger{border-color:#fecaca;background:#fff1f2;color:var(--kb-text,#111827) !important}
.bp-empty{padding:26px;text-align:center;color:#64748b;font-size:13px}
.bp-notice-box{margin:0 0 12px;padding:10px 12px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:12px;font-size:13px}
.bp-top-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-bottom:10px}
.bp-security-box{margin:0 0 12px;padding:12px;border:1px solid #bfdbfe;background:#f8fbff;color:#1e3a8a;border-radius:10px;font-size:13px}
.bp-security-grid{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}
.bp-security-actions{display:flex;gap:8px;flex-wrap:wrap}
.bp-e2e-warning{margin-top:8px;padding:8px 10px;border:1px solid #fecaca;background:#fff1f2;color:#9f1239;border-radius:8px;font-size:12px}
.bp-settings-form{max-width:620px;display:grid;gap:10px}
.bp-contact-code-row{display:grid;grid-template-columns:1fr 170px;gap:14px;align-items:center}
.bp-qr-box{display:flex;align-items:center;justify-content:center;min-height:160px;background:#fff;border:1px solid #d7e2f2;border-radius:10px}
.bp-contact-link{color:inherit;text-decoration:none;font-weight:700}
.bp-contact-link:hover{text-decoration:underline}
.bp-recipient-row{display:none !important}
@media (max-width: 900px){
  .bp-inbox-wrap{padding:14px 12px}
  .bp-inbox-shell{grid-template-columns:1fr}
  .bp-inbox-list{max-height:36vh}
  .bp-chat-log{max-height:46vh;padding:12px}
  .bp-msg{max-width:92%}
  .bp-chat-compose{padding:12px}
  .bp-compose-actions{justify-content:stretch}
  .bp-btn-send{width:100%}
  .bp-chat-head-row{flex-direction:column;align-items:flex-start}
  .bp-chat-head-actions{width:100%;justify-content:flex-end}
  .bp-contact-code-row{grid-template-columns:1fr}
  .bp-contact-book{grid-template-columns:1fr}
  .bp-inbox-title{font-size:24px}
}
</style>

<div class="bp-inbox-wrap">
  <div class="bp-top-actions">
    <button type="button" class="bp-mini-btn" onclick="window.location.href='<?php echo esc_js($inbox_url); ?>';">Inbox</button>
    <button type="button" class="bp-mini-btn" onclick="window.location.href='<?php echo esc_js($contacts_url); ?>';">Contacten</button>
    <button type="button" class="bp-mini-btn" onclick="window.location.href='<?php echo esc_js($settings_url); ?>';">Instellingen</button>
  </div>
  <div class="bp-inbox-hero">
    <h1 class="bp-inbox-hero-title">Berichten inbox</h1>
    <div class="bp-inbox-hero-sub">Bekijk gesprekken, reageer snel en beheer je berichten overzichtelijk.</div>
  </div>
  <?php if ($notice !== ''): ?>
    <div class="bp-notice-box"><?php echo esc_html($notice); ?></div>
  <?php endif; ?>
  <?php if ($undo_token !== '' && in_array($undo_kind, ['bericht', 'gesprek'], true)): ?>
    <div class="bp-notice-box" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <span><?php echo $undo_kind === 'gesprek' ? 'Gesprek verwijderd.' : 'Bericht verwijderd.'; ?> Ongedaan maken kan 10 seconden.</span>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
        <?php wp_nonce_field('bp_undo_verwijderen', 'bp_undo_nonce'); ?>
        <input type="hidden" name="action" value="bp_undo_verwijderen">
        <input type="hidden" name="bp_undo" value="<?php echo esc_attr($undo_token); ?>">
        <button type="submit" class="bp-mini-btn">Undo</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($is_settings_view): ?>
    <div class="bp-inbox-card">
      <div class="bp-inbox-head">
        <h2 class="bp-inbox-title">E2E instellingen</h2>
        <div class="bp-inbox-sub">Beheer je contactcode en encryptiesleutels.</div>
      </div>
      <div style="padding:16px;">
        <div class="bp-security-box">
          <div class="bp-contact-code-row">
            <div>
              <div><strong>Jouw contactcode:</strong> <code id="bp-contact-code"><?php echo esc_html($my_contact_code); ?></code></div>
              <div style="margin-top:4px;">Deel deze code of je telefoonnummer alleen met contacten die jij wil toevoegen.</div>
            </div>
            <div class="bp-qr-box">
              <img id="bp-contact-qr" src="" alt="Contact QR" width="150" height="150">
            </div>
          </div>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bp-settings-form">
          <?php wp_nonce_field('bp_e2e_settings', 'bp_e2e_settings_nonce'); ?>
          <input type="hidden" name="action" value="bp_e2e_settings">
          <label for="bp-rotation-days" style="font-weight:700;color:#0f2f67;">Automatische sleutel-rotatie (dagen)</label>
          <input id="bp-rotation-days" type="number" min="7" max="365" name="rotation_days" class="bp-field" value="<?php echo (int) $rotation_days; ?>">
          <button type="submit" class="bp-mini-btn" style="width:max-content;">Opslaan</button>
        </form>

        <div class="bp-security-actions" style="margin-top:12px;">
          <button type="button" class="bp-mini-btn" id="bp-e2e-export">Sleutel exporteren</button>
          <label class="bp-mini-btn" for="bp-e2e-import" style="cursor:pointer;">Sleutel importeren</label>
          <input type="file" id="bp-e2e-import" accept=".json,application/json" style="display:none;">
          <button type="button" class="bp-mini-btn bp-mini-btn-danger" id="bp-e2e-rotate">Sleutel nu roteren</button>
          <button type="button" class="bp-mini-btn" id="bp-install-app" style="display:none;">Installeer Berichten app</button>
          <button type="button" class="bp-mini-btn" id="bp-ios-install-help-reset">Toon iPhone-installatiehulp opnieuw</button>
        </div>
      </div>
    </div>
  <?php elseif ($is_contacts_view): ?>
    <div class="bp-inbox-card">
      <div class="bp-inbox-head">
        <h2 class="bp-inbox-title">Contacten beheren</h2>
        <div class="bp-inbox-sub">Voeg contacten toe en verwijder contacten voor je inbox.</div>
      </div>
      <div style="padding:16px;">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;max-width:820px;flex-wrap:wrap;">
            <?php wp_nonce_field('bp_add_contact', 'bp_contact_nonce'); ?>
            <input type="hidden" name="action" value="bp_add_contact">
            <input type="text" name="contact_code" class="bp-field" style="margin:0;max-width:220px;" placeholder="Contactcode (bijv. A1B2C3D4)">
            <input type="text" name="contact_phone" class="bp-field" style="margin:0;max-width:220px;" placeholder="Telefoonnummer">
            <button type="submit" class="bp-mini-btn">Toevoegen</button>
          </form>

        <?php if (!empty($manual_contacts)): ?>
          <div class="bp-contact-book">
            <?php foreach ($manual_contacts as $cid): ?>
              <?php $cu = get_user_by('id', (int) $cid); if (!$cu) continue; ?>
              <?php $has_key = BP_Core_Berichten::get_public_jwk((int) $cid) !== ''; ?>
              <?php $open_thread_url = add_query_arg(['thread' => (int) $cid, 'to' => (int) $cid], $inbox_url); ?>
              <?php
                $meta_photo = (string) get_user_meta((int) $cid, 'kb_profielfoto', true);
                $avatar_url = $meta_photo !== '' ? $meta_photo : (string) get_avatar_url((int) $cid, ['size' => 96]);
                $phone = (string) get_user_meta((int) $cid, 'kb_telefoon', true);
                $role_label = 'Gebruiker';
                if (class_exists('BP_Core_Roles')) {
                    if (BP_Core_Roles::is_client($cu)) {
                        $role_label = 'Client';
                    } elseif (BP_Core_Roles::is_begeleider($cu)) {
                        $role_label = 'Begeleider';
                    } elseif (BP_Core_Roles::is_leidinggevende($cu)) {
                        $role_label = 'Leidinggevende';
                    } elseif (user_can($cu, 'manage_options')) {
                        $role_label = 'Administrator';
                    }
                }
              ?>
              <div class="bp-contact-card">
                <?php if ($avatar_url !== ''): ?>
                  <img class="bp-contact-avatar" src="<?php echo esc_url($avatar_url); ?>" alt="<?php echo esc_attr((string) $cu->display_name); ?>">
                <?php else: ?>
                  <?php echo get_avatar((int) $cid, 52, '', (string) $cu->display_name, ['class' => 'bp-contact-avatar']); ?>
                <?php endif; ?>
                <div class="bp-contact-main">
                  <button type="button" class="bp-contact-name" onclick="window.location.href='<?php echo esc_js($open_thread_url); ?>';" style="background:none;border:0;padding:0;cursor:pointer;text-align:left;"><?php echo esc_html((string) $cu->display_name); ?></button>
                  <div class="bp-contact-sub">
                    <span><?php echo esc_html($role_label); ?></span>
                    <?php if ($phone !== ''): ?>
                      <span> · <?php echo esc_html($phone); ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="bp-contact-sub">
                    <span class="bp-contact-status <?php echo $has_key ? 'ok' : 'no'; ?>"><?php echo $has_key ? 'E2E gereed' : 'Geen E2E sleutel'; ?></span>
                  </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                  <?php wp_nonce_field('bp_remove_contact', 'bp_contact_nonce'); ?>
                  <input type="hidden" name="action" value="bp_remove_contact">
                  <input type="hidden" name="contact_id" value="<?php echo (int) $cid; ?>">
                  <button type="submit" class="bp-mini-btn bp-mini-btn-danger" style="padding:6px 9px;">x</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="bp-empty" style="text-align:left;padding:14px 0;">Nog geen contacten toegevoegd.</div>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
  <div class="bp-inbox-shell">
    <div class="bp-inbox-card">
      <div class="bp-inbox-head">
        <h2 class="bp-inbox-title">Berichten</h2>
        <div class="bp-inbox-sub">Aparte inboxpagina met al je gesprekken.</div>
      </div>

      <div class="bp-inbox-list">
        <?php if (empty($threads)): ?>
          <div class="bp-empty">Nog geen berichten gevonden.</div>
        <?php else: ?>
          <?php foreach ($threads as $tid => $thread): ?>
            <?php
              $is_active = ((int) $tid === (int) $selected_thread_id);
              $thread_url = add_query_arg(['thread' => (int) $tid], $self_url);
              $preview = trim((string) ($thread['preview'] ?? ''));
              if (mb_strlen($preview) > 65) {
                  $preview = mb_substr($preview, 0, 65) . '...';
              }
            ?>
            <a href="<?php echo esc_url($thread_url); ?>" onclick="window.location.href='<?php echo esc_js($thread_url); ?>'; return false;" class="bp-inbox-thread<?php echo $is_active ? ' is-active' : ''; ?>">
              <div class="bp-inbox-row">
                <div class="bp-inbox-name"><?php echo esc_html((string) $thread['name']); ?></div>
                <div class="bp-inbox-time"><?php echo !empty($thread['last_at']) ? esc_html(date_i18n('d-m H:i', (int) $thread['last_at'])) : ''; ?></div>
              </div>
              <div class="bp-inbox-row">
                <div class="bp-inbox-preview"><?php echo esc_html($preview); ?></div>
                <?php if (!empty($thread['unread'])): ?>
                  <span class="bp-inbox-badge"><?php echo (int) $thread['unread']; ?></span>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="bp-inbox-card bp-chat">
      <div class="bp-inbox-head">
        <?php
          $active_name = 'Selecteer een gesprek';
          if ($selected_thread_id > 0 && !empty($threads[$selected_thread_id]['name'])) {
              $active_name = (string) $threads[$selected_thread_id]['name'];
          }
        ?>
        <div class="bp-chat-head-row">
          <div>
            <h3 class="bp-inbox-title" style="font-size:18px;"><?php echo esc_html($active_name); ?></h3>
            <div class="bp-inbox-sub">Gespreksweergave in chatstijl.</div>
          </div>
          <div class="bp-chat-head-actions">
            <button type="button" class="bp-mini-btn" id="bp-e2e-unlock-btn">Ontgrendel berichten</button>
            <?php if ($selected_thread_id > 0): ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Weet je zeker dat je dit hele gesprek wilt verwijderen?');">
                <?php wp_nonce_field('bp_verwijder_gesprek_' . $selected_thread_id, 'bp_verwijder_gesprek_nonce'); ?>
                <input type="hidden" name="action" value="bp_verwijder_gesprek">
                <input type="hidden" name="other_user_id" value="<?php echo (int) $selected_thread_id; ?>">
                <button type="submit" class="bp-mini-btn bp-mini-btn-danger">Gesprek verwijderen</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="bp-chat-log">
        <?php if (empty($selected_messages)): ?>
          <div class="bp-empty">Kies links een gesprek of start hieronder een nieuw bericht.</div>
        <?php else: ?>
          <?php foreach ($selected_messages as $msg): ?>
            <?php
              $msg_id = (int) ($msg->id ?? 0);
              $is_me = ((int) ($msg->van_id ?? 0) === $user_id);
              $is_unread = ((int) ($msg->naar_id ?? 0) === $user_id) && ((int) ($msg->gelezen ?? 0) === 0);
              $msg_time = strtotime((string) ($msg->aangemaakt ?? '')) ?: 0;
            ?>
            <?php
              $avatar_user_id = $is_me ? $user_id : (int) $selected_thread_id;
              $msg_avatar = (string) get_user_meta($avatar_user_id, 'kb_profielfoto', true);
              if ($msg_avatar === '') {
                  $msg_avatar = (string) get_avatar_url($avatar_user_id, ['size' => 56]);
              }
            ?>
            <div class="bp-msg-row<?php echo $is_me ? ' is-me' : ''; ?>">
              <?php if (!$is_me): ?>
                <img class="bp-msg-avatar" src="<?php echo esc_url($msg_avatar); ?>" alt="<?php echo esc_attr($active_name); ?>" data-name="<?php echo esc_attr($active_name); ?>">
              <?php endif; ?>
              <div class="bp-msg<?php echo $is_me ? ' is-me' : ''; ?>">
              <div class="bp-msg-meta">
                <span class="bp-msg-meta-left">
                  <span><?php echo $is_me ? 'Jij' : esc_html($active_name); ?></span>
                  <?php $msg_status = sanitize_key((string) ($msg->status ?? 'pending')); ?>
                  <?php if ((int) ($msg->gelezen ?? 0) === 1 || $msg_status === 'read'): ?>
                    <span class="bp-msg-check is-read" title="Verzonden en gelezen">&#10003;&#10003;</span>
                  <?php elseif ($msg_status === 'delivered'): ?>
                    <span class="bp-msg-check" title="Ontvangen">&#10003;&#10003;</span>
                  <?php else: ?>
                    <span class="bp-msg-check" title="Verzonden">&#10003;</span>
                  <?php endif; ?>
                </span>
                <span><?php echo $msg_time ? esc_html(date_i18n('d-m-Y H:i', $msg_time)) : ''; ?></span>
              </div>
              <?php if (!empty($msg->onderwerp)): ?>
                <div class="bp-msg-title"><?php echo esc_html((string) $msg->onderwerp); ?></div>
              <?php endif; ?>
              <?php $raw_body = (string) ($msg->inhoud ?? ''); ?>
              <?php $raw_body_l = ltrim($raw_body); ?>
              <?php $is_e2e_body = (strpos($raw_body_l, 'e2e:v1:') === 0 || strpos($raw_body, 'e2e:v1:') !== false); ?>
              <div
                class="bp-msg-body"
                <?php if ($is_e2e_body): ?>
                  data-e2e="1"
                  data-raw="<?php echo esc_attr($raw_body); ?>"
                <?php endif; ?>
              ><?php echo $is_e2e_body ? esc_html('[Versleuteld bericht]') : esc_html($raw_body); ?></div>

              <?php if ($is_unread && $msg_id > 0): ?>
                <div style="margin-top:8px;">
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('bp_markeer_gelezen_' . $msg_id, 'bp_gelezen_nonce'); ?>
                    <input type="hidden" name="action" value="bp_markeer_gelezen">
                    <input type="hidden" name="bericht_id" value="<?php echo (int) $msg_id; ?>">
                    <button type="submit" class="bp-mini-btn">Markeer gelezen</button>
                  </form>
                </div>
              <?php endif; ?>
              <?php if ($msg_id > 0): ?>
                <div style="margin-top:8px;">
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Bericht verwijderen?');">
                    <?php wp_nonce_field('bp_verwijder_bericht_' . $msg_id, 'bp_verwijder_nonce'); ?>
                    <input type="hidden" name="action" value="bp_verwijder_bericht">
                    <input type="hidden" name="bericht_id" value="<?php echo (int) $msg_id; ?>">
                    <button type="submit" class="bp-mini-btn bp-mini-btn-danger">Bericht verwijderen</button>
                  </form>
                </div>
              <?php endif; ?>
              </div>
              <?php if ($is_me): ?>
                <img class="bp-msg-avatar" src="<?php echo esc_url($msg_avatar); ?>" alt="Jij" data-name="Jij">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="bp-chat-compose">
        <?php
          $active_recipient_id = (int) $selected_to;
          $active_recipient = $active_recipient_id > 0 ? get_user_by('id', $active_recipient_id) : null;
          $can_send = $active_recipient_id > 0 && BP_Core_Berichten::mag_sturen_naar($user_id, $active_recipient_id);
          $active_recipient_fp = $active_recipient_id > 0 ? (string) get_user_meta($active_recipient_id, 'bp_msg_e2e_public_jwk_fp', true) : '';
          $active_recipient_has_key = $can_send
            && BP_Core_Berichten::get_public_jwk($active_recipient_id) !== ''
            && preg_match('/\A[a-f0-9]{64}\z/i', $active_recipient_fp);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('bp_stuur_bericht', 'bp_bericht_nonce'); ?>
          <input type="hidden" name="action" value="bp_stuur_bericht">
          <?php if (!$can_send): ?>
            <div class="bp-empty" style="padding:10px 12px;margin-bottom:10px;text-align:left;">
              Kies eerst een gesprek via Contacten of open een bestaand gesprek.
            </div>
          <?php else: ?>
            <input type="hidden" name="naar_id" value="<?php echo (int) $active_recipient_id; ?>" data-has-key="<?php echo $active_recipient_has_key ? '1' : '0'; ?>">
          <?php endif; ?>

          <input type="hidden" name="onderwerp" value="">
          <textarea name="inhoud" class="bp-field" rows="4" maxlength="5000" placeholder="Typ je bericht..." required></textarea>
          <div class="bp-e2e-warning" id="bp-e2e-recipient-warning" style="display:none;">Deze ontvanger heeft nog geen E2E sleutel. Bericht wordt verstuurd met veilige server-encryptie (niet end-to-end).</div>

          <div class="bp-compose-actions">
            <button type="submit" class="bp-btn-send" id="bp-send-btn" <?php echo $can_send ? '' : 'disabled'; ?>>Verstuur bericht</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <script id="bp-e2e-config" type="application/json"><?php echo wp_json_encode([
      'userId' => $user_id,
      'adminPost' => admin_url('admin-post.php'),
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('bp_e2e_public_key'),
      'verifyAccountNonce' => wp_create_nonce('bp_core_verify_account_password'),
      'publicKeys' => $e2e_public_keys,
      'publicKeyFingerprints' => $e2e_public_fingerprints,
      'contactCode' => $my_contact_code,
      'qrProxy' => wp_nonce_url(admin_url('admin-post.php?action=bp_contact_qr'), 'bp_contact_qr', 'bp_qr_nonce'),
      'rotationDays' => (int) $rotation_days,
      'swUrl' => add_query_arg('bp_berichten_sw', '1', home_url('/')),
      'swScope' => '/',
      'lockEnabled' => (bool) $lock_enabled,
  ]); ?></script>
</div>
