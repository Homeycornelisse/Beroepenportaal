<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
  wp_die('Geen rechten.');
}

global $wpdb;
$table = $wpdb->prefix . 'kb_audit_log';

$limit = 50;
$entries = $wpdb->get_results($wpdb->prepare(
  "SELECT id, object_type, object_id, actor_id, actie, oud, nieuw, aangemaakt FROM {$table} ORDER BY aangemaakt DESC LIMIT %d",
  $limit
));

function bp_core_audit_actor_label($actor_id): string {
  $actor_id = (int)$actor_id;
  if ($actor_id <= 0) return '—';
  $u = get_user_by('id', $actor_id);
  if (!$u) return 'User #' . $actor_id;
  return $u->display_name . ' (' . $u->user_email . ')';
}

function bp_core_audit_short($val): string {
  if ($val === null || $val === '') return '—';
  $s = (string)$val;
  $s = wp_strip_all_tags($s);
  if (mb_strlen($s) > 120) {
    $s = mb_substr($s, 0, 120) . '…';
  }
  return $s;
}

?>
<div class="wrap">
  <h1>Logboek (wijzigingen)</h1>
  <p>Hier zie je wie wat heeft aangepast. Dit helpt bij vragen zoals: “wie heeft dit veranderd?”</p>

  <table class="widefat striped" style="max-width:1200px;">
    <thead>
      <tr>
        <th style="width:160px;">Datum</th>
        <th style="width:240px;">Wie</th>
        <th style="width:160px;">Wat</th>
        <th style="width:160px;">Actie</th>
        <th>Oud</th>
        <th>Nieuw</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($entries)): ?>
        <tr><td colspan="6">Nog geen logregels.</td></tr>
      <?php else: foreach ($entries as $e): ?>
        <tr>
          <td><?php echo esc_html(date('d-m-Y H:i', strtotime($e->aangemaakt))); ?></td>
          <td><?php echo esc_html(bp_core_audit_actor_label($e->actor_id)); ?></td>
          <td>
            <code><?php echo esc_html($e->object_type); ?></code>
            <?php if (!empty($e->object_id)): ?>
              <span style="color:#64748b;">#<?php echo esc_html((string)$e->object_id); ?></span>
            <?php endif; ?>
          </td>
          <td><code><?php echo esc_html($e->actie); ?></code></td>
          <td style="font-size:12px;color:#334155;">
            <?php echo esc_html(bp_core_audit_short($e->oud)); ?>
          </td>
          <td style="font-size:12px;color:#334155;">
            <?php echo esc_html(bp_core_audit_short($e->nieuw)); ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <p style="margin-top:10px;color:#64748b;">Laatste <?php echo (int)$limit; ?> regels.</p>
</div>
