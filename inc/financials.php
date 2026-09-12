<?php
/**
 * inc/financials.php — what an agent owes, and what Mont Haus covered.
 *
 * The ONE implementation of the money. `agent.php` renders it as the
 * Financials tab for a single agent; `billing.php` runs it across everybody to
 * build the collections view. Two implementations of the same arithmetic would
 * eventually disagree, and the first anyone would know of it is a broker
 * holding an invoice that does not match the screen.
 *
 * Include-only. Nothing here echoes, queries the session, or depends on page
 * state — `mh_agent_financials()` takes a connection and an intake id and
 * returns arrays.
 *
 * If you change any of the arithmetic, run:  php tests/test_campaign_billing.php
 * The suite extracts mk_campaign_months(), mk_weekday_days() and
 * mk_camp_resolve() from THIS file by regex, so each must stay a top-level
 * function whose closing brace is in column 0.
 */

function mk_fin_add(array &$months, string $date, array $item): void {
    $key = substr($date, 0, 7);                       // YYYY-MM
    if (!isset($months[$key])) {
        $months[$key] = [
            'key'        => $key,
            'label'      => date('F Y', strtotime($key . '-01')),
            'items'      => [],
            'broker'     => 0.0,
            'mh'         => 0.0,
            'unassigned' => 0.0,
        ];
    }
    $months[$key]['items'][]      = $item;
    $months[$key]['broker']      += $item['broker'];
    $months[$key]['mh']          += $item['mh'];
    $months[$key]['unassigned']  += $item['unassigned'];
}

/**
 * Display name for a collateral type. Explicit map rather than ucwords() —
 * that would render 'oh_signs' as "Oh Signs". Matches the section headings
 * used on the Collateral tab.
 */
function mk_coll_type_label(string $type): string {
    $map = [
        'business_cards' => 'Business Cards',
        'yard_signs'     => 'Yard Signs',
        'oh_signs'       => 'Open House Signs',
        'postcards'      => 'Postcards',
        'brochures'      => 'Brochures',
        'other'          => 'Other',
    ];
    return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
}

/** Split one line's total into broker / MH / unassigned per its paid_by. */
function mk_fin_split(?string $paid_by, float $total, $broker_amt, $mh_amt): array {
    switch ($paid_by) {
        case 'broker':    return ['broker' => $total, 'mh' => 0.0,    'unassigned' => 0.0];
        case 'mont_haus': return ['broker' => 0.0,    'mh' => $total, 'unassigned' => 0.0];
        case 'split':     return [
            'broker'     => (float)($broker_amt ?? 0),
            'mh'         => (float)($mh_amt ?? 0),
            'unassigned' => 0.0,
        ];
        default:          return ['broker' => 0.0, 'mh' => 0.0, 'unassigned' => $total];
    }
}

/**
 * Every YYYY-MM a recurring campaign bills in.
 *
 * From the month of start_date through the month of end_date. An open-ended
 * campaign (no end_date) runs through the CURRENT month and keeps growing — it
 * is running until somebody stops it.
 *
 * $started_only clamps the list to months that have actually begun, and
 * Financials always passes true. A month that has not started has not been
 * delivered and cannot be owed: showing September in August makes the month
 * totals wrong and the running totals worse. The grid passes false, so a
 * committed run stays visible there and a future month can be entered ahead of
 * time — it simply will not appear in Financials until it arrives.
 *
 * Capped at 60 months so a mistyped year produces a wrong number rather than
 * thousands of rows.
 */
function mk_campaign_months(?string $start, ?string $end, bool $started_only = false): array {
    if (!$start) return [];
    $cur  = substr($start, 0, 7);
    $last = $end ? substr($end, 0, 7) : date('Y-m');
    if ($started_only && $last > date('Y-m')) $last = date('Y-m');
    if ($last < $cur) {
        // End before start, or a run that has not begun yet. The first case is
        // a typo and should still show its one month; the second must show
        // nothing at all, or a placement booked for next year lands in this
        // month's totals.
        if ($started_only && $cur > date('Y-m')) return [];
        $last = $cur;
    }

    $out = [];
    while ($cur <= $last && count($out) < 60) {
        $out[] = $cur;
        $cur = date('Y-m', strtotime($cur . '-01 +1 month'));
    }
    return $out;
}

