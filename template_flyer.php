<?php
/**
 * template_flyer.php — Listing Flyer on the admin side (Nikki, 2026-10-21):
 * the portal's tool (inc/flyer_body.php, the same form, script and
 * endpoints) for the agent chosen in the picker. ?agent=<marketing account
 * id>. The page sets $_GET['preview'] to that account and calls
 * portal_context(), the path the roster's eye icon takes, so the listing
 * list, the photos, the QR choices, the render and the PDF all act on that
 * agent through ?preview=, and the contact lines are theirs. Admins only.
 * See templates.php and inc/admin_tool_shell.php.
 */
require_once __DIR__ . '/inc/flyer.php';
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
    $agent      = flyer_agent($conn, $ctx);
    $render_url = portal_url($ctx, '/portal/flyer_render.php');
    $export_url = portal_url($ctx, '/portal/flyer_export.php');
    $photos_url = portal_url($ctx, '/portal/social_photos.php');
    $upload_url = portal_url($ctx, '/portal/social_upload.php');
    $qr_by_listing = [];   // the QR choices per listing, for the dropdown to switch as the listing changes
    foreach ($listings as $l) $qr_by_listing[$l['id']] = array_map(fn($o) => $o[0], flyer_qr_options($conn, $ctx, $l));
    $tp_no_listings = 'We do not see any live listings under ' . htmlspecialchars($ctx['acct']['agent_name'], ENT_QUOTES, 'UTF-8') . '\'s name in the feed.';
}

admin_tool_header([
    'title'   => 'Listing Flyer',
    'hint'    => 'A printed Letter-size flyer of one of the agent\'s listings. Pick the listing and up to six photos, check the text, and download the PDF.',
    'actions' => '<a class="btn btn-outline btn-sm" href="templates.php' . ($agent_id ? '?agent=' . $agent_id : '') . '"><i class="ti ti-layout-grid"></i> Templates</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'styles'  => ['/assets/css/social.css', '/assets/css/flyer.css'],
]);
admin_agent_picker($agents, $agent_id, 'template_flyer.php');
if ($ctx) {
    include __DIR__ . '/inc/flyer_body.php';
} else {
    echo '<div class="note">Choose an agent above to load their listings.</div>';
}
admin_tool_footer();
