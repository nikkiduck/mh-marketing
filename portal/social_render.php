<?php
/**
 * portal/social_render.php — the post as HTML for the preview frame.
 * POST (the builder form, with csrf_token) → text/html, the same document
 * social_export.php screenshots. See inc/social.php.
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = social_context($conn);   // or the admin's own context with ?preview=admin (2026-10-28)
social_gate();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo social_placeholder_html('That page had been open too long. Please reload it.');
    exit;
}
$p = social_post_from_request($conn, $ctx, $_POST);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo is_string($p) ? social_placeholder_html($p) : social_render($p);