/**
 * How many times a given weekday falls inside one month AND inside the run.
 *
 * This is the whole of the fixed-placement problem: a Wednesday marquee and a
 * Saturday marquee at the same rate in the same August are not the same money,
 * because August 2026 has four Wednesdays and five Saturdays. Counting the
 * calendar is the only way to get that right, and it is why the quantity is
 * derived rather than typed.
 *
 * $dow is 0=Sunday .. 6=Saturday, matching date('w').
 *
 * Clipped to start/end so a run beginning mid-month counts only the days it
 * actually covers — a placement that starts on the 20th did not run on the
 * 6th. (Note this is the opposite of `monthly`, which never prorates: a month
 * of impressions is delivered in full whenever it starts.)
 */
function mk_weekday_days(string $ym, int $dow, ?string $start, ?string $end): int {
    $first = $ym . '-01';
    $ndays = (int)date('t', strtotime($first));
    $lo    = $start ?: $first;
    $hi    = $end   ?: sprintf('%s-%02d', $ym, $ndays);

    $n = 0;
    for ($d = 1; $d <= $ndays; $d++) {
        $date = sprintf('%s-%02d', $ym, $d);
        if ($date < $lo || $date > $hi) continue;
        if ((int)date('w', strtotime($date)) === $dow) $n++;
    }
    return $n;
}

/**
 * What one placement charges in one month.
 *
 * $ov is that campaign's month rows, keyed [ym => row]. Only months somebody
 * has actually entered have a row.
 *
 * Returns null when the month produces no line at all — a per-day month whose
 * run does not touch that weekday. Otherwise an array whose 'set' flag says
 * whether the figure was entered or is still waiting for one.
 *
 * ── The rule that matters ────────────────────────────────────────────────
 *
 * A `monthly` placement has NO amount that repeats itself forward. Every month
 * is entered, and a month nobody has entered bills NOTHING and is flagged on
 * the Financials tab. `budget` there is a convenience default that pre-fills
 * the grid, and **must never be fallen back to here**.
 *
 * That is for buys that move around. A `monthly_flat` placement is the other
 * kind: a standing buy, where repeating the amount IS the instruction, given
 * once when the placement was set up. Falling back to `budget` there is
 * honouring a decision, not inventing one — which is the whole distinction
 * between the two modes, and the reason they are two modes rather than one
 * mode with a heuristic.
 *
 * `per_unit` is different again: the quantity IS derivable, so the calendar
 * supplies it and a row only overrides a month the outlet ran short.
 *
 * Nothing here decides whether a month is shown at all — mk_campaign_months()
 * does that, and it is what keeps a month that has not started out of
 * Financials.
 */
