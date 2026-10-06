<?php
/**
 * portal/orders.php — the agent's print orders (collateral): one card per
 * order with a picture of the proof, and ?id= opens one order with the proof
 * large, the quantity, where it is, and a tracking link.
 * docs/AGENT_PORTAL_PLAN.md sections 3 to 5 (2026-10-06).
 *
 * Scope: the portal account only, like Spend and Advertising. The detail
 * view is picked out of the already-scoped list, so an id from the address
 * bar can never reach another account's order.
 *
 * Shown: what, how many, status in plain words, when it was ordered and
 * delivered, tracking, the proof, and what it cost (from mh_agent_financials,
 * like everything else). NOT shown: vendor, order number, internal notes,
 * the receipt (that is on Spend), or anything about invoices.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/financials.php';

$ctx  = portal_context($conn);
$acct = (int)$ctx['acct']['id'];

$orders = [];
$r = $conn->query("SELECT * FROM marketing_collateral_orders WHERE intake_id = {$acct} ORDER BY COALESCE(ordered_at, created_at) DESC, id DESC");
foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $o) $orders[(int)$o['id']] = $o;

$fin = mh_agent_financials($conn, $acct);

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
portal_header($ctx, 'Print orders', 'orders');

if ($want > 0 && !$one):
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/orders.php')) ?>">← All print orders</a></p>
  <h1 class="pt-h1">That order could not be found</h1>
  <p class="pt-lead">It may have been removed. If you expected to see it here, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>

<?php elseif ($one):
    $o   = $one;
    $sp  = portal_item_spend($fin, 'Collateral', (int)$o['id']);
    $img = (string)($o['proof_file'] ?? '');
    $is_pdf = $img !== '' && strtolower(pathinfo($img, PATHINFO_EXTENSION)) === 'pdf';
    $src = ['order' => (int)$o['id']];
    $track = portal_tracking_url($o);
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/orders.php')) ?>">← All print orders</a></p>
  <h1 class="pt-h1"><?= ph(portal_order_title($o)) ?></h1>
  <p class="pt-lead" style="margin-bottom:18px">
    <span class="pt-state"><?= ph(portal_order_state($o)) ?></span>
    <?php if (trim((string)($o['qty'] ?? '')) !== ''): ?>Quantity <?= ph($o['qty']) ?><?php endif; ?>
  </p>

  <div class="pt-gallery one">
    <figure class="pt-fig">
      <?php if ($img !== '' && !$is_pdf): ?>
        <a href="<?= ph(portal_url($ctx, '/portal/asset.php', $src)) ?>" target="_blank" rel="noopener">
          <img src="<?= ph(portal_url($ctx, '/portal/asset.php', !empty($o['proof_thumb']) ? $src + ['thumb' => 1] : $src)) ?>" alt="Proof" loading="lazy">
        </a>
      <?php else: ?>
        <?= portal_picture($ctx, $src, $img, $o['proof_thumb'] ?? null, 'No proof on the portal yet', true) ?>
      <?php endif; ?>
      <figcaption>
        <b><?= $img !== '' ? 'Proof' : 'The proof is not on the portal yet' ?></b>
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
        <p class="pt-card-sub" style="margin:0">Nothing for this order on your spend yet.</p>
      <?php else: ?>
        <p class="pt-line-f" style="margin:0"><?= portal_item_spend_html($sp) ?></p>
        <p class="pt-card-sub" style="margin-top:8px">The month it falls in is on your <a href="<?= ph(portal_url($ctx, '/portal/spend.php')) ?>">Spend</a> page.</p>
      <?php endif; ?>
    </div>
  </div>
  <p class="pt-help" style="margin-top:20px">Need more, or something changed? Email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>?subject=<?= rawurlencode('Print order: ' . portal_order_title($o)) ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>

<?php else: ?>
  <h1 class="pt-h1">Your print orders</h1>
  <p class="pt-lead">Business cards, signs, postcards and brochures Mont Haus has ordered for you. Tap one to see the proof and where it is.</p>

  <?php if (!$orders): ?>
    <div class="pt-card"><p class="pt-card-sub" style="margin:0">No print orders yet. When Mont Haus orders something for you it will appear here.</p></div>
  <?php else: ?>
    <div class="pt-cards">
    <?php foreach ($orders as $o):
        $sp = portal_item_spend($fin, 'Collateral', (int)$o['id']);
        $qty = trim((string)($o['qty'] ?? ''));
        $when = portal_date($o['delivered_at'] ?? null) !== '' ? 'Delivered ' . portal_date($o['delivered_at'])
              : (portal_date($o['ordered_at'] ?? null) !== '' ? 'Ordered ' . portal_date($o['ordered_at']) : '');
    ?>
      <a class="pt-card pt-item" href="<?= ph(portal_url($ctx, '/portal/orders.php', ['id' => (int)$o['id']])) ?>">
        <?= portal_picture($ctx, ['order' => (int)$o['id']], $o['proof_file'] ?? null, $o['proof_thumb'] ?? null, 'No proof yet') ?>
        <span class="pt-item-body">
          <span class="pt-state"><?= ph(portal_order_state($o)) ?></span>
          <h2 class="pt-card-h"><?= ph(portal_order_title($o)) ?></h2>
          <p class="pt-card-sub"><?= $qty !== '' ? 'Quantity ' . ph($qty) : '' ?><?= $qty !== '' && $when !== '' ? ' · ' : '' ?><?= ph($when) ?></p>
          <?php if ($sp['lines'] > 0): ?><p class="pt-card-sub pt-line-f" style="margin-top:6px"><?= portal_item_spend_html($sp) ?></p><?php endif; ?>
          <span class="pt-card-go">See it ›</span>
        </span>
      </a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif;
portal_footer();
