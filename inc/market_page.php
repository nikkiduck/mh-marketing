<?php
/**
 * inc/market_page.php — the Market Report as public pages on monthaus.com
 * (Nikki, 2026-11-12: "every location has a webpage that lives at
 * monthaus.com/market-reports/{month}/{location}", generated automatically,
 * from September 2026). Marketing owns the numbers and builds each place's
 * page model here; the site (webroot/market-reports.php there) only fetches
 * api/market_reports.php, caches it and draws. Plan and the board-rules
 * check: site docs/HANDOFF-public-market-reports.md.
 *
 * One place = one card of the Market Snapshot list (market_cards()): a
 * county (CAR's Local Market Update), an Aspen Glenwood town (the MLS's town
 * report; our own feed until the report is out), Denver Metro (REcolorado's
 * Market Watch) or a Communities town computed from our own listing feed.
 * Its public slug is issued HERE and nowhere else: a town's Communities key
 * (`aspen`, `crested-butte`, the key of monthaus.com/communities/{key}), a
 * county's name with -county (`pitkin-county`: Eagle, Ouray and Montrose are
 * each both a county and a town), `denver-metro`.
 *
 * A place carries: `sections` (one per property type: tiles), `summary` (the
 * row other pages' town tables print), `series` (month by month, for the
 * charts: 24 months for a county, from the months on file for a town) and
 * `credits` (the boards' lines; the notice NAR's model rule 13 and the MLS
 * Grid licence require for figures we compile ourselves).
 *
 * Thin months (Nikki, 2026-11-12, "sounds good" to the plan): under
 * MARKET_PAGE_THIN closed sales in the month, the tiles that rest on those
 * sales lead with the year-to-date figure and print the month's beneath it
 * with no percentage change; a town with one sale would read "+200%".
 *
 * Pure: no database, no portal. The caller loads the months (market_load())
 * and attaches the feed (market_attach_feed(), inc/market.php).
 */
require_once __DIR__ . '/market_data.php';
require_once __DIR__ . '/market_feed.php';
require_once __DIR__ . '/market_flyer_render.php';   // market_flyer_signed(), market_flyer_paren()

/**
 * Which sources may appear on the public pages: 'car' (county reports),
 * 'mls_pdf' (Aspen Glenwood town reports), 'metro' (REcolorado Market Watch,
 * PPAR) and 'feed' (figures we compile from the listing feed; cleared with
 * Anyprop, Nikki 2026-11-12). Taking a source out removes its sections, and a
 * place left with none is not published.
 */
const MARKET_PUBLIC_SOURCES = ['car', 'mls_pdf', 'metro', 'feed'];

/** Fewer closed sales than this in the month and the year-to-date figures lead. */
const MARKET_PAGE_THIN = 5;

/** The Communities area a county or metro card sits under in the area menu (the towns carry their own). */
const MARKET_PAGE_AREAS = [
    'pitkin' => 'Roaring Fork Valley', 'garfield' => 'Roaring Fork Valley', 'eagle' => 'Vail Valley',
    'san-miguel' => 'Southwest Colorado', 'montrose' => 'Southwest Colorado', 'ouray' => 'Southwest Colorado', 'la-plata' => 'Southwest Colorado',
    'summit' => 'Summit County', 'routt' => 'Routt County',
    'denver-metro' => 'Front Range', 'denver' => 'Front Range', 'el-paso' => 'Front Range', 'teller' => 'Front Range',
];

function market_page_slugify(string $s): string { return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }

/** "october-2026" for "2026-10". */
function market_page_month_slug(string $ym): string { return strtolower(market_month_name($ym)) . '-' . market_year($ym); }

/** "2026-10" for "october-2026" or "2026-10"; '' when it is neither. */
function market_page_month_from_slug(string $s): string {
    $s = strtolower(trim($s));
    if (preg_match('/^\d{4}-\d{2}$/', $s)) return $s;
    if (preg_match('/^([a-z]+)-(\d{4})$/', $s, $m) && ($t = strtotime('1 ' . $m[1] . ' ' . $m[2])) !== false) return date('Y-m', $t);
    return '';
}

