<?php
/**
 * pipeline_review.php — review queue for Paperless Pipeline transactions.
 *
 * Ported from monthausint.com/hot-sheets/pipeline_review.php. Nothing parsed
 * from Paperless reaches a hot sheet until it is promoted here into
 * hs_manual_listings (plus an hs_manual_listing_changes row so it shows in
 * Latest Updates). A promoted deal that later changes status returns here.
 *
 * Changed from the hub:
 *   · STATUS: a cancelled / withdrawn / expired deal (pl_map_status() = null)
 *     can no longer be promoted as an Active pocket listing. If it was already
 *     on the hot sheet, the card offers only "Remove from hot sheet".
 *   · Photo, city, MLS # and the buyer-rep link come from site.monthaus.com
 *     (pl_enrich_address()); the link is the site's listing page, not Lofty.
 *   · Agents are roster names (marketing_intakes).
 *
 * POST actions: promote, reject, unreject. Admin only.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_login();
require_role('admin');
require_once __DIR__ . '/inc/pipeline.php';
require_once __DIR__ . '/inc/boards.php';
require_once __DIR__ . '/inc/schema.php';

$now = date('Y-m-d H:i:s');
$uid = (int)($_SESSION['user_id'] ?? 0);
$error = ''; $success = '';

$chk = $conn->query("SHOW TABLES LIKE 'hs_pipeline_transactions'");
if (!$chk || !$chk->fetch_row()) {
    http_response_code(503);
    exit('Paperless Pipeline is not set up yet: run sql/hot_sheets_v3_pipeline.sql.');
}

// Hot Sheet areas (2026-10-07, sql/hot_sheets_v4_areas.sql): a pocket listing
// or buyer rep belongs to ONE area's email, chosen here when promoted
// (defaulted from the MLS match) and changeable under Recently reviewed.
$has_area   = mk_column_exists($conn, 'hs_manual_listings', 'area_key');
$area_names = mk_area_names();
$pick_area  = fn($v) => isset($area_names[(string)$v]) ? (string)$v : null;

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $tid    = (int)($_POST['txn_row_id'] ?? 0);

    if ($action === 'set_area' && $has_area) {
        $ml_id = (int)($_POST['manual_listing_id'] ?? 0);
        $ak    = $pick_area($_POST['area_key'] ?? '');
        $s = $conn->prepare("UPDATE hs_manual_listings SET area_key = ?, updated_at = ? WHERE id = ?");
        if ($s && $ml_id) {
            $s->bind_param('ssi', $ak, $now, $ml_id); $s->execute(); $s->close();
            $success = $ak ? 'Area set to ' . $area_names[$ak] . '.' : 'Area cleared: this entry is in no Hot Sheet until one is chosen.';
        }
    }

    if ($action === 'reject' && $tid) {
        // Dismissing used to touch only the review queue, which left an
        // already-promoted entry sitting on the hot sheet with no obvious way
        // to pull it — the deal fell through but the listing kept sending.
        // If this transaction was promoted, retire its manual_listings row too;
        // that is the flag build_hot_sheet_data() actually reads.
        $ml_id = 0;
        $q = $conn->prepare("SELECT manual_listing_id FROM hs_pipeline_transactions WHERE id=? LIMIT 1");
        if ($q) {
            $q->bind_param('i', $tid);
            $q->execute();
            $ml_id = (int)($q->get_result()->fetch_assoc()['manual_listing_id'] ?? 0);
            $q->close();
        }

        $s = $conn->prepare("UPDATE hs_pipeline_transactions SET review_status='rejected', reviewed_by=?, reviewed_at=? WHERE id=?");
        if ($s) { $s->bind_param('isi', $uid, $now, $tid); $s->execute(); $s->close(); }

        $success = 'Dismissed.';
        if ($ml_id) {
            $d = $conn->prepare("UPDATE hs_manual_listings SET is_active=0, updated_at=? WHERE id=?");
            if ($d) {
                $d->bind_param('si', $now, $ml_id);
                $d->execute();
                $d->close();
                $success = 'Dismissed and removed from the hot sheet.';
            }
        }
    }

    if ($action === 'unreject' && $tid) {
        // Mirror of the above: restoring a dismissed row puts its hot sheet
        // entry back, so Restore undoes Dismiss completely rather than
        // returning a transaction whose listing is still hidden.
        $ml_id = 0;
        $q = $conn->prepare("SELECT manual_listing_id FROM hs_pipeline_transactions WHERE id=? LIMIT 1");
        if ($q) {
            $q->bind_param('i', $tid);
            $q->execute();
            $ml_id = (int)($q->get_result()->fetch_assoc()['manual_listing_id'] ?? 0);
            $q->close();
        }

        $conn->query("UPDATE hs_pipeline_transactions SET review_status='pending' WHERE id={$tid}");

        $success = 'Returned to the queue.';
        if ($ml_id) {
            $d = $conn->prepare("UPDATE hs_manual_listings SET is_active=1, updated_at=? WHERE id=?");
            if ($d) {
                $d->bind_param('si', $now, $ml_id);
                $d->execute();
                $d->close();
                $success = 'Returned to the queue and restored on the hot sheet.';
            }
        }
    }

    if ($action === 'promote' && $tid) {
        // Load the staged row
        $r   = $conn->query("SELECT * FROM hs_pipeline_transactions WHERE id={$tid} LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;

        if (!$row) {
            $error = 'That staged row no longer exists.';
        } else {
            $entry_type = in_array($_POST['entry_type'] ?? '', ['buyer_rep','pocket_listing'], true)
                        ? $_POST['entry_type'] : ($row['suggested_entry_type'] ?: 'pocket_listing');
            $address    = trim($_POST['address']     ?? '') ?: trim((string)$row['address']);
            $city       = trim($_POST['city']        ?? '') ?: null;
            $agents     = trim($_POST['agent_names'] ?? '') ?: null;
            $status     = pl_map_status($row['status_category'], $row['status_label']);
            $notes      = trim($_POST['notes'] ?? '') ?: null;
            $area_key   = $pick_area($_POST['area_key'] ?? '');   // blank = take the MLS match's area below
            $success_extra = '';   // appended to the flash by the branches below

            // Price rules: a pending deal publishes no figure — the contract
            // price stays confidential until closing. On close, the sale price
            // becomes close_price.
            $price       = null;
            $close_price = null;
            if ($status === 'Closed') {
                $close_price = ($row['sale_price'] !== null && $row['sale_price'] !== '')
                             ? (float)$row['sale_price'] : null;
            }

            if ($status === null) {
                // Cancelled, withdrawn, expired or unrecognised: nothing to publish.
                $error = 'Paperless reports "' . ($row['status_label'] ?: $row['status_category'] ?: 'no status')
                       . '", which is not active, pending or closed. Nothing was added. Use Dismiss'
                       . (!empty($row['manual_listing_id']) ? ' & remove to take it off the hot sheet.' : '.');
            } elseif ($address === '') {
                $error = 'An address is required before promoting.';
            } else {
                // Photo, city, MLS # and listing page from site.monthaus.com's
                // listings (every board, any status). Pocket listings are off
                // market, so only buyer-side deals get a link.
                $enr   = pl_enrich_address($address);
                $photo = $enr['photo'] ?: null;
                $lurl  = ($entry_type === 'buyer_rep') ? ($enr['url'] ?: null) : null;
                if ($city === null && !empty($enr['city'])) $city = $enr['city'];
                if ($area_key === null) $area_key = $pick_area($enr['area_key'] ?? '');
                if ($area_key === null && $city !== null) {
                    // A typed city with no MLS match: the board the town belongs to, then its area.
                    $b = mk_board_towns()[strtolower(trim($city))] ?? null;
                    if ($b !== null) $area_key = $pick_area(mk_board_area($b) ?? '');
                }
                $manual_url = trim($_POST['listing_url'] ?? '');
                if ($manual_url !== '' && $entry_type === 'buyer_rep') $lurl = $manual_url;   // typed URL wins

                $existing_id = (int)($row['manual_listing_id'] ?? 0);

                if ($existing_id) {
                    // Already promoted once. Note the status BEFORE updating, so
                    // we can tell a genuine status change from a re-promote done
                    // only to correct a photo or link.
                    $prev_status = null;
                    $ps = $conn->prepare("SELECT status FROM hs_manual_listings WHERE id=? LIMIT 1");
                    if ($ps) {
                        $ps->bind_param('i', $existing_id);
                        $ps->execute();
                        $prow = $ps->get_result()->fetch_assoc();
                        $ps->close();
                        $prev_status = $prow['status'] ?? null;
                    }

                    // Photo and link are overwritten outright, including with NULL.
                    // Re-review exists precisely to correct a bad auto-match, so
                    // the fresh enrichment result has to win — an earlier version
                    // used COALESCE to preserve the existing value, which meant a
                    // wrong photo could never be cleared. A URL typed into the
                    // form still takes precedence (handled above).
                    $s = $conn->prepare(
                        "UPDATE hs_manual_listings
                            SET entry_type=?, address=?, city=?, status=?, close_price=?,
                                agent_names=?, notes=?, primary_photo_url=?, listing_url=?,
                                mls_number=?, market=?, postal_code=COALESCE(postal_code, ?),
                                is_active=1, updated_at=?
                          WHERE id=?"
                    );
                    if ($s) {
                        $mlsn = $enr['mls_number']; $mkt = $enr['market']; $pc = $row['postal_code'] ?: $enr['postal_code'];
                        $s->bind_param('ssssdssssssssi', $entry_type, $address, $city, $status,
                                       $close_price, $agents, $notes, $photo, $lurl, $mlsn, $mkt, $pc, $now, $existing_id);
                        $s->execute(); $s->close();
                    }
                    $ml_id = $existing_id;
                    if ($has_area && $area_key !== null) {
                        // A re-promote keeps the area already chosen unless the form named one or the match now gives one.
                        $u = $conn->prepare("UPDATE hs_manual_listings SET area_key = ? WHERE id = ?");
                        if ($u) { $u->bind_param('si', $area_key, $ml_id); $u->execute(); $u->close(); }
                    }

                    // Only log a change when the status genuinely moved. Without
                    // this, correcting a photo re-announced the listing in Latest
                    // Updates and reset its 14-day clock — a re-promote is an
                    // edit, not news.
                    if ($prev_status !== null && strcasecmp((string)$prev_status, $status) !== 0) {
                        $c = $conn->prepare(
                            "INSERT INTO hs_manual_listing_changes (manual_listing_id, change_type, old_value, new_value, detected_at)
                             VALUES (?, 'status', ?, ?, ?)"
                        );
                        if ($c) {
                            $c->bind_param('isss', $ml_id, $prev_status, $status, $now);
                            $c->execute(); $c->close();
                        }
                        $success_extra = ' Status changed ' . $prev_status . ' → ' . $status . '.';
                    } else {
                        $success_extra = ' Details updated, not re-announced (status unchanged).';
                    }

                } else {
                    $s = $conn->prepare(
                        "INSERT INTO hs_manual_listings
                            (entry_type, address, city, state_abbr, postal_code, price, close_price, status,
                             agent_names, notes, primary_photo_url, listing_url, mls_number, market,
                             is_active, created_at)
                         VALUES (?,?,?,'CO',?,?,?,?,?,?,?,?,?,?,1,?)"
                    );
                    if (!$s) {
                        $error = 'Database error: ' . $conn->error;
                    } else {
                        // The hub threw away the MLS number, board and postal code
                        // here even though the table had columns for them.
                        $mlsn = $enr['mls_number']; $mkt = $enr['market']; $pc = $row['postal_code'] ?: $enr['postal_code'];
                        $s->bind_param('ssssddssssssss', $entry_type, $address, $city, $pc, $price,
                                       $close_price, $status, $agents, $notes, $photo, $lurl, $mlsn, $mkt, $now);
                        $s->execute();
                        $ml_id = (int)$conn->insert_id;
                        $s->close();
                        if ($has_area && $area_key !== null) {
                            $u = $conn->prepare("UPDATE hs_manual_listings SET area_key = ? WHERE id = ?");
                            if ($u) { $u->bind_param('si', $area_key, $ml_id); $u->execute(); $u->close(); }
                        }

                        $c = $conn->prepare(
                            "INSERT INTO hs_manual_listing_changes (manual_listing_id, change_type, old_value, new_value, detected_at)
                             VALUES (?, 'new_listing', NULL, ?, ?)"
                        );
                        if ($c) { $c->bind_param('iss', $ml_id, $status, $now); $c->execute(); $c->close(); }
                    }
                }

                if (!$error) {
                    $u = $conn->prepare(
                        "UPDATE hs_pipeline_transactions
                            SET review_status='promoted', manual_listing_id=?, reviewed_by=?, reviewed_at=?
                          WHERE id=?"
                    );
                    if ($u) { $u->bind_param('iisi', $ml_id, $uid, $now, $tid); $u->execute(); $u->close(); }

                    // Report what the MLS lookup actually recovered, so a missing
                    // photo or link is visible rather than silently absent.
                    $bits = [];
                    $bits[] = $enr['photo'] ? 'photo from the MLS' : 'no MLS photo, using placeholder';
                    $bits[] = $enr['mls_number'] ? ('MLS ' . $enr['mls_number']) : 'no MLS match';
                    if ($has_area) $bits[] = $area_key !== null ? 'Hot Sheet area: ' . $area_names[$area_key] : 'NO AREA, so in no Hot Sheet: set it under Recently reviewed';
                    if ($enr['error']) $bits[] = 'lookup: ' . $enr['error'];
                    if ($entry_type === 'buyer_rep') {
                        if ($lurl && $manual_url !== '') $bits[] = 'listing link set manually';
                        elseif ($lurl)                   $bits[] = 'listing link added';
                        else                             $bits[] = 'no listing link: use Re-review below to paste one';
                    }
                    $success = 'Added to the hot sheet as '
                             . ($entry_type === 'buyer_rep' ? 'Buyer Representation' : 'a Pocket Listing')
                             . '. (' . implode('; ', $bits) . ')' . $success_extra;
                }
            }
        }
    }
}

// ── Load queue ───────────────────────────────────────────────────────────────
// Joined to the raw event so we can inspect the original agent ordering.
$pending = [];
$r = $conn->query("
    SELECT pt.*, pe.payload
      FROM hs_pipeline_transactions pt
      LEFT JOIN hs_pipeline_events pe ON pe.id = pt.event_id
     WHERE pt.review_status='pending'
     ORDER BY pt.status_changed_at DESC, pt.id DESC
");
if ($r) $pending = $r->fetch_all(MYSQLI_ASSOC);

// Agents excluded from automatic crediting but sometimes genuinely on a deal.
$conditional = pl_email_list('PIPELINE_CONDITIONAL_AGENT_EMAILS');

/**
 * Was a conditionally-excluded agent listed first in the raw payload?
 *
 * Paperless attaches the managing broker to most transactions, so position is
 * the only available signal for whether they are actually working the deal.
 * It is a heuristic on undocumented ordering, so it only ever produces a
 * suggestion — never an automatic credit.
 *
 * Returns ['name' => ..., 'first' => bool] or null.
 */
