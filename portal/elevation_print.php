<?php
/**
 * portal/elevation_print.php — a plan as a formatted Letter document in the
 * browser, with Print and Download PDF (2026-10-17). GET ?id=<saved plan id>
 * or ?id=working; the signed-in person's own rows only (anyone else's id is a
 * 404). The same HTML, with fonts inlined, is what elevation_pdf.php prints.
 */
require_once __DIR__ . '/../inc/elevation.php';

$ctx = portal_context($conn);
if (!elevation_ready($conn)) portal_stop('Not yet', 'Saving plans to your account is being switched on. Until then, print the page itself from your browser.');

$owner = elevation_owner($ctx);
$id    = (string)($_GET['id'] ?? 'working');
$row   = $id === 'working' ? elevation_working($conn, $owner) : (ctype_digit($id) ? elevation_get($conn, $owner, (int)$id) : null);
if (!$row || $row['plan'] === null || $row['leads'] === null) {
    http_response_code(404);
    portal_stop('Plan not found', $id === 'working' ? 'Nothing has been saved to your account yet. Open the planner, change a number, and try again.' : 'That plan was not found among your saved plans.');
}

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo elevation_render($row, $ctx, false, portal_url($ctx, '/portal/elevation.php') . ($row['kind'] === 'saved' ? '#saved' : ''));
