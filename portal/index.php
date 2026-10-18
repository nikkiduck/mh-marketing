<?php
/**
 * portal/index.php — the agent portal's home. docs/AGENT_PORTAL_PLAN.md.
 *
 * Phase 1 (2026-10-01): the greeting and a card per section. Phase 2/3
 * (2026-10-06): Advertising and Print orders cards. Nothing is shown as
 * "coming soon", so an agent never meets a dead end.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/financials.php';

$ctx = portal_context($conn);
$ids = $ctx['ids'];
$in  = implode(',', array_map('intval', $ids));   // ints from portal_context, never from the request
$acct = (int)$ctx['acct']['id'];

$qr_n = 0;
if (mk_table_exists($conn, 'qr_codes')) {
    $qr_n = (int)$conn->query("SELECT COUNT(*) FROM qr_codes WHERE intake_id IN ({$in})")->fetch_row()[0];
}

/**
 * One scope's advertising, print orders and this year's spend, counted in plain
 * words. Called for the person and once per team they belong to (2026-10-08:
 * team marketing is shown on each member's own portal, as its own section).
 */
function portal_home_counts(mysqli $conn, int $id): array {
    $ads = ['n' => 0, 'now' => 0];
    $mem = ['n' => 0, 'soon' => 0, 'names' => []];   // memberships (2026-10-14): their own card and page
    $r = $conn->query("SELECT * FROM marketing_campaigns WHERE intake_id = {$id} ORDER BY id");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $c) {
        if (portal_is_membership($c)) {
            if (portal_membership_state($c) === 'Ended') continue;
            $mem['n']++; $mem['names'][] = trim((string)($c['name'] ?? '')) ?: 'Membership';
            if (in_array(portal_membership_state($c), ['Renews soon', 'Renewal due'], true)) $mem['soon']++;
            continue;
        }
        $ads['n']++; if (portal_campaign_state($c) === 'Running now') $ads['now']++;
    }
    $ord = ['n' => 0, 'moving' => 0];
    $r = $conn->query("SELECT * FROM marketing_collateral_orders WHERE intake_id = {$id} ORDER BY id");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $o) { $ord['n']++; if (!in_array((string)($o['status'] ?? ''), ['delivered'], true)) $ord['moving']++; }
    // This year's spend, from the one money function (portal/spend.php has the detail).
    $fin = mh_agent_financials($conn, $id);
    $yr = ['broker' => 0.0, 'mh' => 0.0];
    foreach ($fin['months'] as $k => $m) if (substr($k, 0, 4) === date('Y')) { $yr['broker'] += $m['broker']; $yr['mh'] += $m['mh']; }
    return ['ads' => $ads, 'mem' => $mem, 'ord' => $ord, 'fin' => $fin, 'yr' => $yr];
}

