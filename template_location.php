<?php
/**
 * template_location.php — Location Feature (the Social Media Plan,
 * 2026-11-02): one a week about an office, an area or a town, from one of
 * three angles the plan names: the office (images of the office or views
 * from it: the admin's uploads), the team in place (the brokers based there,
 * their faces in a row above the headline) or a local real estate angle (a
 * neighborhood, a notable sale, a trend: the admin's words). ?loc=office:aspen
 * | area:vail-valley | town:telluride &angle=office|team|local&size= preselect.
 * Admins only; see inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = plan_gate($conn);

$pk = 'location';
$pk_agents = plan_agents($conn);
$pk_locations = plan_locations();
$pk_team = [];
foreach ($pk_locations as $k => $l) $pk_team[$k] = array_values(array_map(fn($a) => $a['id'], plan_location_team($l, $pk_agents)));
$pk_preset = ['loc' => (string)($_GET['loc'] ?? 'office:aspen'), 'angle' => (string)($_GET['angle'] ?? 'office'), 'size' => (string)($_GET['size'] ?? 'story')];
// Opened for a week's slot (Weekly Plans, 2026-11-03): the saved piece's form presets the controls and loads into the script.
$pk_plan = plan_item_context($_GET, $pk);
$pk_saved = $pk_plan && $pk_plan['item'] ? $pk_plan['item'] : null;
if ($pk_saved) $pk_preset = plan_preset_from_form($pk, $pk_saved['form']) + $pk_preset;
$pk_hdr = plan_builder_header($pk_plan);
$csrf = (string)$_SESSION['csrf_token'];
$render_url = 'plan_render.php'; $export_url = 'plan_export.php'; $photos_url = 'plan_photos.php';
$upload_url = social_url($ctx, '/portal/social_upload.php'); $delete_url = social_url($ctx, '/portal/social_delete.php');

admin_tool_header([
    'title'   => 'Location Feature',
    'hint'    => $pk_hdr['hint'] . 'One a week in the plan: an office or a view from it, the brokers based there, or a local real estate angle.',
    'actions' => $pk_hdr['back'] . '<a class="btn btn-outline btn-sm" href="social_plan.php"><i class="ti ti-calendar-week"></i> Weekly Plans</a>'
               . '<a class="btn btn-outline btn-sm" href="social_media.php"><i class="ti ti-brand-instagram"></i> Social Media</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'styles'  => ['/assets/css/social.css'],
    'active'  => 'social',
    'css'     => plan_admin_css(),
]);
include __DIR__ . '/inc/plan_body.php';
admin_tool_footer();
