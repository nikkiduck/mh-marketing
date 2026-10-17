<?php
/**
 * portal/elevation_pdf.php — a plan as a PDF download (2026-10-17).
 * GET ?id=<saved plan id> or ?id=working → application/pdf attachment,
 * printed by headless Chromium from the same HTML elevation_print.php shows
 * (fonts and logo inlined, no network). The signed-in person's own rows only.
 */
require_once __DIR__ . '/../inc/elevation.php';

$ctx = portal_context($conn);
if (!elevation_ready($conn)) portal_stop('Not yet', 'Saving plans to your account is being switched on. Until then, print the page itself from your browser.');

$owner = elevation_owner($ctx);
$id    = (string)($_GET['id'] ?? 'working');
$row   = $id === 'working' ? elevation_working($conn, $owner) : (preg_match('/^\d{1,10}$/', $id) ? elevation_get($conn, $owner, (int)$id) : null);
if (!$row || $row['plan'] === null || $row['leads'] === null) {
    http_response_code(404);
    portal_stop('Plan not found', 'That plan was not found among your saved plans.');
}

$pdf = elevation_pdf(elevation_render($row, $ctx, true));
if ($pdf === null) {
    portal_stop('The PDF could not be made', 'Something went wrong making the PDF. Please try again in a minute; if it keeps happening, use Print from the plan page, or email '
        . PORTAL_MARKETING_EMAIL . ' and we will sort it out.');
}
$name = elevation_filename($ctx, $row);
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo $pdf;
