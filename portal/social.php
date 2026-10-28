<?php
/**
 * portal/social.php — Listing Post/Story (Nikki, 2026-10-11; reworked 2026-10-12;
 * named so 2026-10-28, when the Other kind moved to portal/general.php as
 * General Social): an Instagram-ready PNG of one of the agent's listings in four steps:
 * template and look, listing, photo (the listing's or their own, framed by
 * drag and zoom), words (a headline and four sub lines with styles), with a
 * live preview and a download. inc/social.php has the rules;
 * inc/social_render.php has the templates. Hidden behind SOCIAL_BETA_EMAILS
 * while it is built: not in the nav, 404 for everyone else.
 *
 * The preview is an iframe whose srcdoc is the HTML social_render.php
 * returns for the form as it stands (fetch on every change, debounced),
 * scaled to fit. Download POSTs the same form to social_export.php, which
 * screenshots the same HTML. (A Share… button for phones' share sheets was
 * removed 2026-10-12: it opened the sheet, then errored; Nikki.) The
 * framing box in step 3 applies the same CSS the renderer does (zoom, focus),
 * as a control only; the post itself is never drawn here.
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = portal_context($conn);
social_gate();

$listings  = array_values(social_listings($conn, $ctx));
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


portal_header($ctx, 'Listing Post/Story', 'social');
?>
<link rel="stylesheet" href="/assets/css/social.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/social.css') ?>">
  <h1 class="pt-h1">Listing Post/Story</h1>
  <p class="pt-lead">Pick a listing, choose and frame the photo, edit the text, and download. For a post that is not about a listing, use <a href="<?= ph(portal_url($ctx, '/portal/general.php')) ?>">General Social</a>.</p>
  <p class="tp-back"><a href="<?= ph(portal_url($ctx, '/portal/templates.php')) ?>">‹ All templates</a></p>
<?php include __DIR__ . '/../inc/social_body.php';   // the form and its script, shared with the admin side (2026-10-21)
portal_footer();
