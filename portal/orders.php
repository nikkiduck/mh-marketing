<?php
/**
 * portal/orders.php — the agent's print orders (collateral): one card per
 * order with a picture of the proof, and ?id= opens one order with the proof
 * large, the quantity, where it is, and a tracking link.
 * docs/AGENT_PORTAL_PLAN.md sections 3 to 5 (2026-10-06).
 *
 * Scope: the portal's scopes, like Advertising: the person's own orders, then
 * each team they belong to as its own section (2026-10-08). The detail view
 * is picked out of the already-scoped list, so an id from the address bar
 * can never reach another account's order.
 *
 * Shown: what, how many, status in plain words, when it was ordered and
 * delivered, tracking, the proof, and what it cost (from mh_agent_financials,
 * like everything else). NOT shown: vendor, order number, internal notes,
 * the receipt (that is on Spend), or anything about invoices.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/financials.php';

$ctx  = portal_context($conn);

$orders = [];   // each row carries its scope ('_scope')
foreach ($ctx['scopes'] as $scope) {
    $sid = (int)$scope['id'];
    $r = $conn->query("SELECT * FROM marketing_collateral_orders WHERE intake_id = {$sid} ORDER BY COALESCE(ordered_at, created_at) DESC, id DESC");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $o) $orders[(int)$o['id']] = $o + ['_scope' => $scope];
}

$fins = [];
foreach ($ctx['scopes'] as $scope) $fins[(int)$scope['id']] = mh_agent_financials($conn, (int)$scope['id']);

$want = (int)($_GET['id'] ?? 0);
$one  = $want > 0 && isset($orders[$want]) ? $orders[$want] : null;

/** "Business Cards" or "Business Cards: Reorder #2". */
function portal_order_title(array $o): string {
    $t = portal_order_kind((string)$o['type']);
    $l = trim((string)($o['label'] ?? ''));
    return $l !== '' ? "{$t}: {$l}" : $t;
}
/** The tracking link, when there is one to give. */
function portal_tracking_url(array $o): string {
    $n = trim((string)($o['tracking_number'] ?? ''));
    if ($n === '') return '';
    $u = trim((string)($o['tracking_url'] ?? ''));
    if ($u !== '' && preg_match('#^https?://#i', $u)) return $u;
    return 'https://www.google.com/search?q=' . rawurlencode($n);
}

// Not theirs, or gone: a 404 that says so plainly and shows nothing else.
// The status is set before any output, since the page is not buffered.
if ($want > 0 && !$one) http_response_code(404);
portal_header($ctx, 'Collateral', 'orders');

if ($want > 0 && !$one):
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/orders.php')) ?>">← All collateral</a></p>
  <h1 class="pt-h1">That order could not be found</h1>
  <p class="pt-lead">It may have been removed. If you expected to see it here, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>

<?php elseif ($one):
    $o   = $one;
    $scope = $o['_scope'];
    $fin = $fins[(int)$scope['id']];
    $sp  = portal_item_spend($fin, 'Collateral', (int)$o['id']);
    $img = (string)($o['proof_file'] ?? '');
    $is_pdf = $img !== '' && strtolower(pathinfo($img, PATHINFO_EXTENSION)) === 'pdf';
    $src = ['order' => (int)$o['id']];
    $track = portal_tracking_url($o);
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/orders.php')) ?>">← All collateral</a></p>
  <h1 class="pt-h1"><?= ph(portal_order_title($o)) ?></h1>
  <p class="pt-lead" style="margin-bottom:18px">
    <span class="pt-state"><?= ph(portal_order_state($o)) ?></span>
    <?php if ($scope['team']): ?><span class="pt-state"><?= ph($scope['name']) ?></span><?php endif; ?>
    <?php if (trim((string)($o['qty'] ?? '')) !== ''): ?>Quantity <?= ph($o['qty']) ?><?php endif; ?>
  </p>

  <?php if ($img === ''): // nothing uploaded: words, not a placeholder picture (Nikki, 2026-10-09) ?>
  <div class="pt-card" style="margin-bottom:20px">
    <p class="pt-card-sub" style="margin:0"><?= portal_no_creative_html() ?></p>
    <?php if (trim((string)($o['file_url'] ?? '')) !== ''): ?>
      <span class="pt-row" style="margin-top:10px"><a class="pt-btn quiet" href="<?= ph($o['file_url']) ?>" target="_blank" rel="noopener">Open original files</a></span>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="pt-gallery one">
    <figure class="pt-fig">
      <?php if (!$is_pdf): ?>
        <a href="<?= ph(portal_url($ctx, '/portal/asset.php', $src)) ?>" target="_blank" rel="noopener">
          <img src="<?= ph(portal_url($ctx, '/portal/asset.php', !empty($o['proof_thumb']) ? $src + ['thumb' => 1] : $src)) ?>" alt="Proof" loading="lazy">
        </a>
      <?php else: ?>
        <?= portal_picture($ctx, $src, $img, $o['proof_thumb'] ?? null, '', true) ?>
      <?php endif; ?>
      <figcaption>
        <b>Proof</b>
        <span class="pt-row" style="margin-top:8px">
          <?php if ($img !== ''): ?>
            <a class="pt-btn quiet" href="<?= ph(portal_url($ctx, '/portal/asset.php', $src)) ?>" target="_blank" rel="noopener"><?= $is_pdf ? 'Open PDF' : 'Open full size' ?></a>
            <a class="pt-btn quiet" href="<?= ph(portal_url($ctx, '/portal/asset.php', $src + ['dl' => 1])) ?>">Download</a>
          <?php endif; ?>
          <?php if (trim((string)($o['file_url'] ?? '')) !== ''): ?>
            <a class="pt-btn quiet" href="<?= ph($o['file_url']) ?>" target="_blank" rel="noopener">Open original files</a>
          <?php endif; ?>
        </span>
      </figcaption>
    </figure>
  </div>
  <?php endif; ?>

  <div class="pt-cards">
    <div class="pt-card">
      <h2 class="pt-card-h">Where it is</h2>
      <p class="pt-card-sub" style="margin:0">
        <b><?= ph(portal_order_state($o)) ?></b>
        <?php if (portal_date($o['delivered_at'] ?? null) !== ''): ?><br>Delivered <?= ph(portal_date($o['delivered_at'])) ?><?php endif; ?>
        <?php if (portal_date($o['ordered_at'] ?? null) !== ''): ?><br>Ordered <?= ph(portal_date($o['ordered_at'])) ?><?php endif; ?>
      </p>
      <?php if ($track !== ''): ?>
        <span class="pt-row"><a class="pt-btn main" href="<?= ph($track) ?>" target="_blank" rel="noopener">Track the shipment</a></span>
      <?php endif; ?>
    </div>
    <div class="pt-card">
      <h2 class="pt-card-h">What it cost</h2>
      <?php if ($sp['lines'] === 0): ?>
        <p class="pt-card-sub" style="margin:0">Nothing for this order on <?= $scope['team'] ? 'the team\'s' : 'your' ?> spend yet.</p>
      <?php else: ?>
        <p class="pt-line-f" style="margin:0"><?= portal_item_spend_html($sp, portal_scope_you($scope)) ?></p>
        <p class="pt-card-sub" style="margin-top:8px">The month it falls in is on <?= $scope['team'] ? 'the team\'s' : 'your' ?> <a href="<?= ph(portal_url($ctx, '/portal/spend.php', $scope['team'] ? ['team' => (int)$scope['id']] : [])) ?>">Spend</a> page.</p>
      <?php endif; ?>
      <?php if ($scope['team']): ?>
        <p class="pt-card-sub" style="margin-top:8px">This is a <?= ph($scope['name']) ?> order. The amounts are the team's shared spend.</p>
      <?php endif; ?>
    </div>
  </div>
  <p class="pt-help" style="margin-top:20px">Need more, or something changed? Email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>?subject=<?= rawurlencode('Print order: ' . portal_order_title($o)) ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>

