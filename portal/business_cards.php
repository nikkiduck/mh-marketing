<?php
/**
 * portal/business_cards.php — the business card designs, in the portal
 * (Nikki, 2026-10-18; the public gallery at brand-guidelines/business-cards/
 * stays for vendors). The pictures are that gallery's.
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);
$img = '/brand-guidelines/business-cards/img/';
$front = [1 => 'page-01', 'page-02', 'page-03', 'page-04', 'page-05', 'page-06', 'page-07', 'page-08', 'page-09', 'page-10', 'page-15', 'page-16'];
$back  = [1 => 'page-11', 'page-12', 'page-13', 'page-14', 'page-17'];

portal_header($ctx, 'Business Card Templates', 'brand', ['front' => 'Front options', 'back' => 'Back options']);
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/brand.php')) ?>">← Brand Tools</a></p>
  <h1 class="pt-h1">Business Card Templates</h1>
  <p class="pt-lead">The current Mont Haus business card designs, front and back. Tap one to see it larger. To order cards, use the form at the bottom of the page.</p>

  <section class="pt-section" id="front">
    <h2 class="pt-h2">Front options</h2>
    <div class="pt-gal">
      <?php foreach ($front as $n => $f): ?>
        <figure class="pt-gal-item"><img src="<?= ph($img . $f . '.png') ?>" alt="Front option <?= $n ?>" loading="lazy" data-lb><figcaption>Front option <?= $n ?></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="pt-section" id="back">
    <h2 class="pt-h2">Back options</h2>
    <div class="pt-gal">
      <?php foreach ($back as $n => $f): ?>
        <figure class="pt-gal-item"><img src="<?= ph($img . $f . '.png') ?>" alt="Back option <?= $n ?>" loading="lazy" data-lb><figcaption>Back option <?= $n ?></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </section>
<?php portal_lightbox(); portal_footer();
