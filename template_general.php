<?php
/**
 * template_general.php — General Social on the admin side (2026-10-28): the
 * portal's tool (portal/general.php; inc/social_body.php with $sp_general,
 * the same form, script and endpoints) for the admin THEMSELVES, no agent
 * (Nikki: it should not depend on choosing one). The endpoints run in
 * social_context() with ?preview=admin: a synthetic account with id 0, the
 * uploads the admin's own (keyed by users.id anyway), the download kept
 * under CREATIVES_DIR/social/admin-<user id>/. Admins only. See
 * templates.php and inc/admin_tool_shell.php.
 */
require_once __DIR__ . '/inc/social.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
require_login();
require_role('admin');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$_GET['preview'] = 'admin';
$ctx = social_context($conn);
social_gate();
$listings   = [];
$sp_general = true;
$templates  = social_templates_offered();
$themes     = social_themes();
$labels     = social_labels();
$styles     = social_line_styles();
$defaults   = social_line_defaults();
$render_url = social_url($ctx, '/portal/social_render.php');
$export_url = social_url($ctx, '/portal/social_export.php');
$photos_url = social_url($ctx, '/portal/social_photos.php');
$upload_url = social_url($ctx, '/portal/social_upload.php');
$next_sat   = (new DateTimeImmutable('next saturday'))->format('Y-m-d');
$line_names = ['Text 1', 'Text 2', 'Text 3', 'Text 4'];

admin_tool_header([
    'title'   => 'General Social',
    'hint'    => 'A post, square or story that is not about a listing: a stock photo or your own, the words lower left or centred, the logo where you want it.',
    'actions' => '<a class="btn btn-outline btn-sm" href="templates.php"><i class="ti ti-layout-grid"></i> Templates</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'styles'  => ['/assets/css/social.css'],
]);
include __DIR__ . '/inc/social_body.php';
admin_tool_footer();
