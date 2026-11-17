<?php
/**
 * inc/collateral_shipping.php — one shipment, entered once (Nikki, 2026-11-16).
 *
 * "If two pieces of collateral are linked ... I shouldn't have to update the
 * shipping status on both. Changing the Yard Signs to Shipped and adding a
 * tracking number there should also update the Open House signs so he knows
 * they're both en route ... Ditto when I mark one delivered."
 *
 * Linked = the "Billed with" group on one agent: the order holding the cost
 * and every order pointing at it (marketing_collateral_orders
 * .billed_with_order_id; no chains, agent.php offers only orders that point
 * at nothing). One invoice is one box, the same assumption order_split.php
 * makes for an invoice split across agents. So the four shipping fields
 * (MK_SHIP_FIELDS) are the same on every order of a group:
 *
 *   · saving any order of a group copies its four fields to the others;
 *   · an order that is being linked in THIS save takes the group's fields
 *     instead: a new, blank order must not reset a shipment already on its
 *     way to "pending".
 *
 * The values are stored on every order, so every reader (the Collateral tab,
 * the agent portal, the accounting email) is right without knowing about
 * groups. If a combined purchase ever arrives in two boxes, unlink the order.
 *
 * Also here: the tracking link made from an explicit Carrier choice. A
 * tracking number alone opened a web search ("a broken link because I didn't
 * enter the full Fedex URL. Silly."). The carrier is PICKED on the form, never
 * worked out from what the number looks like (no guessing, 2026-10-22); what
 * is stored is the link, in tracking_url, so there is no new column.
 */

/** The fields a shipment's orders share. */
const MK_SHIP_FIELDS = ['status', 'tracking_number', 'tracking_url', 'delivered_at'];

/** Carrier key => [name, tracking address with %s for the number]. */
function mk_tracking_carriers(): array {
    return [
        'fedex' => ['FedEx', 'https://www.fedex.com/fedextrack/?trknbr=%s'],
        'ups'   => ['UPS',   'https://www.ups.com/track?tracknum=%s'],
        'usps'  => ['USPS',  'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s'],
    ];
}

/** The tracking link for a carrier and a number; '' when either is missing or the carrier is not one of ours. */
function mk_tracking_link(string $carrier, string $number): string {
    $n = preg_replace('/\s+/', '', $number);
    $c = mk_tracking_carriers()[$carrier] ?? null;
    return ($c && $n !== '') ? sprintf($c[1], rawurlencode($n)) : '';
}

/**
 * Which carrier the form should show for a stored order: the one whose link
 * for this number IS the stored link (so it was made by mk_tracking_link()),
 * else '' ("link as entered"), which leaves a pasted link alone on the next save.
 */
function mk_tracking_carrier_of(?string $url, ?string $number): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    foreach (mk_tracking_carriers() as $k => $_) if (mk_tracking_link($k, (string)$number) === $url) return $k;
    return '';
}

/** The order an order is billed with (0 = none), read before a save so the save can tell a new link from an old one. */
function mk_coll_billed_with(mysqli $conn, int $intake, int $oid): int {
    $s = $conn->prepare("SELECT billed_with_order_id FROM marketing_collateral_orders WHERE id = ? AND intake_id = ? LIMIT 1");
    if (!$s) return 0;
    $s->bind_param('ii', $oid, $intake); $s->execute();
    $r = $s->get_result()->fetch_row(); $s->close();
    return (int)($r[0] ?? 0);
}

/**
 * After an order has been saved: make its group agree on the shipping fields.
 * $link_was is what mk_coll_billed_with() said before the save.
 * Returns how many OTHER orders were changed (0 when the order is on its own).
 */
function mk_coll_share_shipping(mysqli $conn, int $intake, int $oid, int $link_was): int {
    $s = $conn->prepare("SELECT billed_with_order_id, status, tracking_number, tracking_url, delivered_at
                           FROM marketing_collateral_orders WHERE id = ? AND intake_id = ? LIMIT 1");
    if (!$s) return 0;
    $s->bind_param('ii', $oid, $intake); $s->execute();
    $o = $s->get_result()->fetch_assoc(); $s->close();
    if (!$o) return 0;
    $parent = (int)($o['billed_with_order_id'] ?? 0);

    if ($parent > 0 && $parent !== $link_was) {
        // Linked in this save: this order joins a shipment, so it takes the shipment's fields.
        $u = $conn->prepare("UPDATE marketing_collateral_orders c
                               JOIN marketing_collateral_orders p ON p.id = c.billed_with_order_id AND p.intake_id = c.intake_id
                                SET c.status = p.status, c.tracking_number = p.tracking_number,
                                    c.tracking_url = p.tracking_url, c.delivered_at = p.delivered_at
                              WHERE c.id = ? AND c.intake_id = ?");
        if ($u) { $u->bind_param('ii', $oid, $intake); $u->execute(); $u->close(); }
        return 0;
    }

    // Otherwise what was just saved is the news: copy it to the rest of the group.
    $root = $parent > 0 ? $parent : $oid;
    $u = $conn->prepare("UPDATE marketing_collateral_orders
                            SET status = ?, tracking_number = ?, tracking_url = ?, delivered_at = ?
                          WHERE intake_id = ? AND id <> ? AND (id = ? OR billed_with_order_id = ?)");
    if (!$u) return 0;
    $u->bind_param('ssssiiii', $o['status'], $o['tracking_number'], $o['tracking_url'], $o['delivered_at'], $intake, $oid, $root, $root);
    $u->execute();
    $n = (int)$u->affected_rows;   // rows whose values actually changed
    $u->close();
    return $n;
}

/**
 * The other orders of an order's group, from the agent's orders already loaded
 * ($all: id => row). [] when the order is on its own.
 */
function mk_coll_group_others(array $all, int $oid): array {
    if (!isset($all[$oid])) return [];
    $root = (int)($all[$oid]['billed_with_order_id'] ?? 0) ?: $oid;
    $out = [];
    foreach ($all as $id => $r) {
        if ((int)$id === $oid) continue;
        if ((int)$id === $root || (int)($r['billed_with_order_id'] ?? 0) === $root) $out[(int)$id] = $r;
    }
    return $out;
}