/**
 * The places of a month, in the cards' order: [slug => ['slug', 'key' (card
 * key), 'title', 'kind' county|town|metro, 'source' car|mls_pdf|metro|feed,
 * 'market', 'area', 'def']]. Needs the feed attached for the feed towns.
 */
function market_page_places(array $data): array {
    $out = [];
    foreach (market_cards($data) as $key => $d) {
        if (!empty($d['county']))    { $kind = 'county'; $source = 'car';     $slug = market_page_slugify((string)$d['title']); $area = MARKET_PAGE_AREAS[$key] ?? ''; }
        elseif (!empty($d['town']))  { $kind = 'town';   $source = 'mls_pdf'; $slug = market_page_slugify((string)$d['title']); $area = (string)($d['place'] ?? ''); }
        elseif (!empty($d['feed']))  { $kind = 'town';   $source = 'feed';    $slug = (string)$d['feed'];                        $area = (string)($d['place'] ?? ''); }
        elseif (!empty($d['metro'])) { $kind = 'metro';  $source = 'metro';   $slug = market_page_slugify((string)$d['title']); $area = MARKET_PAGE_AREAS[$key] ?? ''; }
        else continue;
        if ($slug === '' || isset($out[$slug])) continue;   // a slug is issued once; the first card keeps it
        $out[$slug] = ['slug' => $slug, 'key' => $key, 'title' => (string)$d['title'], 'kind' => $kind, 'source' => $source,
                       'market' => (string)($d['market'] ?? ''), 'area' => $area, 'def' => $d];
    }
    return $out;
}

/** The words a month's tiles use. */
function market_page_words(string $m): array {
    $ly = market_month_ly($m);
    return ['month' => market_month_label($m), 'mon' => market_month_name($m), 'ly' => market_month_short($ly), 'yr' => market_year($m), 'lyYr' => market_year($ly),
            'from' => 'January 1, ' . market_year($ly), 'to' => date('F j, Y', (int)strtotime('last day of ' . $m . '-01'))];
}

/** One tile. $tag: a word beside the label when the figure is not the month's ("Year to date"). */
function market_page_tile(string $label, string $value, ?float $chg = null, string $against = '', string $small = '', string $tag = '', ?string $vs = null): array {
    $dir = 0;
    if ($vs === null) {
        $vs = '';
        if ($chg !== null && $against !== '') {
            $s = market_flyer_signed($chg);
            $vs = $s === 'Even' ? 'Even with ' . $against : $s . ' vs ' . $against;
            $dir = market_dir($chg);
        }
    }
    return ['label' => $label, 'value' => $value, 'vs' => $vs, 'dir' => $dir, 'small' => $small, 'tag' => $tag];
}

/** Percentage points between two percentages: "+3.0 pts vs Oct 2025". */
function market_page_pts($now, $ly, string $against): string {
    if ($now === null || $ly === null || $now === '' || $ly === '') return '';
    $d = (float)$now - (float)$ly;
    if (abs($d) < 0.05) return 'Even with ' . $against;
    return ($d > 0 ? '+' : '−') . number_format(abs($d), 1) . ' pts vs ' . $against;
}

function market_page_pct($v): string { return ($v === null || $v === '') ? '–' : number_format((float)$v, 1) . '%'; }
function market_page_has($v): bool { return $v !== null && $v !== ''; }

/**
 * A section from a CAR row (a county's, or an Aspen Glenwood town's: same
 * fields). $feed: the feed place of the same county or town and property
 * type, for the price per square foot CAR does not publish; null for none.
 */
