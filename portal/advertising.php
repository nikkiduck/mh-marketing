<?php
/**
 * portal/advertising.php — the agent's advertising: one card per placement
 * with a picture of the creative, and ?id= opens one placement with every
 * creative shown large. docs/AGENT_PORTAL_PLAN.md sections 3 to 5 (2026-10-06).
 *
 * Scope: the portal's scopes (portal_context()['scopes']): the person's own
 * placements, then each team they belong to as its own section (2026-10-08;
 * a team's marketing is shown on every member's portal). Every query carries
 * one of those ids, and the detail view is picked out of the already-scoped
 * list, so an id from the address bar can never reach another account's row.
 *
 * Shown: outlet, name, print or digital (and the ad size), when it ran, its
 * state in plain words, the creatives (uploaded pictures first; the original
 * file link as "Open original"), and what it cost from the same
 * mh_agent_financials() pass as Spend. NOT shown: budget, notes, UTM fields,
 * the receipt, the "sent" flag, or anything about invoices.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/financials.php';

$ctx  = portal_context($conn);

$has_img   = mk_column_exists($conn, 'marketing_campaign_assets', 'image_file');   // sql/creatives_v1.sql
$has_med   = mk_column_exists($conn, 'marketing_campaigns', 'medium');
$has_share = mk_column_exists($conn, 'marketing_campaigns', 'split_group');

// Newest run first; a placement with no dates yet sorts by when it was set up.
// Each row carries its scope ('_scope'), so the detail view knows whose it is.
$campaigns = [];
foreach ($ctx['scopes'] as $scope) {
    $sid = (int)$scope['id'];
    $r = $conn->query("SELECT * FROM marketing_campaigns WHERE intake_id = {$sid} ORDER BY COALESCE(start_date, created_at) DESC, id DESC");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $c) $campaigns[(int)$c['id']] = $c + ['assets' => [], '_scope' => $scope];
}

// Their creatives, in one query over the ids the scoped list gave us.
if ($campaigns) {
    $in = implode(',', array_map('intval', array_keys($campaigns)));
    $r = $conn->query("SELECT * FROM marketing_campaign_assets WHERE campaign_id IN ({$in}) ORDER BY campaign_id, uploaded_at ASC, id ASC");
    foreach ($r ? $r->fetch_all(MYSQLI_ASSOC) : [] as $a) {
        $cid = (int)$a['campaign_id'];
        if (isset($campaigns[$cid])) $campaigns[$cid]['assets'][] = $a;   // never a row the IN list did not ask for
    }
}

$fins = [];
foreach ($ctx['scopes'] as $scope) $fins[(int)$scope['id']] = mh_agent_financials($conn, (int)$scope['id']);

$want = (int)($_GET['id'] ?? 0);
$one  = $want > 0 && isset($campaigns[$want]) ? $campaigns[$want] : null;

/** The creative to picture a placement by: the first with an uploaded image, else the first. */
function portal_lead_asset(array $c): ?array {
    foreach ($c['assets'] as $a) if (!empty($a['image_file'])) return $a;
    return $c['assets'][0] ?? null;
}
/** Outlet plus name, as the card's title. */
function portal_campaign_title(array $c): string {
    $t = portal_platform_label((string)$c['platform']);
    $n = trim((string)($c['name'] ?? ''));
    return $n !== '' ? "{$t}: {$n}" : $t;
}
/** "Print, Half page" / "Digital". */
function portal_campaign_medium(array $c, bool $has_med): string {
    if (!$has_med) return '';
    if (($c['medium'] ?? 'digital') === 'print') return 'Print' . (trim((string)($c['ad_size'] ?? '')) !== '' ? ', ' . trim((string)$c['ad_size']) : '');
    return 'Digital';
}

// Not theirs, or gone: a 404 that says so plainly and shows nothing else.
// The status is set before any output, since the page is not buffered.
if ($want > 0 && !$one) http_response_code(404);
portal_header($ctx, 'Advertising', 'ads');

if ($want > 0 && !$one):
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/advertising.php')) ?>">← All advertising</a></p>
  <h1 class="pt-h1">That placement could not be found</h1>
  <p class="pt-lead">It may have been removed. If you expected to see it here, email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>

<?php elseif ($one):
    $c  = $one;
    $scope = $c['_scope'];
    $fin = $fins[(int)$scope['id']];
    $sp = portal_item_spend($fin, 'Advertising', (int)$c['id']);
    $medium = portal_campaign_medium($c, $has_med);
