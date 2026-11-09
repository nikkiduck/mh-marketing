<?php
/**
 * plan_export.php — a Social Media Plan template as a download: the PNG of
 * one picture (headless Chromium, social_export_png()), or the story SET as a
 * ZIP of PNGs numbered in play order (plan_export_zip()); from the week page,
 * a saved piece by plan_week + plan_slot (2026-11-03) + plan_piece (post |
 * story, 2026-11-09). A copy of each PNG,
 * or the ZIP, is kept under CREATIVES_DIR/social/admin-<user id>/. Admins
 * only. See inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';

$ctx = plan_gate($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    portal_stop('Please try again', 'That page had been open too long. Go back, reload it, and download again.');
}
$in = $_POST;
// From the week page (2026-11-03): no form, just the week and slot; the saved piece's form is the request.
if (!isset($in['kind']) && isset($in['plan_week'], $in['plan_slot'])) {
    $item = plan_week_load((string)$in['plan_week'])['items'][(int)$in['plan_slot']] ?? null;
    $pieces = plan_item_pieces($item);
    $pc = (string)($in['plan_piece'] ?? '');   // post | story (2026-11-09); the only piece when not said
    $piece = $pieces[$pc] ?? (count($pieces) === 1 ? reset($pieces) : null);
    if (!$piece) portal_stop('Nothing saved for that day', 'Open the piece from Weekly Plans, make it, and save it to the week first.');
    $in = $piece['form'];
    if (($item['kind'] ?? '') === 'property' && ($l = plan_listing($conn, (string)($in['listing'] ?? '')))) plan_listing_tiles($l);   // the listing's photos into the session, so the saved ids pass plan_photo()
}
$r = plan_post_from_request($conn, $ctx, $in);
if (is_string($r)) portal_stop('Nothing to download yet', ph($r));

if (!empty($r['set'])) {
    $zip = plan_export_zip($r);
    if ($zip === null) {
        portal_stop('The story set could not be made', 'Something went wrong making the frames. Please try again in a minute; if it keeps happening, email '
            . PORTAL_MARKETING_EMAIL . ' and we will sort it out.');
    }
    $name = $r['name'] . '-' . date('Ymd-Hi') . '.zip';
    social_keep_copy($ctx, $name, $zip);
    header('Content-Type: application/zip');
    header('Content-Length: ' . strlen($zip));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo $zip;
    exit;
}

$frame = $r['frames'][0];
$png = social_export_png(plan_render_frame($frame, null, true), plan_frame_height($frame));
if ($png === null) {
    portal_stop('The picture could not be made', 'Something went wrong making the PNG. Please try again in a minute; if it keeps happening, email '
        . PORTAL_MARKETING_EMAIL . ' and we will sort it out.');
}
$name = plan_filename($r, $frame);
social_keep_copy($ctx, $name, $png);
header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo $png;
