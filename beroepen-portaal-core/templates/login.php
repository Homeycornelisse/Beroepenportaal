<?php defined('ABSPATH') || exit;
$error = isset($error) ? (string)$error : '';
$message = isset($message) ? (string)$message : '';
$redirect = isset($redirect) ? (string)$redirect : '';
if ($redirect === '' && isset($_GET['redirect'])) {
  $redirect = esc_url_raw((string) wp_unslash($_GET['redirect']));
}
$twofa_method = isset($twofa_method) ? sanitize_key((string)$twofa_method) : '';
$login_back = '';
if (is_singular() && get_the_ID()) {
  $login_back = (string) get_permalink((int) get_the_ID());
}
if ($login_back === '') {
  $login_back = home_url('/');
}
?>
<div class="kb-wrap kb-login-wrap">
  <div class="kb-login-box">
    <div class="kb-login-logo">
      <span style="font-size:36px;">🎓</span>
      <div class="kb-login-logo-text">Beroepen Portaal</div>
      <div style="font-size:13px;color:#94a3b8;margin-top:2px;">Jobcoach platform</div>
    </div>

    <?php if ($error): ?>
      <div class="kb-notice kb-notice-error" style="margin-bottom:16px;">⚠️ <?= esc_html($error) ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
      <div class="kb-notice kb-notice-ok" style="margin-bottom:16px;"><?= esc_html($message) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <?php wp_nonce_field('kb_login', 'kb_login_nonce'); ?>
      <input type="hidden" name="kb_login_back" value="<?= esc_attr($login_back) ?>">
      <?php if ($redirect): ?><input type="hidden" name="redirect_to" value="<?= esc_attr($redirect) ?>"><?php endif; ?>
      <?php if ($twofa_method !== ''): ?>
        <div class="kb-form-group">
          <label class="kb-field-label" for="kb-2fa-code"><?= $twofa_method === 'totp' ? 'Code uit authenticator-app (6 cijfers)' : 'Verificatiecode (6 cijfers)' ?></label>
          <input type="text" id="kb-2fa-code" name="kb_2fa_code" class="kb-login-input"
            value="" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456" required autofocus>
        </div>
        <button type="submit" class="kb-btn kb-btn-primary" style="width:100%;justify-content:center;padding:13px;margin-top:14px;">
          Verifiëren →
        </button>
      <?php else: ?>
        <div class="kb-form-group">
          <label class="kb-field-label" for="kb-email">E-mailadres</label>
          <input type="email" id="kb-email" name="email" class="kb-login-input"
            value="<?= esc_attr($_POST['email'] ?? '') ?>" placeholder="naam@voorbeeld.nl" required autofocus>
        </div>
        <div class="kb-form-group" style="margin-top:14px;">
          <label class="kb-field-label" for="kb-pw">Wachtwoord</label>
          <input type="password" id="kb-pw" name="wachtwoord" class="kb-login-input" placeholder="••••••••" required>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 20px;">
          <label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer;">
            <input type="checkbox" name="onthouden" value="1"> Onthoud mij
          </label>
          <a href="<?= esc_url(wp_lostpassword_url()) ?>" style="font-size:12px;color:#94a3b8;">Wachtwoord vergeten?</a>
        </div>
        <button type="submit" class="kb-btn kb-btn-primary" style="width:100%;justify-content:center;padding:13px;">
          Inloggen →
        </button>
      <?php endif; ?>
    </form>

    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #f1f5f9;">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:10px;">Hoe werkt het?</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
        <div style="background:#f8fafc;border-radius:10px;padding:10px 12px;font-size:12px;text-align:center;">
          <div style="font-size:20px;margin-bottom:4px;">👤</div>
          <strong style="color:#374151;">Cliënt</strong><br>
          <span style="color:#94a3b8;">Beroepen verkennen</span>
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:10px 12px;font-size:12px;text-align:center;">
          <div style="font-size:20px;margin-bottom:4px;">👩‍💼</div>
          <strong style="color:#374151;">Jobcoach</strong><br>
          <span style="color:#94a3b8;">Cliënten begeleiden</span>
        </div>
      </div>
      <?php $uitleg = get_page_by_path('hoe-werkt-het'); ?>
      <?php if ($uitleg): ?>
      <div style="text-align:center;margin-top:12px;">
        <a href="<?= esc_url(get_permalink($uitleg)) ?>" style="font-size:12px;color:#64748b;">
          → Meer informatie over het platform
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
