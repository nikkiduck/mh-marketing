<?php
/**
 * test_campaign_billing.php — the money arithmetic behind recurring placements.
 *
 *   php tests/test_campaign_billing.php
 *
 * Pulls mk_campaign_months(), mk_weekday_days() and mk_camp_resolve() straight
 * out of inc/financials.php rather than copying them, so the test cannot pass against a
 * stale copy of the logic. Each is a top-level function whose closing brace is
 * in column 0, which is what the extractor relies on — if that ever stops being
 * true this file fails loudly rather than silently testing nothing.
 *
 * The rule the whole suite exists to protect:
 *
 *   A monthly placement has no amount that repeats itself forward. Every month
 *   is entered. A month nobody has entered bills NOTHING and is flagged.
 *
 * Several assertions below exist only to catch a "helpful" fallback creeping
 * back in — see THE FALLBACK GUARD section.
 */

$SRC = __DIR__ . '/../inc/financials.php';
$src = file_get_contents($SRC);
if ($src === false) { fwrite(STDERR, "cannot read {$SRC}\n"); exit(1); }

foreach (['mk_campaign_months', 'mk_weekday_days', 'mk_camp_resolve'] as $fn) {
    if (!preg_match('/^function ' . $fn . '\(.*?^\}/ms', $src, $m)) {
        fwrite(STDERR, "FATAL: could not extract {$fn}() from inc/financials.php\n");
        exit(1);
    }
    eval($m[0]);
}

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

// A campaign row as the database hands it back: every value a string or null.
function camp(array $over = []): array {
    return array_merge([
        'id' => 1, 'billing_mode' => 'monthly', 'budget' => null,
        'unit_rate' => null, 'unit_weekday' => null, 'unit_label' => null,
        'start_date' => '2026-08-01', 'end_date' => null,
        'paid_by' => 'broker', 'paid_broker_amount' => null, 'paid_mh_amount' => null,
    ], $over);
}
// One row of marketing_campaign_months.
function mrow(array $over = []): array {
    return array_merge([
        'ym' => '2026-08', 'amount' => null, 'units' => null, 'unit_rate' => null,
        'paid_by' => null, 'paid_broker_amount' => null, 'paid_mh_amount' => null,
        'note' => null,
    ], $over);
}
function byYm(array $rows): array {
    $out = [];
    foreach ($rows as $r) $out[$r['ym']] = $r;
    return $out;
}

echo "\n── The calendar itself ───────────────────────────────────────────\n";
// Aug 1 2026 is a Saturday. This is the whole reason per-day exists.
ok('Aug 2026 Wednesdays',          mk_weekday_days('2026-08', 3, null, null), 4);
ok('Aug 2026 Saturdays',           mk_weekday_days('2026-08', 6, null, null), 5);
ok('Feb 2026 Sundays',             mk_weekday_days('2026-02', 0, null, null), 4);
ok('Aug 2026 Weds from the 10th',  mk_weekday_days('2026-08', 3, '2026-08-10', null), 3);
ok('Aug 2026 Weds up to the 12th', mk_weekday_days('2026-08', 3, null, '2026-08-12'), 2);
ok('run that misses the day entirely',
   mk_weekday_days('2026-08', 3, '2026-08-28', '2026-08-31'), 0);

echo "\n── Month expansion ──────────────────────────────────────────────\n";
ok('Aug–Oct is three months', count(mk_campaign_months('2026-08-01', '2026-10-31')), 3);
ok('Aug 15 – Sep 2 is two',   count(mk_campaign_months('2026-08-15', '2026-09-02')), 2);
ok('end before start is one', count(mk_campaign_months('2026-08-01', '2026-07-01')), 1);
ok('mistyped year is capped', count(mk_campaign_months('2020-01-01', '2099-01-01')), 60);

echo "\n── THE FALLBACK GUARD ───────────────────────────────────────────\n";
// If any of these four start failing, somebody has reintroduced an amount that
// carries into a month nobody entered. That is the bug this design exists to
// prevent, and it is invisible in the UI until it reaches an invoice.
$m = camp(['billing_mode' => 'monthly', 'budget' => '400.00']);   // budget IS set
$r = mk_camp_resolve($m, '2026-08', []);
ok('a month with no row bills nothing',        $r['amount'], 0.0);
ok('...and is flagged not-set',                $r['set'],    false);
ok('...even though the placement has a budget', (float)$m['budget'], 400.0);
$r = mk_camp_resolve($m, '2026-12', byYm([mrow(['ym'=>'2026-08','amount'=>'400.00'])]));
ok('August\'s figure does not reach December',  $r['set'],   false);

echo "\n── Scenario 1: Wednesday marquee, outlet ran only three ─────────\n";
$c1 = camp(['billing_mode'=>'per_unit', 'unit_rate'=>'125.00',
            'unit_weekday'=>3, 'unit_label'=>'Wednesday',
            'start_date'=>'2026-08-01', 'end_date'=>'2026-08-31']);
$r = mk_camp_resolve($c1, '2026-08', []);
ok('scheduled: 4 × $125',         $r['amount'],   500.0);
ok('quantity came from calendar', $r['unit_src'], 'calendar');
ok('and counts as set',           $r['set'],      true);

