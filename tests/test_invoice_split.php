<?php
/**
 * test_invoice_split.php — the arithmetic behind splitting one vendor invoice
 * across several agents.
 *
 *   php tests/test_invoice_split.php
 *
 * Unlike test_campaign_billing.php this does NOT extract the function by regex,
 * because mh_invoice_allocate() already lives in an include-only file that
 * queries nothing and echoes nothing. Requiring inc/invoice_split.php directly
 * tests the real thing, which is the point of having put it there.
 *
 * The rules the suite exists to protect:
 *
 *   1. Allocated costs ALWAYS sum to grand_total exactly. Not to within a cent
 *      — exactly. Anything else means a card statement that does not tie.
 *   2. grand_total is the authority. A set of charges that does not add up to
 *      it must report reconciles=false so the page can refuse to save, rather
 *      than absorbing the discrepancy into the largest row.
 *   3. Tax needs no rate. Splitting tax by merchandise share is exact whatever
 *      the vendor taxed — THE TAX BASE INDEPENDENCE section below is what
 *      proves it, and what fails if somebody "improves" the tax line into
 *      something that needs to know the base.
 *
 * The headline case is real: Oakley Signs order IND-650292, 05-01-2026.
 */

require_once __DIR__ . '/../inc/invoice_split.php';

$pass = 0; $fail = 0;

function ok(string $what, $got, $want): void {
    global $pass, $fail;
    $same = (is_float($got) || is_float($want))
          ? (is_numeric($got) && is_numeric($want) && abs((float)$got - (float)$want) < 0.005)
          : $got === $want;
    if ($same) { $pass++; echo "  ok   {$what}\n"; }
    else {
        $fail++;
        printf("  FAIL %s — got %s, want %s\n", $what,
               var_export($got, true), var_export($want, true));
    }
}

/** Exact to the cent, with no tolerance. Used for the sum-to-total invariant. */
function exact(string $what, float $got, float $want): void {
    global $pass, $fail;
    if (round($got, 2) === round($want, 2)) { $pass++; echo "  ok   {$what}\n"; }
    else {
        $fail++;
        printf("  FAIL %s — got %.4f, want %.4f\n", $what, $got, $want);
    }
}