function market_page_car_section(array $r, string $pt, array $w, string $source, ?array $feed = null): ?array {
    if (($r['sold'] ?? null) === null) return null;
    $sold = (int)$r['sold'];
    $thin = $sold < MARKET_PAGE_THIN;
    $ytdOk = market_page_has($r['ytd_sold'] ?? null) && (int)$r['ytd_sold'] > 0;
    if ($thin && !$ytdOk && $sold === 0) return null;   // nothing sold this month or this year: nothing to say
    $lead = $thin && $ytdOk;
    $ly = $w['ly']; $ya = $w['lyYr'] . ' YTD'; $mon = $w['mon'];
    $t = [];
    if ($lead) {
        $t[] = market_page_tile('Closed sales', market_int($r['ytd_sold']), market_change($r['ytd_sold'], $r['ytd_sold_ly']), $ya, $mon . ': ' . market_int($sold), 'Year to date');
        $t[] = market_page_tile('Median price', market_money($r['ytd_median']), market_change($r['ytd_median'], $r['ytd_median_ly']), $ya, $sold ? $mon . ': ' . market_money($r['median']) : '', 'Year to date');
        $t[] = market_page_tile('Average price', market_money($r['ytd_average']), market_change($r['ytd_average'], $r['ytd_average_ly'] ?? null), $ya, $sold ? $mon . ': ' . market_money($r['average']) : '', 'Year to date');
        $t[] = market_page_tile('Days on market', market_int($r['ytd_days_on_market']), market_change($r['ytd_days_on_market'], $r['ytd_days_on_market_ly'] ?? null), $ya, $sold ? $mon . ': ' . market_int($r['days_on_market']) : '', 'Year to date');
        $t[] = market_page_tile('% of list received', market_page_pct($r['ytd_pct_of_list'] ?? null), null, '', $sold ? $mon . ': ' . market_page_pct($r['pct_of_list']) : '', 'Year to date',
                                market_page_pts($r['ytd_pct_of_list'] ?? null, $r['ytd_pct_of_list_ly'] ?? null, $ya));
    } else {
        $t[] = market_page_tile('Closed sales', market_int($sold), market_change($r['sold'], $r['sold_ly']), $ly,
                                market_page_has($r['ytd_sold'] ?? null) ? 'YTD ' . market_int($r['ytd_sold']) . market_flyer_paren(market_change($r['ytd_sold'], $r['ytd_sold_ly'])) : '');
        $t[] = market_page_tile('Median price', market_money($r['median']), market_change($r['median'], $r['median_ly']), $ly,
                                market_page_has($r['ytd_median'] ?? null) ? 'YTD ' . market_money($r['ytd_median']) . market_flyer_paren(market_change($r['ytd_median'], $r['ytd_median_ly'])) : '');
        $t[] = market_page_tile('Average price', market_money($r['average']), market_change($r['average'], $r['average_ly']), $ly,
                                market_page_has($r['ytd_average'] ?? null) ? 'YTD ' . market_money($r['ytd_average']) : '');
        $t[] = market_page_tile('Days on market', market_int($r['days_on_market']), market_change($r['days_on_market'], $r['days_on_market_ly']), $ly,
                                market_page_has($r['ytd_days_on_market'] ?? null) ? 'YTD ' . market_int($r['ytd_days_on_market']) : '');
        $t[] = market_page_tile('% of list received', market_page_pct($r['pct_of_list']), null, '', market_page_has($r['ytd_pct_of_list'] ?? null) ? 'YTD ' . market_page_pct($r['ytd_pct_of_list']) : '', '',
                                market_page_pts($r['pct_of_list'], $r['pct_of_list_ly'], $ly));
    }
    // Price per square foot, from our own feed (CAR does not publish it): only when the feed may be shown.
    if ($feed && in_array('feed', MARKET_PUBLIC_SOURCES, true)) {
        $mo = (array)($feed['month'] ?? []); $fl = (array)($feed['ly'] ?? []); $fy = (array)($feed['ytd'] ?? []); $fyl = (array)($feed['ytd_ly'] ?? []);
        if ($lead && market_page_has($fy['price_per_sqft'] ?? null)) {
            $t[] = market_page_tile('Median price per sq ft', '$' . market_int($fy['price_per_sqft']), market_change($fy['price_per_sqft'], $fyl['price_per_sqft'] ?? null), $ya, '', 'Year to date') + ['source' => 'feed'];
        } elseif (!$lead && !empty($mo['sold']) && market_page_has($mo['price_per_sqft'] ?? null)) {
            $t[] = market_page_tile('Median price per sq ft', '$' . market_int($mo['price_per_sqft']), market_change($mo['price_per_sqft'], $fl['price_per_sqft'] ?? null), $ly,
                                    market_page_has($fy['price_per_sqft'] ?? null) ? 'YTD $' . market_int($fy['price_per_sqft']) : '') + ['source' => 'feed'];
        }
    }
    $t[] = market_page_tile('Homes for sale', market_int($r['inventory']), market_change($r['inventory'], $r['inventory_ly']), $ly);
    $t[] = market_page_tile('Months of supply', market_dec1($r['months_supply']), market_change($r['months_supply'], $r['months_supply_ly']), $ly);
    $t[] = market_page_tile('New listings', market_int($r['new_listings']), market_change($r['new_listings'], $r['new_listings_ly']), $ly,
                            market_page_has($r['ytd_new_listings'] ?? null) ? 'YTD ' . market_int($r['ytd_new_listings']) : '');
    return ['pt' => $pt, 'heading' => market_ptype_words($pt), 'source' => $source, 'sold' => $sold, 'thin' => $lead, 'tiles' => $t,
            'summary' => ['sold' => market_int($sold), 'sold_chg' => $lead ? '' : market_flyer_signed(market_change($r['sold'], $r['sold_ly'])),
                          'median' => $sold ? market_money($r['median']) : '–', 'median_chg' => ($lead || !$sold) ? '' : market_flyer_signed(market_change($r['median'], $r['median_ly'])),
                          'ytd_sold' => market_int($r['ytd_sold'] ?? null), 'ytd_median' => market_money($r['ytd_median'] ?? null),
                          'ytd_chg' => market_flyer_signed(market_change($r['ytd_median'] ?? null, $r['ytd_median_ly'] ?? null)),
                          'inv' => market_int($r['inventory'])],
            'point' => ['sold' => $sold, 'median' => $sold ? $r['median'] : null, 'inventory' => $r['inventory'] ?? null]];
}

