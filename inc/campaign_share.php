<?php
/**
 * inc/campaign_share.php — dividing one advertising placement among several
 * agents (2026-10-05). Pure arithmetic: queries nothing, echoes nothing, so
 * tests/test_campaign_share.php tests the real functions.
 *
 * The rule, the same one order_split.php follows for collateral: every
 * agent's own record carries their own true figure. Sharing a placement
 * scales the source placement to its agent's part and writes an identical
 * copy for each other agent at theirs. Nothing downstream changes, because
 * mh_agent_financials() already reads each placement's own budget, rate and
 * month rows.
 *
 * The Share form is a list of LINES: an agent and a dollar amount each, plus
 * the total they must add up to (Nikki, 2026-10-05: dollars, not percentages;
 * lines, not a wall of checkboxes). The amount is the agent's part of the
 * placement's base figure: the budget, or the day rate for a per-day
 * placement. Each agent's base figure is exactly what was typed.
 *
 * Every copy shows the whole arrangement (the full amount and who pays what),
 * and Share can be reopened from ANY copy to change it: amounts, a new agent,
 * an agent removed. The copies are linked by split_group; split_pct is each
 * one's percentage of the whole, kept for the record only.
 *
 *  - First share: every other figure on the placement (entered months,
 *    who-pays amounts) is divided in proportion to the amounts, by largest
 *    remainder, so the parts add up to the original to the cent.
 *  - Re-dividing: each copy's other figures follow its own base figure up or
 *    down (new ÷ old). Copies may have been edited separately since the first
 *    share, so there is no single original to divide again.
 *
 * Editing a card directly (its budget, a month) still changes only that
 * agent's copy; that is deliberate.
 */

/** Which money column the dollar shares divide: the day rate for per-day, else the budget. */
function mk_share_base_field(?string $billing_mode): string {
    return $billing_mode === 'per_unit' ? 'unit_rate' : 'budget';
}

/** A placement row's base figure in dollars, or null when none has been entered. */
function mk_share_base(array $row): ?float {
    $v = $row[mk_share_base_field($row['billing_mode'] ?? null)] ?? null;
    return is_numeric($v) && (float)$v > 0 ? round((float)$v, 2) : null;
}

/**
 * Everyone sharing a placement: [intake_id => their placement row], this
 * page's own row first, then the other copies in id order. $group_rows is
 * every row carrying the same split_group (it may include $own_row itself).
 * One copy per agent: a second row for the same agent is left out of the
 * arrangement rather than guessed at.
 */
