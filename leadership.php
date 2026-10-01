<?php
/**
 * leadership.php — the public site's Leadership page, managed here, and the
 * non-agent staff who appear on it (docs/HANDOFF-leadership-and-staff.md;
 * built 2026-10-01).
 *
 *   · Leadership: who is on the page and in what order. Drag the cards (or use
 *     the arrows), Save, then Sync to Website. Saving writes leadership_sort
 *     10, 20, 30 ... in one transaction. An agent appears on the site only while
 *     they are on the website themselves (web_status approved); the card warns.
 *   · Staff: people who are not agents (entity_type 'staff': Nikki Boxer,
 *     Kellee Anderson). Added here; edited on agent.php, which shows a staff
 *     member only their contact details, headshots and Leadership settings.
 *     Staff are kept out of every agent query by mk_agents_only_sql().
 *
 * Published as the roster feed's top-level `leadership` array (api/roster.php);
 * the site rebuilds its Leadership page from it on its hourly sync.
 * Same hand-written drag as website_order.php (no library, on purpose).
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/schema.php';
require_once __DIR__ . '/inc/agent_roster.php';   // mk_make_slug()
require_once __DIR__ . '/inc/site_sync.php';      // mk_site_sync()
require_login();
require_role('admin');

$ready = mk_column_exists($conn, 'marketing_intakes', 'leadership_show');
$ld_back = function (string $title, bool $ok, array $lines, bool $offer_sync = false) {
    $_SESSION['mk_flash'] = ['title' => $title, 'ok' => $ok, 'lines' => $lines, 'offer_sync' => $offer_sync];
    header('Location: leadership.php'); exit;
};
/** Who may be on Leadership: an active agent or staff member (never a team). */
$eligible_sql = "is_active = 1 AND status <> 'archived' AND entity_type IN ('agent','staff')";

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    $action = $_POST['_action'] ?? '';
    $next_sort = fn() => (int)$conn->query("SELECT COALESCE(MAX(leadership_sort), 0) FROM marketing_intakes WHERE leadership_show = 1")->fetch_row()[0] + 10;

    if ($action === 'save_order') {
        // Only ids currently on Leadership are honoured, so a stale tab cannot
        // add someone who was removed meanwhile.
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? ''))))));
        $on = [];
        $r = $conn->query("SELECT id FROM marketing_intakes WHERE leadership_show = 1 AND {$eligible_sql}");
        while ($row = $r->fetch_row()) $on[(int)$row[0]] = true;
        $ids = array_values(array_filter($ids, fn($i) => isset($on[$i])));
        if (!$ids) $ld_back('Nothing to save', false, ['The order came through empty, so nothing was changed.']);
        $conn->begin_transaction();
        try {
            $st = $conn->prepare("UPDATE marketing_intakes SET leadership_sort = ? WHERE id = ?");
            foreach ($ids as $pos => $iid) { $n = ($pos + 1) * 10; $st->bind_param('ii', $n, $iid); $st->execute(); }
            $st->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $ld_back('The order was not saved', false, [$e->getMessage(), 'Nothing was changed.']);
        }
        $ld_back('Order saved', true, ['The website shows the new order after its next sync (hourly, or Sync now).'], true);
    }

    if ($action === 'add_leader') {
        $iid = (int)($_POST['id'] ?? 0);
        $row = $conn->query("SELECT agent_name FROM marketing_intakes WHERE id = {$iid} AND {$eligible_sql} LIMIT 1")->fetch_row();
        if (!$row) $ld_back('Not added', false, ['That person could not be found, or is not an active agent or staff member.']);
        $sort = $next_sort();
        $conn->query("UPDATE marketing_intakes SET leadership_show = 1, leadership_sort = {$sort} WHERE id = {$iid}");
        $ld_back('Added to Leadership', true, [$row[0] . ' is now last on the page. Drag them into place, then Save.'], true);
    }

    if ($action === 'remove_leader') {
        $iid = (int)($_POST['id'] ?? 0);
        $conn->query("UPDATE marketing_intakes SET leadership_show = 0 WHERE id = {$iid}");
        $ld_back('Removed from Leadership', true, ['They are no longer on the Leadership page (after the next sync). Nothing else about them changed.'], true);
    }

    if ($action === 'add_staff') {
        $name  = trim((string)($_POST['agent_name'] ?? ''));
        $title = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['agent_title'] ?? '')));
        $email = strtolower(trim((string)($_POST['mh_email'] ?? '')));
        $phone = trim((string)($_POST['cell_phone'] ?? ''));
        $show  = isset($_POST['leadership_show']) ? 1 : 0;
        if ($name === '') $ld_back('Not added', false, ['A staff member needs a name.']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $ld_back('Not added', false, ['That email address does not look right.']);
        $sort = $show ? $next_sort() : 0;
        $st = $conn->prepare("INSERT INTO marketing_intakes
                                (agent_name, agent_title, mh_email, cell_phone, entity_type, status, is_active, intake_date,
                                 leadership_show, leadership_sort)
                              VALUES (?, ?, ?, ?, 'staff', 'active', 1, CURDATE(), ?, ?)");
        $st->bind_param('ssssii', $name, $title, $email, $phone, $show, $sort);
        $st->execute();
        $new_id = (int)$conn->insert_id;
        $st->close();
        if ($new_id && mk_column_exists($conn, 'marketing_intakes', 'slug')) {
            $slug = mk_make_slug($conn, $name, $new_id);   // the feed's key for their Leadership entry
            $s2 = $conn->prepare("UPDATE marketing_intakes SET slug = ? WHERE id = ?");
            $s2->bind_param('si', $slug, $new_id); $s2->execute(); $s2->close();
        }
        // Straight to their page: the headshot is the next thing they need.
        header('Location: agent.php?id=' . $new_id . '&tab=profile'); exit;
    }

    if ($action === 'sync_site') {
        $r = mk_site_sync(false);
        $_SESSION['mk_flash'] = ['title' => $r['ok'] ? 'Website synced' : 'Website sync did not run',
                                 'lines' => $r['lines'], 'ok' => $r['ok'], 'log' => $r['log']];
        header('Location: leadership.php'); exit;
    }
    header('Location: leadership.php'); exit;
}

