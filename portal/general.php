<?php
/**
 * portal/general.php — General Social (Nikki, 2026-10-28): an Instagram-ready
 * PNG that is not about a listing. The same builder as the Listing
 * Post/Story (inc/social_body.php with $sp_general), with the kind fixed to
 * Other: the Mont Haus stock library and the person's own uploads for the
 * photo, every line the agent's; three sizes (post 4:5, square 1:1, story
 * 9:16); the words lower left over the fade or CENTRED on the photo (a
 * multi-line headline in the headline serif, a veil over the photo); the
 * logo upper right, lower left, centred above the words, or none. Until
 * today this lived as "Other / General" inside the listing tool.
 * inc/social.php has the rules; inc/social_render.php the templates.
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = portal_context($conn);
social_gate();

$listings  = [];                                  // no listing: the form never shows the listing step
$templates = social_templates_offered();
$themes    = social_themes();
$labels    = social_labels();
$styles    = social_line_styles();
$defaults  = social_line_defaults();
$render_url = portal_url($ctx, '/portal/social_render.php');
$export_url = portal_url($ctx, '/portal/social_export.php');
$photos_url = portal_url($ctx, '/portal/social_photos.php');
$upload_url = portal_url($ctx, '/portal/social_upload.php');
$next_sat   = (new DateTimeImmutable('next saturday'))->format('Y-m-d');
$line_names = ['Text 1', 'Text 2', 'Text 3', 'Text 4'];
$sp_general = true;

portal_header($ctx, 'General Social', 'social');
?>
<link rel="stylesheet" href="/assets/css/social.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/social.css') ?>">
  <h1 class="pt-h1">General Social</h1>
  <p class="pt-lead">A post, square or story that is not about a listing: choose a Mont Haus stock photo or upload your own, place the words and the logo, and download. If you'd like to create a custom post, you may download the MH logos from the Brand Tools tab.</p>
  <p class="tp-back"><a href="<?= ph(portal_url($ctx, '/portal/templates.php')) ?>">‹ All templates</a></p>
<?php include __DIR__ . '/../inc/social_body.php';
portal_footer();
