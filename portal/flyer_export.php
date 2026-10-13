<?php
/**
 * portal/flyer_export.php — the flyer as a PDF download.
 * POST (the builder form, with csrf_token) → application/pdf attachment,
 * printed by headless Chromium from the same HTML the preview shows (uploads
 * read from disk, fonts embedded). A copy is kept in
 * CREATIVES_DIR/social/<intake id>/ beside the Instagram exports. See inc/flyer.php.
 */
require_once __DIR__ . '/../inc/flyer.php';

$ctx = portal_context($conn);
social_gate();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    portal_stop('Please try again', 'That page had been open too long. Go back, reload it, and download again.');
}
$p = flyer_post_from_request($conn, $ctx, $_POST);
if (is_string($p)) portal_stop('Nothing to download yet', ph($p));

$pdf = flyer_export_pdf(flyer_render($p, null, true));
if ($pdf === null) {
    portal_stop('The flyer could not be made', 'Something went wrong making the PDF. Please try again in a minute; if it keeps happening, email '
        . PORTAL_MARKETING_EMAIL . ' and we will sort it out.');
}
$name = flyer_filename($p);
social_keep_copy($ctx, $name, $pdf);

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo $pdf;