/** A section from our own feed's place (a town's or a county's one property type). */
function market_page_feed_section(?array $p, string $pt, array $w): ?array {
    if (!$p) return null;
    $mo = (array)($p['month'] ?? []); $l = (array)($p['ly'] ?? []); $y = (array)($p['ytd'] ?? []); $yl = (array)($p['ytd_ly'] ?? []); $inv = $p['inventory'] ?? null;
    $sold = (int)($mo['sold'] ?? 0); $ytdN = (int)($y['sold'] ?? 0);
    if ($sold === 0 && $ytdN === 0) return null;
    $lead = $sold < MARKET_PAGE_THIN && $ytdN > 0;
    $ly = $w['ly']; $ya = $w['lyYr'] . ' YTD'; $mon = $w['mon'];
    $has = fn($a, $k) => market_page_has($a[$k] ?? null);
    $dom = (($mo['dom_basis'] ?? ($y['dom_basis'] ?? '')) === 'close') ? 'Days to closing' : 'Days on market';   // CREN, elevateMLS and REcolorado send no contract date
    $t = [];
    if ($lead) {
        $t[] = market_page_tile('Closed sales', market_int($ytdN), market_change($ytdN, $yl['sold'] ?? null), $ya, $mon . ': ' . market_int($sold), 'Year to date');
        $t[] = market_page_tile('Median price', market_money($y['median'] ?? null), market_change($y['median'] ?? null, $yl['median'] ?? null), $ya, $sold ? $mon . ': ' . market_money($mo['median'] ?? null) : '', 'Year to date');
        $t[] = market_page_tile('Average price', market_money($y['average'] ?? null), market_change($y['average'] ?? null, $yl['average'] ?? null), $ya, $sold ? $mon . ': ' . market_money($mo['average'] ?? null) : '', 'Year to date');
        if ($has($y, 'price_per_sqft')) $t[] = market_page_tile('Median price per sq ft', '$' . market_int($y['price_per_sqft']), market_change($y['price_per_sqft'], $yl['price_per_sqft'] ?? null), $ya,
                                                                 ($sold && $has($mo, 'price_per_sqft')) ? $mon . ': $' . market_int($mo['price_per_sqft']) : '', 'Year to date');
        if ($has($y, 'avg_dom')) $t[] = market_page_tile($dom, market_int($y['avg_dom']), market_change($y['avg_dom'], $yl['avg_dom'] ?? null), $ya, ($sold && $has($mo, 'avg_dom')) ? $mon . ': ' . market_int($mo['avg_dom']) : '', 'Year to date');
        if ($has($y, 'pct_of_list')) $t[] = market_page_tile('% of list price', market_page_pct($y['pct_of_list']), null, '', ($sold && $has($mo, 'pct_of_list')) ? $mon . ': ' . market_page_pct($mo['pct_of_list']) : '', 'Year to date',
                                                              market_page_pts($y['pct_of_list'], $yl['pct_of_list'] ?? null, $ya));
    } else {
        $t[] = market_page_tile('Closed sales', market_int($sold), market_change($sold, $l['sold'] ?? null), $ly, 'YTD ' . market_int($ytdN) . market_flyer_paren(market_change($ytdN, $yl['sold'] ?? null)));
        $t[] = market_page_tile('Median price', market_money($mo['median'] ?? null), market_change($mo['median'] ?? null, $l['median'] ?? null), $ly,
                                $has($y, 'median') ? 'YTD ' . market_money($y['median']) . market_flyer_paren(market_change($y['median'], $yl['median'] ?? null)) : '');
        $t[] = market_page_tile('Average price', market_money($mo['average'] ?? null), market_change($mo['average'] ?? null, $l['average'] ?? null), $ly, $has($y, 'average') ? 'YTD ' . market_money($y['average']) : '');
        if ($has($mo, 'price_per_sqft')) $t[] = market_page_tile('Median price per sq ft', '$' . market_int($mo['price_per_sqft']), market_change($mo['price_per_sqft'], $l['price_per_sqft'] ?? null), $ly,
                                                                  $has($y, 'price_per_sqft') ? 'YTD $' . market_int($y['price_per_sqft']) : '');
        if ($has($mo, 'avg_dom')) $t[] = market_page_tile($dom, market_int($mo['avg_dom']), market_change($mo['avg_dom'], $l['avg_dom'] ?? null), $ly, $has($y, 'avg_dom') ? 'YTD ' . market_int($y['avg_dom']) : '');
        if ($has($mo, 'pct_of_list')) $t[] = market_page_tile('% of list price', market_page_pct($mo['pct_of_list']), null, '', $has($y, 'pct_of_list') ? 'YTD ' . market_page_pct($y['pct_of_list']) : '', '',
                                                               market_page_pts($mo['pct_of_list'], $l['pct_of_list'] ?? null, $ly));
    }
    $active = is_array($inv) && market_page_has($inv['active'] ?? null) ? $inv['active'] : null;
    if ($active !== null) $t[] = market_page_tile('Homes for sale', market_int($active), null, '', 'At month end');
    if (is_array($inv) && market_page_has($inv['months_supply'] ?? null)) $t[] = market_page_tile('Months of supply', market_dec1($inv['months_supply']));
    return ['pt' => $pt, 'heading' => market_ptype_words($pt), 'source' => 'feed', 'sold' => $sold, 'thin' => $lead, 'tiles' => $t,
            'summary' => ['sold' => market_int($sold), 'sold_chg' => $lead ? '' : market_flyer_signed(market_change($sold, $l['sold'] ?? null)),
                          'median' => $sold ? market_money($mo['median'] ?? null) : '–', 'median_chg' => ($lead || !$sold) ? '' : market_flyer_signed(market_change($mo['median'] ?? null, $l['median'] ?? null)),
                          'ytd_sold' => market_int($ytdN), 'ytd_median' => market_money($y['median'] ?? null),
                          'ytd_chg' => market_flyer_signed(market_change($y['median'] ?? null, $yl['median'] ?? null)),
                          'inv' => $active !== null ? market_int($active) : '–'],
            'point' => ['sold' => $sold, 'median' => $sold ? ($mo['median'] ?? null) : null, 'inventory' => $active]];
}

