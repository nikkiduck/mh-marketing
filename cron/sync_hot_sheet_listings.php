<?php
/**
 * cron/sync_hot_sheet_listings.php — Mont Haus listings from site.monthaus.com
 * → hs_listing_state, with every difference logged to hs_listing_changes.
 *
 * Replaces the hub's hot-sheets/sync_listings.php (Spark, Aspen + Vail only).
 * This portal never talks to Anyprop for listings: the public site does, and
 * serves Mont Haus listings at api/listings.php?scope=mh. See
 * docs/HOT_SHEETS_PLAN.md.
 *
 * For each listing in the feed, compared with what we saw last time:
 *   · never seen             → new_listing        (NOT on the very first run: see --baseline)
 *   · status differs         → status, plus closed when it became Closed/Sold
 *   · list price differs     → price
 * A listing we had that the feed stopped returning (an agent left, or it aged
 * past the site's 30-day window) → in_feed = 0, and a `removed` row only if it
 * was still active when last seen. Nothing is ever deleted.
 *
 * Every transition is logged, one row each. The hub skipped a status change
 * when an un-notified one already existed, which is how a week of
 * Active → Pending → Closed lost its sale. Deciding what to show is the email's job.
 *
 * Usage:
 *   php /var/www/marketing.monthaus.com/cron/sync_hot_sheet_listings.php --dry-run
 *   php /var/www/marketing.monthaus.com/cron/sync_hot_sheet_listings.php
 *   php /var/www/marketing.monthaus.com/cron/sync_hot_sheet_listings.php --fixture=/path/feed.json
 *
 * The first run on an empty table records a baseline and logs no changes, or
 * every existing listing would announce itself as new. --baseline forces that.
 *
 * Requires: hot_sheets_v1.sql; LISTINGS_FEED_URL (inc/config.php);
 * LISTINGS_FEED_TOKEN (inc/db.php).
 * Cron, when ready (written in words, never inside a block comment):
 *   minute 20, every 2nd hour → php .../cron/sync_hot_sheet_listings.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';

$args     = array_slice($argv, 1);
$dry      = in_array('--dry-run', $args, true);
$baseline = in_array('--baseline', $args, true);
$fixture  = null;
foreach ($args as $a) if (preg_match('/^--fixture=(.+)$/', $a, $m)) $fixture = $m[1];

$now = gmdate('Y-m-d H:i:s');
function out(string $s): void { global $now; echo "[{$now}] {$s}" . PHP_EOL; }

out('Hot Sheet listing sync' . ($dry ? ' — DRY RUN, nothing will be written' : '') . ($fixture ? " — fixture {$fixture}" : ''));

$chk = $conn->query("SHOW TABLES LIKE 'hs_listing_changes'");
if (!$chk || !$chk->fetch_row()) { out('✗ sql/hot_sheets_v1.sql has not been run. Nothing done.'); exit(1); }

// ── Fetch ────────────────────────────────────────────────────────────────────
if ($fixture) {
    $body = (string)@file_get_contents($fixture);
    $code = $body === '' ? 0 : 200; $err = null;
} else {
    if (!defined('LISTINGS_FEED_URL') || !defined('LISTINGS_FEED_TOKEN') || LISTINGS_FEED_TOKEN === '') {
        out('✗ LISTINGS_FEED_URL (inc/config.php) / LISTINGS_FEED_TOKEN (inc/db.php) not set. Nothing done.'); exit(1);
    }
    $ch = curl_init(LISTINGS_FEED_URL . '?scope=mh');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . LISTINGS_FEED_TOKEN, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_ENCODING => '',
    ]);
    $body = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch) ?: null; curl_close($ch);
}
$feed = json_decode($body, true);
if ($err || $code !== 200 || !is_array($feed['listings'] ?? null)) {
    out("✗ Feed fetch failed (HTTP {$code}" . ($err ? ", {$err}" : '') . '): ' . substr($body, 0, 300) . ' — nothing written.');
    exit(1);
}
$listings = $feed['listings'];
if (!$listings) { out('✗ Feed returned zero listings — refusing to mark everything removed. Nothing written.'); exit(1); }
out('Feed generated ' . ($feed['generated_at'] ?? '?') . ': ' . count($listings) . ' Mont Haus listing(s).'
    . (empty($feed['final_status_tracking']) ? ' (site has not run ap_close_fields.sql: sales arrive as Off Market, with no price)' : ''));

// ── Load state ───────────────────────────────────────────────────────────────
$state = [];
$res = $conn->query("SELECT * FROM hs_listing_state");
foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $state[$r['market'] . '|' . $r['listing_key']] = $r;
if (!$state && !$baseline) { $baseline = true; out('Empty state table: this run records a baseline and logs no changes.'); }

const HS_ACTIVE = ['Active', 'Active Under Contract', 'Pending', 'Coming Soon'];
function is_closed(string $s): bool { return in_array(strtolower($s), ['closed', 'sold', 'leased'], true); }
function money($v): string { return $v === null ? '' : number_format((float)$v, 2, '.', ''); }

// The Hot Sheet area (sql/hot_sheets_v4_areas.sql, 2026-10-07): the feed
// stamps every listing with the Communities area its town or board belongs
// to; stored as sent. Until the migration has run the columns are absent and
// hs_data.php derives the area from the city instead.
$chk = $conn->query("SHOW COLUMNS FROM hs_listing_state LIKE 'area_key'");
$has_area = $chk && $chk->fetch_row();
if (!$has_area) out('~ hs_listing_state has no area_key column yet (sql/hot_sheets_v4_areas.sql): areas not stored this run.');

$up = $conn->prepare("
    INSERT INTO hs_listing_state
        (market, listing_key, mls_id, status, is_rental, property_type, address, city, subdivision,
         list_price, close_price, close_date, beds, baths, sqft, primary_photo, url,
         list_agent_mls_id, list_agent_name, colist_agent_mls_id, colist_agent_name, list_office_name,
         in_feed, first_seen_at, last_seen_at" . ($has_area ? ', area_key, area' : '') . ")
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?" . ($has_area ? ',?,?' : '') . ")
    ON DUPLICATE KEY UPDATE
        mls_id=VALUES(mls_id), status=VALUES(status), is_rental=VALUES(is_rental), property_type=VALUES(property_type),
        address=VALUES(address), city=VALUES(city), subdivision=VALUES(subdivision),
        list_price=VALUES(list_price), close_price=VALUES(close_price), close_date=VALUES(close_date),
        beds=VALUES(beds), baths=VALUES(baths), sqft=VALUES(sqft), primary_photo=VALUES(primary_photo), url=VALUES(url),
        list_agent_mls_id=VALUES(list_agent_mls_id), list_agent_name=VALUES(list_agent_name),
        colist_agent_mls_id=VALUES(colist_agent_mls_id), colist_agent_name=VALUES(colist_agent_name),
        list_office_name=VALUES(list_office_name), in_feed=1, last_seen_at=VALUES(last_seen_at)"
        . ($has_area ? ', area_key=VALUES(area_key), area=VALUES(area)' : ''));
$log = $conn->prepare("INSERT INTO hs_listing_changes (market, listing_key, change_type, old_value, new_value, detected_at)
                       VALUES (?,?,?,?,?,?)");
if (!$up || !$log) { out('✗ prepare failed: ' . $conn->error); exit(1); }

$stats = ['new' => 0, 'carried' => 0, 'status' => 0, 'closed' => 0, 'price' => 0, 'removed' => 0, 'unchanged' => 0];
$seen = [];

$conn->begin_transaction();
try {
    foreach ($listings as $l) {
        $mk = (string)($l['market'] ?? ''); $key = (string)($l['listing_key'] ?? '');
        if ($mk === '' || $key === '') continue;
        $id = "{$mk}|{$key}";
        $seen[$id] = true;
        $old = $state[$id] ?? null;
        $label = trim(($l['address'] ?? '') . ', ' . ($l['city'] ?? ''), ', ') . " ({$mk} #" . ($l['mls_id'] ?? '') . ')';
        $changes = [];

        if (!$old) {
            // First time we see it. New to the MARKET, or just new to us? A board
            // going live (Vail, Elevate on 2026-10-01) delivered 16 long-listed
            // homes at once and every one went out as "New Listing". The feed
            // now carries the board's on-market date: older than 7 days means
            // the listing predates us and is carried in silently.
            $on = (string)($l['on_market_date'] ?? '');
            $carried = $on !== '' && strtotime($on) < strtotime('-7 days');
            if (!$baseline && !$carried) $changes[] = ['new_listing', null, (string)$l['status']];
            elseif ($carried) { $stats['carried']++; echo "    ~ {$label} carried in, on market since {$on}\n"; }
        } else {
            if ((string)$old['status'] !== (string)$l['status']) {
                $changes[] = ['status', $old['status'], (string)$l['status']];
                if (is_closed((string)$l['status']) && !is_closed((string)$old['status'])) {
                    $changes[] = ['closed', money($old['list_price']), money($l['close_price'] ?? null)];
                }
            }
            if (money($old['list_price']) !== money($l['list_price'] ?? null) && ($l['list_price'] ?? null) !== null) {
                $changes[] = ['price', money($old['list_price']), money($l['list_price'])];
            }
            if (!(int)$old['in_feed'] && !$changes) {
                // Back after dropping out (e.g. relisted): worth announcing.
                $changes[] = ['new_listing', 'returned', (string)$l['status']];
            }
        }

        if (!$changes && $old) $stats['unchanged']++;
        foreach ($changes as [$type, $ov, $nv]) {
            $stats[$type === 'new_listing' ? 'new' : $type]++;
            echo "    {$type}: {$label}" . ($ov !== null || $nv !== null ? "  {$ov} → {$nv}" : '') . "\n";
            if (!$dry) { $log->bind_param('ssssss', $mk, $key, $type, $ov, $nv, $now); $log->execute(); }
        }
        if ($dry) continue;

        $mls = (string)($l['mls_id'] ?? ''); $st = (string)($l['status'] ?? ''); $rent = !empty($l['is_rental']) ? 1 : 0;
        $pt = (string)($l['property_type'] ?? ''); $ad = (string)($l['address'] ?? ''); $ci = (string)($l['city'] ?? '');
        $sd = (string)($l['subdivision'] ?? ''); $lp = $l['list_price'] ?? null; $cp = $l['close_price'] ?? null;
        $cd = $l['close_date'] ?? null; $bd = $l['beds'] ?? null; $ba = $l['baths'] ?? null;
        $sq = isset($l['sqft']) ? (int)$l['sqft'] : null; $ph = (string)($l['primary_photo'] ?? ''); $url = (string)($l['url'] ?? '');
        $la = (string)($l['list_agent_mls_id'] ?? ''); $ln = (string)($l['list_agent_name'] ?? '');
        $ca = (string)($l['colist_agent_mls_id'] ?? ''); $cn = (string)($l['colist_agent_name'] ?? '');
        $of = (string)($l['list_office_name'] ?? '');
        $first = $old['first_seen_at'] ?? $now;
        if ($has_area) {
            $ak = (string)($l['area_key'] ?? ''); $an = (string)($l['area'] ?? '');
            // 26 values: 4 s, rental i, 4 s, 5 numeric-or-null as s, sqft i, 9 s, area 2 s. Count them.
            $up->bind_param('ssss' . 'i' . 'ssss' . 'sssss' . 'i' . 'sssssssss' . 'ss',
                $mk, $key, $mls, $st, $rent, $pt, $ad, $ci, $sd, $lp, $cp, $cd, $bd, $ba, $sq, $ph, $url,
                $la, $ln, $ca, $cn, $of, $first, $now, $ak, $an);
        } else {
            // 24 values: 4 s, rental i, 4 s, 5 numeric-or-null as s, sqft i, 9 s. Count them.
            $up->bind_param('ssss' . 'i' . 'ssss' . 'sssss' . 'i' . 'sssssssss',
                $mk, $key, $mls, $st, $rent, $pt, $ad, $ci, $sd, $lp, $cp, $cd, $bd, $ba, $sq, $ph, $url,
                $la, $ln, $ca, $cn, $of, $first, $now);
        }
        $up->execute();
    }

    // Listings we had that the feed no longer returns.
    $rm = $conn->prepare("UPDATE hs_listing_state SET in_feed = 0 WHERE market = ? AND listing_key = ?");
    foreach ($state as $id => $old) {
        if (isset($seen[$id]) || !(int)$old['in_feed']) continue;
        [$mk, $key] = explode('|', $id, 2);
        $was_active = in_array($old['status'], HS_ACTIVE, true);
        if ($was_active && !$baseline) {
            $stats['removed']++;
            echo "    removed: {$old['address']}, {$old['city']} ({$mk} #{$old['mls_id']}) was {$old['status']}\n";
            if (!$dry) { $t = 'removed'; $ov = $old['status']; $nv = null; $log->bind_param('ssssss', $mk, $key, $t, $ov, $nv, $now); $log->execute(); }
        }
        if (!$dry) { $rm->bind_param('ss', $mk, $key); $rm->execute(); }
    }
    $dry ? $conn->rollback() : $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    out('✗ ' . $e->getMessage() . ' — rolled back, nothing written.');
    exit(1);
}

out(($dry ? '✓ DRY RUN: ' : '✓ Done: ') . ($baseline ? '(baseline) ' : '')
    . "{$stats['new']} new, {$stats['carried']} carried in, {$stats['status']} status, {$stats['closed']} closed, {$stats['price']} price, "
    . "{$stats['removed']} removed, {$stats['unchanged']} unchanged.");
$conn->close();