?>
  <p class="pt-back"><a href="<?= ph(portal_url($ctx, '/portal/advertising.php')) ?>">← All advertising</a></p>
  <h1 class="pt-h1"><?= ph(portal_campaign_title($c)) ?></h1>
  <p class="pt-lead" style="margin-bottom:18px">
    <span class="pt-state"><?= ph(portal_campaign_state($c)) ?></span>
    <?php if ($scope['team']): ?><span class="pt-state"><?= ph($scope['name']) ?></span><?php endif; ?>
    <?= ph(portal_run_words($c['start_date'] ?? null, $c['end_date'] ?? null)) ?><?= $medium !== '' ? ' · ' . ph($medium) : '' ?>
  </p>

  <?php if (!$c['assets']): ?>
    <div class="pt-card" style="margin-bottom:20px"><p class="pt-card-sub" style="margin:0">The creative for this placement is not on the portal yet.</p></div>
  <?php else: ?>
    <div class="pt-gallery">
    <?php foreach ($c['assets'] as $i => $a):
        $img   = (string)($a['image_file'] ?? '');
        $is_pdf = $img !== '' && strtolower(pathinfo($img, PATHINFO_EXTENSION)) === 'pdf';
        $label = trim((string)($a['label'] ?? ''));
        if ($label === '' || $label === 'Ad file') $label = count($c['assets']) > 1 ? 'Creative ' . ($i + 1) : 'Creative';
        $src = ['asset' => (int)$a['id']];
    ?>
      <figure class="pt-fig">
        <?php if ($img !== '' && !$is_pdf): ?>
          <a href="<?= ph(portal_url($ctx, '/portal/asset.php', $src)) ?>" target="_blank" rel="noopener">
            <img src="<?= ph(portal_url($ctx, '/portal/asset.php', !empty($a['image_thumb']) ? $src + ['thumb' => 1] : $src)) ?>" alt="<?= ph($label) ?>" loading="lazy">
          </a>
        <?php else: ?>
          <?= portal_picture($ctx, $src, $img, $a['image_thumb'] ?? null, 'No picture yet', true) ?>
        <?php endif; ?>
        <figcaption>
          <b><?= ph($label) ?></b>
          <span class="pt-row" style="margin-top:8px">
            <?php if ($img !== ''): ?>
              <a class="pt-btn quiet" href="<?= ph(portal_url($ctx, '/portal/asset.php', $src)) ?>" target="_blank" rel="noopener"><?= $is_pdf ? 'Open PDF' : 'Open full size' ?></a>
              <a class="pt-btn quiet" href="<?= ph(portal_url($ctx, '/portal/asset.php', $src + ['dl' => 1])) ?>">Download</a>
            <?php endif; ?>
            <?php if (trim((string)($a['file_url'] ?? '')) !== ''): ?>
              <a class="pt-btn quiet" href="<?= ph($a['file_url']) ?>" target="_blank" rel="noopener">Open original</a>
            <?php endif; ?>
          </span>
        </figcaption>
      </figure>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="pt-card">
    <h2 class="pt-card-h">What it cost</h2>
    <?php if ($sp['lines'] === 0): ?>
      <p class="pt-card-sub" style="margin:0">Nothing for this placement on <?= $scope['team'] ? 'the team\'s' : 'your' ?> spend yet.</p>
    <?php else: ?>
      <p class="pt-line-f" style="margin:0"><?= portal_item_spend_html($sp, portal_scope_you($scope)) ?></p>
      <?php if ($sp['lines'] > 1): ?><p class="pt-card-sub" style="margin-top:8px">Across <?= (int)$sp['lines'] ?> months. Each month is on <?= $scope['team'] ? 'the team\'s' : 'your' ?> <a href="<?= ph(portal_url($ctx, '/portal/spend.php', $scope['team'] ? ['team' => (int)$scope['id']] : [])) ?>">Spend</a> page.</p><?php endif; ?>
    <?php endif; ?>
    <?php if ($scope['team']): ?>
      <p class="pt-card-sub" style="margin-top:8px">This is a <?= ph($scope['name']) ?> placement. The amounts are the team's shared spend.</p>
    <?php endif; ?>
    <?php if ($has_share && !empty($c['split_group'])): ?>
      <p class="pt-card-sub" style="margin-top:8px">This placement is shared with other agents. The amounts here are your share.</p>
    <?php endif; ?>
  </div>
  <p class="pt-help" style="margin-top:20px">Questions about this placement? Email <a href="mailto:<?= PORTAL_MARKETING_EMAIL ?>?subject=<?= rawurlencode('Advertising: ' . portal_campaign_title($c)) ?>"><?= PORTAL_MARKETING_EMAIL ?></a>.</p>

<?php else: ?>
  <?php foreach ($ctx['scopes'] as $scope):
      $sid  = (int)$scope['id'];
      $fin  = $fins[$sid];
      $list = array_filter($campaigns, fn($c) => (int)$c['_scope']['id'] === $sid);
      if ($scope['team']): ?>
  <section class="pt-team" id="team-<?= $sid ?>">
  <h2 class="pt-h2"><?= ph($scope['name']) ?></h2>
  <p class="pt-lead">The team's ad placements, shared by every member of <?= ph($scope['name']) ?>.</p>
  <?php else: ?>
  <h1 class="pt-h1">Your advertising</h1>
  <p class="pt-lead">Every ad placement Mont Haus has run or is running for you, with its creative. Tap one to see it in full.</p>
  <?php endif; ?>

  <?php if (!$list): ?>
    <div class="pt-card"><p class="pt-card-sub" style="margin:0"><?= $scope['team'] ? 'No advertising for the team yet.' : 'No advertising yet. When Mont Haus places an ad for you it will appear here.' ?></p></div>
  <?php else: ?>
    <div class="pt-cards">
    <?php foreach ($list as $c):
        $lead   = portal_lead_asset($c);
        $sp     = portal_item_spend($fin, 'Advertising', (int)$c['id']);
        $medium = portal_campaign_medium($c, $has_med);
        $n      = count($c['assets']);
    ?>
      <a class="pt-card pt-item" href="<?= ph(portal_url($ctx, '/portal/advertising.php', ['id' => (int)$c['id']])) ?>">
        <?= $lead ? portal_picture($ctx, ['asset' => (int)$lead['id']], $lead['image_file'] ?? null, $lead['image_thumb'] ?? null, 'No picture yet')
                  : portal_picture($ctx, [], null, null, 'No creative yet') ?>
        <span class="pt-item-body">
          <span class="pt-state"><?= ph(portal_campaign_state($c)) ?></span>
          <h2 class="pt-card-h"><?= ph(portal_campaign_title($c)) ?></h2>
          <p class="pt-card-sub"><?= ph(portal_run_words($c['start_date'] ?? null, $c['end_date'] ?? null)) ?><?= $medium !== '' ? ' · ' . ph($medium) : '' ?><?= $n > 1 ? " · {$n} creatives" : '' ?></p>
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
