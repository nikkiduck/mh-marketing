<?php
/**
 * portal/yard_signs.php — the yard sign and open house sign layouts, in the
 * portal (Nikki, 2026-10-18; the public gallery at brand-guidelines/yard-signs/
 * stays for sign shops). Yard signs are broker-only since 2026-10-29 (the team
 * versions were dropped); the open house signs (corporate, team, broker, each
 * with the arrow left or right) were added the same day.
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);
$img = '/brand-guidelines/yard-signs/img/';

$open_house = [
    ['corporate', 'Corporate', 'openhouse_MH'],
    ['team',      'Team',      'openhouse_Team'],
    ['broker',    'Broker',    'openhouse_Agent'],
];

portal_header($ctx, 'Yard and Open House Sign Templates', 'brand');
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/brand.php')) ?>">← Brand Tools</a></p>
  <h1 class="pt-h1">Yard and Open House Sign Templates</h1>
  <p class="pt-lead">The current Mont Haus yard sign layouts, and the open house signs with the arrow pointing left or right. Tap one to see it larger. To order signs, use the form at the bottom of the page.</p>
  <nav class="pt-jump" aria-label="On this page"><a href="#yard">Yard signs</a><?php foreach ($open_house as [$id, $label]): ?><a href="#open-house-<?= $id ?>">Open house, <?= strtolower($label) ?></a><?php endforeach; ?></nav>

  <section class="pt-section" id="yard">
    <h2 class="pt-h2">Yard signs</h2>
    <div class="pt-gal">
      <?php for ($o = 1; $o <= 3; $o++): ?>
      <figure class="pt-gal-item"><img src="<?= ph($img . "option-{$o}-broker.png") ?>" alt="Yard sign, option <?= $o ?>" loading="lazy" data-lb><figcaption>Option <?= $o ?></figcaption></figure>
      <?php endfor; ?>
    </div>
  </section>

  <?php foreach ($open_house as [$id, $label, $file]): ?>
  <section class="pt-section" id="open-house-<?= $id ?>">
    <h2 class="pt-h2">Open house signs, <?= strtolower($label) ?></h2>
    <div class="pt-gal two">
      <figure class="pt-gal-item"><img src="<?= ph($img . $file . '_qr-L.jpg') ?>" alt="Open house sign, <?= strtolower($label) ?>, arrow left" loading="lazy" data-lb><figcaption>Arrow left</figcaption></figure>
      <figure class="pt-gal-item"><img src="<?= ph($img . $file . '_qr-R.jpg') ?>" alt="Open house sign, <?= strtolower($label) ?>, arrow right" loading="lazy" data-lb><figcaption>Arrow right</figcaption></figure>
    </div>
  </section>
  <?php endforeach; ?>
<?php portal_lightbox(); portal_footer();
