<?php
/**
 * pipeline_events.php — the last 50 Paperless Pipeline webhook bodies, raw.
 *
 * Read-only diagnostic, admin only. Shows each payload pretty-printed with its
 * agents broken out, so you can see which agents are really on a deal versus
 * auto-attached (managing broker, TC). Ported from the hub's page of the same name.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_login();
require_role('admin');
require_once __DIR__ . '/inc/pipeline.php';

$chk = $conn->query("SHOW TABLES LIKE 'hs_pipeline_events'");
if (!$chk || !$chk->fetch_row()) { http_response_code(503); exit('Run sql/hot_sheets_v3_pipeline.sql first.'); }

$excluded    = pl_email_list('PIPELINE_EXCLUDED_AGENT_EMAILS');
$conditional = pl_email_list('PIPELINE_CONDITIONAL_AGENT_EMAILS');
[$by_email] = pl_roster($conn);
$events = $conn->query("SELECT * FROM hs_pipeline_events ORDER BY id DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
$conn->close();

function h($v): string { return htmlspecialchars((string)$v); }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pipeline Payloads | Mont Haus Hot Sheet</title>
<style>
  body { margin:0; background:#f6f7f9; font-family:system-ui,sans-serif; color:#111; }
  .topbar { background:#1a1a1a; color:#fff; padding:12px 20px; display:flex; gap:18px; flex-wrap:wrap; align-items:center; }
  .topbar .title { font-weight:700; font-size:14px; margin-right:auto; }
  .topbar a { color:#d1d5db; text-decoration:none; font-size:13px; }
  .wrap { max-width:1000px; margin:0 auto; padding:24px 20px 60px; }
  .ev { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:14px 16px; margin-bottom:12px; }
  .meta { font-size:12px; color:#6b7280; margin-bottom:8px; }
  .st { font-weight:700; text-transform:uppercase; font-size:11px; }
  .st.parsed { color:#15803d; } .st.error { color:#b91c1c; } .st.unparsed { color:#b45309; }
  table { border-collapse:collapse; font-size:12px; margin:6px 0 10px; width:100%; }
  td, th { text-align:left; padding:4px 8px; border-bottom:1px solid #f3f4f6; }
  th { color:#6b7280; }
  details pre { background:#f9fafb; padding:10px; font-size:11px; overflow:auto; max-height:360px; margin:6px 0 0; }
  .tag { font-size:10px; font-weight:700; padding:1px 6px; border-radius:8px; }
  .tag.x { background:#fee2e2; color:#991b1b; } .tag.c { background:#fef3c7; color:#92400e; } .tag.r { background:#dcfce7; color:#166534; }
</style>
</head>
<body>
<div class="topbar">
  <span class="title">Mont Haus Hot Sheet: Pipeline Payloads</span>
  <a href="pipeline_review.php">Review queue</a>
  <a href="index.php">Roster</a>
</div>
<div class="wrap">
<?php if (!$events): ?><p style="color:#9ca3af;">No events received yet.</p><?php endif; ?>
<?php foreach ($events as $ev):
    $p  = json_decode($ev['payload'], true) ?: [];
    $ag = pl_get($p, ['Agents (JSON)', 'agents_json', 'Agents', 'agents'], null);
    $ag = is_array($ag) ? $ag : (json_decode((string)$ag, true) ?: []);
?>
  <div class="ev">
    <div class="meta">#<?= (int)$ev['id'] ?> · received <?= h($ev['received_at']) ?>
      · <span class="st <?= h($ev['parse_status']) ?>"><?= h($ev['parse_status']) ?></span>
      <?php if ($ev['hub_id']): ?> · imported from hub #<?= (int)$ev['hub_id'] ?><?php endif; ?></div>
    <div><strong><?= h(pl_get($p, ['Transaction Name', 'transaction_name'], '(no name)')) ?></strong>
      · <?= h(pl_get($p, ['Transaction Status Category', 'transaction_status_category'], '')) ?>
      · <?= h(pl_get($p, ['Transaction Side (Listing, Buying, Listing & Buying)', 'Transaction Side'], '')) ?></div>
    <?php if ($ev['parse_notes']): ?><div class="meta" style="margin-top:4px;"><?= h($ev['parse_notes']) ?></div><?php endif; ?>
    <?php if ($ag): ?>
    <table>
      <tr><th>#</th><th>Name</th><th>Email</th><th>Active</th><th>Assistant</th><th>Treated as</th></tr>
      <?php foreach (array_values($ag) as $i => $a): if (!is_array($a)) continue;
          $em = strtolower(trim((string)($a['email'] ?? ''))); ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= h(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))) ?></td>
        <td><?= h($em) ?></td>
        <td><?= isset($a['is_active']) ? ($a['is_active'] ? 'yes' : 'no') : '' ?></td>
        <td><?= !empty($a['is_assistant']) ? 'yes' : '' ?></td>
        <td><?php if (in_array($em, $excluded, true)): ?><span class="tag x">excluded (admin / TC)</span>
            <?php elseif (in_array($em, $conditional, true)): ?><span class="tag c">conditional</span>
            <?php elseif (isset($by_email[$em])): ?><span class="tag r">roster: <?= h($by_email[$em]) ?></span>
            <?php else: ?>not on roster<?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
    <details><summary style="font-size:12px;cursor:pointer;">Raw payload</summary>
      <pre><?= h(json_encode($p ?: $ev['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></details>
  </div>
<?php endforeach; ?>
</div>
</body>
</html>
