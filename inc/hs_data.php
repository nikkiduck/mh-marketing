<?php
/**
 * inc/hs_data.php — assembles the rows the Hot Sheet email renders.
 *
 * Replaces monthausint.com/hot-sheets/templates/hot_sheet_data.php. Sources:
 *   hs_listing_state / hs_listing_changes  (cron/sync_hot_sheet_listings.php)
 *   hs_manual_listings / _changes          (pocket listings + buyer reps)
 *   agent_mls_ids + marketing_intakes      (broker names and mailto links)
 * Queries only through the $conn it is handed; echoes nothing.
 *
 * What changed from the hub, on purpose:
 *   · "Latest Updates" is a WINDOW, not a notified_at flag. It runs from the
 *     most recent Monday 00:00 Mountain time (on a Monday, the one before):
 *     the same span the hub's Monday clearing produced, but computed, so
 *     nothing has to be marked and a missed or doubled send cannot lose or
 *     repeat a week. hs_update_window() is the one definition.
 *   · The change log keeps every transition, so a week of Active → Pending →
 *     Closed shows as Closed. Per listing the card takes the most telling
 *     change in the window: closed, then new listing, then status, then price.
 *   · Brokers come from MLS identities, not name strings: a list / co-list
 *     agent whose board id is on agent_mls_ids shows under their roster name
 *     with a mailto link, a team alias expanding to every member.
 */

require_once __DIR__ . '/hs_helpers.php';
require_once __DIR__ . '/agent_roster.php';   // mk_team_sql(): teams are not people

const HS_SOLD_WINDOW_DAYS = 14;
const HS_ACTIVE_STATUSES  = ['Active', 'Active Under Contract', 'Pending', 'Coming Soon'];

/**
 * [utc_start 'Y-m-d H:i:s', label 'n/j/Y'] for Latest Updates.
 * Most recent Monday 00:00 America/Denver; on a Monday, the previous one.
 */
function hs_update_window(?DateTimeImmutable $now = null): array {
    $tz    = new DateTimeZone('America/Denver');
    $now   = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);
    $n     = (int)$now->format('N');
    $start = $now->setTime(0, 0)->modify('-' . ($n === 1 ? 7 : $n - 1) . ' days');
    return [$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $start->format('n/j/Y')];
}

// Same catchments as the hub: when one property is on two boards, keep the
// copy from the board its town belongs to.
function hs_city_market_map(): array {
    $m = [];
    foreach (['aspen','snowmass village','old snowmass','woody creek','basalt','carbondale','redstone','marble',
              'glenwood springs','new castle','silt','rifle','parachute','battlement mesa'] as $c) $m[$c] = 'aspen';
    foreach (['vail','avon','beaver creek','bachelor gulch','arrowhead','edwards','singletree','cordillera','eagle',
              'eagle-vail','gypsum','minturn','red cliff','wolcott','mccoy','bond'] as $c) $m[$c] = 'vail';
    return $m;
}

function hs_norm_address(string $a): string {
    $a = strtolower(trim($a));
    $a = str_replace(['#', ',', '.'], ' ', $a);
    $map = [
        '/\bnortheast\b/' => 'ne', '/\bnorthwest\b/' => 'nw', '/\bsoutheast\b/' => 'se', '/\bsouthwest\b/' => 'sw',
        '/\bnorth\b/' => 'n', '/\bsouth\b/' => 's', '/\beast\b/' => 'e', '/\bwest\b/' => 'w',
        '/\bdrive\b/' => 'dr', '/\bstreet\b/' => 'st', '/\bavenue\b/' => 'ave', '/\broad\b/' => 'rd',
        '/\bboulevard\b/' => 'blvd', '/\blane\b/' => 'ln', '/\bcourt\b/' => 'ct', '/\bcircle\b/' => 'cir',
        '/\bplace\b/' => 'pl', '/\btrail\b/' => 'trl', '/\bterrace\b/' => 'ter', '/\bparkway\b/' => 'pkwy',
        '/\b(unit|apt|apartment|suite|ste)\b/' => ' ',
    ];
    $a = preg_replace(array_keys($map), array_values($map), $a);
    return trim(preg_replace('/\s+/', ' ', $a));
}

