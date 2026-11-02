<?php
/**
 * template_broker.php — Broker Feature (the Social Media Plan, 2026-11-02):
 * one broker a week, tied to their market. The hook from the plan ("Thinking
 * of buying or selling in Montrose? Call Jackson Horn with Mont Haus.") with
 * the broker's name and market swapped in, or a milestone opener; their
 * headshot, one of the admin's uploads (the plan asks for lifestyle shots,
 * not straight headshots) or a stock photo of their market; the split layout
 * (photo on top, words below) by default, which suits a portrait. ?agent=<id>
 * &hook=<0-5>&size= preselect. Admins only; see inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = plan_gate($conn);

$pk = 'broker';
$pk_agents = plan_agents($conn);
$pk_preset = ['agent' => (int)($_GET['agent'] ?? 0), 'hook' => (int)($_GET['hook'] ?? 0), 'size' => (string)($_GET['size'] ?? 'story')];
$csrf = (string)$_SESSION['csrf_token'];
$render_url = 'plan_render.php'; $export_url = 'plan_export.php'; $photos_url = 'plan_photos.php';
$upload_url = social_url($ctx, '/portal/social_upload.php'); $delete_url = social_url($ctx, '/portal/social_delete.php');

admin_tool_header([
    'title'   => 'Broker Feature',
    'hint'    => 'One a week in the plan: spotlight a broker and tie them to their market, or celebrate a milestone. The hooks rotate week to week.',
    'actions' => '<a class="btn btn-outline btn-sm" href="social_plan.php"><i class="ti ti-calendar-week"></i> This Week\'s Plan</a>'
               . '<a class="btn btn-outline btn-sm" href="templates.php"><i class="ti ti-layout-grid"></i> Templates</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'styles'  => ['/assets/css/social.css'],
]);
if (!$pk_agents) {
    echo '<div class="note">No approved agents are on the roster yet.</div>';
} else {
    include __DIR__ . '/inc/plan_body.php';
}
admin_tool_footer();
