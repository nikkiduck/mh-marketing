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
<title>Pipeline Payloads | Mont Haus Marketing</title>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
<link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
<link rel="stylesheet" href="/assets/css/style-preset.css">
<style>
  /* The shared header (inc/_nav.php), as on every admin page (Nikki, 2026-11-12). */
  .layout-extended .pc-container { margin-top:70px; padding-top:24px; top:0; }
  .pc-header    { padding:0; left:0; top:0; min-height:70px; }
  .mh-header-inner { width:100%; padding:0 15px; display:flex; align-items:center; justify-content:space-between; height:70px; }
  .mh-logo img { height:50px; display:block; }
  .mh-nav { display:flex; align-items:center; gap:4px; }
  .mh-nav-link { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; font-size:.8rem; font-weight:500;
                 color:#eef1f5; text-decoration:none; border-radius:6px; white-space:nowrap; transition:color .2s, background .2s; }
  .mh-nav-link:hover  { color:#fff; background:rgba(174,187,204,.25); }
  .mh-nav-link.active { color:#fff; background:rgba(174,187,204,.45); }
  .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }
  /* The page's own rules sit under .pe-page so they do not leak into the shared header. */
  .hdr-actions { display:flex; gap:8px; flex-wrap:wrap; }
  .wrap { max-width:1000px; margin:32px auto 60px; padding:0 20px; }
  .mk-page-header { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:22px; }
  .pe-page, .pe-page * { box-sizing:border-box; }
  .pe-page { font-family:'Public Sans',system-ui,sans-serif; color:#111; font-size:14px; }
  .pe-page .hint { font-size:13px; color:#6b7280; }
  .pe-page .ev { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:14px 16px; margin-bottom:12px; }
  .pe-page .meta { font-size:12px; color:#6b7280; margin-bottom:8px; }
  .pe-page .st { font-weight:700; text-transform:uppercase; font-size:11px; }
  .pe-page .st.parsed { color:#15803d; } .pe-page .st.error { color:#b91c1c; } .pe-page .st.unparsed { color:#b45309; }
  .pe-page table { border-collapse:collapse; font-size:12px; margin:6px 0 10px; width:100%; }
  .pe-page td, .pe-page th { text-align:left; padding:4px 8px; border-bottom:1px solid #f3f4f6; }
  .pe-page th { color:#6b7280; }
  .pe-page details pre { background:#f9fafb; padding:10px; font-size:11px; overflow:auto; max-height:360px; margin:6px 0 0; }
  .pe-page .tag { font-size:10px; font-weight:700; padding:1px 6px; border-radius:8px; }
  .pe-page .tag.x { background:#fee2e2; color:#991b1b; } .pe-page .tag.c { background:#fef3c7; color:#92400e; } .pe-page .tag.r { background:#dcfce7; color:#166534; }
</style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
<div class="wrap pe-page">

  <div class="mk-page-header">
    <div>
      <h1>Pipeline Payloads</h1>
      <p class="hint" style="margin:4px 0 0;">The last 50 Paperless Pipeline webhook bodies, raw. Read-only.</p>
    </div>
    <div class="hdr-actions">
      <a class="btn btn-outline btn-sm" href="pipeline_review.php"><i class="ti ti-list-check"></i> Pipeline Review</a>
      <a class="btn btn-outline btn-sm" href="hot_sheet_preview.php"><i class="ti ti-mail"></i> Hot Sheets</a>
      <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>
    </div>
  </div>

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
</div><!-- /.pc-container -->
</body>
</html>