function pr_conditional_hint(?string $payload, array $conditional): ?array {
    if (!$payload) return null;
    $p = json_decode($payload, true);
    if (!is_array($p)) return null;
    $raw = $p['agents_json'] ?? $p['Agents (JSON)'] ?? null;
    $ag  = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($ag) || !$ag) return null;

    foreach (array_values($ag) as $i => $a) {
        if (!is_array($a)) continue;
        $em = strtolower(trim($a['email'] ?? ''));
        if (in_array($em, $conditional, true)) {
            return [
                'name'  => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')),
                'first' => $i === 0,
            ];
        }
    }
    return null;
}

$done = [];
$r = $conn->query("
    SELECT pt.*, ml.address AS ml_address, ml.id AS ml_id, ml.is_active AS ml_active" . ($has_area ? ', ml.area_key AS ml_area' : '') . "
      FROM hs_pipeline_transactions pt
      LEFT JOIN hs_manual_listings ml ON ml.id = pt.manual_listing_id
     WHERE pt.review_status <> 'pending'
     ORDER BY pt.reviewed_at DESC LIMIT 40
");
if ($r) $done = $r->fetch_all(MYSQLI_ASSOC);

// The area the parser's MLS match sits in, to pre-select on the promote form.
$match_area = [];
if ($has_area && mk_column_exists($conn, 'hs_listing_state', 'area_key')) {
    foreach ($pending as $t) {
        if (($t['mls_match_key'] ?? '') === '' || ($t['mls_match_market'] ?? '') === '') continue;
        $s = $conn->prepare("SELECT area_key FROM hs_listing_state WHERE market = ? AND listing_key = ?");
        if (!$s) break;
        $s->bind_param('ss', $t['mls_match_market'], $t['mls_match_key']); $s->execute();
        $match_area[(int)$t['id']] = (string)($s->get_result()->fetch_assoc()['area_key'] ?? '');
        $s->close();
    }
}

function h($v): string { return htmlspecialchars((string)$v); }
function money0(?string $v): string {
    return ($v === null || $v === '') ? '—' : '$' . number_format((float)$v, 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pipeline Review | Mont Haus Hot Sheet</title>
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after { box-sizing:border-box; }
  body { margin:0; background:#f6f7f9; font-family:'Public Sans',system-ui,sans-serif; color:#111; }
  .wrap { max-width:1000px; margin:0 auto; padding:28px 20px 60px; }
  h1 { font-size:22px; margin:0 0 4px; }
  .sub { font-size:13px; color:#6b7280; margin:0 0 22px; }
  .flash { padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:16px; }
  .flash.ok  { background:#d1fae5; color:#065f46; }
  .flash.err { background:#fee2e2; color:#991b1b; }
  .card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:16px 18px; margin-bottom:14px; }
  .top { display:flex; align-items:flex-start; gap:10px; margin-bottom:10px; }
  .addr { font-size:16px; font-weight:700; flex:1; min-width:0; }
  .chip { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
          padding:3px 8px; border-radius:10px; white-space:nowrap; }
  .chip.buyer  { background:#dbeafe; color:#1d4ed8; }
  .chip.pocket { background:#dcfce7; color:#166534; }
  .chip.status { background:#f3f4f6; color:#4b5563; }
  /* Listing-side deal the MLS feed already carries — not off-market */
  .chip.onmls  { background:#fef3c7; color:#92400e; }
  .grid { display:grid; grid-template-columns:auto 1fr; gap:5px 14px; font-size:13px; margin-bottom:12px; }
  .k { color:#6b7280; }
  .v { color:#111; }
  .warn { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412;
          font-size:12px; padding:8px 10px; border-radius:6px; margin-bottom:12px; }
  .info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af;
          font-size:12px; padding:8px 10px; border-radius:6px; margin-bottom:12px; }
  form.promote { border-top:1px solid #f3f4f6; padding-top:12px; }
  .row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
  label { font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase;
          letter-spacing:.3px; display:block; margin-bottom:3px; }
  input,select,textarea { font-family:inherit; font-size:13px; padding:7px 9px;
          border:1px solid #d1d5db; border-radius:5px; width:100%; }
  .f1 { flex:1; min-width:150px; }
  .btn { font-size:13px; font-weight:600; padding:8px 14px; border-radius:5px;
         border:1px solid transparent; cursor:pointer; }
  .btn-go { background:#0184BB; color:#fff; }
  .btn-no { background:#fff; color:#6b7280; border-color:#d1d5db; }
  .empty { text-align:center; color:#9ca3af; font-size:14px; padding:40px 0; }
  .topbar { background:#1a1a1a; color:#fff; padding:12px 20px; display:flex;
            align-items:center; gap:18px; flex-wrap:wrap; }
  .topbar .title { font-weight:700; font-size:14px; margin-right:auto; }
  .topbar a { color:#d1d5db; text-decoration:none; font-size:13px; }
  .topbar a:hover { color:#fff; }
  h2 { font-size:13px; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin:30px 0 10px; }
  table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:8px; }
  td,th { padding:8px 12px; font-size:12px; text-align:left; border-bottom:1px solid #f3f4f6; }
  th { color:#6b7280; font-weight:600; }
  a { color:#0184BB; }
</style>
</head>
<body>

<div class="topbar">
  <span class="title">Mont Haus Hot Sheet: Pipeline Review</span>
  <a href="index.php">Roster</a>
  <a href="hot_sheet_preview.php">Preview the Hot Sheets</a>
  <a href="pipeline_events.php">Raw payloads</a>
  <a href="logout.php">Log out</a>
</div>

<?php
// Town list for the City picker. Keys are lowercase; display in title case.
$mh_cities = [];
foreach (array_keys(hs_city_market_map()) as $c) $mh_cities[] = ucwords($c);
sort($mh_cities);
?>
<datalist id="mh-cities">
  <?php foreach ($mh_cities as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?>
</datalist>

<div class="wrap">

  <h1>Pipeline Review</h1>
  <p class="sub">Transactions from Paperless Pipeline. Nothing here reaches a hot sheet until you promote it.</p>

  <?php if ($success): ?><div class="flash ok"><?= h($success) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

  <?php if (!$pending): ?>
    <div class="empty">Nothing waiting for review.</div>
  <?php endif; ?>

  <?php foreach ($pending as $t):
      $type    = $t['suggested_entry_type'] ?: 'pocket_listing';
      $status  = pl_map_status($t['status_category'], $t['status_label']);   // null = not publishable
      $onmls   = $t['match_confidence'] !== 'none';
      $is_repromote = !empty($t['manual_listing_id']);
  ?>
  <div class="card">
    <?php
      // suggested_entry_type comes from the transaction side alone — a
      // listing-side deal is suggested as a pocket listing whether or not it
      // is on the MLS. Calling that "Off-Market" directly above the duplicate
      // warning contradicts the warning, so say what we actually know instead.
      $mls_dupe = $onmls && $type === 'pocket_listing';

      // match_confidence is overloaded: the parser forces 'exact' when
      // Paperless supplies an MLS number, so 'exact' means either "Paperless
      // told us the MLS number" or "the address string matched a synced
      // listing". Those carry very different weight, and mls_number is only
      // populated in the first case — so use it to say which.
      $mls_from_pp = trim((string)($t['mls_number'] ?? '')) !== '';
      $mls_source  = $mls_from_pp
                   ? 'Paperless supplied this MLS number'
                   : 'matched on address, ' . h($t['match_confidence']);
    ?>
    <div class="top">
      <div class="addr"><?= h($t['address'] ?: '(no address)') ?></div>
      <?php if ($type === 'buyer_rep'): ?>
        <span class="chip buyer">Buyer Rep</span>
      <?php elseif ($mls_dupe): ?>
        <span class="chip onmls" title="MLS <?= h($t['mls_match_number'] ?: '(unknown)') ?>: <?= $mls_source ?>">On MLS</span>
      <?php else: ?>
        <span class="chip pocket">Off-Market</span>
      <?php endif; ?>
      <span class="chip status"><?= h($status ?? ($t['status_label'] ?: 'Unknown status')) ?></span>
    </div>

    <div class="grid">
      <div class="k">Side</div>          <div class="v"><?= h($t['side']) ?></div>
      <div class="k">Property type</div> <div class="v"><?= h($t['property_type'] ?: '—') ?></div>
      <div class="k">Agents</div>        <div class="v"><?= h($t['agent_names'] ?: 'none matched') ?></div>
      <div class="k">Under contract</div><div class="v"><?= h($t['acceptance_date'] ?: '—') ?></div>
      <div class="k">Closing</div>       <div class="v"><?= h($t['closing_date'] ?: '—') ?></div>
      <div class="k">Contract price</div><div class="v"><?= money0($t['sale_price']) ?>
        <?php if ($status !== 'Closed'): ?>
          <span style="color:#9ca3af;font-size:11px;">(withheld until closing)</span>
        <?php endif; ?>
      </div>
      <div class="k">Paperless ID</div>  <div class="v"><?= h($t['pipeline_txn_id']) ?></div>
    </div>

    <?php if ($is_repromote): ?>
      <div class="info">Already on the hot sheet. This is a status change. Promoting updates the existing entry
        <?php if ($status === 'Closed'): ?>and publishes the sale price<?php endif; ?>.</div>
    <?php endif; ?>

    <?php if ($status === null): ?>
      <div class="warn"><strong>Not publishable.</strong> Paperless reports
        "<?= h($t['status_label'] ?: $t['status_category'] ?: 'no status') ?>", which is not active, pending or closed.
        <?= $is_repromote ? 'It is still on the hot sheet: use Dismiss &amp; remove to take it off.' : 'Dismiss it, or wait for a status that can be published.' ?></div>
    <?php endif; ?>

    <?php if (!empty($t['unmatched_agents'])): ?>
      <div class="warn"><strong>Unmatched agents:</strong> <?= h($t['unmatched_agents']) ?><br>
        Not on the marketing roster, so they are left off the listing. If one is a real agent, add or fix
        their roster record (email) and re-run the parser rather than typing the name in below.</div>
    <?php endif; ?>

    <?php if ($mls_dupe): ?>
      <div class="warn">
        <?php if ($mls_from_pp): ?>
          <strong>Already on the MLS.</strong> Paperless gave this transaction MLS
          <?= h($t['mls_match_number']) ?>, so the MLS feed already carries it and the
          MLS Listings section will show it. Promoting it as off-market would list it twice.
        <?php else: ?>
          <strong>Possible duplicate.</strong> This address matches MLS
          <?= h($t['mls_match_number'] ?: '(unknown)') ?> (<?= h($t['match_confidence']) ?> address match),
          so it is probably already in the MLS Listings section. Promoting it as off-market
          would list it twice.
        <?php endif; ?>
      </div>
    <?php elseif (!$onmls && $type === 'pocket_listing'): ?>
      <div class="info">No MLS match, consistent with a genuine off-market listing.</div>
    <?php endif; ?>

    <form class="promote" method="POST">
      <input type="hidden" name="_action" value="promote">
      <input type="hidden" name="txn_row_id" value="<?= (int)$t['id'] ?>">
      <div class="row">
        <div class="f1"><label>Address</label>
          <input type="text" name="address" value="<?= h($t['address']) ?>" required></div>
        <div class="f1"><label>City</label>
          <input type="text" name="city" list="mh-cities"
                 placeholder="Auto-filled from the MLS — pick only if blank">
          <div style="font-size:11px;color:#9ca3af;margin-top:4px;">
            Leave blank and the MLS match supplies it. Only needed for a pocket
            listing that was never on the MLS.
          </div></div>
        <div class="f1"><label>Report as</label>
          <select name="entry_type">
            <option value="buyer_rep"      <?= $type==='buyer_rep'?'selected':'' ?>>Buyer Representation</option>
            <option value="pocket_listing" <?= $type==='pocket_listing'?'selected':'' ?>>Pocket Listing (off-market)</option>
          </select></div>
        <?php if ($has_area): $pre = $match_area[(int)$t['id']] ?? ''; ?>
        <div class="f1"><label>Hot Sheet area</label>
          <select name="area_key">
            <option value="">From the MLS match / city</option>
            <?php foreach ($area_names as $ak => $an): ?>
              <option value="<?= h($ak) ?>" <?= $ak === $pre ? 'selected' : '' ?>><?= h($an) ?></option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:11px;color:#9ca3af;margin-top:4px;">
            Which area's email carries it. Leave as is and the MLS match (or the city) decides; pick one when it cannot.
          </div></div>
        <?php endif; ?>
      </div>
      <div class="row">
        <div class="f1"><label>Agents</label>
          <input type="text" name="agent_names" id="agents-<?= (int)$t['id'] ?>" value="<?= h($t['agent_names']) ?>">
          <?php $hint = pr_conditional_hint($t['payload'] ?? null, $conditional);
                if ($hint && $hint['name']): ?>
            <div style="font-size:11px;margin-top:5px;<?= $hint['first'] ? 'color:#9a3412;' : 'color:#9ca3af;' ?>">
              <?php if ($hint['first']): ?>
                <strong><?= h($hint['name']) ?></strong> was listed <strong>first</strong> on this transaction —
                likely a real agent here rather than an automatic attachment.
              <?php else: ?>
                <?= h($hint['name']) ?> is attached to this transaction but not listed first, so was left off.
              <?php endif; ?>
              <button type="button" style="margin-left:4px;font-size:11px;padding:2px 7px;border:1px solid #d1d5db;
                      background:#fff;border-radius:4px;cursor:pointer;"
                      onclick="addAgent(<?= (int)$t['id'] ?>, <?= json_encode($hint['name']) ?>)">+ Add</button>
            </div>
          <?php endif; ?>
        </div>
        <div class="f1"><label>Notes (optional)</label>
          <input type="text" name="notes" placeholder="Internal note"></div>
      </div>
      <?php if ($type === 'buyer_rep'): ?>
      <div class="row">
        <div class="f1"><label>Listing URL (optional — overrides auto-lookup)</label>
          <input type="url" name="listing_url"
                 placeholder="https://site.monthaus.com/listing.php?src=ap&amp;m=aspen&amp;k=...">
          <div style="font-size:11px;color:#9ca3af;margin-top:4px;">
            Leave blank to look the listing up automatically. Paste the listing page URL here if that
            comes back empty.
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="row">
        <?php if ($status !== null): ?>
        <button class="btn btn-go" type="submit">
          <?= $is_repromote ? 'Update hot sheet entry' : 'Add to hot sheet' ?>
        </button>
        <?php endif; ?>
        <?php // Say which of the two things Dismiss will do — a promoted entry
              // is coming off the hot sheet, which is not obvious from "Dismiss" ?>
        <button class="btn btn-no" type="submit" name="_action" value="reject" formnovalidate
                onclick="return confirm(<?= $is_repromote
                    ? json_encode('Dismiss this transaction and remove it from the hot sheet? Restore puts it back.')
                    : json_encode('Dismiss this transaction?') ?>)">
          <?= $is_repromote ? 'Dismiss &amp; remove' : 'Dismiss' ?>
        </button>
      </div>
    </form>
  </div>
  <?php endforeach; ?>

  <?php if ($done): ?>
    <h2>Recently reviewed</h2>
    <table>
      <tr><th>Address</th><th>Outcome</th><?php if ($has_area): ?><th>Hot Sheet area</th><?php endif; ?><th>Status</th><th>When</th><th></th></tr>
      <?php foreach ($done as $d): ?>
      <tr>
        <td><?= h($d['address']) ?></td>
        <td><?= $d['review_status'] === 'promoted'
                ? 'Added' . ($d['ml_address'] ? '' : ' <span style="color:#9ca3af;">(entry since removed)</span>')
                : 'Dismissed' ?></td>
        <?php if ($has_area): ?>
        <td>
          <?php if ($d['review_status'] === 'promoted' && !empty($d['ml_id']) && (int)$d['ml_active'] === 1): ?>
            <form method="POST" style="margin:0;display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="_action" value="set_area">
              <input type="hidden" name="manual_listing_id" value="<?= (int)$d['ml_id'] ?>">
              <select name="area_key" style="width:auto;padding:4px 6px;font-size:12px;<?= ($d['ml_area'] ?? '') === '' || $d['ml_area'] === null ? 'border-color:#fdba74;background:#fff7ed;' : '' ?>" onchange="this.form.submit()">
                <option value="">No area (in no email)</option>
                <?php foreach ($area_names as $ak => $an): ?>
                  <option value="<?= h($ak) ?>" <?= ($d['ml_area'] ?? '') === $ak ? 'selected' : '' ?>><?= h($an) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php else: ?>—<?php endif; ?>
        </td>
        <?php endif; ?>
        <td><?= h($d['status_label'] ?: '—') ?></td>
        <td><?= h($d['reviewed_at'] ?: '—') ?></td>
        <td>
          <form method="POST" style="margin:0;">
            <input type="hidden" name="_action" value="unreject">
            <input type="hidden" name="txn_row_id" value="<?= (int)$d['id'] ?>">
            <button class="btn btn-no" style="padding:3px 8px;font-size:11px;"
                    title="<?= $d['review_status'] === 'promoted'
                        ? 'Send back to the queue. Re-promoting updates the existing hot sheet entry rather than creating a second one.'
                        : 'Put this back in the queue.' ?>">
              <?= $d['review_status'] === 'promoted' ? 'Re-review' : 'Restore' ?>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

</div>
<script>
// Append a suggested agent to that card's Agents field, avoiding duplicates.
function addAgent(rowId, name) {
  var el = document.getElementById('agents-' + rowId);
  if (!el) return;
  var parts = el.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  if (parts.some(function (p) { return p.toLowerCase() === name.toLowerCase(); })) return;
  parts.push(name);
  el.value = parts.join(', ');
  el.focus();
}
</script>
</body>
</html>
