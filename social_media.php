<?php
/**
 * social_media.php — the Social Media area (Nikki, 2026-11-03: "move the
 * Social Media template section into its own area ... just for me the
 * super admin"): the Social Media Plan's tools as cards, reached from the
 * header's Social Media link (inc/_nav.php, super_admin only). The cards
 * were the "Social Media Plan (admin only)" group on templates.php until
 * then. The tools themselves are inc/plan.php's (plan_gate(), super_admin).
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
plan_gate($conn);

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$cards = [
    ['Weekly Plans', 'social_plan.php', 'This week and the four ahead, Monday to Saturday: eight pieces a week, each day\'s suggestion from the rotations. Make or change each piece, save it to the week, mark the week planned and go on to the next.', 'Open the weeks ›'],
    ['Property Post + Set', 'template_property.php', 'Any Mont Haus listing in the feed: New Listing, Featured, Just Sold or Rental. One picture, or a set: the photos in order as one story or carousel, downloaded as a ZIP.', 'Make a post or set ›'],
    ['Broker Feature', 'template_broker.php', 'Spotlight a broker tied to their market with one of the plan\'s hooks, or a milestone: their cutout in front of a location photo under the slate veil.', 'Make a feature ›'],
    ['Location Feature', 'template_location.php', 'An office, an area or a town: the office or a view, the brokers based there with their faces in a row, or a local real estate angle.', 'Make a feature ›'],
    ['Branding Story', 'template_branding.php', 'The seasonal taglines on a mountain photo, centred, with the logo. Two a week in the plan.', 'Make a story ›'],
    ['Digital Ads (prototype)', 'template_ad.php', 'A property ad for the Vail Daily and Aspen Daily News sites: 970 x 250, two or three slides as a looping GIF under 1 MB, or the slides as 2x JPGs for Photoshop. Prototype, 2026-11-09.', 'Make an ad ›'],   // not social media, but Nikki\'s area (super admin) is where the admin-only tools live
];

admin_tool_header([
    'title'   => 'Social Media',
    'hint'    => 'The Social Media Plan: eight pieces a week (3 property, 1 market stats, 1 location, 1 broker, 2 branding) and the tools that make them. Market Snapshot stays under Templates.',
    'actions' => '<a class="btn btn-outline btn-sm" href="templates.php"><i class="ti ti-layout-grid"></i> Templates</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'active'  => 'social',
]);
?>
    <div class="tl-cards">
      <?php foreach ($cards as [$title, $href, $sub, $go]): ?>
      <a class="tl-card" href="<?= $e($href) ?>">
        <h3><?= $e($title) ?></h3>
        <p><?= $e($sub) ?></p>
        <span><?= $e($go) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
<?php admin_tool_footer();
