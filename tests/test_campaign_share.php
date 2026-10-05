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

echo "── the Share form's lines\n";
[$s, $e] = mk_share_lines_validate(30, ['30', '21'], ['600', '400'], '1000', true);
ok($e === '' && $s === [30 => 600.0, 21 => 400.0], '$600 + $400 of $1,000');
[$s, $e] = mk_share_lines_validate(30, ['21', '30', '34'], ['1000', '1000', '1000'], '3000.00', true);
ok($e === '' && array_key_first($s) === 30, 'this page\'s agent is put first whatever the posted order');
[$s, $e] = mk_share_lines_validate(30, ['30', '21'], ['600', '300'], '1000', true);
ok($s === [] && str_contains($e, '$900.00') && str_contains($e, '$1,000.00'), 'amounts short of the total are refused, naming both figures');
[$s, $e] = mk_share_lines_validate(30, ['30', '21'], ['600', '500'], '1000', true);
ok($s === [] && str_contains($e, '$1,100.00'), 'amounts over the total are refused');
[$s, $e] = mk_share_lines_validate(30, ['21'], ['1000'], '1000', true);
ok($s === [] && str_contains($e, 'keep a share'), 'this page\'s agent must keep a share');
[$s, $e] = mk_share_lines_validate(30, ['30', '21'], ['0', '1000'], '1000', true);
ok($s === [] && str_contains($e, 'keep a share'), 'a zero for this page\'s agent is not a share');
[$s, $e] = mk_share_lines_validate(30, ['30'], ['1000'], '1000', true);
ok($s === [] && str_contains($e, 'at least one other'), 'a first share needs someone else');
[$s, $e] = mk_share_lines_validate(30, ['30'], ['1000'], '1000', false);
ok($e === '' && $s === [30 => 1000.0], 'an existing share may shrink back to this agent alone');
[$s, $e] = mk_share_lines_validate(30, ['30', '21', '', ''], ['500', '500', '', ''], '1000', true);
ok($e === '' && count($s) === 2, 'untouched "add agent" rows are ignored');
[$s, $e] = mk_share_lines_validate(30, ['30', '21', ''], ['400', '400', '200'], '1000', true);
ok($s === [] && str_contains($e, 'Choose an agent'), 'an amount with no agent chosen is refused, not dropped');
[$s, $e] = mk_share_lines_validate(30, ['30', '21', '34'], ['500', '500', ''], '1000', true);
ok($s === [] && str_contains($e, 'Enter an amount'), 'an agent with no amount is refused, not dropped');
[$s, $e] = mk_share_lines_validate(30, ['30', '21', '21'], ['400', '300', '300'], '1000', true);
ok($s === [] && str_contains($e, 'twice'), 'the same agent on two lines is refused');
[$s, $e] = mk_share_lines_validate(30, ['30', '21'], ['1200', '-200'], '1000', true);
ok($s === [], 'a negative amount is refused even if the sum would work');
[$s, $e] = mk_share_lines_validate(30, ['30', '21'], ['abc', '1000'], '1000', true);
ok($s === [], 'a non-numeric amount is not a share');
[$s, $e] = mk_share_lines_validate(30, ['30', '21'], ['500', '500'], '', true);
ok($s === [] && str_contains($e, 'total'), 'no total, no share');
[$s, $e] = mk_share_lines_validate(30, ['30', '21', '34'], ['333.34', '333.33', '333.33'], '1000', true);
ok($e === '', 'thirds of $1,000 to the cent are accepted');
[$s, $e] = mk_share_lines_validate(30, ['30', '21'], ['1650', '1650'], '3300', false);
ok($e === '' && $s === [30 => 1650.0, 21 => 1650.0], 'the total itself may be changed when re-dividing');

