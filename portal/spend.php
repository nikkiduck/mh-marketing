<?php
/**
 * portal/spend.php — what the agent spent on marketing, month by month, and
 * what Mont Haus covered. docs/AGENT_PORTAL_PLAN.md, decision 3 (2026-10-01).
 *
 * Every figure comes from mh_agent_financials() (inc/financials.php), the same
 * function behind the admin Financials tab and billing.php, so the portal can
 * never quote a different number. Spend only: NO invoiced, billed, paid or
 * balance status, and nothing read from marketing_billing_months. The agent's
 * bill comes from accounting and may include other items.
 *
 * Lines whose "who pays" is not decided yet show as "Being finalised" (Nikki,
 * 2026-10-01): never folded into either column, so the page neither overstates
 * what the agent owes nor promises that Mont Haus covers it.
 *
 * Scope: the portal's scopes (2026-10-08): the person's own spend, then each
 * team they belong to as its own section, every member seeing the same team
 * figures (Nikki). A month opens per scope: ?m=YYYY-MM[&team=<id>], and the
 * team id is honoured only when it is one of the person's teams.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/financials.php';

$ctx = portal_context($conn);

/** This year's and all-time totals for one scope's months. */
function portal_spend_sums(array $fin): array {
    $year = date('Y');
    $sum = ['year' => ['broker' => 0.0, 'mh' => 0.0, 'unassigned' => 0.0], 'all' => $fin['grand']];
    foreach ($fin['months'] as $k => $m) {
        if (substr($k, 0, 4) === $year) foreach (['broker', 'mh', 'unassigned'] as $f) $sum['year'][$f] += $m[$f];
    }
    return $sum;
}

$fins = [];
foreach ($ctx['scopes'] as $scope) $fins[(int)$scope['id']] = mh_agent_financials($conn, (int)$scope['id']);

$scope  = portal_scope($ctx, (int)($_GET['team'] ?? 0));   // the person unless ?team= names one of their teams
$months = $fins[(int)$scope['id']]['months'];               // newest first, 'YYYY-MM' => [...]
$you    = portal_scope_you($scope);

$want = (string)($_GET['m'] ?? '');
$month = (preg_match('/^\d{4}-\d{2}$/', $want) && isset($months[$want])) ? $months[$want] : null;

portal_header($ctx, 'Spend', 'spend');

/** The three figures, "Being finalised" only when there is something in it. */
function portal_spend_figs(array $f, string $you = 'You'): void {
    ?>
    <div class="pt-figs">
      <div><span><?= ph($you) ?> paid</span><b><?= ph(portal_money($f['broker'])) ?></b></div>
      <div><span>Mont Haus paid</span><b><?= ph(portal_money($f['mh'])) ?></b></div>
      <?php if (round($f['unassigned'], 2) > 0): ?>
        <div class="tbd"><span>Being finalised</span><b><?= ph(portal_money($f['unassigned'])) ?></b></div>
      <?php endif; ?>
    </div>
    <?php
}

if ($month):
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/spend.php')) ?>">← All months</a></p>
  <h1 class="pt-h1"><?= ph($month['label']) ?><?= $scope['team'] ? ' · ' . ph($scope['name']) : '' ?></h1>
  <?php if ($scope['team']): ?><p class="pt-lead">The team's spend for the month, shared by every member.</p><?php endif; ?>
  <div class="pt-card" style="margin-bottom:20px"><?php portal_spend_figs($month, $you); ?></div>

  <div class="pt-lines">
  <?php foreach ($month['items'] as $it):
      $is_ad = ($it['kind'] ?? '') === 'Advertising';
      $title = $is_ad ? portal_platform_label((string)$it['platform']) . ($it['name'] !== '' ? ': ' . $it['name'] : '')
                      : $it['name'];
      $not_set = $is_ad && empty($it['set']) && (float)$it['total'] == 0.0;
  ?>
    <div class="pt-line">
      <div class="pt-line-h">
        <span class="pt-kind"><?= $is_ad ? 'Advertising' : 'Print' ?></span>
        <b><?= ph($title) ?></b>
        <?php if (!$is_ad && !empty($it['platform'])): ?><small><?= ph($it['platform']) ?></small><?php endif; ?>
      </div>
      <div class="pt-line-f">
        <?php if (!empty($it['billed_with'])): ?>
          <span class="muted">Included in <?= ph($it['billed_with']) ?></span>
        <?php elseif ($not_set): ?>
          <span class="tbd">Amount being finalised</span>
        <?php elseif (!empty($it['zeroed']) || (float)$it['total'] == 0.0): ?>
          <span class="muted">No charge</span>
        <?php else: ?>
          <?php if ($it['broker'] > 0): ?><span><?= ph($you) ?> paid <b><?= ph(portal_money($it['broker'])) ?></b></span><?php endif; ?>
          <?php if ($it['mh'] > 0): ?><span>Mont Haus paid <b><?= ph(portal_money($it['mh'])) ?></b></span><?php endif; ?>
          <?php if ($it['unassigned'] > 0): ?><span class="tbd">Being finalised <b><?= ph(portal_money($it['unassigned'])) ?></b></span><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($it['receipt'])): ?>
          <a class="pt-receipt" href="<?= ph(portal_url($ctx, '/portal/receipt.php', ['order' => (int)$it['receipt']])) ?>" target="_blank" rel="noopener">Receipt</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

<?php else: ?>
  <?php foreach ($ctx['scopes'] as $sc):
      $sid = (int)$sc['id'];
      $fin = $fins[$sid];
      $sum = portal_spend_sums($fin);
      $syou = portal_scope_you($sc);
      $q = $sc['team'] ? ['team' => $sid] : [];
      if ($sc['team']): ?>
  <section class="pt-team" id="team-<?= $sid ?>">
  <h2 class="pt-h2"><?= ph($sc['name']) ?></h2>
  <p class="pt-lead">The team's marketing spend and what Mont Haus has covered. Every member of <?= ph($sc['name']) ?> sees the same figures.</p>
  <?php else: ?>
  <h1 class="pt-h1">Your marketing spend</h1>
  <p class="pt-lead">What you have spent on marketing and what Mont Haus has covered. Your bill comes from accounting separately and may include other items.</p>
  <?php endif; ?>

  <?php if (!$fin['months']): ?>
    <div class="pt-card"><p class="pt-card-sub" style="margin:0"><?= $sc['team'] ? 'No marketing spend for the team yet.' : 'No marketing spend yet.' ?></p></div>
  <?php else: ?>
    <div class="pt-cards" style="margin-bottom:28px">
      <div class="pt-card"><h2 class="pt-card-h">This year</h2><?php portal_spend_figs($sum['year'], $syou); ?></div>
      <div class="pt-card"><h2 class="pt-card-h">All time</h2><?php portal_spend_figs($sum['all'], $syou); ?></div>
    </div>
    <h2 class="pt-h2">By month</h2>
    <div class="pt-cards">
    <?php foreach ($fin['months'] as $k => $m): $n = count($m['items']); ?>
      <a class="pt-card" href="<?= ph(portal_url($ctx, '/portal/spend.php', ['m' => $k] + $q)) ?>">
        <h3 class="pt-card-h" style="font-size:24px"><?= ph($m['label']) ?></h3>
        <?php portal_spend_figs($m, $syou); ?>
        <span class="pt-card-go"><?= $n === 1 ? '1 item' : "{$n} items" ?> →</span>
      </a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($sc['team']): ?></section><?php endif; ?>
  <?php endforeach; ?>
<?php endif;
portal_footer();
