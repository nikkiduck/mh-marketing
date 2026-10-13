<?php
/**
 * portal/elevation.php — Elevation Planner (My Tools). A tab for now (Nikki,
 * 2026-10-12: "I'll work on this with you later but make a tab for now"): a
 * short word on what it is and a button to the working demo on the website
 * (webroot/elevation-plan/ there, one static file, state in the browser).
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);
$demo = 'https://site.monthaus.com/elevation-plan/';

portal_header($ctx, 'Elevation Planner', 'elevation');
?>
  <h1 class="pt-h1">Elevation Planner</h1>
  <p class="pt-lead">Your business plan for the year: the production you are aiming for, what it takes in listings, buyers and conversations, and how the year is tracking against it.</p>
  <div class="pt-card">
    <h2 class="pt-card-h">Try the planner</h2>
    <p class="pt-card-sub">The planner is being built into the portal. Until then, the working version opens in a new tab and keeps your numbers in your browser, on this device.</p>
    <span class="pt-row"><a class="pt-btn main" href="<?= ph($demo) ?>" target="_blank" rel="noopener">Open the Elevation Planner</a></span>
  </div>
  <p class="pt-help" style="margin-top:20px">Questions about your plan? Email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>
<?php portal_footer();
