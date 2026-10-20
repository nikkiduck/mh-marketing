<?php
/**
 * portal/market_post.php — Market Snapshot (Templates > Instagram Templates,
 * 2026-10-18): an Instagram-ready PNG of one market's monthly figures, from
 * the Market Report's data, in the Mont Haus look. Seven cards, one per
 * market; a post or a story; dark or light; the watermark, the logo's place
 * and an optional stock photo, or one of the agent's own, behind the figures. The body is
 * inc/market_post_body.php, shared with the admin side's market_snapshot.php.
 * ?market=aspen&month=2026-09 preselects (the report's links). See inc/market.php.
 */
require_once __DIR__ . '/../inc/market.php';

$ctx = portal_context($conn);
social_gate();

$months = market_months();
$month  = market_pick_month((string)($_GET['month'] ?? ''));
$data   = $month !== '' ? market_load($month) : null;
if ($data) market_attach_feed($data);   // the feed towns join the Location list
$market = market_card_key((string)($_GET['market'] ?? ''), $data ?? []);   // a card key, or a market id from the report's links
$stock  = social_stock();
// The person's own photos, shared with the Instagram tool (2026-10-19): the same uploads, drop box and x.
$uploads      = social_uploads($ctx);
$upload_url   = portal_url($ctx, '/portal/social_upload.php');
$delete_url   = portal_url($ctx, '/portal/social_delete.php');
$upload_thumb = fn(string $id) => social_upload_url($ctx, $id, 400);
$board_names = [];   // the picker's groups, from the month's boards.json
foreach (($data['boards'] ?? []) as $b) $board_names[(string)$b['market']] = (string)$b['name'];
$csrf   = (string)($_SESSION['csrf_token'] ?? '');
$render_url = portal_url($ctx, '/portal/market_render.php');
$export_url = portal_url($ctx, '/portal/market_export.php');
$report_url = portal_url($ctx, '/portal/market.php', $month !== '' ? ['m' => $month] : []);

portal_header($ctx, 'Market Snapshot', 'social');
?>
  <p class="pt-soon">From the Market Report.</p>
  <h1 class="pt-h1">Market Snapshot</h1>
  <p class="pt-lead">One place's month on a card: the median sale price, closed sales, days on market and months of supply. Pick the place and the look, then download.</p>
  <p class="tp-back"><a href="<?= ph(portal_url($ctx, '/portal/templates.php')) ?>">‹ All templates</a></p>
<?php if ($month === ''): ?>
  <div class="pt-flash err">No market data is on file yet. The snapshot cards appear here once a month's figures are published.</div>
<?php else:
    include __DIR__ . '/../inc/market_post_body.php';
endif;
portal_footer();
