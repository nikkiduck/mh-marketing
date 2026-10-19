<?php
/**
 * market_report.php — the Monthly Market Report on the admin side
 * (2026-10-18; a button on the Agent Roster header). The same body as the
 * portal's My Tools > Market Report (inc/market_report_body.php), under the
 * admin header, for admins whose login has no linked agent account (Nikki).
 * See inc/market_data.php and docs/HANDOFF-market-report.md.
 */
require_once __DIR__ . '/inc/market.php';
require_login();
require_role('admin');

$month = market_pick_month((string)($_GET['m'] ?? ''));
$data  = $month !== '' ? market_load($month) : null;
if ($data) market_attach_feed($data);   // the Communities towns from our own feed
$boot  = $data ? market_boot($data, ['post' => '/market_snapshot.php', 'self' => '/market_report.php']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Market Report | Mont Haus Marketing</title>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <style>
    .layout-extended .pc-container { margin-top:70px; padding-top:24px; top:0; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    .mh-header-inner { width:100%; padding:0 15px; display:flex; align-items:center; justify-content:space-between; height:70px; }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; font-size:.8rem; font-weight:500;
                   color:#75BDB6; text-decoration:none; border-radius:6px; white-space:nowrap; transition:color .2s, background .2s; }
    .mh-nav-link:hover  { color:#fff; background:rgba(117,189,182,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(117,189,182,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }
    .hdr-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .wrap { max-width:1100px; margin:32px auto 60px; padding:0 20px; }
    .mk-page-header { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .note { background:#fbf7ec; border:1px solid #e3dccb; padding:12px 14px; font-size:13px; color:#5c4a28; margin-bottom:18px; }
    /* The report draws its own title; the roster-style header carries only the way back. */
    .mk-page-header.slim { margin-bottom:6px; justify-content:flex-end; }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">
    <div class="mk-page-header slim">
      <div class="hdr-actions">
        <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>
      </div>
    </div>
    <?php if (!$data): ?>
      <div class="mk-page-header"><h1>Market Report</h1></div>
      <div class="note"><strong>No market data on file yet.</strong> The report appears once a month's figures are published to <code>inc/market_data/</code> (docs/HANDOFF-market-report.md).</div>
    <?php else: include __DIR__ . '/inc/market_report_body.php'; endif; ?>
  </div>
</div>
</body>
</html>
