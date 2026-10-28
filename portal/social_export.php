<?php
/**
 * portal/social_export.php — the post as a PNG download.
 * POST (the builder form, with csrf_token) → image/png attachment, rendered
 * by headless Chromium from the same HTML the preview shows (an uploaded
 * photo read from disk, which is what $export = true does). A copy is kept
 * in CREATIVES_DIR/social/<intake id>/. See inc/social.php.
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = social_context($conn);   // or the admin's own context with ?preview=admin (2026-10-28)
social_gate();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    portal_stop('Please try again', 'That page had been open too long. Go back, reload it, and download again.');
}
$p = social_post_from_request($conn, $ctx, $_POST);
if (is_string($p)) portal_stop('Nothing to download yet', ph($p));

$png = social_export_png(social_render($p, null, true), social_format_height($p['format']));
if ($png === null) {
    portal_stop('The picture could not be made', 'Something went wrong making the PNG. Please try again in a minute; if it keeps happening, email '
        . PORTAL_MARKETING_EMAIL . ' and we will sort it out.');
}
$name = social_filename($p);
social_keep_copy($ctx, $name, $png);

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo $png;
