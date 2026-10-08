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

// Advertising and print orders: the account's rows, counted in plain words.
$ads = ['n' => 0, 'now' => 0];
$r = $conn->query("SELECT * FROM marketing_campaigns WHERE intake_id = {$acct} ORDER BY id");
foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $c) { $ads['n']++; if (portal_campaign_state($c) === 'Running now') $ads['now']++; }
$ord = ['n' => 0, 'moving' => 0];
$r = $conn->query("SELECT * FROM marketing_collateral_orders WHERE intake_id = {$acct} ORDER BY id");
foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $o) { $ord['n']++; if (!in_array((string)($o['status'] ?? ''), ['delivered'], true)) $ord['moving']++; }

// This year's spend, from the one money function (portal/spend.php has the detail).
$fin = mh_agent_financials($conn, $acct);
$yr = ['broker' => 0.0, 'mh' => 0.0];
foreach ($fin['months'] as $k => $m) if (substr($k, 0, 4) === date('Y')) { $yr['broker'] += $m['broker']; $yr['mh'] += $m['mh']; }

// Hot Sheets (2026-10-07): the signed-in person's own subscription, by their address.
// An admin previewing sees the ACCOUNT's subscription (same rule as portal/hotsheets.php);
// until 2026-10-08 the card read the admin's own row, so every preview listed the admin's areas.
$hs = null;
if (mk_table_exists($conn, 'hs_subscribers') && mk_column_exists($conn, 'hs_subscribers', 'areas')) {
    require_once __DIR__ . '/../inc/boards.php';
    require_once __DIR__ . '/../inc/agent_lifecycle.php';
    if ($ctx['preview']) {
        $hs = mk_hs_subscription($conn, (int)$ctx['acct']['id']);
    } else {
        $st = $conn->prepare("SELECT frequency, areas, is_active, unsubscribed_at FROM hs_subscribers WHERE email = ? LIMIT 1");
        $em = strtolower(trim((string)$ctx['user']['email']));
        $st->bind_param('s', $em); $st->execute();
        $hs = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }
}

$photo = trim((string)($ctx['acct']['headshot_face_url'] ?? '')) ?: trim((string)($ctx['acct']['headshot_url'] ?? ''));
$is_team = ($ctx['acct']['entity_type'] ?? 'agent') === 'team';

portal_header($ctx, 'Home', 'home');
?>
  <div class="pt-hello">
    <?php if ($photo !== ''): ?><img class="pt-avatar" src="<?= ph($photo) ?>" alt=""><?php endif; ?>
    <div>
      <h1 class="pt-h1">Hello, <?= ph(portal_greeting_name($ctx)) ?></h1>
      <p class="pt-lead" style="margin:0"><?= $is_team ? 'Your team\'s marketing at Mont Haus.' : 'Your marketing at Mont Haus.' ?></p>
    </div>
  </div>

  <div class="pt-cards">
    <?php if ($hs !== null || mk_table_exists($conn, 'hs_subscribers')): // first (Nikki, 2026-10-07) ?>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/hotsheets.php')) ?>">
      <h2 class="pt-card-h">Internal Hot Sheets</h2>
      <p class="pt-card-sub"><?php
        $hs_areas = $hs ? mk_areas_decode($hs['areas']) : [];
        if ($hs && (int)$hs['is_active'] && !$hs['unsubscribed_at'] && $hs_areas) {
            echo 'You get the ' . ph(implode(', ', array_map('mk_area_name', $hs_areas))) . ' Hot Sheet' . (count($hs_areas) === 1 ? '' : 's')
               . ' ' . ($hs['frequency'] === 'daily' ? 'every day' : 'twice a week') . '.';
        } elseif ($hs && (int)$hs['is_active'] && !$hs['unsubscribed_at']) {
            echo 'You are subscribed but have not chosen any areas yet, so nothing is being sent.';
        } else {
            echo 'Emailed Mont Haus listings, pocket listings, buyer reps and rentals, for the areas you choose.';
        }
      ?></p>
      <span class="pt-card-go">Customize ›</span>
    </a>
    <?php endif; ?>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/advertising.php')) ?>">
      <h2 class="pt-card-h">Advertising</h2>
      <p class="pt-card-sub"><?php
        if ($ads['n'] === 0)       echo 'No ad placements yet.';
        elseif ($ads['now'] === 1) echo 'You have 1 ad running now' . ($ads['n'] > 1 ? ', and ' . ($ads['n'] - 1) . ' more on record' : '') . '. See the creative.';
        elseif ($ads['now'] > 1)   echo "You have {$ads['now']} ads running now" . ($ads['n'] > $ads['now'] ? ', and ' . ($ads['n'] - $ads['now']) . ' more on record' : '') . '. See the creative.';
        else                       echo ($ads['n'] === 1 ? 'You have 1 ad placement' : "You have {$ads['n']} ad placements") . ' on record. See the creative.';
      ?></p>
      <span class="pt-card-go">See advertising ›</span>
    </a>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/orders.php')) ?>">
      <h2 class="pt-card-h">Print orders</h2>
      <p class="pt-card-sub"><?php
        if ($ord['n'] === 0)         echo 'No print orders yet.';
        elseif ($ord['moving'] === 1) echo 'You have 1 order on the way' . ($ord['n'] > 1 ? ', and ' . ($ord['n'] - 1) . ' delivered' : '') . '. See the proof and where it is.';
        elseif ($ord['moving'] > 1)   echo "You have {$ord['moving']} orders on the way" . ($ord['n'] > $ord['moving'] ? ', and ' . ($ord['n'] - $ord['moving']) . ' delivered' : '') . '. See the proofs and where they are.';
        else                          echo ($ord['n'] === 1 ? 'You have 1 print order' : "You have {$ord['n']} print orders") . ', all delivered. See the proofs.';
      ?></p>
      <span class="pt-card-go">See print orders ›</span>
    </a>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/spend.php')) ?>">
      <h2 class="pt-card-h">Spend</h2>
      <p class="pt-card-sub"><?= $fin['months']
          ? 'This year you\'ve spent ' . ph(portal_money($yr['broker'])) . ' and Mont Haus ' . ph(portal_money($yr['mh']))
            . ', for a total of ' . ph(portal_money($yr['broker'] + $yr['mh'])) . ' on marketing and advertising.'
          : 'No marketing spend yet.' ?></p>
      <span class="pt-card-go">See details ›</span>
    </a>
    <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/qr.php', $qr_n === 0 ? ['request' => 1] : [])) ?>">
      <h2 class="pt-card-h">QR codes</h2>
      <p class="pt-card-sub"><?= $qr_n === 0 ? 'You do not have any QR codes yet. Request one for a marketing project.'
          : ($qr_n === 1 ? 'You have 1 QR code. See where it goes, or change it.'
                         : "You have {$qr_n} QR codes. See where each one goes, or change it.") ?></p>
      <span class="pt-card-go"><?= $qr_n === 0 ? 'Request ›' : 'Review + Edit ›' ?></span>
    </a>
  </div>
<?php portal_footer();