<?php else: ?>
  <?php foreach ($ctx['scopes'] as $scope):
      $sid  = (int)$scope['id'];
      $fin  = $fins[$sid];
      $list = array_filter($orders, fn($o) => (int)$o['_scope']['id'] === $sid);
      if ($scope['team']): ?>
  <section class="pt-team" id="team-<?= $sid ?>">
  <h2 class="pt-h2"><?= ph($scope['name']) ?></h2>
  <p class="pt-lead">The team's collateral, shared by every member of <?= ph($scope['name']) ?>.</p>
  <?php else: ?>
  <h1 class="pt-h1">Your collateral</h1>
  <p class="pt-lead">Business cards, signs, postcards and brochures Mont Haus has ordered for you.</p>
  <?php endif; ?>

  <?php if (!$list): ?>
    <div class="pt-card"><p class="pt-card-sub" style="margin:0"><?= $scope['team'] ? 'No collateral for the team yet.' : 'No collateral yet. When Mont Haus orders something for you it will appear here.' ?></p></div>
  <?php else: ?>
    <div class="pt-cards">
    <?php foreach ($list as $o):
        $sp = portal_item_spend($fin, 'Collateral', (int)$o['id']);
        $qty = trim((string)($o['qty'] ?? ''));
        $when = portal_date($o['delivered_at'] ?? null) !== '' ? 'Delivered ' . portal_date($o['delivered_at'])
              : (portal_date($o['ordered_at'] ?? null) !== '' ? 'Ordered ' . portal_date($o['ordered_at']) : '');
    ?>
      <?php $pic = portal_picture($ctx, ['order' => (int)$o['id']], $o['proof_file'] ?? null, $o['proof_thumb'] ?? null, ''); ?>
      <a class="pt-card pt-item" href="<?= ph(portal_url($ctx, '/portal/orders.php', ['id' => (int)$o['id']])) ?>">
        <?= $pic ?>
        <span class="pt-item-body">
          <span class="pt-state"><?= ph(portal_order_state($o)) ?></span>
          <h2 class="pt-card-h"><?= ph(portal_order_title($o)) ?></h2>
          <p class="pt-card-sub"><?= $qty !== '' ? 'Quantity ' . ph($qty) : '' ?><?= $qty !== '' && $when !== '' ? ' · ' : '' ?><?= ph($when) ?></p>
          <?php if ($pic === ''): ?><p class="pt-card-sub" style="margin-top:6px"><?= portal_no_creative_html(false) ?></p><?php endif; ?>
          <?php if ($sp['lines'] > 0): ?><p class="pt-card-sub pt-line-f" style="margin-top:6px"><?= portal_item_spend_html($sp, portal_scope_you($scope)) ?></p><?php endif; ?>
          <span class="pt-card-go">See it ›</span>
        </span>
      </a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($scope['team']): ?></section><?php endif; ?>
  <?php endforeach; ?>
<?php endif;
portal_footer();