echo "── amounts (first share: divided by largest remainder)\n";
$half = [30 => 50.0, 21 => 50.0];
ok(mk_share_amount('1000.00', $half) === [30 => '500.00', 21 => '500.00'], '$1,000 in half');
$p = mk_share_amount('100.00', [30 => 33.33, 21 => 33.33, 34 => 33.34]);
ok($sum($p) === 100.00, 'thirds add back to the total exactly');
$p = mk_share_amount('0.01', $half);
ok($sum($p) === 0.01 && $p[30] === '0.01' && $p[21] === '0.00', 'one cent at 50/50: the tie goes to the owner, the other gets 0.00');
$p = mk_share_amount('0.02', [30 => 25.0, 21 => 25.0, 34 => 25.0, 35 => 25.0]);
ok($sum($p) === 0.02 && min(array_map('floatval', $p)) >= 0, 'two cents four ways: sums exactly and nobody goes below zero');
ok(mk_share_amount(null, $half) === [30 => null, 21 => null], 'NULL stays NULL: "not set" is not "no charge"');
ok(mk_share_amount('', $half) === [30 => null, 21 => null], 'blank stays NULL');
ok(mk_share_amount('0.00', $half) === [30 => '0.00', 21 => '0.00'], 'an entered zero stays an entered zero');
// A spread of awkward totals: the parts must ALWAYS sum to the original.
$bad = 0;
foreach ([0.03, 1.00, 19.99, 333.33, 1234.56, 99999.99] as $amt) {
    foreach ([[30 => 50.0, 21 => 50.0], [30 => 33.33, 21 => 33.33, 34 => 33.34], [30 => 12.5, 21 => 37.5, 34 => 25.0, 35 => 25.0], [30 => 1.0, 21 => 99.0]] as $sh) {
        if ($sum(mk_share_amount(number_format($amt, 2, '.', ''), $sh)) !== round($amt, 2)) $bad++;
    }
}
ok($bad === 0, 'every amount × split combination sums to the original');
$neg = 0;
foreach ([0.01, 0.02, 0.03, 0.05, 0.07] as $amt) foreach ([2, 3, 4, 5, 7] as $n) {
    $sh = []; for ($k = 0; $k < $n; $k++) $sh[100 + $k] = round(100 / $n, 2);
    if (min(array_map('floatval', mk_share_amount(number_format($amt, 2, '.', ''), $sh))) < 0) $neg++;
}
ok($neg === 0, 'a few cents split up to seven ways never produces a negative share');
// Dollars as weights: every other figure follows the same proportion.
$w = [30 => 600.0, 21 => 400.0];
ok(mk_share_amount('500.00', $w) === [30 => '300.00', 21 => '200.00'], 'a $500 month divides 60/40 when the budget was shared $600/$400');
ok(mk_share_amount('1000.00', $w) === [30 => '600.00', 21 => '400.00'], 'dividing the base figure by its own shares returns exactly what was typed');
$w3 = [30 => 333.34, 21 => 333.33, 34 => 333.33];
ok(mk_share_amount('1000.00', $w3) === [30 => '333.34', 21 => '333.33', 34 => '333.33'], 'typed thirds come back to the cent');
ok($sum(mk_share_amount('847.19', $w3)) === 847.19, 'an awkward month divided by dollar weights still sums exactly');
ok(mk_share_base_field('per_unit') === 'unit_rate' && mk_share_base_field('one_time') === 'budget' && mk_share_base_field('monthly_flat') === 'budget' && mk_share_base_field(null) === 'budget', 'per-day shares divide the day rate; everything else the budget');
ok(mk_share_percentages($w) === [30 => '60.00', 21 => '40.00'], 'the recorded percentages follow the dollars');
ok(mk_share_percentages([30 => 100.0, 21 => 25.0]) === [30 => '80.00', 21 => '20.00'], 'a $125 day rate shared $100/$25 is recorded as 80/20');