$r = mk_camp_resolve($c1, '2026-08', byYm([mrow(['units'=>'3','note'=>'outlet missed one week'])]));
ok('billed: 3 × $125',            $r['amount'],   375.0);
ok('flagged as corrected',        $r['unit_src'], 'override');
ok('reason carried through',      $r['note'],     'outlet missed one week');

echo "\n── Scenario 2: Saturday marquee, five Saturdays ─────────────────\n";
$c2 = camp(['billing_mode'=>'per_unit', 'unit_rate'=>'125.00',
            'unit_weekday'=>6, 'unit_label'=>'Saturday',
            'start_date'=>'2026-08-01', 'end_date'=>'2026-08-31']);
$r = mk_camp_resolve($c2, '2026-08', []);
ok('5 × $125 with no intervention', $r['amount'], 625.0);
// One extra Saturday, at the same rate, is the entire difference.
ok('exactly one day more than the Wednesday buy',
   $r['amount'] - mk_camp_resolve($c1, '2026-08', [])['amount'], 125.0);

echo "\n── Scenario 3: \$400 of impressions, starting mid-month ─────────\n";
// Never prorated. Starting on the 15th still owes the whole month.
$c3 = camp(['billing_mode'=>'monthly', 'budget'=>'400.00',
            'start_date'=>'2026-08-15', 'end_date'=>null]);
$aug = byYm([mrow(['ym'=>'2026-08','amount'=>'400.00'])]);
ok('August is the full 400', mk_camp_resolve($c3, '2026-08', $aug)['amount'], 400.0);

echo "\n── Scenario 4: \$600 in September, decided a month at a time ────\n";
$sep = byYm([
    mrow(['ym'=>'2026-08','amount'=>'400.00']),
    mrow(['ym'=>'2026-09','amount'=>'600.00','note'=>'increased buy']),
]);
ok('August stays 400',            mk_camp_resolve($c3, '2026-08', $sep)['amount'], 400.0);
ok('September is 600',            mk_camp_resolve($c3, '2026-09', $sep)['amount'], 600.0);
// The point of the rewrite: October is not guessed from either of them.
$oct = mk_camp_resolve($c3, '2026-10', $sep);
ok('October bills nothing',       $oct['amount'], 0.0);
ok('October is flagged not-set',  $oct['set'],    false);
ok('October is not a decision',   $oct['zeroed'], false);

echo "\n── Zero is a decision, blank is not ─────────────────────────────\n";
$z = mk_camp_resolve($c3, '2026-10', byYm([mrow(['ym'=>'2026-10','amount'=>'0.00','note'=>'paused'])]));
ok('an entered 0 bills nothing',   $z['amount'], 0.0);
ok('...but counts as entered',     $z['set'],    true);
ok('...and reads as a no-charge',  $z['zeroed'], true);
ok('...with its reason kept',      $z['note'],   'paused');
// A row that carries only a note must not masquerade as an entered amount.
$n = mk_camp_resolve($c3, '2026-10', byYm([mrow(['ym'=>'2026-10','note'=>'waiting on the agent'])]));
ok('a note alone is still not set', $n['set'],   false);

echo "\n── One-time is untouched ────────────────────────────────────────\n";
$one = camp(['billing_mode'=>'one_time', 'budget'=>'250.00', 'start_date'=>'2026-08-20']);
ok('the whole budget, in its month', mk_camp_resolve($one, '2026-08', [])['amount'], 250.0);
ok('and never flagged not-set',      mk_camp_resolve($one, '2026-08', [])['set'],    true);

echo "\n── Per-day edge cases ───────────────────────────────────────────\n";
// No weekday configured: nothing to count, nothing typed. Must not bill silently.
$c5 = camp(['billing_mode'=>'per_unit', 'unit_rate'=>'125.00',
            'unit_weekday'=>null, 'unit_label'=>'insertion']);
$r = mk_camp_resolve($c5, '2026-08', []);
ok('no weekday: charges nothing', $r['amount'],   0.0);
ok('no weekday: flagged not-set', $r['set'],      false);
ok('no weekday: reason is unset', $r['unit_src'], 'unset');
$r = mk_camp_resolve($c5, '2026-08', byYm([mrow(['units'=>'2'])]));
ok('typed quantity bills',        $r['amount'],   250.0);
ok('...and counts as set',        $r['set'],      true);

// A month the run does not touch produces no line at all — not a $0 one.
$c6 = camp(['billing_mode'=>'per_unit', 'unit_rate'=>'125.00',
            'unit_weekday'=>3, 'start_date'=>'2026-08-28', 'end_date'=>'2026-09-30']);
ok('no Wednesday in the August tail', mk_camp_resolve($c6, '2026-08', []), null);
ok('September has its five',          mk_camp_resolve($c6, '2026-09', [])['amount'], 625.0);

// A rate correction for one month only — it must not touch any other month.
$c7 = camp(['billing_mode'=>'per_unit', 'unit_rate'=>'125.00',
            'unit_weekday'=>3, 'start_date'=>'2026-08-01', 'end_date'=>'2026-09-30']);
