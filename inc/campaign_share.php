<?php
/**
 * inc/campaign_share.php — dividing one advertising placement among several
 * agents (2026-10-05). Pure arithmetic: queries nothing, echoes nothing, so
 * tests/test_campaign_share.php tests the real functions.
 *
 * The rule, the same one order_split.php follows for collateral: every
 * agent's own record carries their own true figure. Sharing a placement
 * scales the source placement to its agent's percentage and writes a copy for
 * each other agent at theirs. Nothing downstream changes, because
 * mh_agent_financials() already reads each placement's own budget, rate and
 * month rows.
 *
 * After the split the copies are INDEPENDENT (Nikki, 2026-10-05): an agent may
 * drop out later, or one may pick up most of the cost, so each copy is edited
 * on its own agent's page. The split percentage is kept only as a record of
 * how the placement was first divided.
 */

/**
 * Validate posted shares. $own is the agent the placement belongs to.
 * $posted = [intake_id => percentage]; blanks and zeros are dropped.
 *
 * Returns [shares, error]: shares = [intake_id => float pct] with $own first,
 * or [] and a message. The percentages must total 100 (to the cent of a
 * percent), the owner must keep a share, and at least one other agent must
 * take one.
 */
function mk_share_validate(int $own, array $posted): array {
    $shares = [];
    foreach ($posted as $iid => $pct) {
        $iid = (int)$iid;
        $pct = is_numeric($pct) ? round((float)$pct, 2) : 0.0;
        if ($iid <= 0 || $pct <= 0) continue;
        if ($pct > 100) return [[], 'A share cannot be more than 100%.'];
        $shares[$iid] = $pct;
    }
    if (!isset($shares[$own])) return [[], 'This agent must keep a share. To hand the whole placement to someone else, share it and then delete this copy.'];
    if (count($shares) < 2)   return [[], 'Choose at least one other agent to share with.'];
    $total = round(array_sum($shares), 2);
    if (abs($total - 100.0) > 0.005) return [[], "The shares add up to {$total}%, not 100%."];
    // Owner first: the owner's record absorbs rounding (see mk_share_amount).
    $ordered = [$own => $shares[$own]];
    foreach ($shares as $iid => $pct) if ($iid !== $own) $ordered[$iid] = $pct;
    return [$ordered, ''];
}

/**
 * Divide one money amount by the shares. NULL (no figure entered) stays NULL
 * for everyone: "not set" must not become "0.00", which means "no charge".
 *
 * Largest-remainder method, in whole cents: everyone gets their share rounded
 * DOWN, then the cents left over go one at a time to whoever was rounded down
 * the most (the owner, listed first, wins a tie). So the parts always add up
 * to the original exactly and nobody can end up below zero, which plain
 * rounding with one residual line could do on a few cents split four ways.
 * Returns [intake_id => 'x.xx' | null].
 */
function mk_share_amount($amount, array $shares): array {
    if ($amount === null || $amount === '') return array_fill_keys(array_keys($shares), null);
    $cents = (int)round((float)$amount * 100);
    $out = []; $frac = []; $used = 0; $i = 0;
    foreach ($shares as $iid => $pct) {
        $exact = $cents * $pct / 100;
        $out[$iid] = (int)floor($exact + 1e-9);
        $frac[$iid] = [$exact - $out[$iid], -$i++];   // bigger remainder first, then earlier in the list
        $used += $out[$iid];
    }
    uasort($frac, fn($a, $b) => $b <=> $a);
    $left = $cents - $used;
    foreach (array_keys($frac) as $iid) { if ($left <= 0) break; $out[$iid]++; $left--; }
    return array_map(fn($c) => number_format($c / 100, 2, ".", ""), $out);
}
