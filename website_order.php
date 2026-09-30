<?php
/**
 * website_order.php — the order the brokers appear in on the public site.
 *
 * sort_order used to be a number box on each agent's page, so putting one
 * person above another meant opening two agents and doing arithmetic (Nikki,
 * 2026-09-28). This lays the brokers out the way monthaus.com lays them out,
 * four across, and lets her drag them into place.
 *
 * Only agents whose web_status is 'approved' appear, because sort_order only
 * decides anything for people who are actually on the website. Teams are left
 * out: the site shows individuals.
 *
 * Saving writes sort_order 1..n in one transaction. It does NOT push anything
 * live — the website reads this portal's feed — so the page offers "Sync to
 * Website" straight afterwards (inc/site_sync.php), which is the same button
 * the roster has.
 *
 * The drag is written by hand against the HTML5 drag events. No library, and
 * deliberately so: the agent page lost its crop tool for a day when a CDN
 * stopped answering. The arrows on each card do the same job for touch
 * screens, where HTML5 dragging does not fire at all.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/schema.php';
require_once __DIR__ . '/inc/agent_roster.php';   // mk_team_sql()
require_once __DIR__ . '/inc/site_sync.php';      // mk_site_sync()
require_login();
require_role('admin');

$has_web = mk_column_exists($conn, 'marketing_intakes', 'web_status');

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'save_order' && $has_web) {
        // The form sends the ids in the order they now sit on screen. Anything
        // that is not currently on the website is ignored rather than trusted,
        // so a stale tab cannot promote someone who was taken off the site.
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? '')))));
        $live = [];
        $r = $conn->query("SELECT id FROM marketing_intakes
                            WHERE is_active = 1 AND status <> 'archived' AND web_status = 'approved'"
                          . mk_team_sql($conn));
        while ($row = $r->fetch_row()) $live[(int)$row[0]] = true;
        $ids = array_values(array_unique(array_filter($ids, fn($i) => isset($live[$i]))));

        if (!$ids) {
            $_SESSION['mk_flash'] = ['title' => 'Nothing to save', 'ok' => false,
                                     'lines' => ['The order came through empty, so nothing was changed.']];
            header('Location: website_order.php'); exit;
        }

        $conn->begin_transaction();
        try {
            $st = $conn->prepare("UPDATE marketing_intakes SET sort_order = ? WHERE id = ?");
            foreach ($ids as $pos => $iid) { $n = $pos + 1; $st->bind_param('ii', $n, $iid); $st->execute(); }
            $st->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['mk_flash'] = ['title' => 'The order was not saved', 'ok' => false,
                                     'lines' => [$e->getMessage(), 'Nothing was changed.']];
            header('Location: website_order.php'); exit;
        }

        $_SESSION['mk_flash'] = ['title' => 'Order saved', 'ok' => true, 'offer_sync' => true,
                                 'lines' => [count($ids) . ' broker' . (count($ids) === 1 ? '' : 's') . ' numbered 1 to ' . count($ids) . '.',
                                             'The website still shows the old order until it syncs.']];
        header('Location: website_order.php'); exit;
    }

    // Same push the roster's button does, offered here so saving and publishing
    // are one sitting rather than two pages.
    if ($action === 'sync_site') {
        $r = mk_site_sync(false);
        $_SESSION['mk_flash'] = ['title' => $r['ok'] ? 'Website synced' : 'Website sync did not run',
                                 'lines' => $r['lines'], 'ok' => $r['ok'], 'log' => $r['log']];
        header('Location: website_order.php'); exit;
    }

    header('Location: website_order.php'); exit;
}

$flash = $_SESSION['mk_flash'] ?? null;
unset($_SESSION['mk_flash']);

// ── Load ─────────────────────────────────────────────────────────────────────
// Same order the public grid uses (site webroot/brokers.php): sort_order first,
// then last name, so brokers that share a number do not jump about.
$agents = [];
if ($has_web) {
    $name_expr = "COALESCE(NULLIF(TRIM(mi.agent_name), ''), mi.mls_full_name)";
    $res = $conn->query("
        SELECT mi.id, {$name_expr} AS name, mi.agent_title, mi.headshot_url,
               mi.headshot_face_url, mi.sort_order, mi.slug, mi.office
          FROM marketing_intakes mi
         WHERE mi.is_active = 1 AND mi.status <> 'archived' AND mi.web_status = 'approved'"
         . mk_team_sql($conn, 'mi') . "
         ORDER BY mi.sort_order,
                  SUBSTRING_INDEX(TRIM({$name_expr}), ' ', -1),
                  {$name_expr}");
    if ($res) $agents = $res->fetch_all(MYSQLI_ASSOC);
}
$public_url = defined('PUBLIC_SITE_URL') ? rtrim(PUBLIC_SITE_URL, '/') . '/brokers.php' : '';
$can_sync   = defined('SITE_AGENT_SYNC_URL') && SITE_AGENT_SYNC_URL !== '';

$conn->close();
// ── Nothing below this line may touch the database. ─────────────────────────

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

/** Initials for the card when someone has no photo yet. */
function wo_initials(string $name): string {
    $words = array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2);
    return strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), $words)));
}

