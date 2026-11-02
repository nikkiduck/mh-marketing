<?php
/**
 * templates.php — Templates on the admin side (Nikki, 2026-10-21): the
 * portal's template tools for any agent, so an admin can make an agent's
 * post or flyer without previewing their portal. Pick the agent at the top
 * (inc/admin_tool_shell.php's picker: every active agent, with their live
 * listing count), then a tool: Listing Post/Story (template_social.php)
 * and Listing Flyer (template_flyer.php) work from that agent's listings;
 * General Social (template_general.php, 2026-10-28) and Market Snapshot
 * (market_snapshot.php) need no agent: General Social acts as the admin
 * themselves (social_context() with ?preview=admin); the Social Media Plan
 * group (2026-11-02, inc/plan.php) is admin only and works from the whole
 * roster and feed. The tools are the
 * portal's own bodies (inc/social_body.php, inc/flyer_body.php), drawn under
 * the admin header; the endpoints act on the chosen account through
 * ?preview=, as the roster's eye icon does. Admins only.
 */
require_once __DIR__ . '/inc/social.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
require_login();
require_role('admin');

$agents   = social_agent_choices($conn);
$agent_id = (int)($_GET['agent'] ?? 0);
if (!isset($agents[$agent_id])) $agent_id = 0;
$q = $agent_id ? '?agent=' . $agent_id : '';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$groups = [
    'Instagram Templates' => [
        ['Listing Post/Story', 'template_social.php' . $q, 'An Instagram or Facebook ready post or story from one of the agent\'s listings.', 'Make a post ›', true],
        ['General Social', 'template_general.php', 'A post, square or story that is not about a listing: a Mont Haus stock photo or your own, your words, the logo where you want it. No agent needed.', 'Make a post ›', false],
        ['Market Snapshot', 'market_snapshot.php', 'One place\'s month on a card: the average sale price and three figures. A post or a story. No agent needed.', 'Make a snapshot ›', false],
    ],
    'Print Templates' => [
        ['Listing Flyer', 'template_flyer.php' . $q, 'A printed Letter-size flyer of one of the agent\'s listings: up to six photos, the details, a banner, their contact lines and a QR code.', 'Make a flyer ›', true],
    ],
    // The Social Media Plan's own templates (Nikki, 2026-11-02): admin only, not on the agent portal. See inc/plan.php.
    'Social Media Plan (admin only)' => [
        ['This Week\'s Plan', 'social_plan.php', 'The plan\'s week, Monday to Saturday: eight pieces, each day\'s suggestion from the rotations, a button to its template.', 'Open the week ›', false],
        ['Property Post + Story Set', 'template_property.php', 'Any Mont Haus listing in the feed: New Listing, Featured, Just Sold or Rental. A post, or a story set: the photos in order as one slideshow, downloaded as a ZIP.', 'Make a post or set ›', false],
        ['Broker Feature', 'template_broker.php', 'Spotlight a broker tied to their market with one of the plan\'s hooks, or a milestone; their headshot, a lifestyle shot or a stock photo.', 'Make a feature ›', false],
        ['Location Feature', 'template_location.php', 'An office, an area or a town: the office or a view, the brokers based there with their faces in a row, or a local real estate angle.', 'Make a feature ›', false],
        ['Branding Story', 'template_branding.php', 'The seasonal taglines on a mountain photo, centred, with the logo. Two a week in the plan.', 'Make a story ›', false],
    ],
];

admin_tool_header([
    'title'   => 'Templates',
    'hint'    => 'The same template tools agents have on their portal, for any agent. Choose the agent, then the tool.',
    'actions' => '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
]);
admin_agent_picker($agents, $agent_id, 'templates.php');
?>
    <?php if (!$agent_id): ?><div class="note">Choose an agent above to open the listing post and flyer tools. General Social, Market Snapshot and the Social Media Plan tools work without one.</div><?php endif; ?>
    <?php foreach ($groups as $heading => $cards): ?>
    <div class="tl-group"><?= $e($heading) ?></div>
    <div class="tl-cards">
      <?php foreach ($cards as [$title, $href, $sub, $go, $needsAgent]): ?>
      <a class="tl-card<?= $needsAgent && !$agent_id ? ' off' : '' ?>" href="<?= $e($href) ?>"<?= $needsAgent && !$agent_id ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
        <h3><?= $e($title) ?></h3>
        <p><?= $e($sub) ?></p>
        <span><?= $e($go) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
<?php admin_tool_footer();
