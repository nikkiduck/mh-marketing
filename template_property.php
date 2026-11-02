<?php
/**
 * template_property.php — Property post + story set (the Social Media Plan,
 * 2026-11-02): a post or a story about ANY Mont Haus listing in the feed, not
 * one agent's (the admin plans the brokerage's week), labelled New Listing,
 * Featured Listing, Just Sold or Featured Rental. On a story the SET: the
 * photos ticked in order become a multi-image slideshow (the plan: "each
 * story should be a multi-image slideshow, not a single photo"), the cover
 * with the words, each further frame with a one-line caption, an end card
 * with the logo, the address and who listed it, downloaded as a ZIP of PNGs.
 * ?listing=market|key&variant=new|featured|sold|rental&size=post|story
 * preselect (social_plan.php's buttons). Admins only; see inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = plan_gate($conn);

$pk = 'property';
$pk_listings = plan_listings($conn);
$pk_preset = ['listing' => (string)($_GET['listing'] ?? ''), 'variant' => (string)($_GET['variant'] ?? 'featured'), 'size' => (string)($_GET['size'] ?? 'post')];
$csrf = (string)$_SESSION['csrf_token'];
$render_url = 'plan_render.php'; $export_url = 'plan_export.php'; $photos_url = 'plan_photos.php';
$upload_url = social_url($ctx, '/portal/social_upload.php'); $delete_url = social_url($ctx, '/portal/social_delete.php');

admin_tool_header([
    'title'   => 'Property Post + Story Set',
    'hint'    => 'Three a week in the plan: New Listings, Featured Listings, Just Sold (sales or rentals). A post, or a story set: the photos in order, as one slideshow.',
    'actions' => '<a class="btn btn-outline btn-sm" href="social_plan.php"><i class="ti ti-calendar-week"></i> This Week\'s Plan</a>'
               . '<a class="btn btn-outline btn-sm" href="templates.php"><i class="ti ti-layout-grid"></i> Templates</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'styles'  => ['/assets/css/social.css'],
    'css'     => plan_admin_css(),
]);
if (!$pk_listings) {
    echo '<div class="note">No Mont Haus listings are in the feed right now (hs_listing_state is empty or the sync has not run).</div>';
} else {
    include __DIR__ . '/inc/plan_body.php';
}
admin_tool_footer();
