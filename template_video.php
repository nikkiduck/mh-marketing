<?php
/**
 * template_video.php — Video (Nikki, 2026-11-26: "build video stories and
 * reels ... start with Admin-only for right now"): a 1080 x 1920 MP4 for a
 * story or a reel, from a listing's photos (each moving slowly, with
 * cross-fades) or from one uploaded video clip, with the logo, the words
 * and a closing card. A page of its own, a card on social_media.php; the
 * picture templates are not touched. Super admin only; see inc/video.php.
 */
require_once __DIR__ . '/inc/video.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = video_gate($conn);

// The first page is the saved videos (inc/video_list.php). The editor (inc/video_body.php) opens with ?new=1 (a blank
// one), ?open=<id> (a saved one, saved over when it is saved again) or ?copy=<id> (Replicate: everything a saved one
// holds, as a new video).
$vd_edit = isset($_GET['new']) || isset($_GET['open']) || isset($_GET['copy']);
$vd_copy = !isset($_GET['open']) && isset($_GET['copy']);
$vd_open = null;
if (isset($_GET['open']) || isset($_GET['copy'])) {
    $vd_open = video_saved_read($ctx, (string)($_GET['open'] ?? $_GET['copy']));
    if (!$vd_open) { header('Location: template_video.php'); exit; }
}
$vd_listings = video_listings($conn, $ctx);
$vd_agents   = video_agents($conn, $ctx);
$vd_href     = fn(array $q) => 'template_video.php?' . http_build_query($q);   // this page with a query (the agents' is portal/video.php)
$vd_clips    = video_clips($ctx);
$csrf = (string)$_SESSION['csrf_token'];
$render_url = 'video_render.php'; $export_url = 'video_export.php'; $clip_url = 'video_clip.php'; $logo_url = 'video_team_logo.php'; $photos_url = 'plan_photos.php'; $saved_url = 'video_saved.php'; $caption_url = 'video_caption.php';
// My Photos: the person's own uploads go through the social tools' endpoints, into the same folder those tools read.
require_once __DIR__ . '/inc/video_ai.php';
$suggest_url = video_ai_enabled() ? 'video_suggest.php' : '';   // Suggest Texts, when the Claude API key and client are there
$upload_url = social_url($ctx, '/portal/social_upload.php'); $delete_url = social_url($ctx, '/portal/social_delete.php');

admin_tool_header([
    'title'   => 'Video',
    'hint'    => 'A story or reel as a video: a listing\'s photos in motion, or a video clip you upload, with the logo, the text and a closing card. It downloads as an MP4; add music in Instagram when you post.',
    'actions' => ($vd_edit ? '<a class="btn btn-outline btn-sm" href="template_video.php"><i class="ti ti-movie"></i> Saved videos</a> ' : '') . '<a class="btn btn-outline btn-sm" href="social_media.php"><i class="ti ti-brand-instagram"></i> Social Media</a>',
    'styles'  => ['/assets/css/social.css'],
    'active'  => 'social',
    'css'     => video_page_css(),
]);
include __DIR__ . ($vd_edit ? '/inc/video_body.php' : '/inc/video_list.php');
admin_tool_footer();