/** The metro section (REcolorado's Market Watch; PPAR's headline): what the post states, all residential. */
function market_page_metro_section(array $f, array $w): array {
    $ly = $w['ly'];
    $pc = fn($v) => $v === null ? null : (float)$v / 100;
    $t = [];
    $t[] = market_page_tile('Closed sales', market_int($f['closed'] ?? null), $pc($f['closed_change_pct'] ?? null), $ly);
    if (market_page_has($f['average'] ?? null)) $t[] = market_page_tile('Average price', market_money($f['average']), $pc($f['average_change_pct'] ?? null), $ly);
    $t[] = market_page_tile('Median price', market_money($f['median'] ?? null), $pc($f['median_change_pct'] ?? null), $ly);
    if (market_page_has($f['median_days_in_mls'] ?? null)) {
        $d = $f['days_change'] ?? null;
        $vs = $d === null ? '' : ((int)$d === 0 ? 'Same as ' . $ly : abs((int)$d) . ' day' . (abs((int)$d) === 1 ? '' : 's') . ((int)$d < 0 ? ' fewer' : ' more') . ' than ' . $ly);
        $t[] = market_page_tile('Median days in MLS', market_int($f['median_days_in_mls']), null, '', '', '', $vs);
    }
    if (market_page_has($f['avg_days_on_market'] ?? null)) $t[] = market_page_tile('Average days on market', market_int($f['avg_days_on_market']));
    if (market_page_has($f['new_listings'] ?? null)) $t[] = market_page_tile('New listings', market_int($f['new_listings']), $pc($f['new_listings_change_pct'] ?? null), $ly);
    if (market_page_has($f['pending'] ?? null)) $t[] = market_page_tile('Pending sales', market_int($f['pending']), $pc($f['pending_change_pct'] ?? null), $ly);
    if (market_page_has($f['active'] ?? null)) $t[] = market_page_tile('Active listings', market_int($f['active']), $pc($f['active_change_pct'] ?? null), $ly);
    if (market_page_has($f['weeks_of_inventory'] ?? null)) $t[] = market_page_tile('Weeks of inventory', market_int($f['weeks_of_inventory']));
    $chg = fn($v) => $v === null ? '' : market_flyer_signed((float)$v / 100);
    return ['pt' => 'ALL', 'heading' => (string)($f['scope'] ?? 'All residential'), 'source' => 'metro', 'sold' => (int)($f['closed'] ?? 0), 'thin' => false, 'tiles' => $t,
            'summary' => ['sold' => market_int($f['closed'] ?? null), 'sold_chg' => $chg($f['closed_change_pct'] ?? null), 'median' => market_money($f['median'] ?? null),
                          'median_chg' => $chg($f['median_change_pct'] ?? null), 'ytd_sold' => '–', 'ytd_median' => '–', 'ytd_chg' => '', 'inv' => market_int($f['active'] ?? null)],
            'point' => ['sold' => $f['closed'] ?? null, 'median' => $f['median'] ?? null, 'inventory' => $f['active'] ?? null]];
}

