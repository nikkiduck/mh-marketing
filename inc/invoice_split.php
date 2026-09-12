<?php
/**
 * inc/invoice_split.php — splitting ONE vendor invoice across several agents.
 *
 * The problem this exists for: a vendor bills the discount, the rush fee, the
 * shipping and the sales tax ONCE, at the bottom of the invoice, no matter how
 * many agents' signs are on it. Recording only each agent's line-item total
 * therefore under-states every one of them, and the order never reconciles to
 * the card statement.
 *
 * On Oakley IND-650292 the gap is not marginal: $2,368.40 of merchandise was
 * charged as $3,647.04. Every $1.00 of sticker price actually cost $1.54.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────────
 *
 * Every bottom-of-invoice line is allocated pro-rata by each row's share of the
 * merchandise subtotal.
 *
 *     share = that row's merchandise ÷ total merchandise on the invoice
 *     cost  = merchandise
 *           − discount × share
 *           + rush     × share
 *           + shipping × share
 *           + tax      × share
 *           + other    × share
 *
 * ONE percentage drives all five adjustments. That is what makes it defensible:
 * there is no line on which anyone could argue one agent was favoured over
 * another, and no per-line judgement call to be made differently next time.
 *
 * Why pro-rata is right for each of them, briefly:
 *
 *   rush      Oakley's "Mega Rush" on IND-650292 was $644.34 — exactly 30.00%
 *             of the post-discount subtotal. It is a percentage of spend by
 *             construction, so splitting it by share of spend reproduces the
 *             vendor's own arithmetic rather than approximating it.
 *
 *   discount  A percentage-off promo is earned by the size of the order, and
 *             every party's spend helped earn it. (A promo that applies to one
 *             product only is NOT this: assign that to its row directly and
 *             keep it out of the pool.)
 *
 *   tax       You never have to work out what the vendor actually taxed.
 *             Merchandise, discount and rush all split by the same share, so
 *             whatever combination of them the taxable base is, that base
 *             splits by the same share too. Allocating tax by share is
 *             therefore EXACT, not an approximation — which is the reason this
 *             file does not try to store or infer a tax rate anywhere.
 *
 *   shipping  Freight is really driven by weight and piece count. On a combined
 *             sign order those track dollar value closely, and the alternatives
 *             (per-sign, or split evenly per agent) need a judgement call every
 *             time. One rule applied identically always beats a more precise
 *             rule applied inconsistently, because only the first one can be
 *             checked later.
 *
 * ── ROWS, NOT AGENTS ────────────────────────────────────────────────────────
 *
 * Allocation happens per ROW, not per agent, because each row becomes its own
 * marketing_collateral_orders record. Allocation is linear, so allocating per
 * row and summing per agent gives the same answer as allocating per agent —
 * except for the rounding pennies, and per-row is the better place for those to
 * land since the row is what carries a cost downstream.
 *
 * ── THE PENNY ───────────────────────────────────────────────────────────────
 *
 * Rounding five allocations to the cent across three or more rows will usually
 * leave the sum a cent or two off what the card was charged. The residual is
 * assigned to the single largest row by merchandise, so the allocated costs
 * ALWAYS sum to grand_total exactly.
 *
 * grand_total is the authority, not the sum of the parts — it is the number on
 * the card statement. If the entered charges do not add up to it, `reconciles`
 * comes back false and the caller must refuse to save rather than quietly
 * dumping the discrepancy on whichever row happens to be biggest.
 *
 * Include-only. Nothing here queries the database, reads the session, or
 * echoes: mh_invoice_allocate() takes arrays and returns arrays. That is what
 * makes tests/test_invoice_split.php able to test the real function rather than
 * a copy of it.
 *
 * If you change any of this arithmetic, run:
 *     php tests/test_invoice_split.php
 */

/**
 * Allocate one invoice's order-level charges across its rows.
 *
 * @param array $rows     [ ['key' => string, 'merch' => float], ... ]
 *                        'key' must be unique per row — the caller decides what
 *                        it means (a row index, a collateral order id).
 * @param array $charges  [
 *                          'discount'    => float,   // POSITIVE; it is subtracted
 *                          'rush'        => float,
 *                          'shipping'    => float,
 *                          'tax'         => float,
 *                          'other'       => float,
 *                          'grand_total' => float,   // what the card was charged
 *                        ]
 *
 * @return array [
 *   'merch_total'    => float   sum of every row's merchandise
 *   'adjust_total'   => float   −discount +rush +shipping +tax +other
 *   'expected_total' => float   merch_total + adjust_total
 *   'grand_total'    => float   as passed in
 *   'reconciles'     => bool    expected_total matches grand_total to the cent
 *   'factor'         => float   grand_total ÷ merch_total (0 when no merchandise)
 *   'residual'       => float   the rounding penny that was moved
 *   'residual_key'   => ?string the row it was moved onto
 *   'rows'           => [ key => [
 *                            'merch','share','discount','rush','shipping',
 *                            'tax','other','allocated','rounding','landed'
 *                        ] ]
 * ]
 *
 * 'discount' in the returned rows is NEGATIVE — it is the signed contribution
 * to that row's landed cost, so the row's own numbers add up without the reader
 * having to remember which one to subtract.
 */