function rows(array $pairs): array {
    $out = [];
    foreach ($pairs as $k => $m) $out[] = ['key' => (string)$k, 'merch' => (float)$m];
    return $out;
}
function sum_landed(array $res): float {
    $t = 0.0;
    foreach ($res['rows'] as $r) $t += $r['landed'];
    return round($t, 2);
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\nOAKLEY IND-650292 — the real invoice\n";
// ═══════════════════════════════════════════════════════════════════════════
//
//   Alumibond agent sign  ×5   allisonD-sign.pdf   $  310.00
//   Alumibond agent sign  ×5   JMDrai_sign.pdf     $  310.00
//   PVC Open House L/R    ×20  generic MH artwork  $1,748.40
//                                       Subtotal   $2,368.40
//                             Mega Rush Printing   $  644.34
//                    Promo Code BLOOM26 Discount   $  220.60
//                                  CO Sales Tax    $  281.63
//                                      Shipping    $  573.27
//                                         TOTAL    $3,647.04

$OAKLEY = [
    'discount'    => 220.60,
    'rush'        => 644.34,
    'shipping'    => 573.27,
    'tax'         => 281.63,
    'other'       => 0.00,
    'grand_total' => 3647.04,
];
$oak = mh_invoice_allocate(
    rows(['allison' => 310.00, 'jm' => 310.00, 'house' => 1748.40]),
    $OAKLEY
);

ok('merchandise subtotal',        $oak['merch_total'],    2368.40);
ok('order-level adjustments',     $oak['adjust_total'],   1278.64);
ok('expected equals charged',     $oak['expected_total'], 3647.04);
ok('reconciles',                  $oak['reconciles'],     true);
ok('landed cost factor',          round($oak['factor'], 4), 1.5399);

ok('Allison share',   round($oak['rows']['allison']['share'], 6), 0.130890);
ok('JM share',        round($oak['rows']['jm']['share'],      6), 0.130890);
ok('house share',     round($oak['rows']['house']['share'],   6), 0.738220);

// The headline numbers. If these move, every agent's recorded cost moves.
ok('Allison landed',  $oak['rows']['allison']['landed'],  477.37);
ok('JM landed',       $oak['rows']['jm']['landed'],       477.37);
ok('house landed',    $oak['rows']['house']['landed'],   2692.30);

exact('THE INVARIANT — landed costs sum to the card charge', sum_landed($oak), 3647.04);

// The two agents bought identical merchandise, so nothing about the split may
// distinguish them. A rounding rule that walked the residual down the list
// would break this, which is why the residual goes to the LARGEST row.
ok('identical merchandise gives identical cost',
   $oak['rows']['allison']['landed'] === $oak['rows']['jm']['landed'], true);

ok('residual landed on the largest row', $oak['residual_key'], 'house');
ok('residual is pennies', abs($oak['residual']) < 0.05, true);

// Each component, for the row that carries the most money.
ok('house discount is negative',  $oak['rows']['house']['discount'], -162.85);
ok('house rush',                  $oak['rows']['house']['rush'],      475.66);
ok('house shipping',              $oak['rows']['house']['shipping'],  423.20);
ok('house tax',                   $oak['rows']['house']['tax'],       207.90);
ok('discount sign is negative on every row',
   $oak['rows']['allison']['discount'] < 0 && $oak['rows']['jm']['discount'] < 0, true);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nTHE TAX BASE INDEPENDENCE\n";
// ═══════════════════════════════════════════════════════════════════════════
//
// The claim in inc/invoice_split.php is that splitting tax by merchandise share
// is EXACT whatever the vendor taxed, because every candidate base is itself
// split by the same share.
//
// Proved by construction: take the actual tax figure, work out what rate it
// implies against three different plausible bases, then confirm — ROW BY ROW —
// that taxing each row's own slice of that base at that rate gives back exactly
// the tax the allocator assigned it. If that holds for all three bases, the
// allocation cannot depend on which base is right, which is why the code never
// asks and stores no rate anywhere.
//
// Compared per row on purpose. Summing the rounded rows and comparing to the
// invoice tax would fail by a cent — see the assertion below, which pins that
// cent as expected behaviour rather than a defect.

$bases = [
    'post-discount merchandise'         => 2368.40 - 220.60,
    'post-discount merchandise + rush'  => 2368.40 - 220.60 + 644.34,
    'everything except tax'             => 2368.40 - 220.60 + 644.34 + 573.27,
];
foreach ($bases as $label => $base) {
    $rate = 281.63 / $base;
    foreach ($oak['rows'] as $key => $r) {
        // That row's slice of whatever the base is, taxed at the implied rate.
        ok("tax on {$key} reproduces against \"{$label}\"",
           round($base * $r['share'] * $rate, 2), $r['tax']);
    }
}

// The rounded per-row tax figures come to $281.62, a cent under the $281.63 on
// the invoice. That cent is not lost: it is part of the residual the allocator
// moves onto the largest row, which is why the landed costs still sum exactly
// to $3,647.04 above. Pinned here so the shortfall reads as designed rather
// than as something to go chasing.
$tax_sum = 0.0;
foreach ($oak['rows'] as $r) $tax_sum += $r['tax'];
ok('rounded per-row tax is a cent short, by design', round($tax_sum, 2), 281.62);
ok('and the residual covers it', abs($oak['residual']) >= 0.005, true);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nTHE SUM-TO-TOTAL INVARIANT — awkward numbers\n";
// ═══════════════════════════════════════════════════════════════════════════
//
// Three-way splits of prime-ish amounts are where per-row rounding drifts.
// Every one of these must still land exactly on grand_total.

$awkward = [
    'three equal thirds'      => [[100.00, 100.00, 100.00], 33.33,  0,     10.01, 0,    0],
    'seven rows, odd cents'   => [[11.11, 22.22, 33.33, 44.44, 55.55, 66.66, 77.77], 13.37, 41.03, 19.99, 27.71, 3.03],
    'one cent line'           => [[0.01, 999.99], 0, 0, 50.00, 12.34, 0],
    'a single row takes all'  => [[500.00], 25.00, 10.00, 40.00, 33.33, 1.00],
    'lopsided 99 to 1'        => [[9900.00, 100.00], 700.00, 0, 250.00, 401.11, 0],
];
foreach ($awkward as $label => $spec) {
    [$merch, $disc, $rush, $ship, $tax, $other] = $spec;
    $mt    = array_sum($merch);
    $grand = round($mt - $disc + $rush + $ship + $tax + $other, 2);
    $res   = mh_invoice_allocate(rows($merch), [
        'discount' => $disc, 'rush' => $rush, 'shipping' => $ship,
        'tax' => $tax, 'other' => $other, 'grand_total' => $grand,
    ]);
    exact("{$label} — sums to charge", sum_landed($res), $grand);
    ok("{$label} — reconciles", $res['reconciles'], true);
    // Shares must total exactly 1, or the allocation is leaking somewhere.
    $shares = 0.0;
    foreach ($res['rows'] as $r) $shares += $r['share'];
    ok("{$label} — shares total 1", round($shares, 9), 1.0);
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\nGRAND TOTAL IS THE AUTHORITY\n";
// ═══════════════════════════════════════════════════════════════════════════
//
// A mistyped charge must be REPORTED, not absorbed. The page refuses to save on
// reconciles=false; if this ever returns true for a bad set of figures, a wrong
// number reaches an agent's record with nothing to show for it.

$bad = mh_invoice_allocate(rows(['a' => 100.00, 'b' => 100.00]), [
    'discount' => 0, 'rush' => 0, 'shipping' => 20.00, 'tax' => 10.00,
    'other' => 0, 'grand_total' => 999.00,      // nowhere near 230.00
]);
ok('mistyped total does not reconcile', $bad['reconciles'], false);
ok('expected total is still reported',  $bad['expected_total'], 230.00);
// It still allocates against grand_total, so the caller can show the damage.
exact('still ties to the entered charge', sum_landed($bad), 999.00);

$good = mh_invoice_allocate(rows(['a' => 100.00]), [
    'discount' => 0, 'rush' => 0, 'shipping' => 0, 'tax' => 0,
    'other' => 0, 'grand_total' => 100.00,
]);
ok('a plain order with no extras reconciles', $good['reconciles'], true);
ok('and costs exactly its merchandise', $good['rows']['a']['landed'], 100.00);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nEMPTY AND DEGENERATE INPUT\n";
// ═══════════════════════════════════════════════════════════════════════════
//
// A blank form hits mh_invoice_allocate() on every keystroke. None of this is
// an error state — it is just an empty one — and none of it may divide by zero.

$empty = mh_invoice_allocate([], $OAKLEY);
ok('no rows — no division by zero', $empty['merch_total'], 0.0);
ok('no rows — factor is zero',      $empty['factor'],      0.0);
ok('no rows — no rows out',         count($empty['rows']), 0);

$zero = mh_invoice_allocate(rows(['a' => 0.00, 'b' => 0.00]), [
    'discount' => 0, 'rush' => 0, 'shipping' => 25.00, 'tax' => 0,
    'other' => 0, 'grand_total' => 25.00,
]);
ok('zero merchandise — shares are zero', $zero['rows']['a']['share'], 0.0);
ok('zero merchandise — nothing allocated', $zero['rows']['a']['landed'], 0.0);
ok('zero merchandise — no residual dumped', $zero['residual'], 0.0);

$nocharges = mh_invoice_allocate(rows(['a' => 60.00, 'b' => 40.00]), [
    'discount' => 0, 'rush' => 0, 'shipping' => 0, 'tax' => 0,
    'other' => 0, 'grand_total' => 100.00,
]);
ok('no adjustments — cost is merchandise (a)', $nocharges['rows']['a']['landed'], 60.00);
ok('no adjustments — cost is merchandise (b)', $nocharges['rows']['b']['landed'], 40.00);
ok('no adjustments — factor is 1', round($nocharges['factor'], 6), 1.0);

// A credit line. Rare, but a vendor reissue produces one and it must not throw.
$credit = mh_invoice_allocate(rows(['a' => 500.00, 'b' => -100.00]), [
    'discount' => 0, 'rush' => 0, 'shipping' => 40.00, 'tax' => 0,
    'other' => 0, 'grand_total' => 440.00,
]);
exact('a credit row still ties', sum_landed($credit), 440.00);
ok('credit row stays negative', $credit['rows']['b']['landed'] < 0, true);
ok('residual never lands on the credit row', $credit['residual_key'] !== 'b', true);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nDISCOUNT-ONLY AND DISCOUNT-HEAVY\n";
// ═══════════════════════════════════════════════════════════════════════════

$discounted = mh_invoice_allocate(rows(['a' => 100.00, 'b' => 300.00]), [
    'discount' => 40.00, 'rush' => 0, 'shipping' => 0, 'tax' => 0,
    'other' => 0, 'grand_total' => 360.00,
]);
ok('discount splits by share (a)', $discounted['rows']['a']['landed'], 90.00);
ok('discount splits by share (b)', $discounted['rows']['b']['landed'], 270.00);
exact('discounted order ties', sum_landed($discounted), 360.00);

// 100% off. Everything is free and nothing may go negative or NaN.
$free = mh_invoice_allocate(rows(['a' => 250.00, 'b' => 250.00]), [
    'discount' => 500.00, 'rush' => 0, 'shipping' => 0, 'tax' => 0,
    'other' => 0, 'grand_total' => 0.00,
]);
exact('a fully discounted order ties to zero', sum_landed($free), 0.00);
ok('fully discounted row costs nothing', $free['rows']['a']['landed'], 0.00);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nMANY ROWS FOR ONE AGENT\n";
// ═══════════════════════════════════════════════════════════════════════════
//
// One agent can have several line types on the same invoice — yard signs and
// open-house signs, say. Each is its own row and its own collateral order.
// Allocation is linear, so the agent's total must equal what a single combined
// row of the same merchandise would have produced, give or take the penny.

$split_rows = mh_invoice_allocate(
    rows(['jm_yard' => 200.00, 'jm_oh' => 110.00, 'other' => 310.00]),
    ['discount' => 62.00, 'rush' => 0, 'shipping' => 100.00, 'tax' => 50.00,
     'other' => 0, 'grand_total' => 708.00]
);
$combined = mh_invoice_allocate(
    rows(['jm' => 310.00, 'other' => 310.00]),
    ['discount' => 62.00, 'rush' => 0, 'shipping' => 100.00, 'tax' => 50.00,
     'other' => 0, 'grand_total' => 708.00]
);
$jm_split = round($split_rows['rows']['jm_yard']['landed'] + $split_rows['rows']['jm_oh']['landed'], 2);
ok('two rows for one agent equal one combined row',
   abs($jm_split - $combined['rows']['jm']['landed']) <= 0.02, true);
exact('multi-row invoice still ties', sum_landed($split_rows), 708.00);

// ═══════════════════════════════════════════════════════════════════════════
printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