/** The feed town a card stands for: a feed card's own, a town report card's by its label. Null for none. */
function market_page_feed_town(array $data, array $def): ?array {
    if (!empty($def['feed'])) return $data['towns_feed'][$def['feed']] ?? null;
    if (!empty($def['town'])) foreach ((array)($data['towns_feed'] ?? []) as $t) if (($t['label'] ?? '') === $def['town']) return $t;
    return null;
}

/**
 * A place's sections for one month, the sources not cleared for the public
 * left out: [] when the month has nothing for it.
 */
function market_page_sections(array $data, array $place): array {
    $def = $place['def']; $w = market_page_words((string)$data['month']); $out = [];
    $pub = fn(string $src) => in_array($src, MARKET_PUBLIC_SOURCES, true);
    if ($place['kind'] === 'county') {
        if (!$pub('car')) return [];
        $fc = $data['counties_feed'][strtolower((string)preg_replace('/\s+County$/i', '', (string)$def['county']))] ?? null;
        foreach (['SF', 'TC'] as $pt) {
            if (($r = market_county_row($data, (string)$def['county'], $pt)) && ($s = market_page_car_section($r, $pt, $w, 'car', $fc[$pt] ?? null))) $out[] = $s;
        }
    } elseif ($place['kind'] === 'metro') {
        if ($pub('metro') && ($f = market_front_row($data, (string)$def['metro']))) $out[] = market_page_metro_section($f, $w);
    } else {
        $ft = market_page_feed_town($data, $def);
        if (!empty($def['town']) && $pub('mls_pdf')) {
            foreach (['SF', 'TC'] as $pt) {
                if (($r = market_town_row($data, (string)$def['town'], $pt)) && ($s = market_page_car_section($r, $pt, $w, 'mls_pdf', $ft[$pt] ?? null))) $out[] = $s;
            }
        }
        if (!$out && $ft && $pub('feed')) {   // a feed town, or a town whose report is not out yet
            foreach (['SF', 'TC'] as $pt) if ($s = market_page_feed_section($ft[$pt] ?? null, $pt, $w)) $out[] = $s;
        }
    }
    return $out;
}

