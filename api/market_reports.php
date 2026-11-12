<?php
/**
 * api/market_reports.php — the Market Report for the public pages on
 * monthaus.com (monthaus.com/market-reports/{month}/{place}; Nikki,
 * 2026-11-12). The site fetches this, caches it and draws; every figure and
 * every word of a tile is built here (inc/market_page.php), so the two
 * projects cannot disagree. Read-only, no session.
 *
 *   GET ?index=1      the months on file, newest first, and each month's places:
 *                     { months: [ { month, slug, label, generated, places: [ { slug, title, kind, area } ] } ] }
 *   GET ?m=2026-10    one month (also ?m=october-2026): market_page_month():
 *                     { month, slug, label, ly, generated, as_of, late, thin, areas: [ { name, places: [slug] } ],
 *                       places: { slug: { slug, title, kind, area, market, county, county_slug,
 *                                         sections: [ { pt, heading, source, source_label, thin, sold, tiles: [ { label, value, vs, dir, small, tag } ] } ],
 *                                         summary: { SF|TC|ALL: { sold, sold_chg, median, median_chg, ytd_sold, ytd_median, ytd_chg, inv, source } },
 *                                         series:  { SF|TC|ALL: [ { m, sold, median, inventory } ] },
 *                                         credits: [ ... ] } } }
 *
 * Auth: Authorization: Bearer <ROSTER_FEED_TOKEN> (inc/db.php), the token the
 * site already holds for the roster feed (its MARKETING_FEED_TOKEN).
 *
 *   curl -s -H "Authorization: Bearer $TOKEN" 'https://marketing.monthaus.com/api/market_reports.php?index=1' | head -c 600
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function feed_fail(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$bearer = '';
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($hdr === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $hdr = $v;
}
if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $hdr, $m)) $bearer = $m[1];

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';   // defines ROSTER_FEED_TOKEN

if (!defined('ROSTER_FEED_TOKEN') || strlen((string)ROSTER_FEED_TOKEN) < 24) feed_fail(503, 'feed not configured');
if ($bearer === '' || !hash_equals((string)ROSTER_FEED_TOKEN, $bearer)) feed_fail(401, 'unauthorized');

require_once __DIR__ . '/../inc/market.php';        // market_load(), market_attach_feed()
require_once __DIR__ . '/../inc/market_page.php';   // the page model

$months = market_months();   // newest first
if (!$months) feed_fail(503, 'no market data on file');

/** A month, loaded with its feed towns; null when it does not parse. */
$load = function (string $m): ?array {
    $d = market_load($m);
    if ($d) market_attach_feed($d);
    return $d;
};

if (isset($_GET['index'])) {
    $out = [];
    foreach ($months as $m) {
        if (!($d = $load($m))) continue;
        $places = [];
        foreach (market_page_places($d) as $slug => $p) {
            if (market_page_sections($d, $p)) $places[] = ['slug' => $slug, 'title' => $p['title'], 'kind' => $p['kind'], 'area' => $p['area']];
        }
        $out[] = ['month' => $m, 'slug' => market_page_month_slug($m), 'label' => market_month_label($m),
                  'generated' => (string)($d['meta']['generated'] ?? ''), 'places' => $places];
    }
    echo json_encode(['months' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$want = market_page_month_from_slug((string)($_GET['m'] ?? ''));
if ($want === '') $want = $months[0];
if (!in_array($want, $months, true)) feed_fail(404, 'no such month');

$history = [];
foreach (array_reverse($months) as $m) {   // oldest first, up to the month asked for
    if ($m > $want) break;
    if ($d = $load($m)) $history[] = $d;
}
$data = $history ? end($history) : null;
if (!$data || $data['month'] !== $want) feed_fail(503, 'the month could not be read');

echo json_encode(market_page_month($data, $history), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
