<?php
/**
 * portal/templates.php — Templates (My Tools): the landing for the template
 * tools, Listing Post/Story (portal/social.php), General Social
 * (portal/general.php, 2026-10-28), Market Snapshot and Listing Flyer
 * (portal/flyer.php). The menu has two levels, so a third for the tools
 * would not fit; each tool page carries a switch to the other instead
 * (Nikki, 2026-10-13: "a dropdown under the Templates menu item").
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/social.php';

$ctx = portal_context($conn);
social_gate();

// Two groups (Nikki, 2026-10-15): the flyer is print, not Instagram.
$groups = [
    'Instagram Templates' => [
        ['Listing Post/Story', '/portal/social.php', 'An Instagram or Facebook ready post or story from one of your listings.', 'Make a post ›'],
        ['General Social', '/portal/general.php', 'A post, square or story that is not about a listing: a Mont Haus stock photo or your own, your words, the logo where you want it.', 'Make a post ›'],   // 2026-10-28
        ['Market Snapshot', '/portal/market_post.php', 'One place\'s month on a card: the median sale price, closed sales, days on market and months of supply. A post or a story.', 'Make a snapshot ›'],   // from the Market Report (2026-10-18)
    ],
    'Print Templates' => [
        ['Listing Flyer', '/portal/flyer.php', 'A printed Letter-size flyer: up to six photos, the details, a banner, your contact lines and a QR code. Download the PDF.', 'Make a flyer ›'],
    ],
];

portal_header($ctx, 'Templates', 'social');
?>
<link rel="stylesheet" href="/assets/css/social.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/social.css') ?>">
  <h1 class="pt-h1">Templates</h1>
  <p class="pt-lead">Ready-made Mont Haus designs filled in from your listings. Pick one to start.</p>
  <?php foreach ($groups as $heading => $cards): ?>
  <h2 class="tp-group"><?= ph($heading) ?></h2>
  <div class="pt-cards">
    <?php foreach ($cards as [$title, $path, $sub, $go]): ?>
    <a class="pt-card" href="<?= ph(portal_url($ctx, $path)) ?>">
      <h3 class="pt-card-h"><?= ph($title) ?></h3>
      <p class="pt-card-sub"><?= ph($sub) ?></p>
      <span class="pt-card-go"><?= ph($go) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
<?php portal_footer();