/** The credit lines for the sources a place's sections use. */
function market_page_credits(array $data, array $place, array $sections): array {
    $board = null;
    foreach ($data['boards'] as $b) if (($b['market'] ?? '') === $place['market']) { $board = $b; break; }
    $w = market_page_words((string)$data['month']);
    $used = [];
    foreach ($sections as $s) { $used[$s['source']] = true; foreach ($s['tiles'] as $t) if (($t['source'] ?? '') === 'feed') $used['feed'] = true; }
    $out = [];
    if (!empty($used['car']) && !empty($board['county_credit'])) $out[] = (string)$board['county_credit'];
    if (!empty($used['mls_pdf']) && !empty($board['credit'])) $out[] = (string)$board['credit'];
    if (!empty($used['metro'])) {
        $out[] = $place['market'] === 'recolorado'
            ? "Metro figures from REcolorado Market Watch, {$w['month']}. Data courtesy of REcolorado."
            : "Regional figures from the Pikes Peak REALTOR® Services (elevateMLS) market trends page, {$w['month']}.";
    }
    if (!empty($used['feed'])) {
        // The board the feed figures come from: the town's own (a Lake County town is REcolorado's, whatever the card's market).
        $fm = $place['market'];
        if ($place['kind'] === 'county') { $fc = $data['counties_feed'][strtolower((string)preg_replace('/\s+County$/i', '', (string)$place['def']['county']))] ?? null; if ($fc) $fm = (string)$fc['market']; }
        elseif ($ft = market_page_feed_town($data, $place['def'])) $fm = (string)$ft['market'];
        $fb = null;
        foreach ($data['boards'] as $b) if (($b['market'] ?? '') === $fm) { $fb = $b; break; }
        $mls = (string)($fb['mls_full'] ?? ($fb['mls'] ?? 'the MLS'));
        $what = !empty($used['car']) || !empty($used['mls_pdf']) ? 'Price per square foot compiled' : 'Figures compiled';
        // MLS Grid's licence asks for its own wording (REcolorado); NAR's model rule 13 for the rest.
        $out[] = $fm === 'recolorado'
            ? "{$what} by Mont Haus International Realty. Data courtesy of REcolorado as distributed by MLS GRID as of " . date('F j, Y') . ", for the period {$w['from']} through {$w['to']}."
            : "{$what} by Mont Haus International Realty, based on information from {$mls} for the period {$w['from']} through {$w['to']}.";
    }
    return $out;
}

/** A short source line under a section: who published the figures. */
function market_page_source_label(array $data, array $place, string $source): string {
    $board = null;
    foreach ($data['boards'] as $b) if (($b['market'] ?? '') === $place['market']) { $board = $b; break; }
    $mls = (string)($board['mls'] ?? 'the MLS');
    if ($source === 'car')     return 'Colorado Association of REALTORS® Local Market Update';
    if ($source === 'mls_pdf') return $mls . ' Local Market Update';
    if ($source === 'metro')   return $place['market'] === 'recolorado' ? 'REcolorado Market Watch' : 'Pikes Peak REALTOR® Services';
    $ft = $place['kind'] === 'county' ? null : market_page_feed_town($data, $place['def']);
    if ($ft) foreach ($data['boards'] as $b) if (($b['market'] ?? '') === $ft['market']) { $mls = (string)$b['mls']; break; }
    return 'Mont Haus International Realty, from ' . $mls . ' listing data';
}

/**
 * Everything the site needs for one month: the page model of every place.
 * $history: every month on file up to this one, oldest first, each loaded and
 * with its feed attached (the last is $data's own month); the towns' month by
 * month series come from it.
 */
