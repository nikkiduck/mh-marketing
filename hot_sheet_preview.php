<?php
/**
 * hot_sheet_preview.php — the Hot Sheet emails exactly as they would go out today.
 *
 *   /hot_sheet_preview.php                   every area: what each would carry, and whether it would be sent
 *   /hot_sheet_preview.php?area=vail-valley  that area's email, as HTML
 *
 * Admin only. Renders the real email from the same build_hs_data() the send
 * cron uses, so what you see is what would be sent. Sends nothing.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_login();
require_role('admin');

require_once __DIR__ . '/inc/hs_data.php';
require_once __DIR__ . '/inc/hs_template.php';

$chk = $conn->query("SHOW TABLES LIKE 'hs_manual_listings'");
if (!$chk || !$chk->fetch_row()) {
    http_response_code(503);
    exit('Hot Sheets is not set up yet: run sql/hot_sheets_v1.sql and sql/hot_sheets_v2.sql.');
}
$chk = $conn->query("SHOW COLUMNS FROM hs_subscribers LIKE 'areas'");
$migrated = $chk && $chk->fetch_row();

$data  = build_hs_data($conn);
$conn->close();
// ── Nothing below this line may touch the database. ─────────────────────────

$areas   = hs_all_areas($data);
$orphans = hs_rows_without_area($data);
$area    = (string)($_GET['area'] ?? '');

if ($area !== '') {
    if (!isset($areas[$area])) { http_response_code(404); exit('No such area. Areas: ' . implode(', ', array_keys($areas))); }
    // The unsubscribe link points nowhere real in a preview.
    echo render_hot_sheet_email($areas[$area], SITE_URL . '/unsubscribe.php?t=preview');
    exit;
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
$allowed = array_values(array_filter(array_map('trim', explode(',', defined('HOT_SHEET_ALLOWED_RECIPIENTS') ? HOT_SHEET_ALLOWED_RECIPIENTS : ''))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Hot Sheets | Mont Haus Marketing</title>
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
    .hdr-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .wrap { max-width:1100px; margin:32px auto 60px; padding:0 20px; }
    .mk-page-header { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .card { background:#fff; border-radius:6px; padding:22px 26px; margin-bottom:18px; }
    .card-title { font-size:14px; font-weight:700; margin:0 0 16px; }
    .sub-table { width:100%; border-collapse:collapse; }
    .sub-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; padding:8px 10px; }
    .sub-table td { padding:10px; border-top:1px solid #f1ece0; vertical-align:middle; font-size:14px; }
    .pill { display:inline-block; font-size:11px; padding:2px 9px; border-radius:10px; white-space:nowrap; }
    .pill.on  { background:#f0fdf4; color:#15803d; }
    .pill.off { background:#f3f4f6; color:#6b7280; }
    .btn-xs { padding:4px 10px; font-size:11px; line-height:1.3; white-space:nowrap; }
    .note { background:#fbf7ec; border:1px solid #e3dccb; padding:12px 14px; font-size:13px; color:#5c4a28; margin-bottom:18px; }
    .warn { background:#fff7ed; border:1px solid #fed7aa; padding:12px 14px; font-size:13px; color:#9a3412; margin-bottom:18px; }
    .hint { font-size:12px; color:#9ca3af; }
    .n { font-variant-numeric: tabular-nums; }
    @media (max-width:820px) { .sub-table thead { display:none; } .sub-table td { display:block; border:0; padding:3px 0; }
                               .sub-table tr { display:block; border-top:1px solid #f1ece0; padding:12px 0; } }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <div class="mk-page-header">
      <div>
        <h1>Hot Sheets</h1>
        <p class="hint" style="margin:4px 0 0;">One email per area. Latest Updates since <?= e($data['since_label']) ?>. An area with nothing to show is not sent.</p>
      </div>
      <div class="hdr-actions">
        <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>
        <a class="btn btn-outline btn-sm" href="subscribers.php"><i class="ti ti-mail"></i> Subscribers</a>
        <a class="btn btn-outline btn-sm" href="pipeline_review.php"><i class="ti ti-list-check"></i> Pipeline Review</a>
      </div>
    </div>

    <?php if ($allowed): ?>
      <div class="note">
        <strong>Test mode.</strong> The Hot Sheets only go to <?= e(implode(', ', $allowed)) ?>. Clear
        <code>HOT_SHEET_ALLOWED_RECIPIENTS</code> in <code>inc/config.php</code> when everyone should receive them.
      </div>
    <?php endif; ?>
    <?php if (!$migrated): ?>
      <div class="warn"><strong>sql/hot_sheets_v4_areas.sql has not been run.</strong> The previews below work, but the send
        cron refuses to run and subscribers cannot choose areas until it has.</div>
    <?php endif; ?>
    <?php if ($orphans): ?>
      <div class="warn">
        <strong><?= count($orphans) ?> entr<?= count($orphans) === 1 ? 'y is' : 'ies are' ?> in no area</strong>, so in no email:
        <?= e(implode('; ', array_map(fn($r) => $r['address'], $orphans))) ?>.
        Set the area under Recently reviewed on <a href="pipeline_review.php">Pipeline Review</a>.
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-title">Today's emails</div>
      <table class="sub-table">
        <thead><tr><th>Area</th><th>Updates</th><th>MLS</th><th>Pocket</th><th>Buyer's Rep</th><th>Rentals</th><th>Today</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($areas as $k => $a): ?>
          <tr>
            <td style="font-weight:600;"><?= e($a['area_name']) ?></td>
            <td class="n"><?= count($a['updates']) ?></td>
            <td class="n"><?= count($a['mls_listings']) ?></td>
            <td class="n"><?= count($a['pocket_listings']) ?></td>
            <td class="n"><?= count($a['buyer_rep']) ?></td>
            <td class="n"><?= count($a['rentals']) ?></td>
            <td><span class="pill <?= $a['has_content'] ? 'on' : 'off' ?>"><?= $a['has_content'] ? 'Would be sent' : 'Nothing to show' ?></span></td>
            <td style="text-align:right;"><a class="btn btn-outline btn-xs" href="hot_sheet_preview.php?area=<?= e($k) ?>" target="_blank">Preview</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin:14px 0 0;">
        A listing's area is the website's Communities area its town belongs to, else its board's. Pocket listings
        and buyer reps get theirs when promoted on Pipeline Review.
      </p>
    </div>

  </div>
</div>
</body>
</html>
