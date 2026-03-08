<?php
defined('ABSPATH') || exit;

$created = !empty($_GET['created']);
$error   = !empty($_GET['error']);
$bulk_done = !empty($_GET['bulk_done']);
$bulk_error = !empty($_GET['bulk_error']);
$transfer_done = !empty($_GET['transfer_done']);
$transfer_error = !empty($_GET['transfer_error']);

// Data
$clients = get_users(['role' => BP_Core_Roles::ROLE_CLIENT, 'orderby' => 'display_name', 'order' => 'ASC']);
$begeleiders = get_users(['role__in' => [BP_Core_Roles::ROLE_BEGELEIDER], 'orderby' => 'display_name', 'order' => 'ASC']);
$leidinggevenden = get_users(['role__in' => [BP_Core_Roles::ROLE_LEIDINGGEVENDE, 'administrator'], 'orderby' => 'display_name', 'order' => 'ASC']);

$leidinggevenden_only = get_users(['role' => BP_Core_Roles::ROLE_LEIDINGGEVENDE, 'orderby' => 'display_name', 'order' => 'ASC']);
?>

<div class="wrap">
  <h1>Gebruikers</h1>
  <p>Hier maak je cliënten, begeleiders en leidinggevenden aan. Cliënten kun je koppelen aan een begeleider.</p>

  <?php if ($created): ?>
    <div class="notice notice-success is-dismissible"><p>✅ Gebruiker aangemaakt.</p></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="notice notice-error is-dismissible"><p>❌ Oeps, er ging iets mis. Check of e-mail uniek is, en of alles is ingevuld.</p></div>
  <?php endif; ?>

  <?php if ($bulk_done): ?>
    <div class="notice notice-success is-dismissible"><p>✅ Bulk-overname uitgevoerd.</p></div>
  <?php endif; ?>
  <?php if ($bulk_error): ?>
    <div class="notice notice-error is-dismissible"><p>❌ Bulk-overname mislukt. Kies “van” en “naar”.</p></div>
  <?php endif; ?>

  <?php if ($transfer_done): ?>
    <div class="notice notice-success is-dismissible"><p>✅ Cliënten zijn overgezet naar de nieuwe begeleider.</p></div>
  <?php endif; ?>
  <?php if ($transfer_error): ?>
    <div class="notice notice-error is-dismissible"><p>❌ Overzetten mislukt. Kies “van” en “naar”.</p></div>
  <?php endif; ?>

  <h2>Nieuwe gebruiker</h2>
  <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;max-width:900px;">
    <?php wp_nonce_field('bp_core_create_user'); ?>
    <input type="hidden" name="action" value="bp_core_create_user">

    <table class="form-table" style="margin-top:0;">
      <tr>
        <th style="width:180px;"><label for="bp_name">Naam</label></th>
        <td><input type="text" id="bp_name" name="bp_name" class="regular-text" required></td>
      </tr>
      <tr>
        <th><label for="bp_email">E-mailadres</label></th>
        <td><input type="email" id="bp_email" name="bp_email" class="regular-text" required></td>
      </tr>
      <tr>
        <th><label for="bp_role">Rol</label></th>
        <td>
          <select id="bp_role" name="bp_role">
            <option value="<?= esc_attr(BP_Core_Roles::ROLE_CLIENT) ?>">Cliënt</option>
            <option value="<?= esc_attr(BP_Core_Roles::ROLE_BEGELEIDER) ?>">Begeleider</option>
            <option value="<?= esc_attr(BP_Core_Roles::ROLE_LEIDINGGEVENDE) ?>">Leidinggevende</option>
          </select>
          <p class="description">Tip: Admin blijft gewoon “administrator” via WordPress zelf.</p>
        </td>
      </tr>
      <tr>
        <th><label for="bp_pass">Wachtwoord</label></th>
        <td>
          <input type="text" id="bp_pass" name="bp_pass" class="regular-text" placeholder="(leeg = automatisch)" autocomplete="new-password">
        </td>
      </tr>
      <tr>
        <th><label for="bp_begeleider_id">Begeleider (voor cliënt)</label></th>
        <td>
          <select id="bp_begeleider_id" name="bp_begeleider_id">
            <option value="0">— Geen —</option>
            <?php foreach ($begeleiders as $b): ?>
              <option value="<?= (int)$b->ID ?>"><?= esc_html(bp_core_user_label($b)) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th><label for="bp_leidinggevende_id">Leidinggevende (optioneel)</label></th>
        <td>
          <select id="bp_leidinggevende_id" name="bp_leidinggevende_id">
            <option value="0">— Geen —</option>
            <?php foreach ($leidinggevenden as $l): ?>
              <option value="<?= (int)$l->ID ?>"><?= esc_html(bp_core_user_label($l)) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="description">Dit gebruiken we ook voor “team” functies later.</p>
        </td>
      </tr>
    </table>

    <p>
      <button class="button button-primary">Gebruiker aanmaken</button>
    </p>
  </form>

  <hr style="margin:22px 0;">

  <h2>Overnames</h2>
  <p>Handig als er iemand vertrekt, of als je cliënten wilt herverdelen.</p>

  <div style="display:flex;flex-wrap:wrap;gap:16px;">
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:16px;max-width:520px;flex:1;min-width:320px;">
      <?php wp_nonce_field('bp_core_bulk_transfer_leidinggevende'); ?>
      <input type="hidden" name="action" value="bp_core_bulk_transfer_leidinggevende">
      <h3 style="margin-top:0;">🔄 Bulk-overname leidinggevende</h3>
      <p class="description">Alle cliënten én begeleiders worden overgezet naar de gekozen leidinggevende.</p>

      <table class="form-table" style="margin-top:0;">
        <tr>
          <th style="width:140px;"><label for="bp_from_leid">Van</label></th>
          <td>
            <select id="bp_from_leid" name="bp_from_leid" required>
              <option value="">— Kies —</option>
              <?php foreach ($leidinggevenden as $l): ?>
                <option value="<?= (int)$l->ID ?>"><?= esc_html(bp_core_user_label($l)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><label for="bp_to_leid">Naar</label></th>
          <td>
            <select id="bp_to_leid" name="bp_to_leid" required>
              <option value="">— Kies —</option>
              <?php foreach ($leidinggevenden as $l): ?>
                <option value="<?= (int)$l->ID ?>"><?= esc_html(bp_core_user_label($l)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      </table>

      <p style="margin:0;"><button class="button button-primary">Alles overdragen</button></p>
    </form>

    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:16px;max-width:520px;flex:1;min-width:320px;">
      <?php wp_nonce_field('bp_core_transfer_clients_begeleider'); ?>
      <input type="hidden" name="action" value="bp_core_transfer_clients_begeleider">
      <h3 style="margin-top:0;">👥 Cliënten overzetten tussen begeleiders</h3>
      <p class="description">Alle cliënten met “vaste begeleider” worden omgezet. (Leidinggevende wordt meegezet als de nieuwe begeleider er één heeft.)</p>

      <table class="form-table" style="margin-top:0;">
        <tr>
          <th style="width:140px;"><label for="bp_from_begel">Van</label></th>
          <td>
            <select id="bp_from_begel" name="bp_from_begel" required>
              <option value="">— Kies —</option>
              <?php foreach ($begeleiders as $b): ?>
                <option value="<?= (int)$b->ID ?>"><?= esc_html(bp_core_user_label($b)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><label for="bp_to_begel">Naar</label></th>
          <td>
            <select id="bp_to_begel" name="bp_to_begel" required>
              <option value="">— Kies —</option>
              <?php foreach ($begeleiders as $b): ?>
                <option value="<?= (int)$b->ID ?>"><?= esc_html(bp_core_user_label($b)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      </table>

      <p style="margin:0;"><button class="button button-primary">Cliënten overzetten</button></p>
    </form>
  </div>

  <h2>Overzicht</h2>

  <h3>Cliënten (<?= count($clients) ?>)</h3>
  <table class="widefat striped" style="max-width:1100px;">
    <thead>
      <tr>
        <th>Naam</th>
        <th>E-mail</th>
        <th>Begeleider</th>
        <th>Leidinggevende</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($clients)): ?>
        <tr><td colspan="4">Nog geen cliënten.</td></tr>
      <?php else: foreach ($clients as $c):
        $bid = (int)get_user_meta($c->ID, 'kb_begeleider_id', true);
        $lid = (int)get_user_meta($c->ID, 'kb_leidinggevende_id', true);
        $b = $bid ? get_user_by('id', $bid) : null;
        $l = $lid ? get_user_by('id', $lid) : null;
      ?>
        <tr>
          <td><strong><?= esc_html($c->display_name) ?></strong></td>
          <td><?= esc_html($c->user_email) ?></td>
          <td><?= $b ? esc_html($b->display_name) : '—' ?></td>
          <td><?= $l ? esc_html($l->display_name) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <h3 style="margin-top:18px;">Begeleiders (<?= count($begeleiders) ?>)</h3>
  <table class="widefat striped" style="max-width:1100px;">
    <thead>
      <tr>
        <th>Naam</th>
        <th>E-mail</th>
        <th>Leidinggevende</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($begeleiders)): ?>
        <tr><td colspan="3">Nog geen begeleiders.</td></tr>
      <?php else: foreach ($begeleiders as $b):
        $lid = (int)get_user_meta($b->ID, 'kb_leidinggevende_id', true);
        $l = $lid ? get_user_by('id', $lid) : null;
      ?>
        <tr>
          <td><strong><?= esc_html($b->display_name) ?></strong></td>
          <td><?= esc_html($b->user_email) ?></td>
          <td><?= $l ? esc_html($l->display_name) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <h3 style="margin-top:18px;">Leidinggevenden (<?= count($leidinggevenden_only) ?>)</h3>
  <table class="widefat striped" style="max-width:1100px;">
    <thead>
      <tr>
        <th>Naam</th>
        <th>E-mail</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($leidinggevenden_only)): ?>
        <tr><td colspan="2">Nog geen leidinggevenden.</td></tr>
      <?php else: foreach ($leidinggevenden_only as $l): ?>
        <tr>
          <td><strong><?= esc_html($l->display_name) ?></strong></td>
          <td><?= esc_html($l->user_email) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

</div>