function market_page_month(array $data, array $history): array {
    $m = (string)$data['month'];
    $places = market_page_places($data);

    // The county a town sits in, by the feed's county for it, when that county has a page of its own.
    $countySlug = [];
    foreach ($places as $slug => $p) if ($p['kind'] === 'county') $countySlug[strtolower((string)preg_replace('/\s+County$/i', '', (string)$p['def']['county']))] = $slug;

    // Month by month, per place and property type, from the months on file.
    $series = [];
    foreach ($history as $h) {
        $hp = market_page_places($h);
        foreach ($hp as $slug => $p) {
            foreach (market_page_sections($h, $p) as $s) $series[$slug][$s['pt']][] = ['m' => (string)$h['month']] + $s['point'];
        }
    }

    $out = [];
    foreach ($places as $slug => $p) {
        $sections = market_page_sections($data, $p);
        if (!$sections) continue;
        $pl = ['slug' => $slug, 'title' => $p['title'], 'kind' => $p['kind'], 'area' => $p['area'], 'market' => $p['market'], 'county' => '', 'county_slug' => '',
               'sections' => [], 'summary' => [], 'series' => [], 'credits' => market_page_credits($data, $p, $sections)];
        if ($p['kind'] === 'county') {
            $pl['county'] = preg_replace('/\s+County$/i', '', (string)$p['def']['county']);
        } elseif ($p['kind'] === 'town' && ($ft = market_page_feed_town($data, $p['def']))) {
            $c = (string)(($ft['SF']['county'] ?? '') ?: ($ft['TC']['county'] ?? ''));
            $pl['county'] = $c;
            $pl['county_slug'] = $countySlug[strtolower($c)] ?? '';
        }
        foreach ($sections as $s) {
            $pl['summary'][$s['pt']] = $s['summary'] + ['source' => $s['source']];
            $pl['sections'][] = ['pt' => $s['pt'], 'heading' => $s['heading'], 'source' => $s['source'], 'source_label' => market_page_source_label($data, $p, $s['source']),
                                 'thin' => $s['thin'], 'sold' => $s['sold'], 'tiles' => $s['tiles']];
            // A county's 24 months come from CAR's trend; everything else from the months on file.
            if ($p['kind'] === 'county') {
                $ptype = $s['pt'] === 'TC' ? 'Townhouse-condo' : 'Single family';
                $rows = array_values(array_filter((array)$data['county_trend'], fn($r) => ($r['county'] ?? '') === $p['def']['county'] && ($r['ptype'] ?? '') === $ptype));
                usort($rows, fn($a, $b) => strcmp((string)$a['month'], (string)$b['month']));
                $pl['series'][$s['pt']] = array_map(fn($r) => ['m' => (string)$r['month'], 'sold' => $r['sold'] ?? null, 'median' => empty($r['sold']) ? null : ($r['median'] ?? null), 'inventory' => $r['inventory'] ?? null], $rows);
            } else {
                $pl['series'][$s['pt']] = $series[$slug][$s['pt']] ?? [];
            }
        }
        $out[$slug] = $pl;
    }

    // The area menu: the Communities areas in the registry's order (the feed towns carry it), then any other.
    $areas = [];
    foreach ((array)($data['towns_feed'] ?? []) as $t) $areas[(string)$t['area']] = [];
    foreach ($out as $slug => $pl) $areas[$pl['area'] !== '' ? $pl['area'] : 'Colorado'][] = $slug;
    $kindOrder = ['metro' => 0, 'county' => 1, 'town' => 2];
    $menu = [];
    foreach ($areas as $name => $slugs) {
        if (!$slugs) continue;
        usort($slugs, fn($a, $b) => [$kindOrder[$out[$a]['kind']], array_search($a, array_keys($out), true)] <=> [$kindOrder[$out[$b]['kind']], array_search($b, array_keys($out), true)]);
        $menu[] = ['name' => $name, 'places' => $slugs];
    }

    $w = market_page_words($m);
    return ['month' => $m, 'slug' => market_page_month_slug($m), 'label' => $w['month'], 'ly' => $w['ly'], 'month_name' => $w['mon'],
            'generated' => (string)($data['meta']['generated'] ?? ''), 'as_of' => (string)($data['meta']['current_as_of'] ?? ''),
            'late' => array_values((array)($data['meta']['late'] ?? [])), 'thin' => MARKET_PAGE_THIN,
            'areas' => $menu, 'places' => $out];
}
