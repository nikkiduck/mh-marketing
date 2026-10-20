<?php
/**
 * portal/market_export.php — a Market Snapshot post as a PNG download.
 * POST (the builder form, with csrf_token) → image/png attachment, rendered
 * by headless Chromium from the same HTML the preview shows (fonts, marks
 * and the stock backdrop read from disk: social_export_png(), the Instagram
 * tool's call). A copy is kept in CREATIVES_DIR/social/<intake id>/ for a
 * portal login, as the Instagram posts are. See inc/market.php.
 */
require_once __DIR__ . '/../inc/market.php';

$ctx = market_gate($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    portal_stop('Please try again', 'That page had been open too long. Go back, reload it, and download again.');
}
$o = market_card_options_from_request($_POST, $ctx);
$r = market_card_html($o, true, $ctx);
if (is_string($r)) portal_stop('Nothing to download yet', ph($r));

$png = social_export_png($r['html'], market_card_height($o['format']));
if ($png === null) {
    portal_stop('The picture could not be made', 'Something went wrong making the PNG. Please try again in a minute; if it keeps happening, email '
        . PORTAL_MARKETING_EMAIL . ' and we will sort it out.');
}
$name = market_card_filename($o);
if ($ctx) social_keep_copy($ctx, $name, $png);

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo $png;