function mk_share_members(array $own_row, array $group_rows): array {
    $members = [(int)$own_row['intake_id'] => $own_row];
    usort($group_rows, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    foreach ($group_rows as $g) {
        $iid = (int)$g['intake_id'];
        if ((int)$g['id'] === (int)$own_row['id'] || isset($members[$iid])) continue;
        $members[$iid] = $g;
    }
    return $members;
}

/**
 * What the Share form was looking at: each copy and its base figure. Posted
 * back with the form; if it no longer matches (a copy was edited or removed in
 * another tab) the save is refused rather than applied to figures nobody saw.
 */
function mk_share_signature(array $members): string {
    $bits = [];
    foreach ($members as $row) $bits[] = (int)$row['id'] . ':' . number_format((float)(mk_share_base($row) ?? 0), 2, '.', '');
    sort($bits);
    return implode(',', $bits);
}

/**
 * Validate the Share form's lines: one per agent, each with a dollar amount,
 * plus the total they must add up to. $agents[i] / $amounts[i] are the posted
 * columns; a line with neither is ignored (an untouched "add agent" row).
 *
 * $first is true when the placement has not been shared yet: then at least one
 * other agent is needed. On a placement that is already shared the lines are
 * the whole arrangement, and it may shrink back to this agent alone.
 *
 * Returns [shares, error]: shares = [intake_id => dollars] with $own first, or
 * [] and a message.
 */
function mk_share_lines_validate(int $own, array $agents, array $amounts, $total, bool $first): array {
    $usd = fn(float $v) => '$' . number_format($v, 2);
    $total = is_numeric($total) ? round((float)$total, 2) : 0.0;
    if ($total <= 0) return [[], 'Enter the total amount being shared.'];
    $shares = [];
    foreach ($agents as $i => $iid) {
        $iid = (int)$iid;
        $raw = trim((string)($amounts[$i] ?? ''));
        if ($iid <= 0 && $raw === '') continue;                       // an empty row
        if ($iid <= 0) return [[], 'Choose an agent for every amount, or remove the line.'];
        $amt = is_numeric($raw) ? round((float)$raw, 2) : 0.0;
        if ($amt <= 0) return [[], $iid === $own
            ? 'This agent must keep a share. To hand the whole placement to someone else, share it and then delete this copy.'
            : 'Enter an amount for every agent listed, or remove the line.'];
        if (isset($shares[$iid])) return [[], 'The same agent is listed twice.'];
        $shares[$iid] = $amt;
    }
    if (!isset($shares[$own])) return [[], 'This agent must keep a share. To hand the whole placement to someone else, share it and then delete this copy.'];
    if ($first && count($shares) < 2) return [[], 'Add at least one other agent to share with.'];
    $sum = round(array_sum($shares), 2);
    if (abs($sum - $total) > 0.005) return [[], 'The amounts add up to ' . $usd($sum) . ', not the ' . $usd($total) . ' total.'];
    // Owner first: the owner wins a rounding tie (see mk_share_amount).
    $ordered = [$own => $shares[$own]];
    foreach ($shares as $iid => $v) if ($iid !== $own) $ordered[$iid] = $v;
    return [$ordered, ''];
}

/**
 * Divide one money amount in proportion to the shares (the dollar amounts act
 * as weights). NULL (no figure entered) stays NULL for everyone: "not set"
 * must not become "0.00", which means "no charge".
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

/**
 * Plan a change to a placement that is ALREADY shared.
 * $current = [intake_id => base figure now, or null] for every copy;
 * $shares  = the new arrangement from mk_share_lines_validate().
 *
 * Returns:
 *   keep   [intake_id => ratio|null]  the copy stays; its other figures are
 *          multiplied by ratio (new ÷ old). null = it had no base figure, so
 *          there is nothing to scale by and only the base figure is set.
 *   add    [intake_id => ratio]       a new copy, made from this page's copy
 *          with its figures multiplied by ratio (the new agent's amount ÷ this
 *          page's amount BEFORE the change, or after it if it had none).
 *   remove [intake_id, ...]           copies to delete.
 */
function mk_share_replan(int $own, array $current, array $shares): array {
    $plan = ['keep' => [], 'add' => [], 'remove' => []];
    $own_old = is_numeric($current[$own] ?? null) && (float)$current[$own] > 0 ? (float)$current[$own] : (float)$shares[$own];
    foreach ($shares as $iid => $amt) {
        if (array_key_exists($iid, $current)) {
            $old = is_numeric($current[$iid]) ? (float)$current[$iid] : 0.0;
            $plan['keep'][$iid] = $old > 0 ? $amt / $old : null;
        } else {
            $plan['add'][$iid] = $amt / $own_old;
        }
    }
    foreach (array_keys($current) as $iid) if (!isset($shares[$iid])) $plan['remove'][] = $iid;
    return $plan;
}

/**
 * One figure multiplied by a ratio, to the cent. NULL stays NULL ("not set"
 * is not "no charge"), and a null ratio leaves the figure as it was.
 */
function mk_share_scale($amount, ?float $ratio): ?string {
    if ($amount === null || $amount === '') return null;
    if ($ratio === null) return number_format((float)$amount, 2, '.', '');
    return number_format(round((float)$amount * $ratio, 2), 2, '.', '');
}
