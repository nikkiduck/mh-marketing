<?php
/**
 * template_ad.php — Digital Ads (PROTOTYPE, Nikki, 2026-11-09): a property
 * ad for the newspapers' web sites as an animated GIF of up to three slides
 * (headline, headline + price, Presented by), 970 x 250, under 1 MB, plus the
 * slides as 2x JPGs for Photoshop. Super admin only; see inc/ads.php.
 */
require_once __DIR__ . '/inc/ads.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = plan_gate($conn);

$ad_listings = plan_listings($conn);
$ad_agents   = plan_agents($conn);
$ad_phones   = ads_agent_phones($conn);
$csrf = (string)$_SESSION['csrf_token'];
$render_url = 'ad_render.php'; $export_url = 'ad_export.php'; $photos_url = 'plan_photos.php';

admin_tool_header([
    'title'   => 'Digital Ads',
    'hint'    => 'Prototype. A property ad for the Vail Daily and Aspen Daily News sites: 970 x 250, two or three slides as a looping GIF under 1 MB, or the slides as 2x JPGs for Photoshop.',
    'actions' => '<a class="btn btn-outline btn-sm" href="social_media.php"><i class="ti ti-brand-instagram"></i> Social Media</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'styles'  => ['/assets/css/social.css'],
    'active'  => 'social',
    'css'     => plan_admin_css() . '
    .wrap { max-width:1180px; }
    .ad-stages { display:grid; gap:14px; margin:0 0 22px; }
    .ad-stage { cursor:pointer; }
    .ad-stage-l { font-size:12px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:#6b7a8f; margin:0 0 6px; display:flex; align-items:center; gap:8px; }
    .ad-stage-l b { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#dfe3e8; color:#1f242c; font-size:12px; }
    .ad-stage.act .ad-stage-l b { background:#4a5566; color:#fff; }
    .ad-stage-box { position:relative; width:100%; overflow:hidden; border-radius:6px; background:#2b3038; box-shadow:0 0 0 2px transparent; transition:box-shadow .15s; }
    .ad-stage.act .ad-stage-box { box-shadow:0 0 0 3px #4a5566; }
    .ad-stage-box iframe { position:absolute; left:0; top:0; width:970px; height:250px; border:0; transform-origin:0 0; pointer-events:none; background:#2b3038; }
    .ad-busy { position:absolute; right:10px; top:10px; width:10px; height:10px; border-radius:50%; background:#aebbcc; opacity:0; transition:opacity .2s; }
    .ad-busy.on { opacity:1; animation:adpulse 1s infinite; }
    @keyframes adpulse { 50% { opacity:.3; } }
    .ad-grid { display:grid; grid-template-columns:1fr; gap:0 20px; }
    @media (min-width:900px) { .ad-grid { grid-template-columns:1fr 1fr; } }
    .ad-framer { max-width:none; }
    .ad-photos { display:grid; grid-template-columns:repeat(auto-fill, minmax(92px, 1fr)); gap:8px; margin-top:14px; }
    .ad-photos .sp-photo { position:relative; display:block; aspect-ratio:4 / 3; overflow:hidden; border-radius:6px; cursor:pointer; outline:3px solid transparent; outline-offset:-3px; }
    .ad-photos .sp-photo img { display:block; width:100%; height:100%; object-fit:cover; }
    .ad-photos .sp-photo.on { outline-color:#4a5566; }
    .ad-badge { position:absolute; left:6px; top:6px; font:600 11px/1 var(--sans); letter-spacing:.1em; color:#fff; background:rgba(43,48,56,.9); border-radius:999px; padding:4px 7px; }
    .ad-badge:empty { display:none; }
    .sp-help.bad { color:#8f2424; }
    .sp-field textarea { font-family:inherit; }
    ',
]);
if (!$ad_listings) {
    echo '<div class="note">No Mont Haus listings are in the feed right now (hs_listing_state is empty or the sync has not run).</div>';
} else {
    include __DIR__ . '/inc/ads_body.php';
}
admin_tool_footer();