$rate = byYm([mrow(['ym'=>'2026-09', 'unit_rate'=>'140.00'])]);
ok('August at the placement rate', mk_camp_resolve($c7, '2026-08', $rate)['amount'], 500.0);  // 4 × 125
ok('September at the typed rate',  mk_camp_resolve($c7, '2026-09', $rate)['amount'], 700.0);  // 5 × 140
$c8 = camp(['billing_mode'=>'per_unit', 'unit_rate'=>'125.00',
            'unit_weekday'=>3, 'start_date'=>'2026-08-01', 'end_date'=>'2026-10-31']);
ok('October is back to 125',       mk_camp_resolve($c8, '2026-10', $rate)['amount'], 500.0);  // 4 × 125

echo "\n── Who pays ─────────────────────────────────────────────────────\n";
ok('inherited from the placement',
   mk_camp_resolve($c3, '2026-08', $aug)['paid_by'], 'broker');
ok('overridden for one month',
   mk_camp_resolve($c3, '2026-08', byYm([mrow(['ym'=>'2026-08','amount'=>'400.00','paid_by'=>'mont_haus'])]))['paid_by'],
   'mont_haus');
// The split amounts must travel with whichever row supplied paid_by, or the
// placement's own split would be attached to an overridden payer.
$sp = mk_camp_resolve(
    camp(['paid_by'=>'split', 'paid_broker_amount'=>'300.00', 'paid_mh_amount'=>'100.00']),
    '2026-08',
    byYm([mrow(['ym'=>'2026-08','amount'=>'400.00','paid_by'=>'mont_haus'])])
);
ok('overridden payer drops the old split', $sp['pb'], null);

echo "\n── A standing buy: monthly_flat ─────────────────────────────────\n";
// $1,000 a month on the Aspen Daily News leaderboard, no end date. The
// repetition IS the instruction here, given once — which is the entire
// difference from `monthly`, where a repeated amount would be invented.
$flat = camp(['billing_mode'=>'monthly_flat', 'budget'=>'1000.00',
              'start_date'=>'2026-07-01', 'end_date'=>null]);
ok('July bills the standing amount',   mk_camp_resolve($flat, '2026-07', [])['amount'], 1000.0);
ok('August too, with nothing entered', mk_camp_resolve($flat, '2026-08', [])['amount'], 1000.0);
ok('...and counts as set',             mk_camp_resolve($flat, '2026-08', [])['set'],    true);
// One month different, without disturbing the standing figure.
$one = byYm([mrow(['ym'=>'2026-08','amount'=>'750.00','note'=>'half month credit'])]);
ok('an entered month overrides',       mk_camp_resolve($flat, '2026-08', $one)['amount'], 750.0);
ok('July is untouched by it',          mk_camp_resolve($flat, '2026-07', $one)['amount'], 1000.0);
ok('an entered 0 is still no charge',
   mk_camp_resolve($flat, '2026-08', byYm([mrow(['ym'=>'2026-08','amount'=>'0.00'])]))['zeroed'], true);
// The one way a standing buy can bill nothing: no standing amount.
$noamt = camp(['billing_mode'=>'monthly_flat', 'budget'=>null, 'start_date'=>'2026-07-01']);
ok('no standing amount bills nothing', mk_camp_resolve($noamt, '2026-07', [])['amount'], 0.0);
ok('...and is flagged not-set',        mk_camp_resolve($noamt, '2026-07', [])['set'],    false);

echo "\n── Months that have not started ─────────────────────────────────\n";
// Anchored to the real current month, not a hard-coded one, so these keep
// meaning the same thing next year.
$now  = date('Y-m');
$next = date('Y-m', strtotime($now . '-01 +1 month'));
$soon = date('Y-m', strtotime($now . '-01 +4 months'));
$back = date('Y-m', strtotime($now . '-01 -2 months'));

// Open-ended: runs to the current month either way.
$openm = mk_campaign_months($back . '-01', null, true);
ok('open-ended ends at the current month', end($openm), $now);

// Committed future run: the grid shows it, Financials does not.
$grid = mk_campaign_months($back . '-01', $soon . '-28', false);
$fin  = mk_campaign_months($back . '-01', $soon . '-28', true);
ok('the grid shows the committed run',   end($grid), $soon);
ok('Financials stops at this month',     end($fin),  $now);
ok('and never includes next month',      in_array($next, $fin, true), false);
ok('the grid still can',                 in_array($next, $grid, true), true);

// A placement booked to start later must not appear at all — not even as one
// month. This is the case that would otherwise drop a future buy into this
// month's totals.
ok('a run starting next month shows nothing yet',
   mk_campaign_months($next . '-01', null, true), []);
ok('...but is visible in the grid',
   count(mk_campaign_months($next . '-01', $next . '-28', false)), 1);

// The end-before-start typo still yields its one month, unclamped.
ok('end before start is still one month',
   count(mk_campaign_months($back . '-01', $back . '-01', true)), 1);

echo "\n─────────────────────────────────────────────────────────────────\n";
printf("%d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