function mk_camp_resolve(array $c, string $ym, array $ov): ?array {
    $row  = $ov[$ym] ?? null;
    $mode = $c['billing_mode'] ?? 'one_time';

    // '' counts as absent — an empty form field posts '' and must not be read
    // as "override with nothing".
    $has = function (string $col) use ($row): bool {
        return $row !== null && isset($row[$col]) && $row[$col] !== null && $row[$col] !== '';
    };

    $units = null; $rate = null; $unit_src = null; $set = true;

    if ($mode === 'per_unit') {
        $rate = (float)($has('unit_rate') ? $row['unit_rate'] : ($c['unit_rate'] ?? 0));

        if ($has('units')) {
            $units    = (float)$row['units'];
            $unit_src = 'override';
        } elseif (($c['unit_weekday'] ?? null) !== null && $c['unit_weekday'] !== '') {
            $units    = (float)mk_weekday_days($ym, (int)$c['unit_weekday'],
                                               $c['start_date'] ?: null, $c['end_date'] ?: null);
            $unit_src = 'calendar';
            // The run does not touch this weekday in this month at all. Not an
            // error and not a $0 line — there was simply no placement.
            if ($units <= 0) return null;
        } else {
            // No weekday to count and no quantity typed. Charge nothing, say so.
            $units    = 0.0;
            $unit_src = 'unset';
            $set      = false;
        }
        $amount = round($rate * $units, 2);

    } elseif ($mode === 'monthly') {
        if ($has('amount')) {
            $amount = (float)$row['amount'];    // 0.00 is a decision, not a blank
        } else {
            $amount = 0.0;
            $set    = false;
        }

    } elseif ($mode === 'monthly_flat') {
        // A standing buy. `budget` IS the monthly amount, and that is the whole
        // difference from `monthly` — the decision was made once, when the
        // placement was set up, so falling back to it here is honouring an
        // instruction rather than inventing one.
        if ($has('amount')) {
            $amount = (float)$row['amount'];    // this month overridden
        } elseif (($c['budget'] ?? null) !== null && $c['budget'] !== '') {
            $amount = (float)$c['budget'];
        } else {
            // Standing buy with no standing amount. Nothing to repeat.
            $amount = 0.0;
            $set    = false;
        }

    } else {                                     // one_time
        $amount = (float)($c['budget'] ?? 0);
    }

    $paid_by = $has('paid_by') ? $row['paid_by'] : ($c['paid_by'] ?? null);
    // The split amounts belong to whichever row supplied paid_by — attaching
    // the placement's split to an overridden payer would invent a number.
    $pb = $has('paid_by') ? $row['paid_broker_amount'] : ($c['paid_broker_amount'] ?? null);
    $pm = $has('paid_by') ? $row['paid_mh_amount']     : ($c['paid_mh_amount']     ?? null);

    return [
        'amount'   => $amount,
        'paid_by'  => $paid_by ?: null,
        'pb'       => $pb,
        'pm'       => $pm,
        'units'    => $units,
        'rate'     => $rate,
        'unit_src' => $unit_src,
        'set'      => $set,                       // entered, vs still awaiting a figure
        'zeroed'   => ($set && $amount == 0.0),   // entered AND deliberately nothing
        'edited'   => $row !== null,
        'note'     => $row['note'] ?? null,
    ];
}


/**
 * Every billable line for one agent, bucketed by the month the spend was
 * incurred: Order Date for collateral, Run Start for a placement. Both fall
 * back to created_at so nothing can drop out of the report entirely.
 *
 * Who pays drives the split:
 *   broker     → the whole amount is billed to the broker
 *   mont_haus  → Mont Haus covers the whole amount
 *   split      → the two stored amounts are used as entered
 *   not set    → counted as unassigned; shown, but in neither total, so an
 *                undecided line is visible rather than silently skewing things
 *
 * Returns:
 *   months          'YYYY-MM' => ['label','items'=>[],'broker','mh','unassigned'],
 *                   newest month first, newest line first within a month
 *   grand           ['broker','mh','unassigned'] across every month shown
 *   coll_rows       the collateral orders, keyed by id — the Collateral tab
 *                   reuses these rather than querying twice
 *   camp_overrides  [campaign_id][ym] => row, likewise for the month grid
 */
