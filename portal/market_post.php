<?php
/**
 * portal/market_post.php — Market Snapshot (Templates > Instagram Templates,
 * 2026-10-18): an Instagram-ready PNG of one market's monthly figures, from
 * the Market Report's data, in the Mont Haus look. Seven cards, one per
 * market; a post or a story; dark or light; the watermark, the logo's place
 * and an optional stock photo behind the figures. The body is
 * inc/market_post_body.php, shared with the admin side's market_snapshot.php.
 * ?market=aspen&month=2026-09 preselects (the report's links). See inc/market.php.
 */
require_once __DIR__ . '/../inc/market.php';

$ctx = portal_context($conn);
social_gate();

$months = market_months();
$month  = market_pick_month((string)($_GET['month'] ?? ''));
$market = (string)($_GET['market'] ?? 'aspen');
$stock  = social_stock();
$csrf   = (string)($_SESSION['csrf_token'] ?? '');
$render_url = portal_url($ctx, '/portal/market_render.php');
$export_url = portal_url($ctx, '/portal/market_export.php');
$report_url = portal_url($ctx, '/portal/market.php', $month !== '' ? ['m' => $month] : []);

portal_header($ctx, 'Market Snapshot', 'social');
?>
  <p class="pt-soon">From the Market Report.</p>
  <h1 class="pt-h1">Market Snapshot</h1>
  <p class="pt-lead">One market's month on a card: the median, closed sales, days on market and supply, credited to its MLS. Pick the market and the look, then download.</p>
  <p class="tp-back"><a href="<?= ph(portal_url($ctx, '/portal/templates.php')) ?>">‹ All templates</a></p>
<?php if ($month === ''): ?>
  <div class="pt-flash err">No market data is on file yet. The snapshot cards appear here once a month's figures are published.</div>
<?php else:
    include __DIR__ . '/../inc/market_post_body.php';
endif;
portal_footer();
