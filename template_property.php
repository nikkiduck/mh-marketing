<?php
/**
 * template_property.php — Property post + set (the Social Media Plan,
 * 2026-11-02): a post, square or story about ANY Mont Haus listing in the
 * feed, not one agent's (the admin plans the brokerage's week), labelled New
 * Listing, Featured Listing, Just Sold or Featured Rental. The SET, at any
 * size: the photos ticked in order become a multi-image slideshow (the plan:
 * "each story should be a multi-image slideshow, not a single photo"; a post
 * or square set is a carousel), the cover with the words, then the photo
 * alone with the logo lower right on every other one, the cover plus up to
 * eight more, an optional closing card, downloaded as a ZIP of PNGs.
 * ?listing=market|key&variant=new|featured|sold|rental&size=post|story
 * preselect (social_plan.php's buttons). Admins only; see inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = plan_gate($conn);

$pk = 'property';
$pk_listings = plan_listings($conn);
$pk_preset = ['listing' => (string)($_GET['listing'] ?? ''), 'variant' => (string)($_GET['variant'] ?? 'featured'), 'size' => (string)($_GET['size'] ?? 'post')];
// Opened for a week's slot (Weekly Plans, 2026-11-03): the saved piece's form presets the controls and loads into the script.
$pk_plan = plan_item_context($_GET, $pk);
$pk_saved = $pk_plan && $pk_plan['item'] ? $pk_plan['item'] : null;
if ($pk_saved) $pk_preset = plan_preset_from_form($pk, $pk_saved['form']) + $pk_preset;
$pk_hdr = plan_builder_header($pk_plan);
$csrf = (string)$_SESSION['csrf_token'];
$render_url = 'plan_render.php'; $export_url = 'plan_export.php'; $photos_url = 'plan_photos.php';
$upload_url = social_url($ctx, '/portal/social_upload.php'); $delete_url = social_url($ctx, '/portal/social_delete.php');

admin_tool_header([
    'title'   => 'Property Post + Set',
    'hint'    => $pk_hdr['hint'] . 'Three a week in the plan: New Listings, Featured Listings, Just Sold (sales or rentals). One picture, or a set: the photos in order as one story or carousel.',
    'actions' => $pk_hdr['back'] . '<a class="btn btn-outline btn-sm" href="social_plan.php"><i class="ti ti-calendar-week"></i> Weekly Plans</a>'
               . '<a class="btn btn-outline btn-sm" href="social_media.php"><i class="ti ti-brand-instagram"></i> Social Media</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'styles'  => ['/assets/css/social.css'],
    'active'  => 'social',
    'css'     => plan_admin_css(),
]);
if (!$pk_listings) {
    echo '<div class="note">No Mont Haus listings are in the feed right now (hs_listing_state is empty or the sync has not run).</div>';
} else {
    include __DIR__ . '/inc/plan_body.php';
}
admin_tool_footer();
