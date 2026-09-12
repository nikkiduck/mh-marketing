<?php
/**
 * billing.php — what every agent owes, and where it is in the billing cycle.
 *
 * A collections view, not a report. The only question it answers is "what do I
 * need to do next", so anything settled and old drops off the page entirely.
 *
 * The figures are NOT computed here. mh_agent_financials() in inc/financials.php
 * is the one implementation, shared with the agent's Financials tab — the two
 * cannot disagree because there is only one of them.
 *
 * State lives in marketing_billing_months: billed_at, paid_at, and the amount
 * frozen at the moment BILLED was ticked. See sql/alter_billing_status_v1.sql
 * for why that snapshot exists.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/financials.php';
require_login();
require_role('admin');

$me = function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : 0;

// ── POST: tick or untick one month ────────────────────────────────────────────
//
// Ticking BILLED freezes the amount currently owed. Unticking clears the
// snapshot with it — a stale figure attached to a month nobody has invoiced
// would be worse than no figure at all.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $iid    = (int)($_POST['intake_id'] ?? 0);
    $ym     = trim($_POST['ym'] ?? '');
    $on     = !empty($_POST['on']);

    if (in_array($action, ['set_billed', 'set_paid'], true)
        && $iid > 0
        && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {

        // The intake must exist. A posted id for a deleted agent would create
        // an orphan row the page can never show or clear.
        $own = $conn->prepare("SELECT id FROM marketing_intakes WHERE id=? LIMIT 1");
        $ok  = false;
        if ($own) { $own->bind_param('i', $iid); $own->execute(); $ok = (bool)$own->get_result()->fetch_assoc(); $own->close(); }

        if ($ok && $action === 'set_billed') {
            if ($on) {
                // Recompute rather than trusting a figure posted from the page:
                // the browser's copy could be minutes stale, and this number is
                // the one that goes on an invoice.
                $f     = mh_agent_financials($conn, $iid);
                $owed  = (float)($f['months'][$ym]['broker'] ?? 0);
                $s = $conn->prepare(
                    "INSERT INTO marketing_billing_months (intake_id, ym, billed_at, billed_amount, billed_by)
                     VALUES (?, ?, NOW(), ?, ?)
                     ON DUPLICATE KEY UPDATE
                        billed_at = NOW(), billed_amount = VALUES(billed_amount), billed_by = VALUES(billed_by)"
                );
                if ($s) { $s->bind_param('isdi', $iid, $ym, $owed, $me); $s->execute(); $s->close(); }
            } else {
                $s = $conn->prepare(
                    "UPDATE marketing_billing_months
                        SET billed_at = NULL, billed_amount = NULL, billed_by = NULL
                      WHERE intake_id = ? AND ym = ?"
                );
                if ($s) { $s->bind_param('is', $iid, $ym); $s->execute(); $s->close(); }
            }
        }

        if ($ok && $action === 'set_paid') {
            if ($on) {
                // Paid implies billed. Ticking PAID on a month never invoiced
                // would leave a hole in the record, so stamp both — and take
                // the snapshot now, since there is no earlier moment to take
                // it from.
                $f    = mh_agent_financials($conn, $iid);
                $owed = (float)($f['months'][$ym]['broker'] ?? 0);
                $s = $conn->prepare(
                    "INSERT INTO marketing_billing_months
                        (intake_id, ym, billed_at, billed_amount, billed_by, paid_at, paid_by_user)
                     VALUES (?, ?, NOW(), ?, ?, NOW(), ?)
                     ON DUPLICATE KEY UPDATE
                        paid_at      = NOW(),
                        paid_by_user = VALUES(paid_by_user),
                        billed_at    = COALESCE(billed_at, NOW()),
                        billed_amount= COALESCE(billed_amount, VALUES(billed_amount))"
                );
                if ($s) { $s->bind_param('isdii', $iid, $ym, $owed, $me, $me); $s->execute(); $s->close(); }
            } else {
                $s = $conn->prepare(
                    "UPDATE marketing_billing_months SET paid_at = NULL, paid_by_user = NULL
                      WHERE intake_id = ? AND ym = ?"
                );
                if ($s) { $s->bind_param('is', $iid, $ym); $s->execute(); $s->close(); }
            }
        }
    }
    header('Location: billing.php' . (isset($_POST['show_all']) ? '?all=1' : '')); exit;
}

$show_all = isset($_GET['all']);

// ── How old is a month ────────────────────────────────────────────────────────
/**
 * Whole months between $ym and the current month. 0 = this month, 1 = last
 * month, 2+ = old enough to chase. Negative would mean the future, which
 * mh_agent_financials() never returns.
 *
 * Plain integer arithmetic on purpose. The first version of this used
 * DateTime::diff() and got the SIGN backwards — every past month came back
 * negative, so `$age >= 2` was never true and nothing on the page could ever
 * turn red. It looked completely fine until the ages were printed. Months
 * since year zero is a count; treat it as one.
 */
function bm_age(string $ym): int {
    $now  = (int)date('Y') * 12 + (int)date('n');
    $then = (int)substr($ym, 0, 4) * 12 + (int)substr($ym, 5, 2);
    return $now - $then;
}

// ── Load every agent's months ─────────────────────────────────────────────────
$states = [];        // [intake_id][ym] => row
$r = $conn->query("SELECT * FROM marketing_billing_months");
if ($r) while ($row = $r->fetch_assoc()) $states[(int)$row['intake_id']][$row['ym']] = $row;

