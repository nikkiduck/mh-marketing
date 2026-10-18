<?php
/**
 * portal/brand.php — Brand & templates (Nikki, 2026-10-10): the brand
 * guidelines (with the logo files to download), and the business card and
 * yard sign template galleries, which moved here from monthausint.com the
 * same day (brand-guidelines/business-cards/, brand-guidelines/yard-signs/).
 * Since 2026-10-18 the three open INSIDE the portal (portal/brand_guidelines.php,
 * business_cards.php, yard_signs.php: same header and menu, so people can get
 * back); the public pages stay for vendors. Nothing here is per account.
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);

$items = [
    ['Brand guidelines', portal_url($ctx, '/portal/brand_guidelines.php') . '#downloads',
     'Colors, type, how the logo is used, and the logo files for download.',
     'Download the logos ›'],
    ['Business card templates', portal_url($ctx, '/portal/business_cards.php'),
     'Current MH business card designs.',
     'See the designs ›'],
    ['Yard sign templates', portal_url($ctx, '/portal/yard_signs.php'),
     'Current MH yard sign layouts.',
     'See the designs ›'],
];

portal_header($ctx, 'Brand & templates', 'brand');
?>
  <h1 class="pt-h1">Brand Tools</h1>
  <p class="pt-lead">The Mont Haus brand and current designs.</p>

  <div class="pt-cards">
    <?php foreach ($items as [$title, $url, $sub, $go]): ?>
    <a class="pt-card" href="<?= ph($url) ?>">
      <h2 class="pt-card-h"><?= ph($title) ?></h2>
      <p class="pt-card-sub"><?= ph($sub) ?></p>
      <span class="pt-card-go"><?= ph($go) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <p class="pt-help" style="margin-top:20px">Email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a> if you'd like to order new signs, cards, or other collateral, or if you have questions on logo usage.</p>
<?php portal_footer();