function mh_invoice_allocate(array $rows, array $charges): array {
    $discount = (float)($charges['discount']    ?? 0);
    $rush     = (float)($charges['rush']        ?? 0);
    $shipping = (float)($charges['shipping']    ?? 0);
    $tax      = (float)($charges['tax']         ?? 0);
    $other    = (float)($charges['other']       ?? 0);
    $grand    = (float)($charges['grand_total'] ?? 0);

    $merch_total = 0.0;
    foreach ($rows as $r) $merch_total += (float)($r['merch'] ?? 0);

    $adjust   = -$discount + $rush + $shipping + $tax + $other;
    $expected = $merch_total + $adjust;

    $out = [
        'merch_total'    => $merch_total,
        'adjust_total'   => $adjust,
        'expected_total' => $expected,
        'grand_total'    => $grand,
        // Half a cent, because these are all DECIMAL(10,2) values that have
        // been through a float. Anything looser would let a real typo through.
        'reconciles'     => abs($expected - $grand) < 0.005,
        'factor'         => 0.0,
        'residual'       => 0.0,
        'residual_key'   => null,
        'rows'           => [],
    ];

    // No merchandise means no denominator and nothing to allocate against.
    // Return zeros rather than dividing — a blank form hits this on every
    // keystroke, and it is not an error state, just an empty one.
    if (abs($merch_total) < 0.005 || !$rows) {
        foreach ($rows as $r) {
            $out['rows'][(string)$r['key']] = [
                'merch' => (float)($r['merch'] ?? 0), 'share' => 0.0,
                'discount' => 0.0, 'rush' => 0.0, 'shipping' => 0.0,
                'tax' => 0.0, 'other' => 0.0,
                'allocated' => 0.0, 'rounding' => 0.0, 'landed' => 0.0,
            ];
        }
        return $out;
    }

    $out['factor'] = $grand / $merch_total;

    $sum_allocated = 0.0;
    $max_key = null;
    $max_merch = null;

    foreach ($rows as $r) {
        $key   = (string)$r['key'];
        $merch = (float)($r['merch'] ?? 0);
        $share = $merch / $merch_total;

        // Each component is rounded to the cent on its own, so every figure the
        // page shows is a real money amount rather than something that only
        // looks right once totalled.
        $d = -round($discount * $share, 2);
        $u =  round($rush     * $share, 2);
        $s =  round($shipping * $share, 2);
        $t =  round($tax      * $share, 2);
        $o =  round($other    * $share, 2);

        $allocated = round($merch + $d + $u + $s + $t + $o, 2);
        $sum_allocated += $allocated;

        // Largest row by merchandise carries the residual. First one wins a
        // tie, which keeps the result stable when two rows are equal — as they
        // are on IND-650292, where both agents' signs cost exactly $310.00.
        if ($max_merch === null || $merch > $max_merch) {
            $max_merch = $merch;
            $max_key   = $key;
        }

        $out['rows'][$key] = [
            'merch' => $merch, 'share' => $share,
            'discount' => $d, 'rush' => $u, 'shipping' => $s,
            'tax' => $t, 'other' => $o,
            'allocated' => $allocated, 'rounding' => 0.0, 'landed' => $allocated,
        ];
    }

    // Force the total onto the card charge. See the header: grand_total is the
    // authority. When `reconciles` is false the caller must not save, so this
    // never silently absorbs a mistyped figure.
    $residual = round($grand - $sum_allocated, 2);
    if (abs($residual) >= 0.005 && $max_key !== null) {
        $out['rows'][$max_key]['rounding'] = $residual;
        $out['rows'][$max_key]['landed']   = round($out['rows'][$max_key]['allocated'] + $residual, 2);
        $out['residual']     = $residual;
        $out['residual_key'] = $max_key;
    }

    return $out;
}

/**
 * The one-line explanation of a row's landed cost, for the Collateral tab and
 * the Financials line. Kept here so the page and the notes field cannot drift.
 */
function mh_invoice_row_note(string $vendor, string $order_number, float $merch): string {
    $who = trim($vendor . ($order_number !== '' ? " {$order_number}" : ''));
    if ($who === '') $who = 'a combined invoice';
    return sprintf(
        'Landed cost from %s — $%s of merchandise plus its pro-rata share of discount, rush, shipping and tax.',
        $who,
        number_format($merch, 2)
    );
}
