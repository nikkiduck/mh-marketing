<?php
/**
 * templates.php — Templates on the admin side (Nikki, 2026-10-21): the
 * portal's template tools for any agent, so an admin can make an agent's
 * post or flyer without previewing their portal. Pick the agent at the top
 * (inc/admin_tool_shell.php's picker: every active agent, with their live
 * listing count), then a tool: Listing Post/Story (template_social.php)
 * and Listing Flyer (template_flyer.php) work from that agent's listings;
 * General Social (template_general.php, 2026-10-28) needs no listing but
 * still acts through the chosen account (its render and export endpoints
 * do); Market Snapshot (market_snapshot.php) needs no agent. The tools are the
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
        ['General Social', 'template_general.php' . $q, 'A post, square or story that is not about a listing: a Mont Haus stock photo or your own, your words, the logo where you want it.', 'Make a post ›', true],
        ['Market Snapshot', 'market_snapshot.php', 'One place\'s month on a card: the average sale price and three figures. A post or a story. No agent needed.', 'Make a snapshot ›', false],
    ],
    'Print Templates' => [
        ['Listing Flyer', 'template_flyer.php' . $q, 'A printed Letter-size flyer of one of the agent\'s listings: up to six photos, the details, a banner, their contact lines and a QR code.', 'Make a flyer ›', true],
    ],
];

admin_tool_header([
    'title'   => 'Templates',
    'hint'    => 'The same template tools agents have on their portal, for any agent. Choose the agent, then the tool.',
    'actions' => '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
]);
admin_agent_picker($agents, $agent_id, 'templates.php');
?>
    <?php if (!$agent_id): ?><div class="note">Choose an agent above to open the post and flyer tools (General Social needs one too, for its download). Market Snapshot works without one.</div><?php endif; ?>
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
