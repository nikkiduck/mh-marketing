<?php
/**
 * digital_print.php — Digital + Print: the landing for what is made for a
 * newspaper's site or for paper (Nikki, 2026-11-28: "Move the Print
 * Templates to the Digital Ads card and change title to Digital + Print.
 * Create a landing page here with my options").
 *
 * Digital Ads (template_ad.php) was a card of its own on Admin Home since
 * 2026-11-18; Listing Flyer and One-Page Brochure (template_flyer.php) were
 * the Print Templates group on templates.php. Who may open each did not
 * change: Digital Ads is the super admin's (plan_gate()), the flyer and the
 * brochure are every admin's and work from one agent's listings, so the
 * Print group carries the agent picker (inc/admin_tool_shell.php). A group
 * with no card for this person is not drawn.
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
    'Digital' => ['', false, [
        ['Digital Ads', 'template_ad.php', 'A property ad for the Vail Daily and Aspen Daily News sites in seven sizes: two or three slides as a looping GIF under 1 MB, or the slides as 2x JPGs for Photoshop.', 'Make an ad ›', $super, false],
    ]],
    'Print' => ['Made from one agent\'s listings, with their contact info. Choose the agent, then the piece.', true, [
        ['Listing Flyer', 'template_flyer.php' . $q, 'A printed letter-sized flyer of one of the agent\'s listings, for a window or a sign: up to six photos, the details, their contact info and a QR code.', 'Make a flyer ›', true, true],
        ['One-Page Brochure', 'template_flyer.php' . ($q !== '' ? $q . '&layout=brochure' : '?layout=brochure'), 'A single-sided brochure of one of the agent\'s listings: three photos, the details and the full description, with their contact info and a QR code.', 'Make a brochure ›', true, true],
    ]],
];

admin_tool_header([
    'title'   => 'Digital + Print',
    'hint'    => 'Ads for the newspapers\' sites, and the printed pieces: the listing flyer and the one-page brochure.',
    'actions' => '<a class="btn btn-outline btn-sm" href="social_media.php"><i class="ti ti-brand-instagram"></i> Social Media</a>'
               . '<a class="btn btn-outline btn-sm" href="agents.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'css'     => '.tl-sub { font-size:13px; color:#6b7280; margin:-4px 0 12px; }',
]);

$first = true;
foreach ($groups as $heading => [$sub, $picker, $cards]):
    $cards = array_values(array_filter($cards, fn($c) => $c[4]));
    if (!$cards) continue;
?>
    <div class="tl-group"<?= $first ? ' style="margin-top:0"' : '' ?>><?= $e($heading) ?></div>
    <?php if ($sub !== ''): ?><p class="tl-sub"><?= $e($sub) ?></p><?php endif; ?>
    <?php if ($picker) admin_agent_picker($agents, $agent_id, 'digital_print.php'); ?>
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
