<?php
/**
 * plan_export.php — a Social Media Plan template as a download: the PNG of
 * one picture (headless Chromium, social_export_png()), or the story SET as a
 * ZIP of PNGs numbered in play order (plan_export_zip()). A copy of each PNG,
 * or the ZIP, is kept under CREATIVES_DIR/social/admin-<user id>/. Admins
 * only. See inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';

$ctx = plan_gate($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    portal_stop('Please try again', 'That page had been open too long. Go back, reload it, and download again.');
}
$r = plan_post_from_request($conn, $ctx, $_POST);
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
