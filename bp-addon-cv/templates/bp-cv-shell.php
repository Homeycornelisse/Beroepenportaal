<?php
use BP_CV\Shell;
use BP_CV\Util;
use BP_CV\Shortcodes;

defined('ABSPATH') || exit;

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class('bp-portal-shell'); ?>>

<?php wp_body_open(); ?>

<header class="bp-portal-header">
  <div class="bp-portal-header-inner">
    <div class="bp-portal-brand">
      <a class="bp-portal-logo" href="<?php echo esc_url(home_url('/')); ?>">
        <span class="bp-portal-logo-mark">🎓</span>
        <span class="bp-portal-logo-text"><?php echo esc_html(Util::get_org_name()); ?></span>
      </a>
      <div class="bp-portal-tagline">Re-integratie Platform</div>
    </div>

    <nav class="bp-portal-nav" aria-label="Hoofdmenu">
      <?php foreach (Shell::nav_items() as $item): ?>
        <a class="bp-portal-nav-item" href="<?php echo esc_url($item['url']); ?>">
          <span class="bp-portal-nav-ico"><?php echo esc_html($item['icon']); ?></span>
          <span><?php echo esc_html($item['label']); ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="bp-portal-user">
      <?php if (is_user_logged_in()): ?>
        <span class="bp-portal-user-pill"><?php echo esc_html(wp_get_current_user()->user_login); ?></span>
        <a class="bp-portal-logout" href="<?php echo esc_url(Util::get_logout_url()); ?>">Uitloggen</a>
      <?php else: ?>
        <a class="bp-portal-logout" href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">Inloggen</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main class="bp-portal-main">
  <div class="bp-portal-container">
    <?php
      // Render via shortcode handler (includes login + rechten check).
      echo Shortcodes::render_cv(['shell' => '0']);
    ?>
  </div>
</main>

<footer class="bp-portal-footer">
  <div class="bp-portal-footer-inner">
    <div class="bp-foot-col bp-foot-brand">
      <div class="bp-foot-title"><?php echo esc_html(Util::get_org_name()); ?></div>
      <div class="bp-foot-text">Een professioneel platform voor 2e spoor re-integratie. Cliënten en jobcoaches op één plek.</div>
    </div>

    <div class="bp-foot-col">
      <div class="bp-foot-head">Voor cliënten</div>
      <a class="bp-foot-link" href="<?php echo esc_url(Util::get_portal_url('beroepenoverzicht')); ?>">Beroepenoverzicht</a>
      <a class="bp-foot-link" href="<?php echo esc_url(Util::get_portal_url('2e-spoor-logboek')); ?>">2e Spoor Logboek</a>
      <a class="bp-foot-link" href="<?php echo esc_url(get_permalink()); ?>">CV</a>
      <a class="bp-foot-link" href="<?php echo esc_url(wp_login_url()); ?>">Inloggen</a>
    </div>

    <div class="bp-foot-col">
      <div class="bp-foot-head">Voor begeleiders</div>
      <a class="bp-foot-link" href="<?php echo esc_url(Util::get_portal_url('dashboard')); ?>">Dashboard</a>
      <a class="bp-foot-link" href="<?php echo esc_url(Util::get_portal_url('hoe-werkt-het')); ?>">Hoe werkt het?</a>
      <a class="bp-foot-link" href="<?php echo esc_url(wp_login_url()); ?>">Inloggen</a>
    </div>

    <div class="bp-foot-col">
      <div class="bp-foot-head">Platform</div>
      <a class="bp-foot-link" href="<?php echo esc_url(Util::get_portal_url('uitleg')); ?>">Uitleg & handleiding</a>
      <a class="bp-foot-link" href="<?php echo esc_url(Util::get_portal_url('veilige-gegevensopslag')); ?>">Veilige gegevensopslag</a>
      <a class="bp-foot-link" href="<?php echo esc_url(Util::get_portal_url('gdpr-conform')); ?>">GDPR-conform</a>
    </div>
  </div>

  <div class="bp-portal-footer-bottom">
    <div class="bp-portal-footer-bottom-inner">
      <span>© <?php echo esc_html(date('Y')); ?> <?php echo esc_html(Util::get_org_name()); ?> — Alle rechten voorbehouden</span>
      <span class="bp-foot-right">Re-integratieplatform voor werkgevers & jobcoaches</span>
    </div>
  </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
