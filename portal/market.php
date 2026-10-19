<?php
/**
 * portal/market.php — the Monthly Market Report (My Tools > Market Report,
 * 2026-10-18): the monthly report of the Colorado markets Mont Haus
 * serves, built into the portal from Nikki's Claude artifact. The body is
 * inc/market_report_body.php, shared with the admin side's market_report.php;
 * the data is the newest folder under inc/market_data (?m=YYYY-MM for an
 * older month). See inc/market_data.php and docs/HANDOFF-market-report.md.
 */
require_once __DIR__ . '/../inc/market.php';

$ctx   = portal_context($conn);
$month = market_pick_month((string)($_GET['m'] ?? ''));
$data  = $month !== '' ? market_load($month) : null;
if ($data) market_attach_feed($data);   // the Communities towns from our own feed

portal_header($ctx, 'Market Report', 'market');
if (!$data) {
    echo '<h1 class="pt-h1">Market Report</h1>';
    echo '<div class="pt-flash err">No market data is on file yet. The report appears here once a month\'s figures are published.</div>';
} else {
    $boot = market_boot($data, ['post' => portal_url($ctx, '/portal/market_post.php'), 'self' => portal_url($ctx, '/portal/market.php')]);
    include __DIR__ . '/../inc/market_report_body.php';
}
portal_footer();