echo "── who is sharing\n";
$a = ['id' => 12, 'intake_id' => 30, 'billing_mode' => 'one_time', 'budget' => '1000.20', 'unit_rate' => null];
$b = ['id' => 13, 'intake_id' => 21, 'billing_mode' => 'one_time', 'budget' => '999.90',  'unit_rate' => null];
$c = ['id' => 14, 'intake_id' => 34, 'billing_mode' => 'one_time', 'budget' => '999.90',  'unit_rate' => null];
ok(array_keys(mk_share_members($b, [$c, $a, $b])) === [21, 30, 34], 'this page\'s copy first, then the others in id order');
ok(array_keys(mk_share_members($a, [])) === [30], 'a placement that is not shared has one member: itself');
$dup = ['id' => 15, 'intake_id' => 21] + $b;
ok(count(mk_share_members($a, [$a, $b, $c, $dup])) === 3 && mk_share_members($a, [$a, $b, $c, $dup])[21]['id'] === 13, 'a second row for the same agent is left out');
ok(mk_share_base($a) === 1000.20 && mk_share_base(['billing_mode' => 'per_unit', 'budget' => '500', 'unit_rate' => '125.00']) === 125.0, 'the base figure is the budget, or the day rate for per-day');
ok(mk_share_base(['billing_mode' => 'one_time', 'budget' => null]) === null && mk_share_base(['billing_mode' => 'one_time', 'budget' => '0.00']) === null, 'no figure entered is null, not zero');
ok(mk_share_signature(mk_share_members($a, [$a, $b, $c])) === mk_share_signature(mk_share_members($c, [$c, $b, $a])), 'the signature is the same from every copy\'s page');
ok(mk_share_signature(mk_share_members($a, [$a, $b, $c])) === '12:1000.20,13:999.90,14:999.90', 'the signature names each copy and its figure');
ok(mk_share_signature(mk_share_members($a, [$a, $b])) !== mk_share_signature(mk_share_members($a, [$a, $b, $c])), 'a copy removed elsewhere changes the signature');

echo "── re-dividing an existing share\n";
$cur = [30 => 1000.20, 21 => 999.90, 34 => 999.90];
$p = mk_share_replan(30, $cur, [30 => 1000.0, 21 => 1000.0, 34 => 1000.0]);
ok(array_keys($p['keep']) === [30, 21, 34] && $p['add'] === [] && $p['remove'] === [], 'same three agents: all kept');
ok(mk_share_scale('1000.20', $p['keep'][30]) === '1000.00' && mk_share_scale('999.90', $p['keep'][21]) === '1000.00', 'each copy\'s figures follow its own new ÷ old');
$p = mk_share_replan(30, $cur, [30 => 1500.0, 21 => 1500.0]);
ok($p['remove'] === [34] && array_keys($p['keep']) === [30, 21], 'an agent left off the lines is removed');
$p = mk_share_replan(30, [30 => 1000.0, 21 => 1000.0], [30 => 750.0, 21 => 750.0, 40 => 500.0]);
ok(array_keys($p['add']) === [40] && abs($p['add'][40] - 0.5) < 1e-9, 'a new agent\'s copy is scaled from this page\'s copy as it was BEFORE the change');
ok(mk_share_scale('200.00', $p['add'][40]) === '100.00' && mk_share_scale('200.00', $p['keep'][30]) === '150.00', 'a $200 month becomes $100 on the new copy and $150 on this one');
$p = mk_share_replan(30, [30 => null, 21 => 500.0], [30 => 400.0, 21 => 600.0, 40 => 200.0]);
ok($p['keep'][30] === null && abs($p['add'][40] - 0.5) < 1e-9, 'a copy with no figure is only given one; a new copy is then scaled against the new figure');
$p = mk_share_replan(30, $cur, [30 => 3000.0]);
ok($p['remove'] === [21, 34] && array_keys($p['keep']) === [30], 'back to one agent: the other copies go');
ok(mk_share_scale(null, 0.5) === null && mk_share_scale('', 0.5) === null, 'scaling leaves "not set" as not set');
ok(mk_share_scale('0.00', 0.5) === '0.00', 'an entered zero stays an entered zero when scaled');
ok(mk_share_scale('123.45', null) === '123.45', 'no ratio, no change');
ok(mk_share_scale('100.00', 1 / 3) === '33.33' && mk_share_scale('0.01', 0.5) === '0.01', 'scaling rounds to the cent');

echo str_repeat('─', 65) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