/** The Advertising, Print orders, Memberships and Spend cards for one scope. $who is "You" for the person, the team's name for a team. */
function portal_home_cards(array $ctx, array $scope, array $n): void {
    $ads = $n['ads']; $ord = $n['ord']; $fin = $n['fin']; $yr = $n['yr']; $mem = $n['mem'] ?? ['n' => 0, 'soon' => 0, 'names' => []];
    $q   = $scope['team'] ? ['team' => (int)$scope['id']] : [];
    $you = $scope['team'] ? 'The team has' : 'You have';
    $pre = $scope['team'] ? $scope['short'] . ' ' : '';   // "WB Advertising", "WB Collateral", "WB Marketing Spend"
    $who = $scope['team'] ? 'the team has' : 'you\'ve';
    // An empty card is scrolling for nothing (Nikki, 2026-10-17): the kinds with
    // nothing in them become one line under the cards that have something.
    $empty = [];
    if ($ads['n'] === 0) $empty[] = 'advertising';
    if ($ord['n'] === 0) $empty[] = 'collateral';
    if ($mem['n'] === 0) $empty[] = 'memberships';
    if (!$fin['months']) $empty[] = 'marketing spend';
    $none = $empty ? 'No ' . (count($empty) > 1 ? implode(', ', array_slice($empty, 0, -1)) . ' or ' . end($empty) : $empty[0]) . ' yet.' : '';
    ?>
    <?php if ($ads['n'] > 0): ?>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/advertising.php', $q)) ?>">
      <h2 class="pt-card-h"><?= ph($pre) ?>Advertising</h2>
      <p class="pt-card-sub"><?php
        if ($ads['n'] === 0)       echo 'No ad placements yet.';
        elseif ($ads['now'] === 1) echo "{$you} 1 ad running now" . ($ads['n'] > 1 ? ', and ' . ($ads['n'] - 1) . ' more on record' : '') . '.';
        elseif ($ads['now'] > 1)   echo "{$you} {$ads['now']} ads running now" . ($ads['n'] > $ads['now'] ? ', and ' . ($ads['n'] - $ads['now']) . ' more on record' : '') . '.';
        else                       echo ($ads['n'] === 1 ? "{$you} 1 ad placement" : "{$you} {$ads['n']} ad placements") . ' on record.';
      ?></p>
      <span class="pt-card-go">See advertising ›</span>
    </a>
    <?php endif; ?>
    <?php if ($ord['n'] > 0): ?>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/orders.php', $q)) ?>">
      <h2 class="pt-card-h"><?= ph($pre) ?>Collateral</h2>
      <p class="pt-card-sub"><?php
        if ($ord['n'] === 0)         echo 'No collateral yet.';
        elseif ($ord['moving'] === 1) echo "{$you} 1 order on the way" . ($ord['n'] > 1 ? ', and ' . ($ord['n'] - 1) . ' delivered' : '') . '.';
        elseif ($ord['moving'] > 1)   echo "{$you} {$ord['moving']} orders on the way" . ($ord['n'] > $ord['moving'] ? ', and ' . ($ord['n'] - $ord['moving']) . ' delivered' : '') . '.';
        else                          echo ($ord['n'] === 1 ? "{$you} 1 collateral order" : "{$you} {$ord['n']} collateral orders") . '.';
      ?></p>
      <span class="pt-card-go">See collateral ›</span>
    </a>
    <?php endif; ?>
    <?php if ($mem['n'] > 0): ?>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/memberships.php', $q)) ?>">
      <h2 class="pt-card-h"><?= ph($pre) ?>Memberships</h2>
      <p class="pt-card-sub"><?php
        // Chambers, associations and clubs Mont Haus has joined on their behalf (2026-10-14); ended ones are not counted.
        $is = $scope['team'] ? 'The team is' : 'You are';
        if ($mem['n'] === 0)     echo 'No memberships yet.';
        elseif ($mem['n'] === 1) echo "{$is} a member of " . ph($mem['names'][0]) . ($mem['soon'] ? ', renewing soon.' : '.');
        else                     echo "{$is} a member of {$mem['n']} organizations" . ($mem['soon'] ? " ({$mem['soon']} renewing soon)." : '.');
      ?></p>
      <span class="pt-card-go">See memberships ›</span>
    </a>
    <?php endif; ?>
    <?php if ($fin['months']): ?>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/spend.php', $q)) ?>">
      <h2 class="pt-card-h"><?= $pre !== '' ? ph($pre) . 'Marketing Spend' : 'Spend' ?></h2>
      <p class="pt-card-sub"><?= $fin['months']
          ? "This year {$who} spent " . ph(portal_money($yr['broker'])) . ' and Mont Haus ' . ph(portal_money($yr['mh']))
            . ', for a total of ' . ph(portal_money($yr['broker'] + $yr['mh'])) . ' on marketing and advertising.'
          : 'No marketing spend yet.' ?></p>
      <span class="pt-card-go">See details ›</span>
    </a>
    <?php endif; ?>
    <?php if ($none !== ''): ?><p class="pt-none"><?= ph($none) ?></p><?php endif; ?>
    <?php
}

$own = portal_home_counts($conn, $acct);

// The greeting line: email and phone (Nikki, 2026-10-13).
$who   = $conn->query("SELECT cell_phone, mh_email FROM marketing_intakes WHERE id = {$acct}")->fetch_assoc() ?: [];
$phone = portal_phone((string)($who['cell_phone'] ?? ''));
$email = trim((string)($who['mh_email'] ?? '')) ?: ($ctx['preview'] ? '' : (string)$ctx['user']['email']);   // never the admin's own address in a preview

$photo = trim((string)($ctx['acct']['headshot_face_url'] ?? '')) ?: trim((string)($ctx['acct']['headshot_url'] ?? ''));
$is_team = ($ctx['acct']['entity_type'] ?? 'agent') === 'team';

