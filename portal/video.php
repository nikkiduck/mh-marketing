<?php
/**
 * portal/video.php — Video for agents (Nikki, 2026-11-28: "this is working
 * fantastically. Let's get it live for the agents"): the admin side's Video
 * tool (template_video.php) on the portal, the first card under Templates >
 * Instagram Templates. A 1080 x 1920 MP4 for a story or a reel from one of
 * the agent's listings, their own photos or a clip they upload.
 *
 * The same pages and code as the admin's: inc/video_list.php (the saved
 * videos, the first page) and inc/video_body.php (the editor: ?new=1,
 * ?open=<id>, ?copy=<id>). VIDEO_PORTAL is what makes it the agent's: their
 * own listings, themselves and their teams under Presented by, their own
 * folder of clips, jobs and saved videos. See "Whose side" in inc/video.php.
 */
define('VIDEO_PORTAL', true);
require_once __DIR__ . '/../inc/video.php';
require_once __DIR__ . '/../inc/video_ai.php';

$ctx = video_gate($conn);
$vd_href = fn(array $q = []) => portal_url($ctx, '/portal/video.php', $q);

$vd_edit = isset($_GET['new']) || isset($_GET['open']) || isset($_GET['copy']);
$vd_copy = !isset($_GET['open']) && isset($_GET['copy']);
$vd_open = null;
if (isset($_GET['open']) || isset($_GET['copy'])) {
    $vd_open = video_saved_read($ctx, (string)($_GET['open'] ?? $_GET['copy']));
    if (!$vd_open) { header('Location: ' . $vd_href()); exit; }
}
$vd_listings = video_listings($conn, $ctx);
$vd_agents   = video_agents($conn, $ctx);
$vd_clips    = video_clips($ctx);
$csrf = (string)$_SESSION['csrf_token'];
$u = fn(string $f) => video_url($ctx, $f);
$render_url = $u('video_render.php'); $export_url = $u('video_export.php'); $clip_url = $u('video_clip.php'); $logo_url = $u('video_team_logo.php');
$photos_url = $u('video_photos.php'); $saved_url = $u('video_saved.php'); $caption_url = $u('video_caption.php');
$suggest_url = video_ai_enabled() ? $u('video_suggest.php') : '';
$upload_url = portal_url($ctx, '/portal/social_upload.php'); $delete_url = portal_url($ctx, '/portal/social_delete.php');

portal_header($ctx, 'Video', 'social');
?>
<link rel="stylesheet" href="/assets/css/social.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/social.css') ?>">
<style>
<?= video_page_css() ?>
    /* The portal's page is narrower than the editor needs, and its header scrolls away. */
    .pt-main { max-width:1180px; }
    @media (min-width:900px) { .vd-side { top:16px; max-height:calc(100vh - 28px); } }
</style>
  <h1 class="pt-h1">Video</h1>
  <p class="pt-lead">A story or reel as a video: a listing's photos in motion, your own photos, or a video clip you upload, with the logo, your text and a closing card. It downloads as an MP4; add music in Instagram when you post.</p>
  <p class="tp-back"><?php if ($vd_edit): ?><a href="<?= ph($vd_href()) ?>">‹ Saved videos</a><?php else: ?><a href="<?= ph(portal_url($ctx, '/portal/templates.php')) ?>">‹ All templates</a><?php endif; ?></p>
<?php include __DIR__ . ($vd_edit ? '/../inc/video_body.php' : '/../inc/video_list.php');
portal_footer();