/** Street number + first non-directional word: "123 main". */
function hs_fuzzy_key(string $norm): string {
    $p = preg_split('/\s+/', $norm);
    if (count($p) < 2 || !ctype_digit($p[0])) return '';
    foreach (array_slice($p, 1) as $w) {
        if (!in_array($w, ['n','s','e','w','ne','nw','se','sw'], true)) return $p[0] . ' ' . $w;
    }
    return '';
}

/**
 * One copy per property per sale/rental. Keyed on sale-vs-rental AND address,
 * never address alone: the hub learned that a home offered for sale and for
 * rent at once is two genuine listings. Canonical board first, then a copy
 * with a photo.
 */
function hs_dedup(array $rows): array {
    $map = hs_city_market_map();
    $groups = [];
    foreach ($rows as $r) {
        $groups[((int)$r['is_rental']) . '|' . hs_norm_address($r['address']) . '|' . strtolower(trim($r['city']))][] = $r;
    }
    $out = [];
    foreach ($groups as $g) {
        if (count($g) > 1) {
            $canon = $map[strtolower(trim($g[0]['city']))] ?? null;
            usort($g, fn($a, $b) => [($b['market'] === $canon), ($b['primary_photo'] !== '')]
                                <=> [($a['market'] === $canon), ($a['primary_photo'] !== '')]);
        }
        $out[] = $g[0];
    }
    return $out;
}

/**
 * Roster lookups, built once per request.
 *   by_id['market|mls_id'] → [[name, email], ...]   (a team alias gives several)
 *   by_name[lower name]    → email                   (for manual listings' agent_names)
 */
