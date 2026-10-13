<?php
/**
 * portal/flyer_render.php — the flyer as HTML for the preview frame.
 * POST (the builder form, with csrf_token) → text/html, the same document
 * flyer_export.php prints. See inc/flyer.php.
 */
require_once __DIR__ . '/../inc/flyer.php';

$ctx = portal_context($conn);
social_gate();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo flyer_placeholder_html('That page had been open too long. Please reload it.');
    exit;
}
$p = flyer_post_from_request($conn, $ctx, $_POST);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo is_string($p) ? flyer_placeholder_html($p) : flyer_render($p);