/** The title, showing the line break the Title field was given. */
function wo_title(string $t): string {
    $t = preg_replace('/[ \t]*\R[ \t]*/', "\n", trim($t));
    return nl2br(e($t), false);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Website Order | Mont Haus Marketing</title>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <style>
    .layout-extended .pc-container { margin-top:70px; padding-top:24px; top:0; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    .mh-header-inner { width:100%; padding:0 15px; display:flex; align-items:center; justify-content:space-between; height:70px; }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; font-size:.8rem; font-weight:500;
                   color:#75BDB6; text-decoration:none; border-radius:6px; white-space:nowrap; transition:color .2s, background .2s; }
    .mh-nav-link:hover  { color:#fff; background:rgba(117,189,182,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(117,189,182,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }
    .wrap { max-width:1180px; margin:32px auto 60px; padding:0 20px; }
    .mk-page-header { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:6px; }
    .mk-page-header h1 { font-size:20px; font-weight:700; margin:0; }
    .hdr-actions { display:flex; align-items:stretch; gap:8px; flex-wrap:wrap; }
    .hdr-actions form { display:flex; margin:0; }
    button.btn, input.btn { font-family:inherit; }
    .hdr-actions .btn { line-height:1.45; box-sizing:border-box; margin:0; }
    .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; font-size:12px; font-weight:600; border:none;
           border-radius:3px; cursor:pointer; text-decoration:none; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
    .btn-primary { background:#f97316; color:#fff; } .btn-primary:hover { background:#ea6c0a; color:#fff; }
    .btn-outline { background:#fff; border:1px solid #d1d5db; color:#374151; text-transform:none; letter-spacing:0; }
    .btn-outline:hover { background:#f3f4f6; color:#111; }
    .btn-sm { padding:4px 10px; font-size:11px; }
    .btn[disabled] { opacity:.5; cursor:not-allowed; }

    .lede { font-size:13px; color:#6b7280; margin:0 0 18px; max-width:56em; }
    .flash { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; border-radius:6px; padding:12px 16px; margin-bottom:16px; font-size:13px; }
    .flash strong { display:block; margin-bottom:4px; }
    .flash ul { margin:0; padding-left:18px; }
    .flash-log { margin-top:8px; }
    .flash-log > summary { cursor:pointer; font-size:11px; text-transform:uppercase; letter-spacing:.1em; }
    .flash-log > pre { margin:8px 0 0; padding:10px 12px; max-height:280px; overflow:auto; background:#fff;
                       border:1px solid #e5e7eb; font-size:11.5px; line-height:1.5; white-space:pre-wrap; word-break:break-word; }

    /* The bar follows you down the page: with 25 brokers the Save button would
       otherwise be a scroll away from whatever you just moved. */
    .order-bar { position:sticky; top:78px; z-index:5; display:flex; align-items:center; gap:12px; flex-wrap:wrap;
                 background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:12px 16px; margin-bottom:18px; }
    .order-bar .state { font-size:13px; color:#6b7280; }
    .order-bar .state.dirty { color:#b45309; font-weight:600; }

    .grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:18px; align-items:stretch; }
    @media (max-width:1000px) { .grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
    @media (max-width:760px)  { .grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
    @media (max-width:460px)  { .grid { grid-template-columns:1fr; } }

    .wo-card { position:relative; display:flex; flex-direction:column; background:#fff; border:1px solid #e5e7eb;
               border-radius:4px; overflow:hidden; cursor:grab; user-select:none; transition:border-color .15s, box-shadow .15s, opacity .15s; }
    .wo-card:hover { border-color:#c9a96a; box-shadow:0 6px 18px rgba(33,29,21,.08); }
    .wo-card.dragging { opacity:.35; cursor:grabbing; }
    .wo-card.landed   { border-color:#c9a96a; box-shadow:0 0 0 2px rgba(201,169,106,.35); }
    .wo-photo { aspect-ratio:4/5; background:#f3f4f6; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .wo-photo img { width:100%; height:100%; object-fit:cover; object-position:50% 0; pointer-events:none; }
    .wo-photo span { font-size:28px; color:#9ca3af; font-weight:600; }
    .wo-body { padding:10px 12px 12px; display:flex; flex-direction:column; flex:1 1 auto; }
    .wo-name { font-size:14px; font-weight:600; color:#111; margin:0 0 3px; }
    .wo-title { font-size:10.5px; letter-spacing:.14em; text-transform:uppercase; color:#6b7280; margin:0 0 8px; line-height:1.5; }
    .wo-foot { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:auto; }
    .wo-pos { display:inline-flex; align-items:center; justify-content:center; min-width:24px; height:22px; padding:0 6px;
              background:#1f1b12; color:#fff; font-size:11px; font-weight:600; border-radius:3px; }
    .wo-nudge { display:flex; gap:4px; }
    .wo-nudge button { width:26px; height:22px; padding:0; display:inline-flex; align-items:center; justify-content:center;
                       background:#fff; border:1px solid #d1d5db; border-radius:3px; cursor:pointer; color:#374151; font-size:12px; line-height:1; }
    .wo-nudge button:hover:not([disabled]) { background:#f3f4f6; color:#111; }
    .wo-nudge button[disabled] { opacity:.35; cursor:default; }
    .empty-state { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:34px; text-align:center; color:#6b7280; font-size:14px; }
    .hint { font-size:12px; color:#9ca3af; }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <?php if ($flash): ?>
      <div class="flash<?= array_key_exists('ok', $flash) && !$flash['ok'] ? ' bad' : '' ?>">
        <strong><?= e($flash['title']) ?></strong>
        <ul><?php foreach ($flash['lines'] as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
        <?php if (!empty($flash['offer_sync']) && $can_sync): ?>
          <form method="POST" style="margin:8px 0 0;" id="syncNowForm">
            <input type="hidden" name="_action" value="sync_site">
            <button type="submit" class="btn btn-outline btn-sm" id="syncNowBtn">
              <i class="ti ti-refresh"></i> Sync to Website now</button>
          </form>
        <?php endif; ?>
        <?php if (!empty($flash['log'])): ?>
          <details class="flash-log"><summary>Full log</summary><pre><?= e(trim($flash['log'])) ?></pre></details>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="mk-page-header">
      <h1>Website Order</h1>
      <div class="hdr-actions">
        <?php if ($public_url): ?>
          <a class="btn btn-outline btn-sm" href="<?= e($public_url) ?>" target="_blank" rel="noopener">
            <i class="ti ti-external-link"></i> See the live page</a>
        <?php endif; ?>
        <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-arrow-left"></i> Agent Roster</a>
      </div>
    </div>

    <p class="lede">
      This is the order brokers appear in on the public site, laid out four across the way that page lays them out.
      Drag a card where you want it, or use the arrows. Only brokers who are on the website show here, and saving
      does not publish on its own: the website picks the new order up on its next sync.
    </p>

    <?php if (!$has_web): ?>
      <div class="empty-state">The website columns are not in this database yet. Run <code>sql/agent_roster_v1.sql</code>.</div>
    <?php elseif (!$agents): ?>
      <div class="empty-state">
        Nobody is on the website yet. Put a broker on the site from their agent page, under Website, and they will appear here.
      </div>
    <?php else: ?>

      <form method="POST" id="orderForm">
        <input type="hidden" name="_action" value="save_order">
        <input type="hidden" name="order" id="orderField" value="">

        <div class="order-bar">
          <button type="submit" class="btn btn-primary btn-sm" id="saveBtn" disabled>
            <i class="ti ti-device-floppy"></i> Save order</button>
          <span class="state" id="orderState">Nothing moved yet.</span>
          <span class="hint" style="margin-left:auto;"><?= count($agents) ?> on the website</span>
        </div>

        <div class="grid" id="grid">
          <?php foreach ($agents as $i => $a):
                $name  = (string)$a['name'];
                $photo = (string)($a['headshot_url'] ?: $a['headshot_face_url']); ?>
            <article class="wo-card" draggable="true" data-id="<?= (int)$a['id'] ?>" tabindex="0"
                     aria-label="<?= e($name) ?>, position <?= $i + 1 ?>">
              <div class="wo-photo">
                <?php if ($photo): ?>
                  <img src="<?= e($photo) ?>" alt="" draggable="false">
                <?php else: ?>
                  <span><?= e(wo_initials($name)) ?></span>
                <?php endif; ?>
              </div>
              <div class="wo-body">
                <p class="wo-name"><?= e($name) ?></p>
                <?php if (trim((string)$a['agent_title']) !== ''): ?>
                  <p class="wo-title"><?= wo_title((string)$a['agent_title']) ?></p>
                <?php endif; ?>
                <div class="wo-foot">
                  <span class="wo-pos"><?= $i + 1 ?></span>
                  <span class="wo-nudge">
                    <button type="button" class="js-back" title="Move earlier" aria-label="Move earlier">&#9664;</button>
                    <button type="button" class="js-fwd"  title="Move later"   aria-label="Move later">&#9654;</button>
                  </span>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </form>

    <?php endif; ?>

  </div>
</div>

<script>
(function () {
  var grid = document.getElementById('grid');
  if (!grid) return;
  var form  = document.getElementById('orderForm');
  var field = document.getElementById('orderField');
  var save  = document.getElementById('saveBtn');
  var state = document.getElementById('orderState');

  var cards = function () { return Array.prototype.slice.call(grid.querySelectorAll('.wo-card')); };
  var start = cards().map(function (c) { return c.dataset.id; }).join(',');
  var dragged = null;

  // Renumber the badges, switch the end arrows off, and work out whether this
  // differs from what was on screen when the page loaded.
  function refresh() {
    var list = cards();
    list.forEach(function (c, i) {
      c.querySelector('.wo-pos').textContent = i + 1;
      c.querySelector('.js-back').disabled = (i === 0);
      c.querySelector('.js-fwd').disabled  = (i === list.length - 1);
      c.setAttribute('aria-label', c.querySelector('.wo-name').textContent + ', position ' + (i + 1));
    });
    var now = list.map(function (c) { return c.dataset.id; }).join(',');
    field.value = now;
    var dirty = now !== start;
    save.disabled = !dirty;
    state.textContent = dirty ? 'Moved, not saved yet.' : 'Nothing moved yet.';
    state.className = 'state' + (dirty ? ' dirty' : '');
  }

  // ── Dragging ───────────────────────────────────────────────────────────────
  grid.addEventListener('dragstart', function (e) {
    var card = e.target.closest ? e.target.closest('.wo-card') : null;
    if (!card) return;
    dragged = card;
    card.classList.add('dragging');
    // Firefox will not start a drag unless something is on the dataTransfer.
    try { e.dataTransfer.setData('text/plain', card.dataset.id); } catch (err) {}
    if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
  });

  grid.addEventListener('dragover', function (e) {
    if (!dragged) return;
    e.preventDefault();                       // without this the drop never fires
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
    var over = e.target.closest ? e.target.closest('.wo-card') : null;
    if (!over || over === dragged) return;
    // Past the halfway line of the card you are over, land after it. The card
    // moves as you go, so the grid always shows the order you would get.
    var box  = over.getBoundingClientRect();
    var after = (e.clientX - box.left) > box.width / 2;
    grid.insertBefore(dragged, after ? over.nextSibling : over);
  });

  grid.addEventListener('drop', function (e) { if (dragged) e.preventDefault(); });

  grid.addEventListener('dragend', function () {
    if (!dragged) return;
    dragged.classList.remove('dragging');
    dragged.classList.add('landed');
    var settled = dragged;
    setTimeout(function () { settled.classList.remove('landed'); }, 900);
    dragged = null;
    refresh();
  });

  // ── Arrows (and touch screens, where dragging never fires) ─────────────────
  grid.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.js-back, .js-fwd') : null;
    if (!btn) return;
    var card = btn.closest('.wo-card');
    if (btn.classList.contains('js-back')) {
      if (card.previousElementSibling) grid.insertBefore(card, card.previousElementSibling);
    } else if (card.nextElementSibling) {
      grid.insertBefore(card.nextElementSibling, card);
    }
    card.classList.add('landed');
    setTimeout(function () { card.classList.remove('landed'); }, 900);
    refresh();
    card.querySelector(btn.classList.contains('js-back') ? '.js-back' : '.js-fwd').focus();
  });

  // Same moves from the keyboard, for anyone who cannot drag.
  grid.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    var card = e.target.closest ? e.target.closest('.wo-card') : null;
    if (!card || card !== e.target) return;
    e.preventDefault();
    if (e.key === 'ArrowLeft') {
      if (card.previousElementSibling) grid.insertBefore(card, card.previousElementSibling);
    } else if (card.nextElementSibling) {
      grid.insertBefore(card.nextElementSibling, card);
    }
    refresh();
    card.focus();
  });

  // Do not let an unsaved rearrangement disappear with the tab.
  var leaving = false;
  form.addEventListener('submit', function () { leaving = true; });
  window.addEventListener('beforeunload', function (e) {
    if (leaving || save.disabled) return;
    e.preventDefault();
    e.returnValue = '';
  });

  var syncForm = document.getElementById('syncNowForm');
  if (syncForm) {
    syncForm.addEventListener('submit', function () {
      var b = document.getElementById('syncNowBtn');
      b.disabled = true;
      b.innerHTML = '<i class="ti ti-loader-2"></i> Syncing, this can take a minute';
    });
  }

  refresh();
})();
</script>
</body>
</html>
