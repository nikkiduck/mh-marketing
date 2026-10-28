<?php
/**
 * template_social.php — Listing Post/Story on the admin side (Nikki,
 * 2026-10-21): the portal's tool (inc/social_body.php, the same form, script
 * and endpoints) for the agent chosen in the picker. ?agent=<marketing
 * account id>. The page sets $_GET['preview'] to that account and calls
 * portal_context(), the path the roster's eye icon takes, so the listing
 * list, the photos, the render and the export all act on that agent through
 * ?preview=; the uploads are the admin's own (social_upload_dir() keys them
 * by users.id). Admins only. See templates.php and inc/admin_tool_shell.php.
 */
require_once __DIR__ . '/inc/social.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
require_login();
require_role('admin');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$agents   = social_agent_choices($conn);
$agent_id = (int)($_GET['agent'] ?? 0);
if (!isset($agents[$agent_id])) $agent_id = 0;

$ctx = null;
if ($agent_id) {
    $_GET['preview'] = $agent_id;          // the chosen agent's account, as the eye icon does
    $ctx = portal_context($conn);
    social_gate();
    $listings   = array_values(social_listings($conn, $ctx));
    $templates  = social_templates_offered();
    $themes     = social_themes();
    $labels     = social_labels();
    $styles     = social_line_styles();
    $defaults   = social_line_defaults();
    $render_url = portal_url($ctx, '/portal/social_render.php');
    $export_url = portal_url($ctx, '/portal/social_export.php');
    $photos_url = portal_url($ctx, '/portal/social_photos.php');
    $upload_url = portal_url($ctx, '/portal/social_upload.php');
    $next_sat   = (new DateTimeImmutable('next saturday'))->format('Y-m-d');
    $line_names = ['Text 1', 'Text 2', 'Text 3', 'Text 4'];
    $tp_no_listings = 'We do not see any live listings under ' . htmlspecialchars($ctx['acct']['agent_name'], ENT_QUOTES, 'UTF-8')
                    . '\'s name in the feed.';
}

admin_tool_header([
    'title'   => 'Listing Post/Story',
    'hint'    => 'Pick a listing, choose and frame the photo, edit the text, and download. The post is made for the agent chosen above.',
    'actions' => '<a class="btn btn-outline btn-sm" href="templates.php' . ($agent_id ? '?agent=' . $agent_id : '') . '"><i class="ti ti-layout-grid"></i> Templates</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'styles'  => ['/assets/css/social.css'],
]);
admin_agent_picker($agents, $agent_id, 'template_social.php');
if ($ctx) {
    include __DIR__ . '/inc/social_body.php';
} else {
    echo '<div class="note">Choose an agent above to load their listings.</div>';
}
admin_tool_footer();