$flash = $_SESSION['mk_flash'] ?? null;
unset($_SESSION['mk_flash']);

// ── Load ─────────────────────────────────────────────────────────────────────
$leaders = $candidates = $staff = [];
if ($ready) {
    $name_expr = "COALESCE(NULLIF(TRIM(agent_name), ''), mls_full_name)";
    $cols = "id, {$name_expr} AS name, agent_title, headshot_url, headshot_face_url, entity_type, web_status, mh_email, cell_phone, leadership_show";
    $leaders = $conn->query("SELECT {$cols} FROM marketing_intakes WHERE leadership_show = 1 AND {$eligible_sql}
                              ORDER BY leadership_sort, {$name_expr}")->fetch_all(MYSQLI_ASSOC);
    $candidates = $conn->query("SELECT {$cols} FROM marketing_intakes WHERE leadership_show = 0 AND {$eligible_sql}
                                 ORDER BY entity_type = 'staff' DESC, {$name_expr}")->fetch_all(MYSQLI_ASSOC);
    $staff = $conn->query("SELECT {$cols} FROM marketing_intakes WHERE entity_type = 'staff' AND is_active = 1
                            ORDER BY {$name_expr}")->fetch_all(MYSQLI_ASSOC);
}
$public_url = defined('PUBLIC_SITE_URL') ? rtrim(PUBLIC_SITE_URL, '/') . '/leadership.php' : '';
$can_sync   = defined('SITE_AGENT_SYNC_URL') && SITE_AGENT_SYNC_URL !== '';

$conn->close();
// ── Nothing below this line may touch the database. ─────────────────────────

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
function wo_initials(string $name): string {
    $words = array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2);
    return strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), $words)));
}
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
  <title>Leadership &amp; Staff | Mont Haus Marketing</title>
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
    .ld-type { font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; padding:2px 6px; border-radius:3px; }
    .ld-type.agent { background:#eef2ff; color:#3730a3; } .ld-type.staff { background:#fdf3e2; color:#8a5a12; }
    .ld-warn { font-size:11px; color:#b45309; margin:0 0 6px; }
    .ld-links { display:flex; gap:6px; margin:0 0 8px; }
    .ld-links a, .ld-links button { font-size:11px; padding:2px 8px; border:1px solid #d1d5db; background:#fff; border-radius:3px; color:#374151; text-decoration:none; cursor:pointer; font-family:inherit; }
    .ld-links button:hover, .ld-links a:hover { background:#f3f4f6; }
    .ld-panel { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:16px 18px; margin-top:22px; }
    .ld-panel h2 { font-size:15px; font-weight:700; margin:0 0 4px; }
    .ld-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:10px; }
    .ld-row label { display:flex; flex-direction:column; font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; gap:4px; }
    .ld-row input[type=text], .ld-row input[type=email], .ld-row select, .ld-row textarea { font:14px 'Public Sans', sans-serif; padding:7px 9px; border:1px solid #d1d5db; border-radius:4px; min-width:200px; text-transform:none; letter-spacing:0; color:#111; }
    .ld-row textarea { min-height:38px; }
    .ld-row .chk { flex-direction:row; align-items:center; gap:6px; text-transform:none; font-size:13px; color:#374151; letter-spacing:0; }
    table.ld-staff { width:100%; border-collapse:collapse; margin-top:10px; font-size:13px; }
    table.ld-staff th { text-align:left; font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; padding:6px 8px; border-bottom:1px solid #e5e7eb; }
    table.ld-staff td { padding:8px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
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
            <button type="submit" class="btn btn-outline btn-sm" id="syncNowBtn"><i class="ti ti-refresh"></i> Sync to Website now</button>
          </form>
        <?php endif; ?>
        <?php if (!empty($flash['log'])): ?>
          <details class="flash-log"><summary>Full log</summary><pre><?= e(trim($flash['log'])) ?></pre></details>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="mk-page-header">
      <h1>Leadership &amp; Staff</h1>
      <div class="hdr-actions">
        <?php if ($public_url): ?>
          <a class="btn btn-outline btn-sm" href="<?= e($public_url) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link"></i> See the live page</a>
        <?php endif; ?>
        <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-arrow-left"></i> Agent Roster</a>
      </div>
    </div>

    <p class="lede">
      The website's Leadership page, in order. Drag a card where you want it, or use the arrows, then Save.
      The website picks changes up on its hourly sync, or straight away with Sync to Website.
      Staff are people who are not agents: they appear on Leadership only, never as agents anywhere.
    </p>

    <?php if (!$ready): ?>
      <div class="empty-state">Leadership is not set up in this database yet. Run <code>sql/leadership_v1.sql</code>, then reload.</div>
    <?php else: ?>

      <?php if (!$leaders): ?>
        <div class="empty-state">Nobody is on the Leadership page yet. Add people below.</div>
      <?php else: ?>
      <form method="POST" id="orderForm">
        <input type="hidden" name="_action" value="save_order">
        <input type="hidden" name="order" id="orderField" value="">
        <div class="order-bar">
          <button type="submit" class="btn btn-primary btn-sm" id="saveBtn" disabled><i class="ti ti-device-floppy"></i> Save order</button>
          <span class="state" id="orderState">Nothing moved yet.</span>
          <span class="hint" style="margin-left:auto;"><?= count($leaders) ?> on Leadership</span>
        </div>
      </form>

      <div class="grid" id="grid">
        <?php foreach ($leaders as $i => $a):
              $name  = (string)$a['name'];
              $photo = (string)($a['headshot_url'] ?: $a['headshot_face_url']);
              $is_staff = $a['entity_type'] === 'staff'; ?>
          <article class="wo-card" draggable="true" data-id="<?= (int)$a['id'] ?>" tabindex="0" aria-label="<?= e($name) ?>, position <?= $i + 1 ?>">
            <div class="wo-photo">
              <?php if ($photo): ?><img src="<?= e($photo) ?>" alt="" draggable="false"><?php else: ?><span><?= e(wo_initials($name)) ?></span><?php endif; ?>
            </div>
            <div class="wo-body">
              <p class="wo-name"><?= e($name) ?> <span class="ld-type <?= $is_staff ? 'staff' : 'agent' ?>"><?= $is_staff ? 'Staff' : 'Agent' ?></span></p>
              <?php if (trim((string)$a['agent_title']) !== ''): ?><p class="wo-title"><?= wo_title((string)$a['agent_title']) ?></p><?php endif; ?>
              <?php if (!$is_staff && $a['web_status'] !== 'approved'): ?><p class="ld-warn">Not on the website, so not shown on Leadership.</p><?php endif; ?>
              <?php if (!$photo): ?><p class="ld-warn">No headshot yet.</p><?php endif; ?>
              <div class="ld-links">
                <a href="agent.php?id=<?= (int)$a['id'] ?>&amp;tab=profile">Edit</a>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Take <?= e($name) ?> off the Leadership page?');">
                  <input type="hidden" name="_action" value="remove_leader"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button type="submit">Remove</button>
                </form>
              </div>
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
      <?php endif; ?>

      <div class="ld-panel">
        <h2>Add to Leadership</h2>
        <span class="hint">An agent or staff member not on the page yet. They go last; drag them into place.</span>
        <form method="POST" class="ld-row">
          <input type="hidden" name="_action" value="add_leader">
          <label>Person
            <select name="id" required>
              <option value="">Choose…</option>
              <?php foreach ($candidates as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= $c['entity_type'] === 'staff' ? ' (staff)' : ($c['web_status'] !== 'approved' ? ' (not on the website)' : '') ?></option>
              <?php endforeach; ?>
            </select></label>
          <button type="submit" class="btn btn-primary btn-sm">Add</button>
        </form>
      </div>

      <div class="ld-panel">
        <h2>Add a staff member</h2>
        <span class="hint">Someone who is not an agent. You will go to their page next to add a headshot.</span>
        <form method="POST" class="ld-row">
          <input type="hidden" name="_action" value="add_staff">
          <label>Name <input type="text" name="agent_name" required maxlength="150"></label>
          <label>Title <textarea name="agent_title" rows="1" maxlength="255" placeholder="e.g. Accounting Manager"></textarea></label>
          <label>Email <input type="email" name="mh_email" maxlength="255" placeholder="first.last@monthaus.com"></label>
          <label>Phone <input type="text" name="cell_phone" maxlength="50"></label>
          <label class="chk"><input type="checkbox" name="leadership_show" value="1" checked> Show on Leadership</label>
          <button type="submit" class="btn btn-primary btn-sm">Add staff member</button>
        </form>
      </div>

      <div class="ld-panel">
        <h2>Staff</h2>
        <?php if (!$staff): ?><span class="hint">No staff yet.</span><?php else: ?>
        <table class="ld-staff">
          <tr><th>Name</th><th>Title</th><th>Email</th><th>Phone</th><th>On Leadership</th><th></th></tr>
          <?php foreach ($staff as $s): ?>
            <tr><td><?= e($s['name']) ?></td><td><?= wo_title((string)$s['agent_title']) ?></td><td><?= e($s['mh_email']) ?></td>
                <td><?= e($s['cell_phone']) ?></td><td><?= (int)$s['leadership_show'] ? 'Yes' : 'No' ?></td>
                <td><a href="agent.php?id=<?= (int)$s['id'] ?>&amp;tab=profile">Edit</a></td></tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>

    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var grid = document.getElementById('grid');
  if (!grid) return;
  var form  = document.getElementById('orderForm');   // holds only the Save button: the cards carry their own Remove forms
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
