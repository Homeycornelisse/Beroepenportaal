<?php defined('ABSPATH') || exit;
$logout_url  = wp_logout_url(get_permalink(get_page_by_path('login-portaal')) ?: home_url());
$cv_page     = get_page_by_path('cv');
$logboek_page= get_page_by_path('logboek');
$uitleg_page = get_page_by_path('hoe-werkt-het');
?>
<div class="kb-wrap" id="kb-portaal-root">

  <!-- Topbar -->
  <div class="kb-topbar kb-no-print">
    <div style="display:flex;align-items:center;gap:10px;">
      <span style="font-size:20px;">🎓</span>
      <span style="font-weight:700;color:var(--kb-blue);">Beroepen Portaal</span>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <?php if ($cv_page): ?>
        <a href="<?= esc_url(get_permalink($cv_page)) ?>" class="kb-btn kb-btn-ghost kb-btn-sm">📄 Mijn CV</a>
      <?php endif; ?>
      <?php if ($logboek_page): ?>
        <a href="<?= esc_url(get_permalink($logboek_page)) ?>" class="kb-btn kb-btn-ghost kb-btn-sm">📋 Logboek</a>
      <?php endif; ?>
      <span style="font-size:13px;color:#64748b;"><?= esc_html(wp_get_current_user()->display_name) ?></span>
      <a href="<?= esc_url($logout_url) ?>" class="kb-btn kb-btn-ghost kb-btn-sm">Uitloggen</a>
    </div>
  </div>

  <!-- Hero -->
  <div class="kb-hero">
    <div>
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;opacity:.6;">Welkom terug</div>
      <div class="kb-hero-title"><?= esc_html(wp_get_current_user()->display_name) ?></div>
      <div class="kb-hero-sub">Jouw beroepsselecties worden automatisch opgeslagen</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="text" id="kb-zoek" class="kb-search kb-no-print" placeholder="Zoek beroep of sector…">
      <button id="kb-filter-btn" class="kb-btn kb-btn-ghost kb-no-print">★ Alleen aangevinkt</button>
      <button onclick="window.print()" class="kb-btn kb-btn-orange kb-no-print" title="Exporteer alleen aangevinkte beroepen als PDF">🖨️ PDF export</button>
    </div>
  </div>

  <!-- Print header (verborgen op scherm, verschijnt in PDF) -->
  <div class="kb-portaal-print-header">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #003082;padding-bottom:12px;margin-bottom:16px;">
	      <div>
	        <div style="font-size:18px;font-weight:800;color:#003082;"><?= esc_html(bp_core_get_org_name('Beroepen Portaal')) ?></div>
        <div style="font-size:10px;color:#64748b;margin-top:2px;">Beroepsoriëntatie selectie</div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:13px;font-weight:700;color:#003082;"><?= esc_html(wp_get_current_user()->display_name) ?></div>
        <div style="font-size:10px;color:#64748b;">Exportdatum: <?= date('d-m-Y') ?></div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="kb-filters kb-no-print">
    <select id="kb-sector-filter" class="kb-select"><option value="">Alle sectoren</option></select>
    <select id="kb-niveau-filter" class="kb-select">
      <option value="">Alle niveaus</option>
      <option value="Basis">Basisvakmanschap</option>
      <option value="Middelbaar">MBO</option>
      <option value="Hoger">HBO/WO</option>
    </select>
    <div id="kb-teller" class="kb-teller"></div>
  </div>

  <!-- Beroepen grid -->
  <div id="kb-grid" class="kb-grid">
    <div style="grid-column:1/-1;text-align:center;padding:48px;color:#94a3b8;">Beroepen laden…</div>
  </div>

</div>

<style>
.kb-portaal-print-header { display: none; }
@media print {
  .kb-portaal-print-header { display: block !important; }
  .kb-topbar, .kb-hero > div:last-child, .kb-filters, .kb-no-print,
  nav, header, footer, #wpadminbar { display: none !important; }
  /* Verberg sector-labels van lege groepen */
  .kb-hero { border-radius: 0 !important; background: none !important; color: var(--kb-text) !important; padding: 0 !important; }
  .kb-hero-title { color: var(--kb-blue) !important; font-size: 18px !important; }
  .kb-hero-sub { opacity: 1 !important; color: #64748b !important; }
  .kb-beroep-card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; break-inside: avoid; }
  .kb-notitie { border: none !important; background: transparent !important; resize: none !important; }
  @page { size: A4 portrait; margin: 12mm 14mm; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
