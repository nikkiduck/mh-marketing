<?php
/**
 * portal/memberships.php — the chambers, associations and clubs Mont Haus
 * has joined on the agent's behalf (2026-10-14): a marketing tool for
 * networking, not an ad. One card per membership: the organization, its
 * state in plain words, member since and renewal dates, the organization's
 * website, and what it cost from the same mh_agent_financials() pass as
 * Spend.
 *
 * A membership is a row of marketing_campaigns whose medium is 'membership'
 * (sql/campaign_membership_v1.sql), entered on the admin Advertising tab, so
 * who pays, a share among several agents and the receipt all work as for an
 * ad. portal/advertising.php leaves these rows out.
 *
 * Scope: the portal's scopes, like Advertising: the person's own, then each
 * team they belong to as its own section. Every query carries one of those
 * ids. There is no ?id= detail view: the card says everything there is.
 *
 * NOT shown: dues as a budget figure, notes, the receipt, or anything about
 * invoices.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/financials.php';

$ctx = portal_context($conn);

$has_share = mk_column_exists($conn, 'marketing_campaigns', 'split_group');

// Current ones first (by renewal date, soonest first), ended ones last.
$memberships = [];
foreach ($ctx['scopes'] as $scope) {
    $sid = (int)$scope['id'];
    $r = $conn->query("SELECT * FROM marketing_campaigns WHERE intake_id = {$sid} ORDER BY COALESCE(end_date, '9999-12-31') ASC, COALESCE(start_date, created_at) DESC, id DESC");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $c) {
        if (!portal_is_membership($c)) continue;
        $memberships[(int)$c['id']] = $c + ['_scope' => $scope];
    }
}
uasort($memberships, fn($a, $b) => (portal_membership_state($a) === 'Ended') <=> (portal_membership_state($b) === 'Ended'));

$fins = [];
foreach ($ctx['scopes'] as $scope) $fins[(int)$scope['id']] = mh_agent_financials($conn, (int)$scope['id']);

/** The organization's site, when one was entered and it is a web address. */
function portal_membership_site(array $c): string {
    $u = trim((string)($c['target_url'] ?? ''));
    return preg_match('#^https?://#i', $u) ? $u : '';
}

portal_header($ctx, 'Memberships', 'memberships');

foreach ($ctx['scopes'] as $scope):
    $sid  = (int)$scope['id'];
    $fin  = $fins[$sid];
    $list = array_filter($memberships, fn($c) => (int)$c['_scope']['id'] === $sid);
    if ($scope['team']): ?>
  <section class="pt-team" id="team-<?= $sid ?>">
  <h2 class="pt-h2"><?= ph($scope['short']) ?> Memberships</h2>
  <p class="pt-lead">Organizations Mont Haus has joined on behalf of <?= ph($scope['name']) ?>.</p>
  <?php else: ?>
  <h1 class="pt-h1">Your memberships</h1>
  <p class="pt-lead">Chambers of commerce, associations and clubs Mont Haus has joined on your behalf, so you can network through them.</p>
  <?php endif; ?>

  <?php if (!$list): ?>
    <div class="pt-card"><p class="pt-card-sub" style="margin:0"><?= $scope['team'] ? 'No memberships for the team yet.' : 'No memberships yet. When Mont Haus joins an organization on your behalf it will appear here.' ?></p></div>
  <?php else: ?>
    <div class="pt-cards">
    <?php foreach ($list as $c):
        $sp    = portal_item_spend($fin, 'Advertising', (int)$c['id']);
        $state = portal_membership_state($c);
        $site  = portal_membership_site($c);
        $name  = trim((string)($c['name'] ?? '')) ?: 'Membership';
    ?>
      <div class="pt-card pt-item" id="m-<?= (int)$c['id'] ?>">
        <span class="pt-item-body">
          <span class="pt-state"><?= ph($state) ?></span>
          <h2 class="pt-card-h"><?= ph($name) ?></h2>
          <p class="pt-card-sub"><?= ph(portal_membership_term($c)) ?></p>
          <?php if ($scope['team']): ?>
            <p class="pt-card-sub" style="margin-top:6px">A <?= ph($scope['name']) ?> membership. The amount is the team's shared spend.</p>
          <?php elseif ($has_share && !empty($c['split_group'])): ?>
            <p class="pt-card-sub" style="margin-top:6px">Shared with other agents. The amount is your share.</p>
          <?php endif; ?>
          <?php if ($sp['lines'] > 0): ?><p class="pt-card-sub pt-line-f" style="margin-top:6px"><?= portal_item_spend_html($sp, portal_scope_you($scope)) ?></p><?php endif; ?>
          <?php if ($site !== ''): ?>
            <span class="pt-row" style="margin-top:12px"><a class="pt-btn quiet" href="<?= ph($site) ?>" target="_blank" rel="noopener">Visit <?= ph(preg_replace('#^www\.#', '', (string)parse_url($site, PHP_URL_HOST))) ?></a></span>
          <?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($scope['team']): ?></section><?php endif; ?>
<?php endforeach; ?>

  <p class="pt-help" style="margin-top:24px">Want Mont Haus to join an organization for you, or have a question about one of these? Email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>?subject=<?= rawurlencode('Memberships') ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>
<?php
portal_footer();
