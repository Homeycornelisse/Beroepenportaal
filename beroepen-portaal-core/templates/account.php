<?php
defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    echo '<p>' . esc_html__('U bent niet ingelogd.', 'beroepen-portaal-core') . '</p>';
    return;
}

$me  = wp_get_current_user();
$uid = (int) $me->ID;

// Rol bepalen
$is_admin        = current_user_can('manage_options');
$is_client       = class_exists('BP_Core_Roles') && BP_Core_Roles::is_client($me);
$is_begeleider   = class_exists('BP_Core_Roles') && BP_Core_Roles::is_begeleider($me);
$is_leidinggevende = class_exists('BP_Core_Roles') && BP_Core_Roles::is_leidinggevende($me);

// Rolnaam voor weergave
if ($is_admin)           $rol_label = 'Beheerder';
elseif ($is_leidinggevende) $rol_label = 'Leidinggevende';
elseif ($is_begeleider)  $rol_label = 'Begeleider';
elseif ($is_client)      $rol_label = 'Cliënt';
else                      $rol_label = '';

$profielfoto  = (string) get_user_meta($uid, 'kb_profielfoto', true);
$telefoon     = (string) get_user_meta($uid, 'kb_telefoon', true);

// Client-specifieke velden
$geboortedatum = $is_client ? (string) get_user_meta($uid, 'kb_geboortedatum', true) : '';
$adres         = $is_client ? (string) get_user_meta($uid, 'kb_adres', true) : '';
$postcode      = $is_client ? (string) get_user_meta($uid, 'kb_postcode', true) : '';
$woonplaats    = $is_client ? (string) get_user_meta($uid, 'kb_woonplaats', true) : '';

// Begeleider info (alleen voor client)
$begeleider_id = $is_client ? (int) get_user_meta($uid, 'kb_begeleider_id', true) : 0;
$begeleider    = $begeleider_id > 0 ? get_user_by('id', $begeleider_id) : null;
$begel_tel     = $begeleider ? (string) get_user_meta($begeleider_id, 'kb_telefoon', true) : '';

// Feedback-params
$foto_opgeslagen  = !empty($_GET['bp_foto_opgeslagen']);
$foto_fout        = isset($_GET['bp_foto_fout']) ? sanitize_key($_GET['bp_foto_fout']) : '';
$naw_opgeslagen   = !empty($_GET['bp_naw_opgeslagen']);
$pw_opgeslagen    = !empty($_GET['bp_pw_opgeslagen']);
$pw_fout          = isset($_GET['bp_pw_fout']) ? sanitize_key($_GET['bp_pw_fout']) : '';
$twofa_status     = isset($_GET['bp_2fa_status']) ? sanitize_key((string) $_GET['bp_2fa_status']) : '';

$twofa_enabled = (int) get_user_meta($uid, 'bp_2fa_totp_enabled', true) === 1;
$twofa_secret  = (string) get_user_meta($uid, 'bp_2fa_totp_secret', true);
$twofa_pending = (string) get_user_meta($uid, 'bp_2fa_totp_pending_secret', true);
if (!$twofa_enabled && class_exists('BP_Core_Loader') && method_exists('BP_Core_Loader', 'generate_totp_secret')) {
  // Bewust: zolang 2FA niet geactiveerd is, genereren we bij elke pagina-load een nieuwe setup-sleutel.
  $twofa_pending = BP_Core_Loader::generate_totp_secret();
  update_user_meta($uid, 'bp_2fa_totp_pending_secret', $twofa_pending);
}
$twofa_setup_secret = $twofa_enabled ? $twofa_secret : $twofa_pending;
$issuer = function_exists('bp_core_get_org_name') ? (string) bp_core_get_org_name('Beroepen Portaal') : 'Beroepen Portaal';
$otpauth_uri = '';
$qr_url = '';
if ($twofa_setup_secret !== '') {
  $otpauth_uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $me->user_email)
    . '?secret=' . rawurlencode($twofa_setup_secret)
    . '&issuer=' . rawurlencode($issuer)
    . '&algorithm=SHA1&digits=6&period=30';
  $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($otpauth_uri);
}