function mh_agent_financials(mysqli $conn, int $id): array {
    // ── Financial summary ─────────────────────────────────────────────────────────
    // Every billable line for this agent, bucketed by the month the spend was
    // incurred: Order Date for collateral, Run Start for a placement. Both fall
    // back to created_at so nothing can drop out of the report entirely.
    //
    // Who pays drives the split:
    //   broker     → the whole amount is billed to the broker
    //   mont_haus  → Mont Haus covers the whole amount
    //   split      → the two stored amounts are used as entered
    //   not set    → counted as unassigned; shown, but in neither total, so an
    //                undecided line is visible rather than silently skewing things
    $fin_months = [];   // 'YYYY-MM' => ['label','items'=>[], 'broker','mh','unassigned']

    // Collateral orders.
    // An order can be marked as billed with another (one invoice covering several
    // items). The order holding the cost reports it; the linked ones contribute
    // nothing, so a combined purchase is counted once.
    $coll_rows = [];
    $r = $conn->query("
        SELECT id, type, label, vendor, cost, ordered_at, created_at, billed_with_order_id,
               receipt_file, receipt_orig_name,
               paid_by, paid_broker_amount, paid_mh_amount
          FROM marketing_collateral_orders
         WHERE intake_id = {$id}
    ");
    if ($r) while ($row = $r->fetch_assoc()) $coll_rows[(int)$row['id']] = $row;

    foreach ($coll_rows as $row) {
            $linked_to = (int)($row['billed_with_order_id'] ?? 0);
            // A link pointing at a deleted order would silently zero the cost —
            // treat it as unlinked and flag it in the row instead.
            $orphaned  = $linked_to && !isset($coll_rows[$linked_to]);
            $is_linked = $linked_to && !$orphaned;

            $total = (float)($row['cost'] ?? 0);
            if ($total <= 0 && ($row['paid_by'] ?? '') === '' && !$is_linked) continue;
            $when  = $row['ordered_at'] ?: ($row['created_at'] ?: date('Y-m-d'));

            if ($is_linked) {
                // Shown for context, contributes nothing to any total
                $parent = $coll_rows[$linked_to];
                $plabel = mk_coll_type_label((string)$parent['type'])
                        . (trim((string)$parent['label']) !== '' ? ': ' . trim((string)$parent['label']) : '');
                $type_label  = mk_coll_type_label((string)$row['type']);
                $order_label = trim((string)$row['label']);
                mk_fin_add($fin_months, $when, [
                    'kind'        => 'Collateral',
                    'name'        => $order_label !== '' ? "{$type_label}: {$order_label}" : $type_label,
                    'platform'    => trim((string)($row['vendor'] ?? '')),
                    'total'       => 0.0,
                    'broker'      => 0.0,
                    'mh'          => 0.0,
                    'unassigned'  => 0.0,
                    'paid_by'     => $row['paid_by'] ?? null,
                    'date'        => $when,
                    'ref_id'      => (int)$row['id'],
                    'billed_with' => $plabel,
                    'receipt'     => !empty($row['receipt_file']) ? (int)$row['id'] : 0,
                ]);
                continue;
            }

            $parts = mk_fin_split($row['paid_by'] ?? null, $total,
                                  $row['paid_broker_amount'], $row['paid_mh_amount']);
            // "Business Cards: Initial Order" — the label alone is rarely distinct
            $type_label = mk_coll_type_label((string)$row['type']);
            $order_label = trim((string)$row['label']);
            mk_fin_add($fin_months, $when, array_merge($parts, [
                'kind'     => 'Collateral',
                'name'     => $order_label !== '' ? "{$type_label}: {$order_label}" : $type_label,
                'platform' => trim((string)($row['vendor'] ?? '')),
                'total'    => $total,
                'paid_by'  => $row['paid_by'] ?? null,
                'date'     => $when,
                'ref_id'   => (int)$row['id'],
                // Link pointed at an order that no longer exists — say so rather
                // than quietly counting the cost as if it were never linked
                'orphaned' => $orphaned,
                'receipt'  => !empty($row['receipt_file']) ? (int)$row['id'] : 0,
            ]));
    }

    // Advertising placements
    //
    // one_time : one line, in the month of start_date, for the whole budget.
    // monthly  : one line in every month of the run, each carrying the amount
    //            entered for that month. Never prorated, and never inferred — a
    //            month nobody has entered shows as not set and bills nothing.
    // per_unit : one line per month, unit_rate × the matching days in that month.
    //
    // marketing_campaign_months carries the per-month figures. For `monthly` it is
    // where the money lives — a month with no row bills nothing. For `per_unit` it
    // only overrides the calendar count or that month's rate.
    $camp_overrides = [];                        // [campaign_id][ym] => row
    $ov = $conn->query("
        SELECT m.*
          FROM marketing_campaign_months m
          JOIN marketing_campaigns c ON c.id = m.campaign_id
         WHERE c.intake_id = {$id}
    ");
    if ($ov) {
        while ($o = $ov->fetch_assoc()) {
            $camp_overrides[(int)$o['campaign_id']][$o['ym']] = $o;
        }
    }

    $r = $conn->query("
        SELECT id, platform, name, budget, billing_mode, unit_rate, unit_weekday, unit_label,
               start_date, end_date, created_at,
               paid_by, paid_broker_amount, paid_mh_amount
          FROM marketing_campaigns
         WHERE intake_id = {$id}
    ");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $cid       = (int)$row['id'];
            $budget    = (float)($row['budget'] ?? 0);
            $mode      = $row['billing_mode'] ?? 'one_time';
            $recurring = in_array($mode, ['monthly','monthly_flat','per_unit'], true);
            $start     = $row['start_date'] ?: ($row['created_at'] ? substr($row['created_at'], 0, 10) : date('Y-m-d'));

            // Nothing to bill and nobody assigned: skip entirely, same as before.
            // Each mode carries its money somewhere different, so ask the right
            // column — judging per_unit on `budget` would hide every per-day
            // placement, and judging monthly on it would hide a placement whose
            // months are all entered but whose default was never filled in.
            $has_money = match ($mode) {
                'per_unit' => (float)($row['unit_rate'] ?? 0) > 0,
                'monthly'      => !empty($camp_overrides[$cid]) || $budget > 0,
                'monthly_flat' => true,   // a standing buy is judged on its months, not a column
                default    => $budget > 0,
            };
            if (!$has_money && ($row['paid_by'] ?? '') === '') continue;

            // TRUE: Financials never shows a month that has not begun. A future
            // month has not been delivered and cannot be owed, and putting one in
            // would corrupt both that month's totals and the running totals above
            // them. The grid is where a future month is visible and enterable.
            $months = $recurring
                ? mk_campaign_months($start, $row['end_date'] ?: null, true)
                : [substr($start, 0, 7)];
            // A one-time placement dated in the future is left alone: it is a
            // single committed charge, and it has always shown in its own month.
            if (!$months && !$recurring) $months = [substr($start, 0, 7)];

            $covs = $camp_overrides[$cid] ?? [];

            foreach ($months as $ym) {
                $res = mk_camp_resolve($row, $ym, $covs);
                if ($res === null) continue;                    // no days of that weekday this month

                $amount  = (float)$res['amount'];
                $paid_by = $res['paid_by'];

                // An empty one-time placement is noise and is dropped, as before.
                // Every month of a RECURRING placement is kept even at $0 — a
                // month with no figure yet is the thing most worth seeing, and
                // hiding it is how a month goes unbilled without anyone noticing.
                if ($mode === 'one_time' && $amount <= 0 && (string)$paid_by === '') continue;

                // First month keeps the real start date so it sorts against the
                // collateral lines sensibly; later months sort from the 1st.
                $when  = ($ym === substr($start, 0, 7)) ? $start : ($ym . '-01');
                $parts = mk_fin_split($paid_by ?: null, $amount, $res['pb'], $res['pm']);

                // $platform_labels isn't defined until the UI helpers further down,
                // so keep the raw value and resolve it at render time.
                mk_fin_add($fin_months, $when, array_merge($parts, [
                    'kind'       => 'Advertising',
                    'name'       => trim((string)($row['name'] ?? '')),
                    'platform'   => (string)$row['platform'],
                    'total'      => $amount,
                    'paid_by'    => $paid_by ?: null,
                    'date'       => $when,
                    'ref_id'     => $cid,
                    'recurring'  => $recurring,
                    'mode'       => $mode,
                    'ym'         => $ym,
                    'units'      => $res['units'],
                    'rate'       => $res['rate'],
                    'unit_src'   => $res['unit_src'],
                    'unit_label' => $row['unit_label'] ?: null,
                    'set'        => $res['set'],
                    'zeroed'     => $res['zeroed'],
                    'edited'     => $res['edited'],
                    'ov_note'    => $res['note'],
                ]));
            }
        }
    }

    krsort($fin_months);                                  // newest month first
    foreach ($fin_months as &$_m) {                       // newest line first within a month
        usort($_m['items'], fn($a, $b) => strcmp($b['date'], $a['date']));
    }
    unset($_m);

    // Running totals across every month shown
    $fin_grand = ['broker' => 0.0, 'mh' => 0.0, 'unassigned' => 0.0];
    foreach ($fin_months as $_m) {
        $fin_grand['broker']     += $_m['broker'];
        $fin_grand['mh']         += $_m['mh'];
        $fin_grand['unassigned'] += $_m['unassigned'];
    }

    return [
        'months'         => $fin_months,
        'grand'          => $fin_grand,
        'coll_rows'      => $coll_rows,
        'camp_overrides' => $camp_overrides,
    ];
}
