<?php
/**
 * tests/test_campaign_share.php — the arithmetic behind sharing one
 * advertising placement among several agents. Runs anywhere:
 *
 *   php tests/test_campaign_share.php
 */
require __DIR__ . '/../inc/campaign_share.php';

$pass = 0; $fail = 0;
function ok(bool $cond, string $what): void {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL {$what}\n"; }
}
$sum = fn(array $parts) => round(array_sum(array_map('floatval', $parts)), 2);

echo "── validation\n";
[$s, $e] = mk_share_validate(30, [30 => '50', 21 => '50']);
ok($e === '' && $s === [30 => 50.0, 21 => 50.0], 'two agents, 50/50');
[$s, $e] = mk_share_validate(30, [21 => '25', 30 => '50', 34 => '25']);
ok($e === '' && array_key_first($s) === 30, 'owner is put first whatever the posted order');
[$s, $e] = mk_share_validate(30, [30 => '60', 21 => '30']);
ok($s === [] && str_contains($e, '90'), 'shares short of 100 are refused, with the total');
[$s, $e] = mk_share_validate(30, [30 => '60', 21 => '50']);
ok($s === [] && str_contains($e, '110'), 'shares over 100 are refused');
[$s, $e] = mk_share_validate(30, [21 => '100']);
ok($s === [] && str_contains($e, 'keep a share'), 'the owner must keep a share');
[$s, $e] = mk_share_validate(30, [30 => '100']);
ok($s === [] && str_contains($e, 'at least one other'), 'someone else must take a share');
[$s, $e] = mk_share_validate(30, [30 => '50', 21 => '50', 34 => '', 35 => '0', 0 => '10']);
ok($e === '' && count($s) === 2, 'blank, zero and id 0 rows are ignored');
[$s, $e] = mk_share_validate(30, [30 => '33.33', 21 => '33.33', 34 => '33.34']);
ok($e === '', 'thirds entered to two decimals are accepted');
[$s, $e] = mk_share_validate(30, [30 => 'abc', 21 => '100']);
ok($s === [], 'a non-numeric owner share is not a share');
[$s, $e] = mk_share_validate(30, [30 => '150', 21 => '-50']);
ok($s === [], 'over 100 for one agent is refused even if the sum would work');

echo "── amounts\n";
$half = [30 => 50.0, 21 => 50.0];
ok(mk_share_amount('1000.00', $half) === [30 => '500.00', 21 => '500.00'], '$1,000 in half');
$p = mk_share_amount('100.00', [30 => 33.33, 21 => 33.33, 34 => 33.34]);
ok($sum($p) === 100.00, 'thirds add back to the total exactly');
$p = mk_share_amount('0.01', $half);
ok($sum($p) === 0.01 && $p[30] === '0.01' && $p[21] === '0.00', 'one cent at 50/50: the tie goes to the owner, the other gets 0.00');
$p = mk_share_amount('0.02', [30 => 25.0, 21 => 25.0, 34 => 25.0, 35 => 25.0]);
ok($sum($p) === 0.02 && min(array_map('floatval', $p)) >= 0, 'two cents four ways: sums exactly and nobody goes below zero');
$p = mk_share_amount('1000.00', [30 => 33.33, 21 => 33.33, 34 => 33.34]);
ok($sum($p) === 1000.00, '$1,000 in thirds sums exactly');
ok(mk_share_amount(null, $half) === [30 => null, 21 => null], 'NULL stays NULL: "not set" is not "no charge"');
ok(mk_share_amount('', $half) === [30 => null, 21 => null], 'blank stays NULL');
ok(mk_share_amount('0.00', $half) === [30 => '0.00', 21 => '0.00'], 'an entered zero stays an entered zero');
$p = mk_share_amount('125.00', [30 => 70.0, 21 => 30.0]);
ok($p === [30 => '87.50', 21 => '37.50'], 'a day rate of $125 at 70/30');
// A spread of awkward totals: the parts must ALWAYS sum to the original.
$bad = 0;
foreach ([0.03, 1.00, 19.99, 333.33, 1234.56, 99999.99] as $amt) {
    foreach ([[30 => 50.0, 21 => 50.0], [30 => 33.33, 21 => 33.33, 34 => 33.34], [30 => 12.5, 21 => 37.5, 34 => 25.0, 35 => 25.0], [30 => 1.0, 21 => 99.0]] as $sh) {
        if ($sum(mk_share_amount(number_format($amt, 2, '.', ''), $sh)) !== round($amt, 2)) $bad++;
    }
}
ok($bad === 0, 'every amount × split combination sums to the original');
$p = mk_share_amount('10.00', [30 => 1.0, 21 => 99.0]);
ok($p === [30 => '0.10', 21 => '9.90'], '$10 at 1/99');
// Nobody below zero, anywhere in the spread.
$neg = 0;
foreach ([0.01, 0.02, 0.03, 0.05, 0.07] as $amt) foreach ([2, 3, 4, 5, 7] as $n) {
    $sh = []; for ($k = 0; $k < $n; $k++) $sh[100 + $k] = round(100 / $n, 2);
    if (min(array_map('floatval', mk_share_amount(number_format($amt, 2, '.', ''), $sh))) < 0) $neg++;
}
ok($neg === 0, 'a few cents split up to seven ways never produces a negative share');

echo str_repeat('─', 65) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