function hs_roster(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['by_id' => [], 'by_name' => []];
    $r = $conn->query("SELECT a.market, a.mls_agent_id,
                              COALESCE(NULLIF(TRIM(mi.agent_name),''), mi.mls_full_name) AS name,
                              COALESCE(NULLIF(TRIM(mi.mh_email),''), mi.mls_email) AS email
                         FROM agent_mls_ids a JOIN marketing_intakes mi ON mi.id = a.intake_id
                        WHERE a.member_status = 'Active' AND mi.is_active = 1" . mk_team_sql($conn, 'mi') . "
                        ORDER BY a.is_alias, name");
    if ($r) foreach ($r->fetch_all(MYSQLI_ASSOC) as $x) {
        if (trim((string)$x['name']) === '') continue;
        $cache['by_id'][$x['market'] . '|' . $x['mls_agent_id']][] = [trim($x['name']), strtolower(trim((string)$x['email']))];
    }
    $r = $conn->query("SELECT agent_name, mls_full_name, COALESCE(NULLIF(TRIM(mh_email),''), mls_email) AS email
                         FROM marketing_intakes WHERE is_active = 1" . mk_team_sql($conn));
    if ($r) foreach ($r->fetch_all(MYSQLI_ASSOC) as $x) {
        $em = strtolower(trim((string)$x['email']));
        if ($em === '') continue;
        foreach ([$x['agent_name'], $x['mls_full_name']] as $n) {
            $k = strtolower(trim((string)$n));
            if ($k !== '' && !isset($cache['by_name'][$k])) $cache['by_name'][$k] = $em;
        }
    }
    return $cache;
}

/** [names Mont-Haus-first, name => email] for one listing's list + co-list agents. */
function hs_brokers(mysqli $conn, array $l): array {
    $ro = hs_roster($conn);
    $mh = []; $other = []; $emails = [];
    foreach ([['list_agent_mls_id', 'list_agent_name'], ['colist_agent_mls_id', 'colist_agent_name']] as [$idc, $nc]) {
        $id   = trim((string)($l[$idc] ?? ''));
        $name = trim((string)($l[$nc] ?? ''));
        $hit  = $id !== '' ? ($ro['by_id'][$l['market'] . '|' . $id] ?? null) : null;
        if ($hit) {
            foreach ($hit as [$n, $e]) { $mh[$n] = true; if ($e !== '') $emails[$n] = $e; }
        } elseif ($name !== '') {
            $other[$name] = true;
        }
    }
    return [array_values(array_unique(array_merge(array_keys($mh), array_keys($other)))), $emails];
}

function hs_status_color(string $s): string {
    return ['active' => 'green', 'pending' => 'blue', 'active under contract' => 'orange', 'coming soon' => 'green',
            'closed' => 'orange', 'sold' => 'orange'][strtolower(trim($s))] ?? 'gray';
}

function hs_is_closed(string $s): bool { return in_array(strtolower(trim($s)), ['closed', 'sold', 'leased'], true); }

function hs_badge_for_change(string $type, string $new): array {
    if ($type === 'closed')      return ['Closed', 'orange'];
    if ($type === 'new_listing') return ['New Listing', 'orange'];
    if ($type === 'price')       return ['New Price', 'blue'];
    if ($type === 'status')      return hs_is_closed($new) ? ['Closed', 'orange'] : [$new, 'blue'];
    return [null, null];
}

function hs_mls_row(mysqli $conn, array $l): array {
    [$names, $emails] = hs_brokers($conn, $l);
    return [
        'address'           => $l['address'],
        'city'              => $l['city'],
        'state_abbr'        => 'CO',
        'postal_code'       => '',
        'price'             => $l['list_price']  !== null ? (float)$l['list_price']  : null,
        'close_price'       => $l['close_price'] !== null ? (float)$l['close_price'] : null,
        // The template keys the Sold Price line off exactly 'Closed'.
        'status'            => hs_is_closed($l['status']) ? 'Closed' : $l['status'],
        'status_color'      => hs_status_color($l['status']),
        'price_color'       => null,
        'brokers'           => $names,
        'broker_emails'     => $emails,
        'primary_photo_url' => $l['primary_photo'] !== '' ? $l['primary_photo'] : null,
        'url'               => $l['url'] !== '' ? $l['url'] : null,
        'badge_label'       => null,
        'badge_color'       => null,
    ];
}

/** Manual listing → row. Photo and city borrowed from a matching MLS listing when missing. */
function hs_manual_row(array $ml, array $addr_idx, array $by_name, ?array $badge): array {
    $norm  = hs_norm_address((string)$ml['address']);
    $fk    = hs_fuzzy_key($norm);
    $match = $addr_idx['exact'][$norm] ?? ($fk !== '' ? ($addr_idx['fuzzy'][$fk] ?? null) : null);
    $names = array_values(array_filter(array_map('trim', explode(',', (string)$ml['agent_names']))));
    $emails = [];
    foreach ($names as $n) if (isset($by_name[strtolower($n)])) $emails[$n] = $by_name[strtolower($n)];
    $photo = trim((string)$ml['primary_photo_url']) ?: (string)($match['primary_photo'] ?? '');
    // Pocket listings are off market: nothing to link to.
    $url   = $ml['entry_type'] === 'pocket_listing' ? '' : trim((string)$ml['listing_url']);
    if ($url === '' && $ml['entry_type'] === 'buyer_rep' && $match) $url = (string)$match['url'];
    $buyer = $ml['entry_type'] === 'buyer_rep';
    return [
        'address'           => (string)$ml['address'],
        'city'              => trim((string)$ml['city']) ?: (string)($match['city'] ?? ''),
        'state_abbr'        => (string)($ml['state_abbr'] ?: 'CO'),
        'postal_code'       => (string)$ml['postal_code'],
        'price'             => $ml['price']       !== null ? (float)$ml['price']       : null,
        'close_price'       => $ml['close_price'] !== null ? (float)$ml['close_price'] : null,
        'status'            => $ml['status'],
        'status_color'      => hs_status_color($ml['status']),
        'price_color'       => null,
        'brokers'           => $names,
        'broker_emails'     => $emails,
        'primary_photo_url' => $photo !== '' ? $photo : null,
        'url'               => $url !== '' ? $url : null,
        'hide_link'         => $url === '',
        'badge_label'       => $badge[0] ?? null,
        'badge_color'       => $badge[1] ?? null,
        'type_badge_label'  => $buyer ? 'Buyer Rep' : 'Pocket Listing',
        'type_badge_color'  => $buyer ? 'orange' : 'purple',
    ];
}

function hs_manual_badge(string $status): ?array {
    if ($status === 'Pending') return ['Pending', 'blue'];
    if ($status === 'Closed')  return ['Closed', 'orange'];
    return null;
}

/** Everything render_hot_sheet_email() needs. $now is injectable for tests. */
function build_hs_data(mysqli $conn, ?DateTimeImmutable $now = null): array {
    [$since, $since_label] = hs_update_window($now);
    $sold_since = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))
                    ->modify('-' . HS_SOLD_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');

    // ── Latest Updates: the most telling change per listing in the window ───
    $s = $conn->prepare("SELECT c.change_type, c.new_value, c.detected_at, st.*
                           FROM hs_listing_changes c
                           JOIN hs_listing_state st ON st.market = c.market AND st.listing_key = c.listing_key
                          WHERE c.detected_at >= ? AND c.change_type <> 'removed'
                          ORDER BY c.detected_at DESC, c.id DESC");
    $s->bind_param('s', $since);
    $s->execute();
    $prio = ['closed' => 0, 'new_listing' => 1, 'status' => 2, 'price' => 3];
    $best = [];
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        $k = $c['market'] . '|' . $c['listing_key'];
        if (!isset($best[$k]) || $prio[$c['change_type']] < $prio[$best[$k]['change_type']]) $best[$k] = $c;
    }
    $s->close();

    $badge_by_key = []; $price_changed = [];
    $sale_updates = []; $rental_updates = [];
    foreach (hs_dedup(array_values($best)) as $c) {
        $k = $c['market'] . '|' . $c['listing_key'];
        if ((int)$c['is_rental'] && $c['change_type'] !== 'new_listing') continue;   // rentals: new only, as on the hub
        $row = hs_mls_row($conn, $c);
        [$row['badge_label'], $row['badge_color']] = hs_badge_for_change($c['change_type'], (string)$c['new_value']);
        if ($c['change_type'] === 'price') { $row['price_color'] = 'blue'; $price_changed[$k] = true; }
        $badge_by_key[$k] = [$row['badge_label'], $row['badge_color']];
        if ((int)$c['is_rental']) $rental_updates[] = $row; else $sale_updates[] = $row;
    }

    // ── Inventory: active-ish, plus sold in the last 14 days ────────────────
    $in = "'" . implode("','", HS_ACTIVE_STATUSES) . "'";
    $s = $conn->prepare("SELECT st.* FROM hs_listing_state st
                          WHERE st.in_feed = 1
                            AND (st.status IN ({$in})
                                 OR EXISTS (SELECT 1 FROM hs_listing_changes c
                                             WHERE c.market = st.market AND c.listing_key = st.listing_key
                                               AND c.change_type = 'closed' AND c.detected_at >= ?))
                          ORDER BY st.list_price DESC");
    $s->bind_param('s', $sold_since);
    $s->execute();
    $inventory = hs_dedup($s->get_result()->fetch_all(MYSQLI_ASSOC));
    $s->close();

    $mls_listings = []; $sale_listings = []; $rental_listings = [];
    foreach ($inventory as $l) {
        $k      = $l['market'] . '|' . $l['listing_key'];
        $closed = hs_is_closed($l['status']);
        $row    = hs_mls_row($conn, $l);
        if ($closed)                                        [$row['badge_label'], $row['badge_color']] = ['Sold', 'orange'];
        elseif (strcasecmp($l['status'], 'Pending') === 0)  [$row['badge_label'], $row['badge_color']] = ['Pending', 'blue'];
        elseif (isset($badge_by_key[$k]))                   [$row['badge_label'], $row['badge_color']] = $badge_by_key[$k];
        if (isset($price_changed[$k])) $row['price_color'] = 'blue';
        if ((int)$l['is_rental']) {
            if (!$closed) $rental_listings[] = $row;           // rentals have no sold section
        } else {
            $mls_listings[] = $row;
            if (!$closed) $sale_listings[] = $row;
        }
    }

    // ── Manual entries: pocket listings and buyer representation ────────────
    $addr_idx = ['exact' => [], 'fuzzy' => []];
    $r = $conn->query("SELECT address, city, primary_photo, url FROM hs_listing_state WHERE address <> ''
                        ORDER BY (primary_photo = '') ASC, last_seen_at DESC");
    foreach ($r->fetch_all(MYSQLI_ASSOC) as $x) {
        $n = hs_norm_address($x['address']);
        if ($n === '') continue;
        $addr_idx['exact'][$n] ??= $x;
        if (($fk = hs_fuzzy_key($n)) !== '') $addr_idx['fuzzy'][$fk] ??= $x;
    }
    $by_name = hs_roster($conn)['by_name'];

    $s = $conn->prepare("SELECT c.change_type, c.new_value, m.* FROM hs_manual_listing_changes c
                           JOIN hs_manual_listings m ON m.id = c.manual_listing_id
                          WHERE c.detected_at >= ? AND m.is_active = 1
                          ORDER BY c.detected_at DESC, c.id DESC");
    $s->bind_param('s', $since);
    $s->execute();
    $mprio = ['new_listing' => 0, 'status' => 1, 'price' => 2];
    $mbest = [];
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        $k = (int)$c['id'];
        if (!isset($mbest[$k]) || $mprio[$c['change_type']] < $mprio[$mbest[$k]['change_type']]) $mbest[$k] = $c;
    }
    $s->close();
    foreach ($mbest as $c) {
        // Buyer representation is announced only once under contract, as on the hub.
        if ($c['entry_type'] === 'buyer_rep' && $c['status'] === 'Active') continue;
        $sale_updates[] = hs_manual_row($c, $addr_idx, $by_name, hs_manual_badge($c['status']));
    }

    $s = $conn->prepare("SELECT m.* FROM hs_manual_listings m
                          WHERE m.is_active = 1
                            AND (m.status IN ('Active','Pending')
                                 OR (m.status = 'Closed' AND EXISTS (
                                        SELECT 1 FROM hs_manual_listing_changes c
                                         WHERE c.manual_listing_id = m.id AND c.change_type = 'status'
                                           AND c.new_value = 'Closed' AND c.detected_at >= ?)))
                          ORDER BY m.created_at DESC");
    $s->bind_param('s', $sold_since);
    $s->execute();
    $pocket = []; $buyer = [];
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $m) {
        $row = hs_manual_row($m, $addr_idx, $by_name, hs_manual_badge($m['status']));
        if ($m['entry_type'] === 'buyer_rep') { if ($m['status'] !== 'Active') $buyer[] = $row; }
        else $pocket[] = $row;
    }
    $s->close();

    return [
        'since_label'     => $since_label,
        'sale_updates'    => $sale_updates,
        'sale_listings'   => $sale_listings,
        'rental_updates'  => $rental_updates,
        'rental_listings' => $rental_listings,
        'mls_listings'    => $mls_listings,
        'pocket_listings' => $pocket,
        'buyer_rep'       => $buyer,
    ];
}
