<?php
/**
 * portal/brand.php — Brand & templates (Nikki, 2026-10-10): the brand
 * guidelines (with the logo files to download), and the business card and
 * yard sign template galleries, which moved here from monthausint.com the
 * same day (brand-guidelines/business-cards/, brand-guidelines/yard-signs/).
 * Links only; nothing here is per account, so there is nothing to scope.
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);

$items = [
    ['Brand guidelines', '/brand-guidelines/#downloads',
     'Colors, type, how the logo is used, and the logo files you can download and use: the full set as a ZIP, or each mark on its own.',
     'Download the logos ›'],
    ['Business card templates', '/brand-guidelines/business-cards/',
     'The business card designs Mont Haus prints, front and back, so you can see what to ask for.',
     'See the designs ›'],
    ['Yard sign templates', '/brand-guidelines/yard-signs/',
     'The yard sign layouts, with a broker version and a team version of each.',
     'See the designs ›'],
];

portal_header($ctx, 'Brand & templates', 'brand');
?>
  <h1 class="pt-h1">Brand & templates</h1>
  <p class="pt-lead">The Mont Haus brand, and the designs your collateral is built from. Each opens in a new tab.</p>

  <div class="pt-cards">
    <?php foreach ($items as [$title, $url, $sub, $go]): ?>
    <a class="pt-card" href="<?= ph($url) ?>" target="_blank" rel="noopener">
      <h2 class="pt-card-h"><?= ph($title) ?></h2>
      <p class="pt-card-sub"><?= ph($sub) ?></p>
      <span class="pt-card-go"><?= ph($go) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <p class="pt-help" style="margin-top:20px">Need a file in another format, or a design you do not see here? Email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>
<?php portal_footer();
