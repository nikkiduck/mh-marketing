<?php
/**
 * social_plan.php — This Week's Plan (the Social Media Plan, Nikki,
 * 2026-11-02): the plan's sample week, Monday to Saturday, with this week's
 * suggested pick for each piece and a button that opens the right template
 * with it preselected. The picks step through rotations by the ISO week
 * (plan_week(): markets through the Market Snapshot's places, brokers
 * through the approved roster, locations through the offices and areas,
 * hooks through the plan's six), so the same week always suggests the same
 * thing and next week moves on by one; ?week=YYYY-Www looks ahead or back.
 * The listing suggestions are the feed's newest new listing, the highest
 * priced live one and the latest closing. The plan's words and notes sit
 * under the table. Admins only; the tools are in inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';
require_once __DIR__ . '/inc/admin_tool_shell.php';
$ctx = plan_gate($conn);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$week  = plan_week((string)($_GET['week'] ?? ''));
$idx   = $week['index'];
$def   = plan_definition();
$agents = plan_agents($conn);
$withPhoto = array_values(array_filter($agents, fn($a) => mk_photo_file($a['headshot_url']) !== ''));
$broker = plan_rotate($withPhoto ?: array_values($agents), $idx);
$hookIdx = count(plan_hooks()) ? (($idx % count(plan_hooks())) + count(plan_hooks())) % count(plan_hooks()) : 0;
$hook = $broker ? plan_fill(plan_hooks()[$hookIdx], ['name' => $broker['name'], 'first' => $broker['first'], 'market' => $broker['market']]) : '';
$locs = plan_locations();
$locPool = array_values(array_filter($locs, fn($l) => in_array($l['kind'], ['office', 'area'], true)));
$loc = plan_rotate($locPool, $idx);
$angles = ['office', 'team', 'local'];
$angle = $loc ? ($loc['kind'] === 'office' ? plan_rotate($angles, intdiv($idx, max(1, count($locPool)))) : plan_rotate(['team', 'local'], intdiv($idx, max(1, count($locPool))))) : 'office';
$cards = market_card_defs();
$mKey = plan_rotate(array_keys($cards), $idx); $mNext = plan_rotate(array_keys($cards), $idx + 1);
$listings = plan_listings($conn);
$groups = plan_listing_groups($listings);
$pickNew = ($groups['New Listings'] ?? [])[0] ?? (($groups['Listings'] ?? [])[0] ?? null);
$pickFeat = ($groups['Listings'] ?? [])[0] ?? (($groups['New Listings'] ?? [])[0] ?? null);
$pickSold = ($groups['Just Sold'] ?? [])[0] ?? null;
$pickRent = ($groups['Rentals'] ?? [])[0] ?? null;
$season = plan_seasons()[$week['season']];
$lwords = fn(?array $l) => $l ? (($l['address'] !== '' ? $l['address'] : 'Address withheld') . ', ' . $l['city'] . ($l['price_text'] !== '' ? ' · ' . $l['price_text'] : '') . ($l['agent'] !== '' ? ' · ' . $l['agent'] : '')) : 'Nothing in the feed to suggest';
$lq = fn(?array $l, string $v, string $size) => $l ? 'template_property.php?' . http_build_query(['listing' => $l['id'], 'variant' => $v, 'size' => $size]) : '';

// The rows: day => [piece words, the pick's words, [[button words, href], …]]
$rows = [
    ['Monday',    'Property · New Listing',       $lwords($pickNew),
                  $pickNew ? [['Post', $lq($pickNew, 'new', 'post')], ['Story set', $lq($pickNew, 'new', 'story')]] : []],   // the story size starts with the set ticked
    ['Tuesday',   'Market Stats',                 ($cards[$mKey]['title'] ?? '') . ' (next week: ' . ($cards[$mNext]['title'] ?? '') . ')',
                  [['Story', 'market_snapshot.php?market=' . rawurlencode((string)$mKey)]]],
    ['Tuesday',   'Branding',                     $season['name'] . ': "' . str_replace("\n", ' ', $season['taglines'][0]) . '"',
                  [['Story', 'template_branding.php?season=' . rawurlencode($week['season']) . '&size=story']]],
    ['Wednesday', 'Property · Featured Listing',  $lwords($pickFeat),
                  $pickFeat ? [['Post', $lq($pickFeat, 'featured', 'post')], ['Story set', $lq($pickFeat, 'featured', 'story')]] : []],
    ['Thursday',  'Location Feature',             $loc ? (($loc['kind'] === 'office' ? $loc['label'] . ' office' : $loc['label']) . ' · ' . ['office' => 'the office', 'team' => 'team in place', 'local' => 'local angle'][$angle]) : '',
                  $loc ? [['Story', 'template_location.php?' . http_build_query(['loc' => $loc['key'], 'angle' => $angle, 'size' => 'story'])]] : []],
    ['Friday',    'Property · Just Sold or Rental', $pickSold ? 'Just Sold: ' . $lwords($pickSold) : ($pickRent ? 'Rental: ' . $lwords($pickRent) : 'No closing in the last ' . PLAN_CLOSED_DAYS . ' days and no rental in the feed'),
                  $pickSold ? [['Post', $lq($pickSold, 'sold', 'post')], ['Story set', $lq($pickSold, 'sold', 'story')]] : ($pickRent ? [['Post', $lq($pickRent, 'rental', 'post')], ['Story set', $lq($pickRent, 'rental', 'story')]] : [])],
    ['Friday',    'Branding',                     $season['name'] . ': "' . str_replace("\n", ' ', $season['taglines'][1] ?? $season['taglines'][0]) . '"',
                  [['Story', 'template_branding.php?season=' . rawurlencode($week['season']) . '&size=story']]],
    ['Saturday',  'Broker Feature',               $broker ? $broker['name'] . ' · ' . $broker['market'] . ' · "' . $hook . '"' : 'No approved broker on the roster',
                  $broker ? [['Story', 'template_broker.php?' . http_build_query(['agent' => $broker['id'], 'hook' => $hookIdx, 'size' => 'story'])], ['Post', 'template_broker.php?' . http_build_query(['agent' => $broker['id'], 'hook' => $hookIdx, 'size' => 'post'])]] : []],
];

admin_tool_header([
    'title'   => 'This Week\'s Plan',
    'hint'    => 'The Social Media Plan: eight pieces a week (3 property, 1 market stats, 1 location, 1 broker, 2 branding). Each day\'s suggestion follows a rotation; open it, or pick something else inside the tool.',
    'actions' => '<a class="btn btn-outline btn-sm" href="templates.php"><i class="ti ti-layout-grid"></i> Templates</a>'
               . '<a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>',
    'css'     => '
    .pl-week { display:flex; align-items:center; gap:14px; margin:0 0 18px; flex-wrap:wrap; }
    .pl-week h2 { font-family:"Cormorant Garamond",Georgia,serif; font-weight:500; font-size:26px; margin:0; color:#1f242c; }
    .pl-week a { font-size:13px; color:#4a5569; text-decoration:none; border:1px solid #dfe3e8; border-radius:999px; padding:5px 12px; background:#fff; }
    .pl-week a:hover { border-color:#4a5566; }
    .pl-week .pl-this { color:#6b7a8f; font-size:13px; }
    .pl-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #dfe3e8; border-radius:14px; overflow:hidden; }
    .pl-table th, .pl-table td { text-align:left; padding:12px 14px; border-top:1px solid #dfe3e8; vertical-align:top; font-size:14px; }
    .pl-table th { font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#6b7a8f; font-weight:600; border-top:0; background:#eceff3; }
    .pl-table td.day { font-weight:600; color:#1f242c; white-space:nowrap; width:110px; }
    .pl-table td.piece { color:#434b57; white-space:nowrap; width:220px; }
    .pl-table td.pick { color:#1f242c; }
    .pl-table td.go { white-space:nowrap; text-align:right; }
    .pl-table td.go a { display:inline-block; font-size:13px; font-weight:600; color:#fff; background:#4a5566; border-radius:999px; padding:7px 14px; text-decoration:none; margin-left:6px; }
    .pl-table td.go a:hover { background:#353e4b; }
    .pl-table tr.alt td { border-top-style:dashed; }
    .pl-types { display:grid; grid-template-columns:1fr; gap:14px; margin-top:26px; }
    @media (min-width:760px) { .pl-types { grid-template-columns:1fr 1fr; } }
    .pl-type { background:#fff; border:1px solid #dfe3e8; border-radius:14px; padding:16px 18px; }
    .pl-type h3 { font-family:"Cormorant Garamond",Georgia,serif; font-weight:500; font-size:22px; margin:0 0 2px; color:#1f242c; }
    .pl-type .freq { font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#55637a; margin:0 0 8px; }
    .pl-type p, .pl-type li { font-size:14px; color:#434b57; margin:0 0 6px; line-height:1.5; }
    .pl-type ul { margin:0; padding-left:18px; }
    .pl-type a.tool { font-size:13px; font-weight:600; color:#4a5569; text-decoration:none; }
    .pl-type a.tool:hover { text-decoration:underline; }
    .pl-rot { margin-top:26px; font-size:13px; color:#6b7a8f; line-height:1.6; }
    .pl-rot b { color:#434b57; font-weight:600; }
    @media (max-width:700px) { .pl-table td.piece, .pl-table td.day { white-space:normal; width:auto; } .pl-table td.go { text-align:left; } .pl-table td.go a { margin:4px 6px 0 0; } }
    ',
]);
?>
    <div class="pl-week">
      <a href="social_plan.php?week=<?= $e($week['prev']) ?>">‹ Previous week</a>
      <h2>Week of <?= $e($week['label']) ?></h2>
      <a href="social_plan.php?week=<?= $e($week['next']) ?>">Next week ›</a>
      <?php if ($week['key'] !== $week['this']): ?><a href="social_plan.php">This week</a><?php else: ?><span class="pl-this">This week · <?= $e($season['name']) ?> season</span><?php endif; ?>
    </div>
    <table class="pl-table">
      <thead><tr><th>Day</th><th>Content</th><th>This week's suggestion</th><th></th></tr></thead>
      <tbody>
      <?php $prevDay = ''; foreach ($rows as [$day, $piece, $pick, $buttons]): ?>
        <tr<?= $day === $prevDay ? ' class="alt"' : '' ?>>
          <td class="day"><?= $day === $prevDay ? '' : $e($day) ?></td>
          <td class="piece"><?= $e($piece) ?></td>
          <td class="pick"><?= $e($pick) ?></td>
          <td class="go"><?php foreach ($buttons as [$w, $href]): ?><a href="<?= $e($href) ?>"><?= $e($w) ?> ›</a><?php endforeach; ?></td>
        </tr>
      <?php $prevDay = $day; endforeach; ?>
      </tbody>
    </table>

    <div class="pl-types">
      <?php foreach ($def as $k => $d): ?>
      <div class="pl-type">
        <h3><?= $e($d['name']) ?></h3>
        <p class="freq"><?= $e($d['per_week']) ?></p>
        <p><?= $e($d['what']) ?></p>
        <?php if ($d['notes']): ?><ul><?php foreach ($d['notes'] as $n): ?><li><?= $e($n) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <p style="margin-top:10px"><a class="tool" href="<?= $e($d['tool']) ?>">Open the <?= $e($k === 'market' ? 'Market Snapshot' : $d['name']) ?> tool ›</a></p>
      </div>
      <?php endforeach; ?>
    </div>

    <p class="pl-rot">
      <b>How the suggestions rotate.</b> Markets step through the Market Snapshot's <?= count($cards) ?> places in order (<?= $e(implode(', ', array_slice(array_column($cards, 'title'), 0, 6))) ?>, …),
      brokers through the <?= count($withPhoto) ?> approved agents with a headshot by name, locations through the <?= count($locPool) ?> offices and areas, hooks through the plan's six,
      and the branding season follows the calendar. Each is one week on from the week before, so a week always suggests the same thing whenever you look at it.
    </p>
<?php admin_tool_footer();
