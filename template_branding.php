<?php
/**
 * template_branding.php — Branding (the Social Media Plan, 2026-11-02): two
 * stories a week that follow the seasonal branding strategy. A season, one of
 * its taglines (drafts; Nikki edits), a stock photo suggested for the season
 * or the admin's own, the words centred as General Social draws them, the
 * logo where it reads best. ?season=winter|spring|summer|fall|holidays|always
 * &size= preselect. Admins only; see inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = plan_gate($conn);

$pk = 'branding';
$pk_seasons = plan_seasons();
$pk_preset = ['season' => (string)($_GET['season'] ?? plan_week()['season']), 'size' => (string)($_GET['size'] ?? 'story')];
$csrf = (string)$_SESSION['csrf_token'];
$render_url = 'plan_render.php'; $export_url = 'plan_export.php'; $photos_url = 'plan_photos.php';
$upload_url = social_url($ctx, '/portal/social_upload.php'); $delete_url = social_url($ctx, '/portal/social_delete.php');

admin_tool_header([
    'title'   => 'Branding Story',
    'hint'    => 'Two a week in the plan, following the seasonal branding strategy: a tagline on a mountain photo, centred, with the logo.',
    'actions' => '<a class="btn btn-outline btn-sm" href="social_plan.php"><i class="ti ti-calendar-week"></i> This Week\'s Plan</a>'
               . '<a class="btn btn-outline btn-sm" href="templates.php"><i class="ti ti-layout-grid"></i> Templates</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'styles'  => ['/assets/css/social.css'],
    'css'     => plan_admin_css(),
]);
include __DIR__ . '/inc/plan_body.php';
admin_tool_footer();
