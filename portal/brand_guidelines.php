<?php
/**
 * portal/brand_guidelines.php — the Brand Guidelines inside the portal
 * (Nikki, 2026-10-18: the Brand Tools pages need the portal's header and
 * menu so people can get back). The content is the public page's own
 * (brand-guidelines/index.html, still served there for vendors), lifted and
 * scoped by inc/brand_embed.php, so there is one copy to edit.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/brand_embed.php';

$ctx = portal_context($conn);
$bg  = portal_brand_guidelines();
$css = portal_scope_css((string)@file_get_contents(__DIR__ . '/../brand-guidelines/assets/css/brand-guidelines.css'));

portal_header($ctx, 'Brand Guidelines', 'brand');
?>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://use.typekit.net/keq2dji.css">
<style><?= $css ?>
.bg-embed { margin-top: 8px; }
.bg-embed .bg-section { scroll-margin-top: 70px; }
</style>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/brand.php')) ?>">← Brand Tools</a></p>
  <h1 class="pt-h1">Brand Guidelines</h1>
  <p class="pt-lead">How the Mont Haus identity is used, and the logo files to download. Print partners and designers can be sent the public copy at <a href="/brand-guidelines/" target="_blank" rel="noopener">marketing.monthaus.com/brand-guidelines</a>.</p>
  <?php if ($bg['jump']): // the page's own sections, under the title where they read as part of the page (Nikki, 2026-10-18) ?>
  <nav class="pt-jump" aria-label="On this page"><?php foreach ($bg['jump'] as $id => $label): ?><a href="#<?= ph($id) ?>"><?= ph($label) ?></a><?php endforeach; ?></nav>
  <?php endif; ?>
  <?php if ($bg['html'] === ''): ?>
    <div class="pt-card"><p class="pt-card-sub" style="margin:0">The guidelines could not be loaded. Email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p></div>
  <?php else: ?>
  <div class="bg-embed bg-content"><?= $bg['html'] ?></div>
  <?php endif; ?>
<?php portal_footer();
