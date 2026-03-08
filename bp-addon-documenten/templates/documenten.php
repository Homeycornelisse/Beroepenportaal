<?php
defined('ABSPATH') || exit;
?>
<div id="bp-docs-app" class="bp-docs-app">
  <div class="bp-docs-window">
    <div class="bp-docs-titlebar">
      <div class="bp-docs-traffic">
        <span class="dot red"></span>
        <span class="dot yellow"></span>
        <span class="dot green"></span>
      </div>
      <div class="bp-docs-title">Documentenkluis</div>
      <div class="bp-docs-actions">
        <button type="button" class="bp-docs-btn" id="bp-docs-unlock">Ontgrendel</button>
        <button type="button" class="bp-docs-btn primary" id="bp-docs-upload-btn" disabled>Upload</button>
      </div>
    </div>

    <div class="bp-docs-toolbar">
      <div class="bp-docs-toolbar-left">
        <label for="bp-docs-client-select">Cliënt</label>
        <select id="bp-docs-client-select"></select>
        <button type="button" class="bp-docs-btn" id="bp-docs-new-folder" disabled>Nieuwe map</button>
      </div>
      <div class="bp-docs-toolbar-right">
        <label for="bp-docs-sort">Sorteren</label>
        <select id="bp-docs-sort">
          <option value="date_desc">Nieuwste eerst</option>
          <option value="date_asc">Oudste eerst</option>
          <option value="name_asc">Naam A-Z</option>
          <option value="name_desc">Naam Z-A</option>
        </select>
      </div>
    </div>

    <div class="bp-docs-body">
      <aside class="bp-docs-sidebar">
        <div class="bp-docs-sidebar-head">Mappen</div>
        <div class="bp-docs-folders" id="bp-docs-folders"></div>
      </aside>

      <main class="bp-docs-content">
        <div class="bp-docs-alert" id="bp-docs-alert" hidden></div>
        <input type="file" id="bp-docs-upload" hidden>
        <table class="bp-docs-table">
          <thead>
            <tr>
              <th>Naam</th>
              <th>Grootte</th>
              <th>Datum</th>
              <th>Acties</th>
            </tr>
          </thead>
          <tbody id="bp-docs-table-body">
            <tr><td colspan="4" class="empty">Geen documenten beschikbaar.</td></tr>
          </tbody>
        </table>
      </main>
    </div>
  </div>
</div>