// Former agents are loaded too, and is_active comes back with them.
//
// The "Due from agent" tab is a to-do list and stays active-only — nobody
// chases an invoice to someone who left. The other two tabs are accounting
// views, and a lifetime "covered by Mont Haus" figure that quietly omitted
// everyone offboarded would be wrong, with nothing on screen to explain the
// gap. So the query no longer filters, and each tab decides for itself.
$intakes = [];
$r = $conn->query("
    SELECT id, agent_name, roster_id, is_active
      FROM marketing_intakes
     ORDER BY agent_name ASC
");
if ($r) $intakes = $r->fetch_all(MYSQLI_ASSOC);

$rows       = [];    // one entry per ACTIVE agent that has anything to show
$sum_overdue = 0.0;  // billed 2+ months ago, still unpaid
$sum_open    = 0.0;  // billed, unpaid, recent
$sum_unbilled= 0.0;  // owed but never invoiced
$n_flagged   = 0;    // months whose live total no longer matches the invoice

// ── Data for the other two tabs ───────────────────────────────────────────────
//
// Built in the SAME pass. mh_agent_financials() already returns broker, mh and
// unassigned per month plus the item list — this page previously read `broker`
// and discarded the rest. Nothing new is queried and no migration is involved.
//
// These are deliberately computed from $fin['months'] BEFORE the tab-1 filters
// run. Tab 1 hides settled months older than last month, which is right for a
// to-do list and wrong for a total: reusing $rows would make "covered by Mont
// Haus to date" silently shrink as months got paid off.
$by_month     = [];   // ym => ['label', 'agents' => [...], 'broker','mh','unassigned']
$agent_totals = [];   // one row per agent, all-time
$breakdown    = [];   // [intake_id][ym] => line items, for the tab-1 modal

foreach ($intakes as $ag) {
    $iid    = (int)$ag['id'];
    $active = (int)($ag['is_active'] ?? 0) === 1;
    $fin    = mh_agent_financials($conn, $iid);

    // ---- all-time, every agent, unfiltered
    $t_broker = 0.0; $t_paid = 0.0; $t_mh = 0.0; $t_un = 0.0;
    foreach ($fin['months'] as $ym => $m) {
        $b = (float)$m['broker']; $mh = (float)$m['mh']; $un = (float)$m['unassigned'];
        if (abs($b) < 0.005 && abs($mh) < 0.005 && abs($un) < 0.005) continue;

        $t_broker += $b; $t_mh += $mh; $t_un += $un;
        // Paid is tracked per agent-month in marketing_billing_months, and it
        // records the invoice to the BROKER. Mont Haus's share is absorbed, not
        // invoiced, so it has no payment state and none is invented for it.
        if (($states[$iid][$ym]['paid_at'] ?? null) !== null) $t_paid += $b;

        if (!isset($by_month[$ym])) {
            $by_month[$ym] = ['label' => $m['label'], 'agents' => [],
                              'broker' => 0.0, 'mh' => 0.0, 'unassigned' => 0.0];
        }
        $by_month[$ym]['agents'][] = [
            'id' => $iid, 'name' => $ag['agent_name'], 'active' => $active,
            'broker' => $b, 'mh' => $mh, 'unassigned' => $un,
        ];
        $by_month[$ym]['broker']     += $b;
        $by_month[$ym]['mh']         += $mh;
        $by_month[$ym]['unassigned'] += $un;
    }
    if (abs($t_broker) >= 0.005 || abs($t_mh) >= 0.005 || abs($t_un) >= 0.005) {
        $agent_totals[] = [
            'id' => $iid, 'name' => $ag['agent_name'], 'active' => $active,
            'broker' => $t_broker, 'paid' => $t_paid,
            'outstanding' => $t_broker - $t_paid,
            'mh' => $t_mh, 'unassigned' => $t_un,
        ];
    }

    // ---- tab 1 stays active-only: it is a to-do list, not a ledger
    if (!$active) continue;

    $months = [];
    foreach ($fin['months'] as $ym => $m) {
        $owed = (float)$m['broker'];
        $st   = $states[$iid][$ym] ?? null;
        $billed = $st && $st['billed_at'] !== null;
        $paid   = $st && $st['paid_at']   !== null;
        $age    = bm_age($ym);

        // Nothing was ever owed and nothing was ever ticked: not a billing
        // month at all. A month that WAS ticked stays visible even at $0, or
        // un-ticking it would make the row vanish mid-correction.
        if ($owed <= 0 && !$st) continue;

        // Settled and old enough to stop caring about. This is the rule that
        // keeps the page a to-do list instead of a ledger.
        if ($paid && $age >= 2 && !$show_all) continue;

        // What the invoice says, versus what the tool now says. Only
        // meaningful once an invoice exists.
        $snap  = ($billed && $st['billed_amount'] !== null) ? (float)$st['billed_amount'] : null;
        $drift = ($snap !== null && abs($snap - $owed) >= 0.005);
        if ($drift) $n_flagged++;

        if     ($paid)            { $state = 'paid';     }
        elseif ($billed && $age >= 2) { $state = 'overdue';  $sum_overdue  += $owed; }
        elseif ($billed)          { $state = 'billed';   $sum_open     += $owed; }
        elseif ($age >= 2)        { $state = 'unbilled'; $sum_unbilled += $owed; }
        else                      { $state = 'current';  $sum_unbilled += $owed; }

        // What the month is actually made of, for the modal. Only months that
        // reach tab 1 are collected — the modal only opens from there, and
        // shipping every agent's entire history as inline JSON to power a
        // dialog nothing can open would be dead weight on every page load.
        //
        // paid_by travels with each line because it is what explains the split:
        // the modal answers "what is this $1,500" and "why is some of it Mont
        // Haus" with the same list.
        $lines = [];
        foreach (($m['items'] ?? []) as $it) {
            $lines[] = [
                'kind'     => (string)($it['kind'] ?? ''),
                'name'     => (string)($it['name'] ?? ''),
                'platform' => (string)($it['platform'] ?? ''),
                'total'    => (float)($it['total'] ?? 0),
                'broker'   => (float)($it['broker'] ?? 0),
                'mh'       => (float)($it['mh'] ?? 0),
                'un'       => (float)($it['unassigned'] ?? 0),
                'linked'   => (string)($it['billed_with'] ?? ''),
            ];
        }
        $breakdown[$iid][$ym] = $lines;

        $months[] = [
            'ym' => $ym, 'label' => $m['label'], 'owed' => $owed, 'age' => $age,
            'billed' => $billed, 'paid' => $paid, 'state' => $state,
            'snap' => $snap, 'drift' => $drift, 'n_items' => count($lines),
            'mh' => (float)$m['mh'], 'unassigned' => (float)$m['unassigned'],
            'billed_at' => $st['billed_at'] ?? null,
            'paid_at'   => $st['paid_at']   ?? null,
        ];
    }

    if (!$months) continue;   // "don't show agent if they have no financials"

    // Oldest unresolved month drives the sort: the page should open on whoever
    // has been waiting longest, not whoever is alphabetically first.
    $oldest = null; $outstanding = 0.0;
    foreach ($months as $m) {
        if ($m['paid']) continue;
        $outstanding += $m['owed'];
        if ($oldest === null || $m['ym'] < $oldest) $oldest = $m['ym'];
    }
    $rows[] = [
        'id' => $iid, 'name' => $ag['agent_name'],
        'months' => $months, 'oldest' => $oldest ?? '9999-99',
        'outstanding' => $outstanding,
    ];
}

usort($rows, function ($a, $b) {
    if ($a['oldest'] !== $b['oldest']) return strcmp($a['oldest'], $b['oldest']);
    if (abs($a['outstanding'] - $b['outstanding']) >= 0.005) {
        return $b['outstanding'] <=> $a['outstanding'];
    }
    return strcmp($a['name'], $b['name']);
});

// Newest month first, matching the agent card above it. krsort on a 'YYYY-MM'
// key is a plain string sort and correct — the zero-padded month is what makes
// it so, which is why the key is never shortened to 'Y-n'.
krsort($by_month);
foreach ($by_month as &$_bm) {
    usort($_bm['agents'], function ($a, $b) {
        $ta = $a['broker'] + $a['mh'] + $a['unassigned'];
        $tb = $b['broker'] + $b['mh'] + $b['unassigned'];
        if (abs($ta - $tb) >= 0.005) return $tb <=> $ta;
        return strcmp($a['name'], $b['name']);
    });
}
unset($_bm);

// Biggest relationship first — total spend, not just what is owed. An agent
// Mont Haus covers entirely would sort last on outstanding alone, which is
// exactly the row this tab exists to surface.
usort($agent_totals, function ($a, $b) {
    $ta = $a['broker'] + $a['mh'] + $a['unassigned'];
    $tb = $b['broker'] + $b['mh'] + $b['unassigned'];
    if (abs($ta - $tb) >= 0.005) return $tb <=> $ta;
    return strcmp($a['name'], $b['name']);
});

// All-time totals for the Agent totals tab.
$all_broker = 0.0; $all_paid = 0.0; $all_mh = 0.0; $all_un = 0.0;
foreach ($agent_totals as $t) {
    $all_broker += $t['broker']; $all_paid += $t['paid'];
    $all_mh     += $t['mh'];     $all_un   += $t['unassigned'];
}

// The monthly tab's tiles are scoped to the newest month, not to all time —
// the all-time figures are the next tab's job, and two tabs showing the same
// four numbers under different headings would teach nobody anything.
$latest_ym    = $by_month ? array_key_first($by_month) : null;
$latest_month = $latest_ym !== null ? $by_month[$latest_ym] : null;

// Which tab opens. Kept in the URL so a reload, or the "Show settled history"
// link, comes back to the tab you were on rather than dropping you at the top.
$tab = (string)($_GET['tab'] ?? 'due');
if (!in_array($tab, ['due', 'monthly', 'agents'], true)) $tab = 'due';

function money2(float $n): string { return '$' . number_format($n, 2); }

$nav_active = 'billing';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Billing — Mont Haus Marketing</title>
  <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml" />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    :root { --bs-primary: #0184BB; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    /* Overriding `top` on .pc-container leaves a phantom black gap — see CLAUDE.md */
    .pc-container { margin-left:0; top:0; margin-top:70px; min-height:calc(100vh - 70px); }
    .mh-header-inner {
      width:100%; padding:0 15px;
      display:flex; align-items:center; justify-content:space-between; height:70px;
    }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link {
      display:inline-flex; align-items:center; gap:5px;
      padding:5px 12px; font-size:.8rem; font-weight:500;
      color:#75BDB6; text-decoration:none; border-radius:6px;
      white-space:nowrap; transition:color .2s, background .2s;
    }
    .mh-nav-link:hover  { color:#fff; background:rgba(117,189,182,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(117,189,182,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }

    body { font-family:'Public Sans',sans-serif; background:#f8f9fa; }
    .wrap { max-width:1180px; margin:0 auto; padding:24px 18px 60px; }

    .bl-head { display:flex; align-items:flex-end; justify-content:space-between;
               gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .bl-head h1 { font-size:22px; font-weight:700; color:#1f2937; margin:0; }
    .bl-head-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .bl-head p  { margin:4px 0 0; font-size:13px; color:#6b7280; }

    /* Totals strip. Each figure is a different job, so each gets its own tile
       rather than one "outstanding" number that hides which pile is which. */
    .bl-tiles { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:22px; }
    .bl-tile  { flex:1; min-width:170px; background:#fff; border:1px solid #e9ecef;
                border-radius:10px; padding:14px 16px; }
    .bl-tile .k { font-size:10px; font-weight:700; text-transform:uppercase;
                  letter-spacing:.5px; color:#9ca3af; }
    .bl-tile .v { font-size:22px; font-weight:700; margin-top:4px; color:#1f2937; }
    .bl-tile.overdue  .v { color:#b91c1c; }
    .bl-tile.unbilled .v { color:#b45309; }

    .bl-agent { background:#fff; border:1px solid #e9ecef; border-radius:10px;
                margin-bottom:12px; overflow:hidden; }
    .bl-agent-head { display:flex; align-items:center; gap:12px;
                     padding:12px 16px; border-bottom:1px solid #f3f4f6; }
    /* A darker cut of the brand blue (#0184BB). The brand shade itself is only
       4.2:1 on white, which fails AA at this size; this is 6.2:1. Colour rather
       than more weight because a card whose month rows are all white needs the
       name to be the one thing that is not near-black. */
    .bl-agent-name { font-size:15px; font-weight:700; color:#01679A;
                     text-decoration:none; letter-spacing:.1px; }
    .bl-agent-name:hover { color:#0184BB; text-decoration:underline; }
    .bl-agent-spacer { flex:1; }
    .bl-agent-due { font-size:13px; color:#6b7280; }
    .bl-agent-due strong { color:#1f2937; font-size:15px; }

    .bl-months { display:flex; flex-direction:column; }
    .bl-row { display:flex; align-items:center; gap:14px; padding:9px 16px;
              border-bottom:1px solid #f8f9fa; flex-wrap:wrap; }
    .bl-row:last-child { border-bottom:none; }
    .bl-month { width:130px; font-size:13px; font-weight:600; color:#374151; flex:none; }
    .bl-amt   { width:132px; text-align:right; font-size:14px; font-weight:600;
                color:#1f2937; flex:none; }
    .bl-chips { flex:1; min-width:160px; display:flex; align-items:center; gap:8px;
                flex-wrap:wrap; }
    .bl-boxes { display:flex; align-items:center; gap:14px; flex:none; }
    .bl-box   { display:inline-flex; align-items:center; gap:5px; font-size:11px;
                font-weight:700; text-transform:uppercase; letter-spacing:.4px;
                color:#6b7280; cursor:pointer; }
    .bl-box input { margin:0; cursor:pointer; }

    /* State colours. Red is "they owe us and it is late"; amber is "nobody has
       invoiced this yet" — a different problem with a different owner, which is
       why they are not the same colour. */
    .bl-row.paid     { background:#f0fdf4; }
    .bl-row.billed   { background:#fffbeb; }
    .bl-row.overdue  { background:#fef2f2; }
    .bl-row.unbilled { background:#fff7ed; }

    .bl-tag { display:inline-flex; align-items:center; gap:4px; font-size:10px;
              font-weight:700; text-transform:uppercase; letter-spacing:.4px;
              padding:2px 8px; border-radius:10px; }
    .bl-tag.paid     { background:#dcfce7; color:#166534; }
    .bl-tag.billed   { background:#fef3c7; color:#92400e; }
    .bl-tag.overdue  { background:#fee2e2; color:#991b1b; }
    .bl-tag.unbilled { background:#ffedd5; color:#9a3412; }
    .bl-tag.current  { background:#f3f4f6; color:#6b7280; }
    .bl-drift { display:inline-flex; align-items:center; gap:4px; font-size:11px;
                font-weight:600; color:#991b1b; }

    /* ── Tabs ──────────────────────────────────────────────────────────────
       Three questions, not three filters of one question: who owes us now,
       what did each month cost and who absorbed it, and where does each
       relationship stand overall. */
    .bl-tabs { display:flex; gap:4px; border-bottom:1px solid #e9ecef; margin-bottom:18px;
               flex-wrap:wrap; }
    .bl-tab  { appearance:none; background:none; border:none; cursor:pointer;
               font-family:inherit; font-size:13px; font-weight:600; color:#6b7280;
               padding:9px 14px; border-bottom:2px solid transparent; margin-bottom:-1px;
               display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
    .bl-tab:hover  { color:#1f2937; }
    .bl-tab.active { color:#01679A; border-bottom-color:#0184BB; }
    .bl-panel { display:none; }
    .bl-panel.active { display:block; }
    .bl-sub { display:none; }
    .bl-sub.active { display:inline; }

    /* Agent / Mont Haus / Undecided — the three-way split, used by both new
       tabs so a figure means the same thing in either. */
    .bl-split { width:100%; border-collapse:collapse; }
    .bl-split th { font-size:10px; font-weight:700; text-transform:uppercase;
                   letter-spacing:.4px; color:#9ca3af; text-align:right;
                   padding:8px 16px; border-bottom:1px solid #f3f4f6; white-space:nowrap; }
    .bl-split th:first-child { text-align:left; }
    .bl-split td { padding:9px 16px; font-size:13px; text-align:right;
                   border-bottom:1px solid #f8f9fa; white-space:nowrap; }
    .bl-split td:first-child { text-align:left; white-space:normal; }
    .bl-split tr:last-child td { border-bottom:none; }
    .bl-split tfoot td { border-top:1px solid #e9ecef; border-bottom:none;
                         font-weight:700; color:#1f2937; background:#fcfcfd; }
    .bl-split .agent  { color:#01679A; font-weight:600; text-decoration:none; }
    .bl-split .agent:hover { text-decoration:underline; }
    .bl-num  { font-weight:600; color:#1f2937; }
    .bl-mh   { font-weight:600; color:#0f766e; }
    .bl-un   { font-weight:600; color:#b45309; }
    /* A zero is noise in a money column — the eye should land on the figures
       that are actually there. Dimmed to a dash rather than removed, so an
       empty cell never reads as "not applicable". */
    .bl-zero { color:#d1d5db; font-weight:400; }
    .bl-former { display:inline-block; font-size:9px; font-weight:700;
                 text-transform:uppercase; letter-spacing:.4px; color:#6b7280;
                 background:#f3f4f6; border-radius:8px; padding:1px 6px;
                 margin-left:6px; vertical-align:middle; }

    .bl-mgroup { background:#fff; border:1px solid #e9ecef; border-radius:10px;
                 margin-bottom:12px; overflow:hidden; }
    .bl-mgroup > h3 { margin:0; padding:12px 16px; font-size:14px; font-weight:700;
                      color:#1f2937; border-bottom:1px solid #f3f4f6;
                      display:flex; align-items:center; gap:10px; }
    .bl-mgroup > h3 .sub { font-size:12px; font-weight:500; color:#9ca3af; }

    /* The month amount doubles as the breakdown trigger. Styled as text, not as
       a button: it sits in a money column and must keep its width and alignment
       or every row below it shifts. */
    /* inline-block, NOT width:100%. A full-width button puts its dashed
       underline across the whole cell, so the rule starts well to the left of
       the number and reads as a stray line rather than as an affordance. The
       cell is already text-align:right, so shrink-to-fit lands it correctly. */
    .bl-amt-btn { appearance:none; background:none; border:none; padding:0;
                  font:inherit; font-size:14px; font-weight:600; color:#1f2937;
                  cursor:pointer; display:inline-block; width:auto;
                  border-bottom:1px dashed #cbd5e1; }
    .bl-amt-btn:hover { color:#0184BB; border-bottom-color:#0184BB; }
    /* The separator matters: "$500.00 2" reads as one mangled figure. */
    .bl-amt-btn .n { font-size:11px; font-weight:500; color:#9ca3af; }
    .bl-amt-btn:hover .n { color:#0184BB; }

    /* ── Breakdown modal ───────────────────────────────────────────────────
       A <dialog>, so Escape and the backdrop come from the browser rather than
       from key handlers this page would have to own. */
    dialog.bl-modal { border:none; border-radius:12px; padding:0; max-width:640px;
                      width:calc(100% - 32px); box-shadow:0 20px 50px rgba(0,0,0,.22); }
    dialog.bl-modal::backdrop { background:rgba(17,24,39,.45); }
    .bl-modal-head { display:flex; align-items:flex-start; gap:12px;
                     padding:16px 18px; border-bottom:1px solid #f3f4f6; }
    .bl-modal-head h2 { margin:0; font-size:16px; font-weight:700; color:#1f2937; }
    .bl-modal-head p  { margin:2px 0 0; font-size:12px; color:#6b7280; }
    .bl-modal-x { margin-left:auto; appearance:none; background:none; border:none;
                  cursor:pointer; color:#9ca3af; font-size:20px; line-height:1; padding:2px 4px; }
    .bl-modal-x:hover { color:#374151; }
    .bl-modal-body { max-height:60vh; overflow-y:auto; }
    .bl-modal-body .bl-split td:first-child { min-width:200px; }
    .bl-modal-body .kind { display:block; font-size:10px; font-weight:700;
                           text-transform:uppercase; letter-spacing:.4px; color:#9ca3af; }
    .bl-modal-empty { padding:28px 18px; text-align:center; color:#9ca3af; font-size:13px; }

    .bl-empty { background:#fff; border:1px solid #e9ecef; border-radius:10px;
                padding:44px 20px; text-align:center; color:#9ca3af; font-size:14px; }
    .bl-foot { margin-top:18px; font-size:12px; color:#9ca3af; }
    /* Sits above the list now. Boxed so it reads as a key rather than as the
       first row of data. */
    .bl-key  { display:flex; gap:8px 20px; flex-wrap:wrap; align-items:center;
               background:#fff; border:1px solid #e9ecef; border-radius:10px;
               padding:10px 16px; margin-bottom:14px;
               font-size:12px; color:#6b7280; }
    .bl-key > span { display:inline-flex; align-items:center; gap:6px; }
    /* ── Mobile ────────────────────────────────────────────────────────────
       Nothing above this block changes, so the desktop page is untouched.

       The month row is four flex children sized for a wide screen. On a phone
       they wrapped in whatever order they happened to fit, which is what made
       the amount, the chip and the two checkboxes land on different lines from
       one row to the next. A 2-column grid fixes the positions instead:

           Month label            Amount   ← always one line, amount right-aligned
           chips (when there are any)
           BILLED        PAID

       and every row in the card lines up with every other one. */
    @media (max-width: 640px) {
      .wrap { padding:16px 12px 48px; }

      .bl-row {
        display:grid; grid-template-columns:1fr auto;
        align-items:center; gap:6px 12px; padding:10px 14px;
      }
      .bl-month { width:auto; grid-column:1; font-size:13.5px; }
      .bl-amt   { width:auto; grid-column:2; text-align:right; font-size:15px; }
      .bl-chips { grid-column:1 / -1; min-width:0; gap:6px; }
      .bl-boxes { grid-column:1 / -1; gap:22px; }

      /* "This cycle" is the do-nothing state — it is what every current month
         says, so it carries no information and was the single widest thing in
         the row. Gone, the chips line disappears with it.

         Only the `current` tag: Paid / Invoiced / Overdue / Not invoiced each
         say something you would act on, and they stay.

         Hiding the whole .bl-chips wrapper (not just the tag) is what removes
         the empty grid row and its gap. Safe because `current` means "not yet
         invoiced", and .bl-drift only ever renders on a month that HAS been
         invoiced — the two can never appear in the same row. */
      .bl-row.current .bl-chips { display:none; }

      /* A tap target, and enough separation that BILLED and PAID can't be hit
         by mistake for one another. */
      .bl-box { font-size:11.5px; padding:3px 0; }
      .bl-box input { width:17px; height:17px; }

      .bl-agent-head { flex-wrap:wrap; gap:4px 10px; padding:11px 14px; }
      .bl-agent-name { font-size:14.5px; }
      .bl-agent-spacer { display:none; }        /* wrap does the spacing now */
      .bl-agent-due  { width:100%; font-size:12px; }

      /* The key is four sentences of explanation. On a phone it pushed the
         actual list below the fold, so it collapses to the four chips — the
         colours are the part that has to be learnable, and the prose is
         still there at any wider width. */
      .bl-key { gap:8px 12px; padding:10px 12px; font-size:0; }
      .bl-key > span { font-size:0; gap:0; }
      .bl-key .bl-tag { font-size:10px; }

      .bl-head h1 { font-size:19px; }
      .bl-tile { min-width:calc(50% - 6px); padding:11px 13px; }
      .bl-tile .v { font-size:19px; }
    }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <div class="bl-head">
      <div>
        <h1>Billing</h1>
        <?php // One subtitle per tab. The old single line described the
              // collections view only — on the two report tabs it said months
              // drop off and that totals were "what each agent owes", when
              // neither is true there. A caption that contradicts the table
              // under it is worse than none. ?>
        <p>
          <span class="bl-sub <?= $tab === 'due' ? 'active' : '' ?>" data-sub="due">
            What each agent owes, month by month. Paid months older than last month drop off.
          </span>
          <span class="bl-sub <?= $tab === 'monthly' ? 'active' : '' ?>" data-sub="monthly">
            What each month cost and who absorbed it. Every month on record, settled or not.
          </span>
          <span class="bl-sub <?= $tab === 'agents' ? 'active' : '' ?>" data-sub="agents">
            Where each agent stands overall. All time, including former agents.
          </span>
        </p>
      </div>
      <div class="bl-head-actions">
        <?php // The working spreadsheet this page is gradually replacing. It
              // lives in SharePoint and still holds detail the app has not
              // absorbed, so it belongs next to the totals rather than in a
              // bookmark. The URL is in inc/config.php.
              //
              // Guarded on defined(): publishing this page without a refreshed
              // config.php should drop the link, not fatal the whole page.
              //
              // target=_blank with rel=noopener — the tab that opens must not
              // get a handle back to this one. ?>
        <?php if (defined('ADVERTISING_SHEET_URL') && ADVERTISING_SHEET_URL !== ''): ?>
          <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars(ADVERTISING_SHEET_URL) ?>"
             target="_blank" rel="noopener noreferrer"
             style="text-transform:none;font-size:12px;">
            <i class="ti ti-table"></i> Digital Advertising Spreadsheet
            <i class="ti ti-external-link" style="font-size:11px;opacity:.6;"></i>
          </a>
        <?php endif; ?>
        <?php // One vendor invoice covering several agents. The discount, rush,
              // shipping and tax are billed once at the bottom, so entering each
              // agent's line-item total alone under-states all of them — and it
              // is these totals that would then be wrong. order_split.php writes
              // the landed cost into marketing_collateral_orders.cost, which is
              // the same field mh_agent_financials() already reads to build this
              // page, so nothing here needed changing. ?>
        <a class="btn btn-outline btn-sm" href="order_split.php"
           style="text-transform:none;font-size:12px;">
          <i class="ti ti-arrows-split-2"></i> Split a combined invoice
        </a>
        <?php // Carries the tab, so toggling the filter from the Monthly or
              // Agent tab does not bounce you back to the first one. ?>
        <a class="btn btn-outline btn-sm"
           href="billing.php?tab=<?= urlencode($tab) ?><?= $show_all ? '' : '&amp;all=1' ?>"
           style="text-transform:none;font-size:12px;">
          <i class="ti ti-<?= $show_all ? 'eye-off' : 'history' ?>"></i>
          <?= $show_all ? 'Hide settled months' : 'Show settled history' ?>
        </a>
      </div>
    </div>

    <?php // Tabs are client-side — all three panels are already rendered, because
          // all three come from the one mh_agent_financials() pass that has
          // already happened. A round trip per tab would re-run that work to
          // show data the browser is holding. ?>
    <div class="bl-tabs" role="tablist">
      <button type="button" class="bl-tab <?= $tab === 'due' ? 'active' : '' ?>" data-tab="due">
        <i class="ti ti-user-dollar"></i> Due from agent
      </button>
      <button type="button" class="bl-tab <?= $tab === 'monthly' ? 'active' : '' ?>" data-tab="monthly">
        <i class="ti ti-calendar-month"></i> Monthly breakdown
      </button>
      <button type="button" class="bl-tab <?= $tab === 'agents' ? 'active' : '' ?>" data-tab="agents">
        <i class="ti ti-users"></i> Agent totals
      </button>
    </div>

<div class="bl-panel <?= $tab === 'due' ? 'active' : '' ?>" id="panel-due">

    <div class="bl-tiles">
      <div class="bl-tile overdue">
        <div class="k">Overdue</div>
        <div class="v"><?= money2($sum_overdue) ?></div>
      </div>
      <div class="bl-tile">
        <div class="k">Billed, awaiting payment</div>
        <div class="v"><?= money2($sum_open) ?></div>
      </div>
      <div class="bl-tile unbilled">
        <div class="k">Not yet invoiced</div>
        <div class="v"><?= money2($sum_unbilled) ?></div>
      </div>
      <div class="bl-tile">
        <div class="k">Agents with a balance</div>
        <div class="v"><?= count($rows) ?></div>
      </div>
    </div>

    <div class="bl-key">
      <span><span class="bl-tag paid">Paid</span> settled — drops off after a month</span>
      <span><span class="bl-tag billed">Invoiced</span> sent, awaiting payment</span>
      <span><span class="bl-tag overdue">Overdue</span> invoiced 2+ months ago, still unpaid</span>
      <span><span class="bl-tag unbilled">Not invoiced</span> 2+ months old and never sent</span>
    </div>

    <?php if ($n_flagged): ?>
      <div class="alert-success" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;
                  padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;">
        <i class="ti ti-alert-triangle"></i>
        <?= $n_flagged ?> month<?= $n_flagged === 1 ? ' has' : 's have' ?> changed since being invoiced —
        marked below. The invoice figure and the current one are both shown.
      </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="bl-empty">
        <i class="ti ti-check" style="font-size:28px;display:block;margin-bottom:8px;color:#d1d5db;"></i>
        Nothing outstanding. Every agent is either settled or has no charges.
      </div>
    <?php else: ?>
      <?php foreach ($rows as $ag): ?>
        <div class="bl-agent">
          <div class="bl-agent-head">
            <a class="bl-agent-name" href="agent.php?id=<?= $ag['id'] ?>&amp;tab=financials">
              <?= htmlspecialchars($ag['name']) ?>
            </a>
            <div class="bl-agent-spacer"></div>
            <div class="bl-agent-due">
              Outstanding <strong><?= money2($ag['outstanding']) ?></strong>
            </div>
          </div>
          <div class="bl-months">
            <?php foreach ($ag['months'] as $m): ?>
              <div class="bl-row <?= $m['state'] ?>">
                <div class="bl-month"><?= htmlspecialchars($m['label']) ?></div>
                <?php // The amount IS the breakdown trigger, on every row rather
                      // than only on the ones that used to say "This cycle" —
                      // "what is this made of" is the same question whatever
                      // state the month is in. ?>
                <div class="bl-amt">
                  <button type="button" class="bl-amt-btn"
                          data-bd-agent="<?= $ag['id'] ?>"
                          data-bd-ym="<?= htmlspecialchars($m['ym']) ?>"
                          data-bd-name="<?= htmlspecialchars($ag['name']) ?>"
                          data-bd-label="<?= htmlspecialchars($m['label']) ?>"
                          title="See what makes up this month">
                    <?= money2($m['owed']) ?><span class="n"> &middot; <?= (int)$m['n_items'] ?></span>
                  </button>
                </div>
                <div class="bl-chips">
                  <?php
                    // 'current' has no chip. It only ever meant "not old, not
                    // billed" — which the absence of the other four already
                    // says — and it was occupying the one bit of the row that
                    // could carry something useful.
                    $tag = [
                      'paid'     => ['Paid',        'ti-check'],
                      'billed'   => ['Invoiced',    'ti-mail'],
                      'overdue'  => ['Overdue',     'ti-alert-circle'],
                      'unbilled' => ['Not invoiced','ti-clock'],
                    ][$m['state']] ?? null;
                  ?>
                  <?php if ($tag): ?>
                    <span class="bl-tag <?= $m['state'] ?>">
                      <i class="ti <?= $tag[1] ?>"></i> <?= $tag[0] ?>
                    </span>
                  <?php endif; ?>
                  <?php // Mont Haus's share of the same month, so the split is
                        // visible without leaving the to-do list. ?>
                  <?php if (abs($m['mh']) >= 0.005): ?>
                    <span class="bl-tag" style="background:#ccfbf1;color:#0f766e;"
                          title="Absorbed by Mont Haus — not invoiced to the agent">
                      <i class="ti ti-building"></i> MH <?= money2($m['mh']) ?>
                    </span>
                  <?php endif; ?>
                  <?php if (abs($m['unassigned']) >= 0.005): ?>
                    <span class="bl-tag" style="background:#ffedd5;color:#9a3412;"
                          title="Who Pays is not set on one or more lines this month">
                      <i class="ti ti-help-circle"></i> Undecided <?= money2($m['unassigned']) ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($m['drift']): ?>
                    <span class="bl-drift" title="The figures changed after this month was invoiced">
                      <i class="ti ti-alert-triangle"></i>
                      invoiced <?= money2((float)$m['snap']) ?>, now <?= money2($m['owed']) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div class="bl-boxes">
                  <?php // Each box is its own form. A single form with two
                        // checkboxes would need JS to know which one moved, and
                        // an unchecked box posts nothing at all. ?>
                  <form method="POST" style="margin:0;">
                    <input type="hidden" name="_action"   value="set_billed">
                    <input type="hidden" name="intake_id" value="<?= $ag['id'] ?>">
                    <input type="hidden" name="ym"        value="<?= htmlspecialchars($m['ym']) ?>">
                    <input type="hidden" name="on"        value="<?= $m['billed'] ? '' : '1' ?>">
                    <?php if ($show_all): ?><input type="hidden" name="show_all" value="1"><?php endif; ?>
                    <label class="bl-box"
                           title="<?= $m['billed_at'] ? 'Invoiced ' . htmlspecialchars(date('M j, Y', strtotime($m['billed_at']))) : 'Mark as invoiced' ?>">
                      <input type="checkbox" <?= $m['billed'] ? 'checked' : '' ?>
                             onchange="this.form.submit()"> Billed
                    </label>
                  </form>
                  <form method="POST" style="margin:0;">
                    <input type="hidden" name="_action"   value="set_paid">
                    <input type="hidden" name="intake_id" value="<?= $ag['id'] ?>">
                    <input type="hidden" name="ym"        value="<?= htmlspecialchars($m['ym']) ?>">
                    <input type="hidden" name="on"        value="<?= $m['paid'] ? '' : '1' ?>">
                    <?php if ($show_all): ?><input type="hidden" name="show_all" value="1"><?php endif; ?>
                    <label class="bl-box"
                           title="<?= $m['paid_at'] ? 'Paid ' . htmlspecialchars(date('M j, Y', strtotime($m['paid_at']))) : 'Mark as paid' ?>">
                      <input type="checkbox" <?= $m['paid'] ? 'checked' : '' ?>
                             onchange="this.form.submit()"> Paid
                    </label>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="bl-foot">
      Agents are ordered by their oldest unresolved month. Ticking <strong>Paid</strong> on a
      month that was never invoiced marks it invoiced too.
      Click any amount to see what the month is made of.
    </div>

</div><!-- /panel-due -->

<?php
/**
 * One money cell.
 *
 * A zero prints as a dash in a lighter grey. In a column of figures a row of
 * $0.00s reads as loudly as the real numbers and buries them; a dash says
 * "nothing here" without competing. Deliberately not blank — an empty cell
 * looks like a rendering fault or a value that could not be computed.
 */
function bl_cell(float $n, string $cls = 'bl-num'): string {
    if (abs($n) < 0.005) return '<span class="bl-zero">—</span>';
    return '<span class="' . $cls . '">' . money2($n) . '</span>';
}

/** Agent name, with a tag when they are no longer on the roster. */
function bl_agent_link(array $a): string {
    $h = '<a class="agent" href="agent.php?id=' . (int)$a['id']
       . '&amp;tab=financials">' . htmlspecialchars($a['name']) . '</a>';
    if (empty($a['active'])) $h .= '<span class="bl-former">Former</span>';
    return $h;
}
?>

<div class="bl-panel <?= $tab === 'monthly' ? 'active' : '' ?>" id="panel-monthly">

    <?php // Scoped to the newest month, not to all time. The all-time figures
          // are the next tab's job — two tabs showing the same four numbers
          // under different headings would teach nobody anything. ?>
    <div class="bl-tiles">
      <div class="bl-tile">
        <div class="k">Due from agents<?= $latest_month ? ' — ' . htmlspecialchars($latest_month['label']) : '' ?></div>
        <div class="v"><?= money2($latest_month['broker'] ?? 0) ?></div>
      </div>
      <div class="bl-tile">
        <div class="k">Covered by Mont Haus</div>
        <div class="v" style="color:#0f766e;"><?= money2($latest_month['mh'] ?? 0) ?></div>
      </div>
      <div class="bl-tile unbilled">
        <div class="k">Undecided</div>
        <div class="v"><?= money2($latest_month['unassigned'] ?? 0) ?></div>
      </div>
      <div class="bl-tile">
        <div class="k">Months on record</div>
        <div class="v"><?= count($by_month) ?></div>
      </div>
    </div>

    <?php if (!$by_month): ?>
      <div class="bl-empty">No spend recorded in any month yet.</div>
    <?php else: ?>
      <?php foreach ($by_month as $ym => $bm): ?>
        <div class="bl-mgroup">
          <h3>
            <?= htmlspecialchars($bm['label']) ?>
            <span class="sub"><?= count($bm['agents']) ?>
              agent<?= count($bm['agents']) === 1 ? '' : 's' ?></span>
          </h3>
          <table class="bl-split">
            <thead>
              <tr>
                <th>Agent</th>
                <th>Due from agent</th>
                <th>Covered by Mont Haus</th>
                <th>Undecided</th>
                <th>Month total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($bm['agents'] as $a): ?>
                <tr>
                  <td><?= bl_agent_link($a) ?></td>
                  <td><?= bl_cell($a['broker']) ?></td>
                  <td><?= bl_cell($a['mh'], 'bl-mh') ?></td>
                  <td><?= bl_cell($a['unassigned'], 'bl-un') ?></td>
                  <td><?= bl_cell($a['broker'] + $a['mh'] + $a['unassigned']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td><?= htmlspecialchars($bm['label']) ?> total</td>
                <td><?= bl_cell($bm['broker']) ?></td>
                <td><?= bl_cell($bm['mh'], 'bl-mh') ?></td>
                <td><?= bl_cell($bm['unassigned'], 'bl-un') ?></td>
                <td><?= bl_cell($bm['broker'] + $bm['mh'] + $bm['unassigned']) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>


</div><!-- /panel-monthly -->

<div class="bl-panel <?= $tab === 'agents' ? 'active' : '' ?>" id="panel-agents">

    <div class="bl-tiles">
      <div class="bl-tile">
        <div class="k">Billed to agents, all time</div>
        <div class="v"><?= money2($all_broker) ?></div>
      </div>
      <div class="bl-tile">
        <div class="k">Still outstanding</div>
        <div class="v" style="color:#b91c1c;"><?= money2($all_broker - $all_paid) ?></div>
      </div>
      <div class="bl-tile">
        <div class="k">Covered by Mont Haus, all time</div>
        <div class="v" style="color:#0f766e;"><?= money2($all_mh) ?></div>
      </div>
      <div class="bl-tile unbilled">
        <div class="k">Undecided</div>
        <div class="v"><?= money2($all_un) ?></div>
      </div>
    </div>

    <?php if (!$agent_totals): ?>
      <div class="bl-empty">No agent has any recorded spend yet.</div>
    <?php else: ?>
      <div class="bl-mgroup">
        <table class="bl-split">
          <thead>
            <tr>
              <th>Agent</th>
              <th>Outstanding</th>
              <th>Paid</th>
              <th>Billed to agent</th>
              <th>Covered by Mont Haus</th>
              <th>Undecided</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agent_totals as $a): ?>
              <tr>
                <td><?= bl_agent_link($a) ?></td>
                <td><?= bl_cell($a['outstanding']) ?></td>
                <td><?= bl_cell($a['paid']) ?></td>
                <td><?= bl_cell($a['broker']) ?></td>
                <td><?= bl_cell($a['mh'], 'bl-mh') ?></td>
                <td><?= bl_cell($a['unassigned'], 'bl-un') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td>All agents</td>
              <td><?= bl_cell($all_broker - $all_paid) ?></td>
              <td><?= bl_cell($all_paid) ?></td>
              <td><?= bl_cell($all_broker) ?></td>
              <td><?= bl_cell($all_mh, 'bl-mh') ?></td>
              <td><?= bl_cell($all_un, 'bl-un') ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>

    <div class="bl-foot">
      <strong>Outstanding</strong> and <strong>Paid</strong> add up to
      <strong>Billed to agent</strong>.
    </div>

</div><!-- /panel-agents -->

<?php // One dialog, filled from the data already on the page. An endpoint per
      // month would re-run mh_agent_financials() to return figures the browser
      // is already holding. ?>
<dialog class="bl-modal" id="blModal">
  <div class="bl-modal-head">
    <div>
      <h2 id="blModalTitle">Breakdown</h2>
      <p id="blModalSub"></p>
    </div>
    <button type="button" class="bl-modal-x" id="blModalX" aria-label="Close">&times;</button>
  </div>
  <div class="bl-modal-body" id="blModalBody"></div>
</dialog>

<script id="blBreakdown" type="application/json"><?= json_encode(
    $breakdown, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
) ?></script>

  </div>
</div>

<script>
(function () {
  /* ── Tabs ──────────────────────────────────────────────────────────────── */
  var tabs = document.querySelectorAll('.bl-tab');
  Array.prototype.forEach.call(tabs, function (btn) {
    btn.addEventListener('click', function () {
      var name = btn.getAttribute('data-tab');
      Array.prototype.forEach.call(tabs, function (b) {
        b.classList.toggle('active', b === btn);
      });
      Array.prototype.forEach.call(document.querySelectorAll('.bl-panel'), function (p) {
        p.classList.toggle('active', p.id === 'panel-' + name);
      });
      /* The subtitle describes the scope of the tab under it, so it moves with
         the tab. Left behind, it would tell you months drop off while looking
         at a table where none do. */
      Array.prototype.forEach.call(document.querySelectorAll('.bl-sub'), function (s) {
        s.classList.toggle('active', s.getAttribute('data-sub') === name);
      });
      /* Keep the tab in the URL so a reload — or the Billed/Paid checkboxes,
         which POST and redirect — comes back to the tab you were on. */
      try {
        var u = new URL(window.location.href);
        u.searchParams.set('tab', name);
        history.replaceState(null, '', u);
      } catch (e) { /* older browser: the tab just resets on reload */ }
    });
  });

  /* ── Breakdown modal ───────────────────────────────────────────────────── */
  var data = {};
  try { data = JSON.parse(document.getElementById('blBreakdown').textContent || '{}'); }
  catch (e) { data = {}; }

  var dlg  = document.getElementById('blModal');
  var body = document.getElementById('blModalBody');

  var money = function (n) {
    return '$' + Number(n).toLocaleString('en-US',
      { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
  /* Everything from `data` is server-side text going into innerHTML, so it is
     escaped here. json_encode with JSON_HEX_TAG already stops the script tag
     being closed early; this is the second half of the same job. */
  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var cell = function (n, cls) {
    return Math.abs(Number(n)) < 0.005
      ? '<span class="bl-zero">—</span>'
      : '<span class="' + cls + '">' + money(n) + '</span>';
  };

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.bl-amt-btn') : null;
    if (!btn) return;

    var lines = (data[btn.getAttribute('data-bd-agent')] || {})[btn.getAttribute('data-bd-ym')] || [];
    document.getElementById('blModalTitle').textContent = btn.getAttribute('data-bd-name');
    document.getElementById('blModalSub').textContent =
      btn.getAttribute('data-bd-label') + ' · ' + lines.length +
      (lines.length === 1 ? ' item' : ' items');

    if (!lines.length) {
      body.innerHTML = '<div class="bl-modal-empty">' +
        'Nothing itemised for this month. The figure comes from a month that was ' +
        'ticked by hand rather than from any recorded charge.</div>';
    } else {
      var tb = 0, tm = 0, tu = 0;
      var rows = lines.map(function (l) {
        tb += Number(l.broker); tm += Number(l.mh); tu += Number(l.un);
        return '<tr><td><span class="kind">' + esc(l.kind) +
               (l.platform ? ' · ' + esc(l.platform) : '') + '</span>' + esc(l.name) +
               (l.linked ? '<span class="bl-former">on ' + esc(l.linked) + '</span>' : '') +
               '</td><td>' + cell(l.broker, 'bl-num') +
               '</td><td>' + cell(l.mh, 'bl-mh') +
               '</td><td>' + cell(l.un, 'bl-un') + '</td></tr>';
      }).join('');
      body.innerHTML =
        '<table class="bl-split"><thead><tr><th>Item</th><th>Agent</th>' +
        '<th>Mont Haus</th><th>Undecided</th></tr></thead><tbody>' + rows +
        '</tbody><tfoot><tr><td>Total</td><td>' + cell(tb, 'bl-num') +
        '</td><td>' + cell(tm, 'bl-mh') + '</td><td>' + cell(tu, 'bl-un') +
        '</td></tr></tfoot></table>';
    }

    /* showModal gives Escape, focus trapping and the backdrop for free. The
       fallback matters: without <dialog> support the element is display:none
       and the click would appear to do nothing at all. */
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', 'open');
  });

  document.getElementById('blModalX').addEventListener('click', function () {
    if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open');
  });
  /* Click outside to dismiss. The dialog's own rect is the test — a click on
     the backdrop still targets the dialog element itself, so checking the
     target alone would close it on every click inside too. */
  dlg.addEventListener('click', function (e) {
    var r = dlg.getBoundingClientRect();
    if (e.clientX < r.left || e.clientX > r.right ||
        e.clientY < r.top  || e.clientY > r.bottom) dlg.close();
  });
})();
</script>

</body>
</html>
