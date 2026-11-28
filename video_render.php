<?php
/**
 * video_render.php — the Video tool's preview: POST (the builder form, with
 * csrf_token) → text/html, one page that plays the whole video and loops
 * (video_preview_html()), for the preview iframe. Super admin only. See
 * inc/video.php.
 */
require_once __DIR__ . '/inc/video.php';

$ctx = video_gate($conn);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    http_response_code(400);
    echo social_placeholder_html('That page had been open too long. Please reload it.');
    exit;
}
$v = video_from_request($conn, $ctx, $_POST);
if (is_string($v)) { echo social_placeholder_html($v); exit; }
echo video_preview_html($v, null, $v['kind'] === 'clip' ? video_url($ctx, 'video_clip.php', ['id' => $v['clip']['id']]) : '');
