<?php defined('ABSPATH') || exit; ?>
<div class="kb-wrap kb-uitleg-wrap">

  <!-- Hero -->
  <div class="kb-hero" style="text-align:center;flex-direction:column;align-items:center;padding:40px 28px;">
    <div style="font-size:48px;margin-bottom:12px;">🎓</div>
    <div style="font-size:28px;font-weight:800;">Beroepen Portaal</div>
    <div style="font-size:15px;opacity:.75;margin-top:8px;max-width:500px;">
      Dé tool voor jobcoaches en hun cliënten — van beroepsoriëntatie tot sollicitatiebrief.
    </div>
    <?php $login = get_page_by_path('login-portaal'); ?>
    <?php if ($login): ?>
    <a href="<?= esc_url(get_permalink($login)) ?>" class="kb-btn kb-btn-primary" style="margin-top:20px;padding:13px 28px;font-size:15px;">
      Inloggen op het platform →
    </a>
    <?php endif; ?>
  </div>

  <!-- Stappen -->
  <div style="margin:36px 0;">
    <div style="text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:20px;">Zo werkt het</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
      <?php
      $stappen = [
        ['🔑','Inloggen','De jobcoach maakt een account voor de cliënt en stuurt de inloggegevens.'],
        ['🔍','Beroepen verkennen','De cliënt bladert door 306 kansrijke beroepen, vinkt favorieten aan en schrijft notities.'],
        ['📄','CV uploaden','De cliënt uploadt zijn CV. Het systeem analyseert automatisch passende beroepen.'],
        ['✉️','Sollicitatiebrief','Plak een vacaturetekst — Claude schrijft een gepersonaliseerde brief op basis van het CV.'],
        ['👩‍💼','Begeleiding','De jobcoach bekijkt de selecties, voegt aantekeningen toe en exporteert een pdf-dossier.'],
      ];
      foreach ($stappen as $i => [$icon, $titel, $tekst]): ?>
      <div class="kb-card" style="text-align:center;padding:24px 20px;">
        <div style="font-size:32px;margin-bottom:10px;"><?= $icon ?></div>
        <div style="font-weight:700;color:var(--kb-blue);margin-bottom:6px;"><?= $titel ?></div>
        <div style="font-size:13px;color:#64748b;line-height:1.5;"><?= $tekst ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Voor cliënten & jobcoaches -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:36px;">
    <div class="kb-card" style="border-top:4px solid var(--kb-orange);">
      <div style="font-size:24px;margin-bottom:10px;">👤</div>
      <h3 style="color:var(--kb-blue);margin:0 0 12px;">Voor cliënten</h3>
      <ul style="list-style:none;padding:0;margin:0;font-size:14px;line-height:2;">
        <li>✅ 306 kansrijke beroepen (UWV 2025-2026)</li>
        <li>✅ Filteren op sector en niveau</li>
        <li>✅ Beroepen aanvinken en notities schrijven</li>
        <li>✅ CV uploaden en analyseren</li>
        <li>✅ AI-sollicitatiebrieven genereren</li>
        <li>✅ Altijd toegankelijk via je telefoon of laptop</li>
      </ul>
    </div>
    <div class="kb-card" style="border-top:4px solid var(--kb-purple);">
      <div style="font-size:24px;margin-bottom:10px;">👩‍💼</div>
      <h3 style="color:var(--kb-blue);margin:0 0 12px;">Voor jobcoaches</h3>
      <ul style="list-style:none;padding:0;margin:0;font-size:14px;line-height:2;">
        <li>✅ Alle cliënten in één overzicht</li>
        <li>✅ Beroepsselecties per cliënt bekijken</li>
        <li>✅ Passendheid beoordelen (sterren)</li>
        <li>✅ LKS-percentage en doelgroepfunctie vastleggen</li>
        <li>✅ Advies en vervolgstappen noteren</li>
        <li>✅ Een pdf-dossier exporteren met één klik</li>
      </ul>
    </div>
  </div>

  <!-- CTA -->
  <div style="text-align:center;background:linear-gradient(135deg,var(--kb-blue),var(--kb-mid));border-radius:20px;padding:36px;color:white;">
    <div style="font-size:22px;font-weight:800;margin-bottom:8px;">Klaar om te starten?</div>
    <div style="opacity:.75;margin-bottom:20px;">Log in met de inloggegevens die je jobcoach heeft gestuurd.</div>
    <?php if ($login): ?>
    <a href="<?= esc_url(get_permalink($login)) ?>" class="kb-btn" style="background:white;color:var(--kb-blue);padding:13px 28px;font-size:15px;">
      Inloggen →
    </a>
    <?php endif; ?>
  </div>

</div>
