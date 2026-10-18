<?php
/**
 * portal/yard_signs.php — the yard sign layouts, in the portal (Nikki,
 * 2026-10-18; the public gallery at brand-guidelines/yard-signs/ stays for
 * sign shops). A team version and a broker version of each option.
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);
$img = '/brand-guidelines/yard-signs/img/';

portal_header($ctx, 'Yard Sign Templates', 'brand');
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/brand.php')) ?>">← Brand Tools</a></p>
  <h1 class="pt-h1">Yard Sign Templates</h1>
  <p class="pt-lead">The current Mont Haus yard sign layouts, each as a team sign and a broker sign. Tap one to see it larger. To order signs, use the form at the bottom of the page.</p>
  <nav class="pt-jump" aria-label="On this page"><a href="#option-1">Option 1</a><a href="#option-2">Option 2</a><a href="#option-3">Option 3</a></nav>

  <?php for ($o = 1; $o <= 3; $o++): ?>
  <section class="pt-section" id="option-<?= $o ?>">
    <h2 class="pt-h2">Option <?= $o ?></h2>
    <div class="pt-gal two">
      <figure class="pt-gal-item"><img src="<?= ph($img . "option-{$o}-team.png") ?>" alt="Option <?= $o ?>, team" loading="lazy" data-lb><figcaption>Team</figcaption></figure>
      <figure class="pt-gal-item"><img src="<?= ph($img . "option-{$o}-broker.png") ?>" alt="Option <?= $o ?>, broker" loading="lazy" data-lb><figcaption>Broker</figcaption></figure>
    </div>
  </section>
  <?php endfor; ?>
<?php portal_lightbox(); portal_footer();
