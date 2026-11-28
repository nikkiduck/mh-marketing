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
$ctx = plan_gate($conn);

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
$vd_listings = plan_listings($conn);
$vd_agents   = plan_agents($conn);
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
    'css'     => plan_admin_css() . '
    .wrap { max-width:1180px; }
    .vd { display:grid; grid-template-columns:1fr; gap:20px; align-items:start; }
    @media (min-width:900px) { .vd { grid-template-columns:340px 1fr; } .vd-side { position:sticky; top:82px; max-height:calc(100vh - 94px); overflow-y:auto; scrollbar-width:thin; } }
    .vd-stage { position:relative; width:100%; max-width:340px; margin:0 auto; aspect-ratio:9 / 16; overflow:hidden; border-radius:14px; background:#14181e; }
    .vd-stage iframe, .vd-stage video { position:absolute; left:0; top:0; border:0; }
    .vd-stage iframe { width:1080px; height:1920px; transform-origin:0 0; pointer-events:none; background:#14181e; }
    .vd-stage video { width:100%; height:100%; background:#000; }
    /* A photo is moved by dragging the preview, or its thumbnail in its row. */
    .vd-stage { cursor:grab; user-select:none; -webkit-user-select:none; }
    .vd-stage.drag, .vd-stage.drag ~ * { cursor:grabbing; }
    .vd-cap-p[data-move] { cursor:grab; touch-action:none; user-select:none; -webkit-user-select:none; }
    .vd-cap-p[data-move] img { -webkit-user-drag:none; pointer-events:none; }
    .vd-tabs { display:flex; gap:6px; justify-content:center; margin:12px 0 0; }
    .vd-tab { font:500 13px/1 var(--sans); padding:8px 14px; border:1.5px solid #dfe3e8; border-radius:999px; background:#fff; color:#1f242c; cursor:pointer; }
    .vd-tab.on { border-color:#4a5566; background:#4a5566; color:#fff; }
    .vd-tab:disabled { opacity:.35; cursor:not-allowed; }
    /* The caption for the post, under the preview: written from the listing, then changed and copied by hand. */
    .vd-capbox { max-width:340px; margin:16px auto 2px; }
    .vd-capbox label { display:block; font:600 13px/1.2 var(--sans); color:#1f242c; margin:0 0 6px; }
    .vd-capbox textarea { display:block; width:100%; box-sizing:border-box; min-height:120px; resize:vertical; font:400 14px/1.45 var(--sans); color:#1f242c; padding:10px 12px; border:1.5px solid #dfe3e8; border-radius:8px; background:#fff; }
    .vd-capbox p { display:flex; flex-wrap:wrap; gap:8px; margin:8px 0 0; }
    .vd-capbox .sp-btn { font-size:13px; padding:8px 14px; }
    .vd-len { text-align:center; font-size:13px; color:#6b7a8f; margin:10px 0 0; }
    .vd-photos { display:grid; grid-template-columns:repeat(auto-fill, minmax(92px, 1fr)); gap:8px; margin-top:14px; }
    .vd-photos .sp-photo { position:relative; display:block; aspect-ratio:4 / 3; overflow:hidden; border-radius:6px; cursor:pointer; outline:3px solid transparent; outline-offset:-3px; }
    .vd-photos .sp-photo img { display:block; width:100%; height:100%; object-fit:cover; border:0; border-radius:0; aspect-ratio:auto; }
    .vd-photos .sp-photo.on { outline-color:#4a5566; }
    .vd-n { position:absolute; left:6px; top:6px; min-width:22px; height:22px; padding:0 6px; box-sizing:border-box; display:flex; align-items:center; justify-content:center; font:600 12px/1 var(--sans); color:#fff; background:rgba(43,48,56,.92); border-radius:999px; }
    .vd-n:empty { display:none; }
    .vd-subrow { display:flex; flex-wrap:wrap; align-items:center; gap:6px 20px; margin:22px 0 4px; }
    #vdSlidesStep .vd-subrow { margin-top:0; }
    .vd-sub { font:600 14px/1.3 var(--sans); color:#1f242c; margin:0; }
    .vd-caps { display:grid; gap:10px; margin-top:10px; }
    .vd-cap { display:grid; grid-template-columns:22px 76px 1fr; gap:10px; align-items:start; }
    /* The grip a row is dragged by to change the play order. */
    .vd-grip { width:22px; height:57px; padding:0; border:0; background:none; color:#9aa5b5; font:600 16px/1 var(--sans); letter-spacing:-2px; cursor:grab; touch-action:none; }
    .vd-grip:hover { color:#4a5566; }
    .vd-caps.sorting { user-select:none; -webkit-user-select:none; cursor:grabbing; }
    .vd-caps.sorting .vd-grip { cursor:grabbing; }
    .vd-cap.sorting { background:#f1f3f6; outline:2px dashed #9aa5b5; outline-offset:4px; border-radius:6px; }
    .vd-cap-p { position:relative; display:block; aspect-ratio:4 / 3; border-radius:6px; overflow:hidden; background:#e6e9ee; }
    .vd-cap-p img { display:block; width:100%; height:100%; object-fit:cover; }
    .vd-cap-p b { position:absolute; left:4px; top:4px; min-width:18px; height:18px; padding:0 5px; box-sizing:border-box; display:flex; align-items:center; justify-content:center; font:600 10.5px/1 var(--sans); letter-spacing:.04em; color:#fff; background:rgba(43,48,56,.92); border-radius:999px; }
    .vd-cap-b { display:grid; gap:6px; }
    .vd-zoom { display:flex; align-items:center; gap:10px; font-size:13px; color:#6b7a8f; }
    .vd-zoom input { width:180px; max-width:50%; accent-color:#4a5566; }
    .vd-zoom output { min-width:44px; font-variant-numeric:tabular-nums; color:#1f242c; }
    .vd-cap textarea { width:100%; box-sizing:border-box; font:inherit; font-size:16px; line-height:1.35; padding:8px 10px; border:1.5px solid #dfe3e8; border-radius:8px; resize:vertical; }
    .vd-caps.no-text textarea { display:none; }
    .vd-row { display:flex; flex-wrap:wrap; align-items:center; gap:6px 22px; }
    .vd-logo { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#1f242c; white-space:nowrap; cursor:pointer; }
    .vd-logo input { width:auto; max-width:none; accent-color:#4a5566; }
    .vd-caps.no-logo .vd-logo { display:none; }
    .vd-bar { height:8px; border-radius:999px; background:#e6e9ee; overflow:hidden; margin:14px 0 8px; }
    .vd-bar i { display:block; height:100%; width:0; background:#4a5566; transition:width .4s; }
    .vd-up { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:12px; }
    /* The small buttons (upload, replace, remove): the size and line of the option pills beside them. */
    .vd-mini { font:inherit; font-size:15px; line-height:1.35; padding:7px 16px; border:2px solid var(--line, #dfe3e8); border-radius:999px; background:#fff; color:inherit; white-space:nowrap; cursor:pointer; }
    .vd-mini:hover { border-color:#4a5566; }
    .vd-mini:disabled { opacity:.45; cursor:not-allowed; }
    .sp-help.bad { color:#8f2424; }
    .sp-btn:disabled { opacity:.45; cursor:not-allowed; }
    .sp-field textarea { font-family:inherit; }
    /* The first page: the saved videos. */
    .vl-top { display:flex; align-items:center; gap:14px; margin:0 0 18px; }
    .vl-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:16px; }
    .vl-card { background:var(--card, #fff); border:1px solid var(--line, #dfe3e8); border-radius:14px; overflow:hidden; display:flex; flex-direction:column; }
    .vl-thumb { display:block; aspect-ratio:4 / 3; background:#14181e; }
    .vl-thumb img { display:block; width:100%; height:100%; object-fit:cover; }
    .vl-body { padding:14px 16px 16px; display:flex; flex-direction:column; gap:4px; flex:1; }
    .vl-name { font-family:var(--serif); font-size:22px; font-weight:500; line-height:1.2; margin:0; overflow-wrap:anywhere; }
    .vl-about, .vl-when { font-size:13px; color:#6b7a8f; margin:0; }
    .vl-acts { display:flex; flex-wrap:wrap; gap:8px; margin-top:auto; padding-top:12px; }
    .vl-acts .vd-mini { text-decoration:none; font-size:14px; padding:6px 14px; }
    [hidden] { display:none !important; }
    ',
]);
include __DIR__ . ($vd_edit ? '/inc/video_body.php' : '/inc/video_list.php');
admin_tool_footer();