portal_header($ctx, 'Home', 'home');
?>
  <div class="pt-hello">
    <?php if ($photo !== ''): ?><img class="pt-avatar" src="<?= ph($photo) ?>" alt=""><?php endif; ?>
    <div>
      <h1 class="pt-h1">Hello, <?= ph(portal_greeting_name($ctx)) ?></h1>
      <?php // Email and phone are links in the page's own colour, no underline; a phone shows them on two lines (Nikki, 2026-10-17). ?>
      <p class="pt-lead pt-contact" style="margin:0"><?php if ($is_team): ?>Your team's marketing at Mont Haus.<?php else: ?>
        <?php if ($email !== ''): ?><a href="mailto:<?= ph($email) ?>"><?= ph($email) ?></a><?php endif; ?>
        <?php if ($email !== '' && $phone !== ''): ?><span class="pt-dot"> · </span><?php endif; ?>
        <?php if ($phone !== ''): ?><a href="tel:<?= ph(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= ph($phone) ?></a><?php endif; ?>
      <?php endif; ?></p>
    </div>
  </div>

  <?php // Home mirrors the menu (Nikki, 2026-10-12): My Profile, My Marketing, My Tools. ?>
  <div class="pt-cards">
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/profile.php')) ?>">
      <h2 class="pt-card-h">My Profile</h2>
      <p class="pt-card-sub">Your public-facing info, Hot Sheet subscriptions, service areas and MLS boards.</p>
      <span class="pt-card-go">See and update ›</span>
    </a>
  </div>

  <h2 class="pt-h2 pt-home-h">My Marketing</h2>
  <div class="pt-cards">
    <?php portal_home_cards($ctx, $ctx['scopes'][0], $own); ?>
  </div>
  <?php // A team's marketing follows under its own heading, "WB Marketing" (Nikki, 2026-10-17); the cards keep the initials (2026-10-14).
        foreach ($ctx['teams'] as $t): $ts = portal_scope($ctx, $t['id']); ?>
  <h2 class="pt-h2 pt-home-h"><?= ph($ts['short']) ?> Marketing</h2>
  <div class="pt-cards">
    <?php portal_home_cards($ctx, $ts, portal_home_counts($conn, $t['id'])); ?>
  </div>
  <?php endforeach; ?>

  <h2 class="pt-h2 pt-home-h">My Tools</h2>
  <div class="pt-cards">
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/elevation.php')) ?>">
      <h2 class="pt-card-h">Elevation Planner</h2>
      <p class="pt-card-sub">Your business plan for the year, and how the year is tracking against it.</p>
      <span class="pt-card-go">Open the planner ›</span>
    </a>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/market.php')) ?>">
      <h2 class="pt-card-h">Market Report</h2>
      <p class="pt-card-sub">The month in every market Mont Haus serves: medians, sales, days on market and supply by county, with two years of trend.</p>
      <span class="pt-card-go">Read the report ›</span>
    </a>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/social.php')) ?>">
      <h2 class="pt-card-h">Templates</h2>
      <p class="pt-card-sub">Social templates: Instagram posts and stories for your listings, and a market snapshot card. Print templates: a listing flyer. More coming soon.</p>
      <span class="pt-card-go">See the templates ›</span>
    </a>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/qr.php', $qr_n === 0 ? ['request' => 1] : [])) ?>">
      <h2 class="pt-card-h">QR Codes</h2>
      <p class="pt-card-sub"><?= $qr_n === 0 ? 'You do not have any QR codes yet. Request one for a marketing project.'
          : ($qr_n === 1 ? 'You have 1 QR code. See where it points, or change it.'
                         : "You have {$qr_n} QR codes. See where each one points, or change it.") ?></p>
      <span class="pt-card-go"><?= $qr_n === 0 ? 'Request ›' : 'Review + Edit ›' ?></span>
    </a>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/brand.php')) ?>">
      <h2 class="pt-card-h">Brand Tools</h2>
      <p class="pt-card-sub">MH brand guidelines, with logo files you can download, plus business card and yard sign designs.</p>
      <span class="pt-card-go">See the brand ›</span>
    </a>
  </div>

<?php portal_footer();