$admin_url = admin_url('admin-post.php');
?>
<style>
.kb-account-wrap { max-width: 700px; margin: 0 auto; padding: 16px; font-family: 'Inter', system-ui, sans-serif; box-sizing: border-box; }
.kb-account-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; margin-bottom: 20px; }
.kb-account-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.kb-account-photo-row { display: flex; align-items: center; gap: 20px; margin-bottom: 16px; flex-wrap: wrap; }
.kb-account-input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-size: 14px; box-sizing: border-box; font-family: inherit; }
.kb-account-label { display: block; font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 4px; }
.kb-account-btn { background: #003082; color: #fff; border: none; border-radius: 8px; padding: 10px 22px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
.kb-account-notice { border-radius: 8px; padding: 10px 16px; margin-bottom: 16px; font-size: 13px; }
.kb-account-notice-ok  { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
.kb-account-notice-err { background: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; }
@media (max-width: 600px) {
  .kb-account-form-grid { grid-template-columns: 1fr; }
  .kb-account-photo-row { flex-direction: column; align-items: flex-start; }
  .kb-account-section { padding: 16px; }
  .kb-account-btn { width: 100%; text-align: center; }
  .kb-account-input { font-size: 16px; } /* voorkomt iOS auto-zoom */
}
</style>

<div class="kb-account-wrap">

  <div style="display:flex;align-items:center;gap:12px;margin:0 0 24px;flex-wrap:wrap;">
    <h2 style="font-size:20px;font-weight:800;color:#003082;margin:0;">Mijn account</h2>
    <?php if ($rol_label): ?>
      <span style="background:#eff6ff;color:#003082;border:1px solid #bfdbfe;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700;"><?= esc_html($rol_label) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($foto_opgeslagen): ?>
    <div class="kb-account-notice kb-account-notice-ok">Profielfoto opgeslagen.</div>
  <?php elseif ($foto_fout === 'size'): ?>
    <div class="kb-account-notice kb-account-notice-err">Foto is te groot (max 2 MB).</div>
  <?php elseif ($foto_fout === 'type'): ?>
    <div class="kb-account-notice kb-account-notice-err">Alleen JPEG, PNG of GIF toegestaan.</div>
  <?php elseif ($foto_fout): ?>
    <div class="kb-account-notice kb-account-notice-err">Upload mislukt. Probeer het opnieuw.</div>
  <?php endif; ?>

  <?php if ($naw_opgeslagen): ?>
    <div class="kb-account-notice kb-account-notice-ok">Gegevens opgeslagen.</div>
  <?php endif; ?>

  <?php if ($pw_opgeslagen): ?>
    <div class="kb-account-notice kb-account-notice-ok">Wachtwoord gewijzigd.</div>
  <?php elseif ($pw_fout === 'huidig'): ?>
    <div class="kb-account-notice kb-account-notice-err">Huidig wachtwoord klopt niet.</div>
  <?php elseif ($pw_fout === 'kort'): ?>
    <div class="kb-account-notice kb-account-notice-err">Nieuw wachtwoord moet minimaal 8 tekens zijn.</div>
  <?php elseif ($pw_fout === 'match'): ?>
    <div class="kb-account-notice kb-account-notice-err">Wachtwoorden komen niet overeen.</div>
  <?php endif; ?>

  <?php if ($twofa_status === 'enabled'): ?>
    <div class="kb-account-notice kb-account-notice-ok">Mobiele 2FA is geactiveerd.</div>
  <?php elseif ($twofa_status === 'disabled'): ?>
    <div class="kb-account-notice kb-account-notice-ok">Mobiele 2FA is uitgeschakeld.</div>
  <?php elseif ($twofa_status === 'regen'): ?>
    <div class="kb-account-notice kb-account-notice-ok">Nieuwe 2FA-sleutel aangemaakt. Verifieer nu met je authenticator-app.</div>
  <?php elseif ($twofa_status === 'pw'): ?>
    <div class="kb-account-notice kb-account-notice-err">Huidig wachtwoord klopt niet.</div>
  <?php elseif ($twofa_status === 'code'): ?>
    <div class="kb-account-notice kb-account-notice-err">De code uit je authenticator-app is onjuist.</div>
  <?php elseif ($twofa_status === 'invalid'): ?>
    <div class="kb-account-notice kb-account-notice-err">Ongeldige 2FA-actie.</div>
  <?php endif; ?>

  <!-- Profielfoto -->
  <div class="kb-account-section">
    <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 16px;">Profielfoto</h3>
    <div class="kb-account-photo-row">
      <?php if ($profielfoto): ?>
        <img src="<?= esc_url($profielfoto) ?>" alt="Profielfoto" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;flex-shrink:0;">
      <?php else: ?>
        <div style="width:80px;height:80px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:32px;color:#94a3b8;flex-shrink:0;">&#128100;</div>
      <?php endif; ?>
      <div style="font-size:12px;color:#64748b;">Maximaal 2 MB. Toegestane formaten: JPEG, PNG, GIF.</div>
    </div>
    <form method="post" action="<?= esc_url($admin_url) ?>" enctype="multipart/form-data">
      <input type="hidden" name="action" value="bp_update_account_foto">
      <?php wp_nonce_field('bp_update_account_foto', 'bp_foto_nonce'); ?>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <input type="file" name="bp_foto" accept="image/jpeg,image/png,image/gif" style="font-size:13px;max-width:100%;">
        <button type="submit" class="kb-account-btn">Uploaden</button>
      </div>
    </form>
  </div>

  <?php if ($is_client): ?>
  <!-- Contactgegevens begeleider (alleen voor cliënten) -->
  <div class="kb-account-section">
    <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 16px;">Mijn begeleider</h3>
    <?php if ($begeleider): ?>
      <div class="kb-account-form-grid" style="margin-bottom:0;">
        <div>
          <div class="kb-account-label">Naam</div>
          <div style="font-size:13px;color:#1e293b;"><?= esc_html($begeleider->display_name) ?></div>
        </div>
        <div>
          <div class="kb-account-label">E-mail</div>
          <div style="font-size:13px;"><a href="mailto:<?= esc_attr($begeleider->user_email) ?>" style="color:#003082;"><?= esc_html($begeleider->user_email) ?></a></div>
        </div>
        <?php if ($begel_tel): ?>
        <div>
          <div class="kb-account-label">Telefoon</div>
          <div style="font-size:13px;"><a href="tel:<?= esc_attr($begel_tel) ?>" style="color:#003082;"><?= esc_html($begel_tel) ?></a></div>
        </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p style="color:#64748b;font-size:13px;margin:0;">Er is nog geen begeleider aan u gekoppeld.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- NAW gegevens -->
  <div class="kb-account-section">
    <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 16px;">Persoonlijke gegevens</h3>
    <form method="post" action="<?= esc_url($admin_url) ?>">
      <input type="hidden" name="action" value="bp_update_account_naw">
      <?php wp_nonce_field('bp_update_account_naw', 'bp_naw_nonce'); ?>

      <div class="kb-account-form-grid">
        <div>
          <label class="kb-account-label">Naam</label>
          <input type="text" name="display_name" value="<?= esc_attr($me->display_name) ?>" class="kb-account-input">
        </div>
        <div>
          <label class="kb-account-label">E-mail</label>
          <input type="email" name="kb_email" value="<?= esc_attr($me->user_email) ?>" class="kb-account-input" readonly style="background:#f8fafc;cursor:default;">
        </div>
        <div>
          <label class="kb-account-label">Telefoon</label>
          <input type="tel" name="kb_telefoon" value="<?= esc_attr($telefoon) ?>" class="kb-account-input">
        </div>
        <?php if ($is_client): ?>
        <div>
          <label class="kb-account-label">Geboortedatum</label>
          <input type="date" name="kb_geboortedatum" value="<?= esc_attr($geboortedatum) ?>" class="kb-account-input">
        </div>
        <div>
          <label class="kb-account-label">Adres</label>
          <input type="text" name="kb_adres" value="<?= esc_attr($adres) ?>" class="kb-account-input">
        </div>
        <div>
          <label class="kb-account-label">Postcode</label>
          <input type="text" name="kb_postcode" value="<?= esc_attr($postcode) ?>" class="kb-account-input">
        </div>
        <div>
          <label class="kb-account-label">Woonplaats</label>
          <input type="text" name="kb_woonplaats" value="<?= esc_attr($woonplaats) ?>" class="kb-account-input">
        </div>
        <?php endif; ?>
      </div>

      <button type="submit" class="kb-account-btn">Opslaan</button>
    </form>
  </div>

  <!-- Mobiele 2FA -->
  <div class="kb-account-section">
    <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 12px;">Mobiele 2FA (authenticator-app)</h3>
    <p style="font-size:13px;color:#64748b;margin:0 0 14px;">Beschikbaar voor alle rollen. Gebruik een app zoals Google Authenticator of Microsoft Authenticator.</p>

    <?php if ($twofa_enabled): ?>
      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:10px 12px;font-size:13px;color:#166534;margin-bottom:14px;">
        Mobiele 2FA staat aan voor dit account.
      </div>
      <form method="post" action="<?= esc_url($admin_url) ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="action" value="bp_update_account_2fa_mobile">
        <input type="hidden" name="bp_2fa_mode" value="disable">
        <?php wp_nonce_field('bp_update_account_2fa_mobile', 'bp_2fa_nonce'); ?>
        <div style="flex:1;min-width:220px;">
          <label class="kb-account-label">Huidig wachtwoord (bevestiging)</label>
          <input type="password" name="bp_2fa_huidig_pw" class="kb-account-input" autocomplete="current-password" required>
        </div>
        <button type="submit" class="kb-account-btn" style="background:#b91c1c;">Mobiele 2FA uitschakelen</button>
      </form>
    <?php else: ?>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 12px;font-size:13px;color:#1e3a8a;margin-bottom:14px;">
        Mobiele 2FA staat uit. Activeer hieronder.
      </div>
      <?php if ($twofa_setup_secret): ?>
        <?php if ($qr_url): ?>
          <div style="display:flex;justify-content:flex-start;margin-bottom:12px;">
            <img src="<?= esc_url($qr_url) ?>" alt="2FA QR-code" style="width:220px;height:220px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:8px;">
          </div>
        <?php endif; ?>
        <div style="font-size:12px;color:#475569;margin-bottom:8px;">Voeg deze sleutel toe in je authenticator-app:</div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-family:monospace;font-size:13px;word-break:break-all;margin-bottom:10px;"><?= esc_html($twofa_setup_secret) ?></div>
        <?php if ($otpauth_uri): ?>
          <details style="margin-bottom:12px;">
            <summary style="cursor:pointer;font-size:12px;color:#64748b;">Toon geavanceerde app-link (otpauth)</summary>
            <div style="margin-top:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-family:monospace;font-size:12px;word-break:break-all;"><?= esc_html($otpauth_uri) ?></div>
          </details>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" action="<?= esc_url($admin_url) ?>">
        <input type="hidden" name="action" value="bp_update_account_2fa_mobile">
        <input type="hidden" name="bp_2fa_mode" value="enable">
        <?php wp_nonce_field('bp_update_account_2fa_mobile', 'bp_2fa_nonce'); ?>
        <div class="kb-account-form-grid">
          <div>
            <label class="kb-account-label">Code uit authenticator-app</label>
            <input type="text" name="bp_2fa_code" class="kb-account-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456" required>
          </div>
          <div>
            <label class="kb-account-label">Huidig wachtwoord (bevestiging)</label>
            <input type="password" name="bp_2fa_huidig_pw" class="kb-account-input" autocomplete="current-password" required>
          </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button type="submit" class="kb-account-btn">Mobiele 2FA activeren</button>
        </div>
      </form>
      <form method="post" action="<?= esc_url($admin_url) ?>" style="margin-top:10px;">
        <input type="hidden" name="action" value="bp_update_account_2fa_mobile">
        <input type="hidden" name="bp_2fa_mode" value="regen">
        <?php wp_nonce_field('bp_update_account_2fa_mobile', 'bp_2fa_nonce'); ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
          <div style="min-width:240px;flex:1;">
            <label class="kb-account-label">Huidig wachtwoord (bevestiging)</label>
            <input type="password" name="bp_2fa_huidig_pw" class="kb-account-input" autocomplete="current-password" required>
          </div>
          <button type="submit" class="kb-account-btn" style="background:#475569;">Nieuwe sleutel genereren</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Wachtwoord wijzigen -->
  <div class="kb-account-section">
    <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 16px;">Wachtwoord wijzigen</h3>
    <form method="post" action="<?= esc_url($admin_url) ?>">
      <input type="hidden" name="action" value="bp_update_account_pw">
      <?php wp_nonce_field('bp_update_account_pw', 'bp_pw_nonce'); ?>

      <div style="display:grid;grid-template-columns:1fr;gap:12px;max-width:360px;margin-bottom:14px;">
        <div>
          <label class="kb-account-label">Huidig wachtwoord</label>
          <input type="password" name="bp_pw_huidig" autocomplete="current-password" class="kb-account-input">
        </div>
        <div>
          <label class="kb-account-label">Nieuw wachtwoord <span style="font-weight:400;">(min. 8 tekens)</span></label>
          <input type="password" name="bp_pw_nieuw" autocomplete="new-password" class="kb-account-input">
        </div>
        <div>
          <label class="kb-account-label">Bevestig nieuw wachtwoord</label>
          <input type="password" name="bp_pw_bevestig" autocomplete="new-password" class="kb-account-input">
        </div>
      </div>

      <button type="submit" class="kb-account-btn">Wachtwoord wijzigen</button>
    </form>
  </div>

  <?php if ($is_begeleider || $is_leidinggevende || $is_admin): ?>
  <!-- Organisatie-info (voor medewerkers) -->
  <div class="kb-account-section" style="margin-bottom:0;">
    <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 16px;">Organisatie</h3>
    <div class="kb-account-form-grid" style="margin-bottom:0;">
      <div>
        <div class="kb-account-label">Rol</div>
        <div style="font-size:13px;color:#1e293b;"><?= esc_html($rol_label) ?></div>
      </div>
      <?php if ($is_begeleider):
        $leid_id = (int) get_user_meta($uid, 'kb_leidinggevende_id', true);
        $leid    = $leid_id > 0 ? get_user_by('id', $leid_id) : null;
      ?>
      <div>
        <div class="kb-account-label">Leidinggevende</div>
        <div style="font-size:13px;color:#1e293b;"><?= $leid ? esc_html($leid->display_name) : '<span style="color:#94a3b8;">Niet gekoppeld</span>' ?></div>
      </div>
      <?php if ($leid): ?>
      <div>
        <div class="kb-account-label">E-mail leidinggevende</div>
        <div style="font-size:13px;"><a href="mailto:<?= esc_attr($leid->user_email) ?>" style="color:#003082;"><?= esc_html($leid->user_email) ?></a></div>
      </div>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($is_leidinggevende || $is_admin):
        global $wpdb;
        $team_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'kb_leidinggevende_id' AND meta_value = %s",
            (string) $uid
        ));
      ?>
      <div>
        <div class="kb-account-label">Begeleiders in team</div>
        <div style="font-size:13px;color:#1e293b;"><?= $team_count ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
