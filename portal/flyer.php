<?php
/**
 * portal/flyer.php — Listing Flyer (Nikki, 2026-10-13): a printed Letter-size
 * PDF of one of the agent's listings, after the InDesign display template.
 * Pick a listing, pick up to six photos in order (the first is the big one),
 * check the words (headline, address, price, the three figures, optional
 * notes under them, contact lines), choose the QR code, download the PDF.
 * inc/flyer.php has the rules; inc/flyer_render.php the layout. Photos and
 * uploads come through the Instagram tool's endpoints (social_photos.php,
 * social_upload.php, social_asset.php).
 *
 * The preview is an iframe whose srcdoc is the HTML flyer_render.php returns
 * for the form as it stands (fetch on every change, debounced), scaled to
 * fit. Download PNG's sibling here is Download PDF: the same form posted to
 * flyer_export.php, which prints the same HTML.
 */
require_once __DIR__ . '/../inc/flyer.php';

$ctx = portal_context($conn);
social_gate();

$listings   = array_values(social_listings($conn, $ctx));
$agent      = flyer_agent($conn, $ctx);
$render_url = portal_url($ctx, '/portal/flyer_render.php');
$export_url = portal_url($ctx, '/portal/flyer_export.php');
$photos_url = portal_url($ctx, '/portal/social_photos.php');
$upload_url = portal_url($ctx, '/portal/social_upload.php');


// The QR choices per listing, for the dropdown to switch as the listing changes.
$qr_by_listing = [];
foreach ($listings as $l) $qr_by_listing[$l['id']] = array_map(fn($o) => $o[0], flyer_qr_options($conn, $ctx, $l));

portal_header($ctx, 'Listing Flyer', 'social');   // under Templates in the menu
?>
<link rel="stylesheet" href="/assets/css/social.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/social.css') ?>">
<link rel="stylesheet" href="/assets/css/flyer.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/flyer.css') ?>">
  <p class="pt-soon">Print Templates</p>
  <h1 class="pt-h1">Listing Flyer</h1>
  <p class="pt-lead">A printed flyer of one of your listings, Letter size. Pick the listing and up to six photos, check the text, and download the PDF.</p>
  <p class="tp-back"><a href="<?= ph(portal_url($ctx, '/portal/templates.php')) ?>">‹ All templates</a></p>
<?php include __DIR__ . '/../inc/flyer_body.php';   // the form and its script, shared with the admin side (2026-10-21)
portal_footer();
