<?php
/**
 * social_media.php — the Social Media area: every tool that makes something
 * for Instagram or Facebook, on one page (Nikki, 2026-11-28: "can we combine
 * Social Media and Templates? It's weird that they are two sections").
 *
 * Until that day this page held the Social Media Plan's tools alone
 * (super_admin, since 2026-11-03) and templates.php held the agents' tools
 * for any agent (every admin). They are one list now, in groups; the print
 * tools that sat on templates.php moved to digital_print.php (Digital +
 * Print).
 *
 * Who sees what did not change: the page opens for every admin, and a card
 * is drawn only for a role that can open its page. The Plan's tools
 * (inc/plan.php, plan_gate()) are the super admin's; Listing Post/Story,
 * General Social and Market Snapshot are every admin's. A group with no
 * card for this person is not drawn.
 *
 * Listing Post/Story works from one agent's listings, so its group carries
 * the agent picker (inc/admin_tool_shell.php); nothing else here needs one.
 *
 * To add a tool: one row in $groups.
 */
require_once __DIR__ . '/inc/social.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
require_login();
require_role('admin');

$super    = is_super_admin();
$agents   = social_agent_choices($conn);
$agent_id = (int)($_GET['agent'] ?? 0);
if (!isset($agents[$agent_id])) $agent_id = 0;
$q = $agent_id ? '?agent=' . $agent_id : '';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// heading => [ note under the heading, the agent picker above the cards?, cards ]
// a card: title, href, what it is, the link's words, shown, needs an agent
$groups = [
    'The Plan' => ['', false, [
        ['Weekly Plans', 'social_plan.php', 'This week and the four ahead, Monday to Saturday: eight pieces a week, each day\'s suggestion from the rotations. Make or change each piece, save it to the week, mark the week planned and go on to the next.', 'Open the weeks ›', $super, false],
        ['Link in Bio', 'social_linkinbio.php', 'The covers on monthaus.com/linkinbio, the link in the Instagram bio: each post\'s cover opens the listing, the agent or any page you choose. Add a post from its week, or any picture by hand; reorder and remove.', 'Open the bio ›', $super, false],
    ]],
    'Listings' => ['', false, [
        ['Property Post + Set', 'template_property.php', 'Any Mont Haus listing in the feed: New Listing, Featured, Just Sold or Rental. One picture, or a set: the photos in order as one story or carousel, downloaded as a ZIP.', 'Make a post or set ›', $super, false],
        ['Captions', 'social_captions.php', 'The Suggested Caption of every Mont Haus listing on one page: the headline, the details, the description, who to tag and the hashtags. Read them, edit them, save them; the Property tool shows the saved one.', 'Review the captions ›', $super, false],
    ]],
    'Features + Branding' => ['', false, [
        ['Broker Feature', 'template_broker.php', 'Spotlight a broker tied to their market with one of the plan\'s hooks, or a milestone: their cutout in front of a location photo under the slate veil.', 'Make a feature ›', $super, false],
        ['Location Feature', 'template_location.php', 'An office, an area or a town: the office or a view, the brokers based there with their faces in a row, or a local real estate angle.', 'Make a feature ›', $super, false],
        ['Branding Story', 'template_branding.php', 'The seasonal taglines on a mountain photo, centred, with the logo. Two a week in the plan.', 'Make a story ›', $super, false],
        ['General Social', 'template_general.php', 'A post, square or story that is not about a listing: a Mont Haus stock photo or your own, your words, the logo where you want it.', 'Make a post ›', true, false],
    ]],
    'Market Stats + Video' => ['', false, [
        ['Market Snapshot', 'market_snapshot.php', 'One place\'s month on a card: the average sale price and three figures. A post or a story.', 'Make a snapshot ›', true, false],
        ['Video', 'template_video.php', 'A story or reel as an MP4: a listing\'s photos in slow motion with cross-fades, or a video clip you upload, with the logo, the words and a closing card.', 'Make a video ›', $super, false],
    ]],
    'For an Agent' => ['The tool agents have on their portal, for any agent. Choose the agent, then open it.', true, [
        ['Listing Post/Story', 'template_social.php' . $q, 'An Instagram or Facebook ready post or story from one of the agent\'s listings, with their name and contact info.', 'Make a post ›', true, true],
    ]],
];

admin_tool_header([
    'title'   => 'Social Media',
    'hint'    => 'Everything made for Instagram and Facebook: the weekly plan, the tools that make each piece, and the agents\' post tool for any agent.',
    'actions' => '<a class="btn btn-outline btn-sm" href="digital_print.php"><i class="ti ti-ad-2"></i> Digital + Print</a>'
               . '<a class="btn btn-outline btn-sm" href="agents.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'active'  => 'social',
    'css'     => '.tl-sub { font-size:13px; color:#6b7280; margin:-4px 0 12px; }',
]);

$first = true;
foreach ($groups as $heading => [$sub, $picker, $cards]):
    $cards = array_values(array_filter($cards, fn($c) => $c[4]));
    if (!$cards) continue;
?>
    <div class="tl-group"<?= $first ? ' style="margin-top:0"' : '' ?>><?= $e($heading) ?></div>
    <?php if ($sub !== ''): ?><p class="tl-sub"><?= $e($sub) ?></p><?php endif; ?>
    <?php if ($picker) admin_agent_picker($agents, $agent_id, 'social_media.php'); ?>
    <div class="tl-cards">
      <?php foreach ($cards as [$title, $href, $text, $go, , $needsAgent]): $off = $needsAgent && !$agent_id; ?>
      <a class="tl-card<?= $off ? ' off' : '' ?>" href="<?= $e($href) ?>"<?= $off ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
        <h3><?= $e($title) ?></h3>
        <p><?= $e($text) ?></p>
        <span><?= $e($go) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
<?php
    $first = false;
endforeach;
admin_tool_footer();
