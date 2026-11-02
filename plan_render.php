<?php
/**
 * plan_render.php — one frame of a Social Media Plan template as HTML, for
 * the preview iframe. POST (the builder form, with csrf_token) → text/html,
 * the same document plan_export.php screenshots. Admins only. See inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';

$ctx = plan_gate($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo social_placeholder_html('That page had been open too long. Please reload it.');
    exit;
}
$r = plan_post_from_request($conn, $ctx, $_POST);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo is_string($r) ? social_placeholder_html($r) : plan_render_frame($r['frames'][$r['preview']]);
