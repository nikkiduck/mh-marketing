<?php
/**
 * ad_render.php — one slide of a digital ad as HTML, for a preview iframe.
 * POST (the builder form, with csrf_token, `frame` = the slide index) →
 * text/html, the same document ad_export.php screenshots. Super admin only.
 * See inc/ads.php.
 */
require_once __DIR__ . '/inc/ads.php';

$ctx = plan_gate($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo social_placeholder_html('That page had been open too long. Please reload it.');
    exit;
}
$r = ads_from_request($conn, $ctx, $_POST);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo is_string($r) ? social_placeholder_html($r) : ads_render_frame($r, $r['preview']);
