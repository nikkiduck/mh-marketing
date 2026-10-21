<?php
/**
 * market_snapshot.php — the Market Snapshot builder on the admin side
 * (2026-10-18), for admins whose login has no linked agent account. The same
 * body as the portal's Templates > Market Snapshot (inc/market_post_body.php)
 * under the admin header; the preview and the PNG come from the portal's
 * endpoints, which admit an admin without an account (market_gate()).
 * ?market=aspen&month=2026-09 preselects. See inc/market.php. Reached from
 * templates.php (the admin Templates page) and the Market Report.
 */
require_once __DIR__ . '/inc/market.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';   // the admin page frame, shared with the template tools (2026-10-21)
require_login();
require_role('admin');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$months = market_months();
$month  = market_pick_month((string)($_GET['month'] ?? ''));
$data   = $month !== '' ? market_load($month) : null;
if ($data) market_attach_feed($data);   // the feed towns join the Location list
$market = market_card_key((string)($_GET['market'] ?? ''), $data ?? []);   // a card key, or a market id from the report's links
$stock  = social_stock();
$board_names = [];   // the picker's groups, from the month's boards.json
foreach (($data['boards'] ?? []) as $b) $board_names[(string)$b['market']] = (string)$b['name'];
$csrf   = (string)$_SESSION['csrf_token'];
$render_url = '/portal/market_render.php';
$export_url = '/portal/market_export.php';
$report_url = '/market_report.php' . ($month !== '' ? '?m=' . rawurlencode($month) : '');

admin_tool_header([
    'title'   => 'Market Snapshot',
    'hint'    => "One place's month on an Instagram card, from the Market Report's figures. Agents have the same tool under Templates on their portal.",
    'actions' => '<a class="btn btn-outline btn-sm" href="templates.php"><i class="ti ti-layout-grid"></i> Templates</a>'
               . '<a class="btn btn-outline btn-sm" href="' . htmlspecialchars($report_url, ENT_QUOTES) . '"><i class="ti ti-chart-bar"></i> Market Report</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
]);
?>
    <?php if ($month === ''): ?>
      <div class="note"><strong>No market data on file yet.</strong> The cards appear once a month's figures are published to <code>inc/market_data/</code>.</div>
    <?php else: include __DIR__ . '/inc/market_post_body.php'; endif; ?>
<?php admin_tool_footer();
