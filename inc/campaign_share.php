<?php
/**
 * inc/campaign_share.php — dividing one advertising placement among several
 * agents (2026-10-05). Pure arithmetic: queries nothing, echoes nothing, so
 * tests/test_campaign_share.php tests the real functions.
 *
 * The rule, the same one order_split.php follows for collateral: every
 * agent's own record carries their own true figure. Sharing a placement
 * scales the source placement to its agent's part and writes a copy for each
 * other agent at theirs. Nothing downstream changes, because
 * mh_agent_financials() already reads each placement's own budget, rate and
 * month rows.
 *
 * Shares are entered in DOLLARS (Nikki, 2026-10-05: percentages were "weird"):
 * each agent's part of the placement's base figure, which is the budget, or
 * the day rate for a per-day placement. The parts must add up to that figure
 * to the cent, and each agent's base figure is exactly what was typed. Every
 * other amount on the placement (entered months, who-pays amounts) is divided
 * in the same proportion. A placement with no base figure yet falls back to
 * percentages, since there is nothing to divide in dollars.
 *
 * After the split the copies are INDEPENDENT: an agent may drop out later, or
 * one may pick up most of the cost, so each copy is edited on its own agent's
 * page. split_pct is kept only as a record of the first division.
 */

/** Which money column the dollar shares divide: the day rate for per-day, else the budget. */
function mk_share_base_field(?string $billing_mode): string {
    return $billing_mode === 'per_unit' ? 'unit_rate' : 'budget';
}

/**
 * Validate posted shares. $own is the agent the placement belongs to.
 * $posted = [intake_id => number]; blanks and zeros are dropped.
 *
 * $total is what the shares must add up to: the placement's base figure in
 * dollars, or 100 when the shares are percentages ($unit = '%').
 *
 * Returns [shares, error]: shares = [intake_id => float] with $own first, or
 * [] and a message. The owner must keep a share and at least one other agent
 * must take one.
 */
function mk_share_validate(int $own, array $posted, float $total = 100.0, string $unit = '%'): array {
    $fmt = fn(float $v) => $unit === '%' ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . '%' : '$' . number_format($v, 2);
    $shares = [];
    foreach ($posted as $iid => $v) {
        $iid = (int)$iid;
        $v = is_numeric($v) ? round((float)$v, 2) : 0.0;
        if ($iid <= 0 || $v <= 0) continue;
        if ($v > $total + 0.005) return [[], 'A share cannot be more than the whole (' . $fmt($total) . ').'];
        $shares[$iid] = $v;
    }
    if (!isset($shares[$own])) return [[], 'This agent must keep a share. To hand the whole placement to someone else, share it and then delete this copy.'];
    if (count($shares) < 2)   return [[], 'Choose at least one other agent to share with.'];
    $sum = round(array_sum($shares), 2);
    if (abs($sum - round($total, 2)) > 0.005) return [[], 'The shares add up to ' . $fmt($sum) . ', not ' . $fmt($total) . '.'];
    // Owner first: the owner wins a rounding tie (see mk_share_amount).
    $ordered = [$own => $shares[$own]];
    foreach ($shares as $iid => $v) if ($iid !== $own) $ordered[$iid] = $v;
    return [$ordered, ''];
}

/**
 * Divide one money amount in proportion to the shares (weights: dollars or
 * percentages, it makes no difference). NULL (no figure entered) stays NULL
 * for everyone: "not set" must not become "0.00", which means "no charge".
 *
 * Largest-remainder method, in whole cents: everyone gets their part rounded
 * DOWN, then the cents left over go one at a time to whoever was rounded down
 * the most (the owner, listed first, wins a tie). So the parts always add up
 * to the original exactly and nobody can end up below zero, which plain
 * rounding with one residual line could do on a few cents split four ways.
 * Returns [intake_id => 'x.xx' | null].
 */
function mk_share_amount($amount, array $shares): array {
    if ($amount === null || $amount === '') return array_fill_keys(array_keys($shares), null);
    $cents = (int)round((float)$amount * 100);
    $whole = array_sum($shares);
    if ($whole <= 0) return array_fill_keys(array_keys($shares), null);
    $out = []; $frac = []; $used = 0; $i = 0;
    foreach ($shares as $iid => $w) {
        $exact = $cents * $w / $whole;
        $out[$iid] = (int)floor($exact + 1e-9);
        $frac[$iid] = [$exact - $out[$iid], -$i++];   // bigger remainder first, then earlier in the list
        $used += $out[$iid];
    }
    uasort($frac, fn($a, $b) => $b <=> $a);
    $left = $cents - $used;
    foreach (array_keys($frac) as $iid) { if ($left <= 0) break; $out[$iid]++; $left--; }
    return array_map(fn($c) => number_format($c / 100, 2, '.', ''), $out);
}

/**
 * Each share as a percentage of the whole, to two decimals, for the record
 * (split_pct). Display only; nothing is computed from it afterwards.
 */
function mk_share_percentages(array $shares): array {
    $whole = array_sum($shares);
    return array_map(fn($w) => $whole > 0 ? number_format($w / $whole * 100, 2, '.', '') : '0.00', $shares);
}
