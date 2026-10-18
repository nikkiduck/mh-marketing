<?php
/**
 * market_snapshot.php — the Market Snapshot builder on the admin side
 * (2026-10-18), for admins whose login has no linked agent account. The same
 * body as the portal's Templates > Market Snapshot (inc/market_post_body.php)
 * under the admin header; the preview and the PNG come from the portal's
 * endpoints, which admit an admin without an account (market_gate()).
 * ?market=aspen&month=2026-09 preselects. See inc/market.php.
 */
require_once __DIR__ . '/inc/market.php';
require_login();
require_role('admin');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$months = market_months();
$month  = market_pick_month((string)($_GET['month'] ?? ''));
$market = (string)($_GET['market'] ?? 'aspen');
$stock  = social_stock();
$csrf   = (string)$_SESSION['csrf_token'];
$render_url = '/portal/market_render.php';
$export_url = '/portal/market_export.php';
$report_url = '/market_report.php' . ($month !== '' ? '?m=' . rawurlencode($month) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Market Snapshot | Mont Haus Marketing</title>
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
    .hint { font-size:13px; color:#6b7280; margin:4px 0 0; max-width:60ch; }
    /* The builder reuses the portal's social.css, which expects portal.css's tokens. */
    .wrap { --ink:#0d0c0a; --gold:#c9a96a; --gold-deep:#8a6d36; --paper:#faf8f4; --card:#ffffff; --line:#e8e2d6; --text:#1c1a17; --muted:#6f6857;
            --serif:'Cormorant Garamond', Georgia, serif; --sans:'Jost', 'Helvetica Neue', Arial, sans-serif; }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">
    <div class="mk-page-header">
      <div>
        <h1>Market Snapshot</h1>
        <p class="hint">One market's month on an Instagram card, from the Market Report's figures, credited to its MLS. Agents have the same tool under Templates on their portal.</p>
      </div>
      <div class="hdr-actions">
        <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($report_url, ENT_QUOTES) ?>"><i class="ti ti-chart-bar"></i> Market Report</a>
        <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>
      </div>
    </div>
    <?php if ($month === ''): ?>
      <div class="note"><strong>No market data on file yet.</strong> The cards appear once a month's figures are published to <code>inc/market_data/</code>.</div>
    <?php else: include __DIR__ . '/inc/market_post_body.php'; endif; ?>
  </div>
</div>
</body>
</html>
