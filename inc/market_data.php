<?php
/**
 * inc/market_data.php — the Monthly Market Report data layer (2026-10-18):
 * loading a month, the words for months and numbers, and the snapshot card
 * model. Standalone on purpose (no portal.php, no database), so
 * tests/render_market_sample.php can render every output from the data files
 * alone; inc/market.php adds the parts that need the portal.
 *
 * The tool was built into the portal from Nikki's Claude artifacts (JB owns
 * the report; the artifacts were hers)
 * (/Users/nikkiduck/Mont Haus Master/mont-haus-market-report: its CLAUDE.md
 * holds the sources, the credit lines and the monthly refresh procedure;
 * docs/HANDOFF-market-report.md here repeats what this code needs).
 *
 * Two outputs from one set of data:
 *   · the report: portal/market.php (My Tools > Market Report) and
 *     market_report.php (the admin side) both draw inc/market_report_body.php,
 *     which embeds the month's data as window.MH_MARKET for
 *     assets/js/market-report.js (tabs, tables, tiles, plain SVG charts);
 *   · the snapshot posts: portal/market_post.php and market_snapshot.php draw
 *     inc/market_post_body.php, a builder with a live preview;
 *     portal/market_render.php returns the post as HTML and
 *     portal/market_export.php screenshots the same HTML as a PNG (the
 *     Instagram tool's Chromium call, social_export_png()). ONE render path:
 *     market_card_render() in inc/market_card_render.php.
 *
 * The data: inc/market_data/<YYYY-MM>/ holds one month's five JSON files
 * (boards, county, county_trend, towns, front_range) plus meta.json. The
 * newest folder is the report; ?m=YYYY-MM opens an older one. A monthly
 * refresh is a new folder, committed and deployed; no database table and no
 * SQL. Field names are the artifact's and the JavaScript reads them by name:
 * keep them as they are (docs/HANDOFF-market-report.md).
 *
 * Rules that every output follows (the artifact's CLAUDE.md, section 3): no
 * em dashes; every figure credits its board; a thin market (fewer than 10
 * closed sales in the month) showed year-to-date figures on the card until
 * 2026-10-19 (Nikki: the month, however few; the county card is the steadier
 * number), the YTD pools remain for the report; percent
 * changes are recomputed from this year and last year, one decimal, a
 * trailing .0 dropped (+30%, −4.3%); "% of list" changes are points.
 */
require_once __DIR__ . '/market_feed.php';   // town figures from our own feed (standalone too)

const MARKET_DATA_DIR = __DIR__ . '/market_data';

// ── Months and loading ───────────────────────────────────────────────────────

/** The months on file, newest first: ['2026-09', ...]. A folder counts when it has meta.json and county.json. */
function market_months(): array {
    $out = [];
    foreach (glob(MARKET_DATA_DIR . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]') ?: [] as $dir) {
        $m = basename($dir);
        if (is_file("$dir/meta.json") && is_file("$dir/county.json")) $out[] = $m;
    }
    rsort($out);
    return $out;
}

/** A month from the request (?m=), when it is on file; else the newest. '' when nothing is on file. */
function market_pick_month(?string $want): string {
    $months = market_months();
    if (!$months) return '';
    $want = trim((string)$want);
    return ($want !== '' && in_array($want, $months, true)) ? $want : $months[0];
}

/**
 * One month's data: ['month', 'meta', 'boards', 'county', 'county_trend',
 * 'towns', 'front_range', 'months' (every month on file)]. Null when the
 * folder is missing or a file does not parse.
 */
function market_load(string $month): ?array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) return null;
    $dir = MARKET_DATA_DIR . '/' . $month;
    if (!is_dir($dir)) return null;
    $out = ['month' => $month, 'months' => market_months()];
    foreach (['meta', 'boards', 'county', 'county_trend', 'towns', 'front_range'] as $set) {
        $f = "$dir/$set.json";
        $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        if (!is_array($j)) {
            if ($set === 'towns' || $set === 'front_range') { $j = []; }   // a month may have neither
            else return null;
        }
        $out[$set] = $j;
    }
    usort($out['boards'], fn($a, $b) => ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)));
    return $out;
}

// ── Words for months and numbers ─────────────────────────────────────────────

/** "September 2026" from "2026-09". */
function market_month_label(string $ym): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m', $ym);
    return $d ? $d->format('F Y') : $ym;
}
/** "Sep 2026". */
function market_month_short(string $ym): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m', $ym);
    return $d ? $d->format('M Y') : $ym;
}
/** "September". */
function market_month_name(string $ym): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m', $ym);
    return $d ? $d->format('F') : $ym;
}
/** The same month a year earlier: "2025-09". */
function market_month_ly(string $ym): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m', $ym);
    return $d ? $d->modify('-1 year')->format('Y-m') : $ym;
}
/** "2026". */
function market_year(string $ym): string { return substr($ym, 0, 4); }

/** "$8.45M", "$1.90M", "$514,500"; "–" for none. $long: always full dollars. */
function market_money($v, bool $long = false): string {
    if ($v === null || $v === '' || !is_numeric($v)) return '–';
    $v = (float)$v;
    if (!$long && $v >= 1e7) return '$' . number_format($v / 1e6, 1) . 'M';
    if (!$long && $v >= 1e6) return '$' . number_format($v / 1e6, 2) . 'M';
    return '$' . number_format($v);
}

/** "2,898"; "–" for none. */
function market_int($v): string {
    return ($v === null || $v === '' || !is_numeric($v)) ? '–' : number_format((float)$v);
}

/** "14.9"; "–" for none. */
function market_dec1($v): string {
    return ($v === null || $v === '' || !is_numeric($v)) ? '–' : number_format((float)$v, 1);
}

/** (this − last) / last, or null when either is missing or last is 0. */
function market_change($now, $ly): ?float {
    if ($now === null || $ly === null || !is_numeric($now) || !is_numeric($ly) || (float)$ly == 0.0) return null;
    return ((float)$now - (float)$ly) / (float)$ly;
}

/**
 * "+19%", "−4.3%" (U+2212 minus), "Even" for no change; '' for null. One
 * decimal, a trailing .0 dropped (the artifact's rule 5).
 */
function market_change_text(?float $f): string {
    if ($f === null) return '';
    $p = round(abs($f) * 100, 1);
    if ($p == 0.0) return 'Even';
    $s = rtrim(rtrim(number_format($p, 1), '0'), '.');
    return ($f > 0 ? '+' : '−') . $s . '%';
}

/** A whole-percent change as the metro reports give it: "+2%", "−18%", "Even"; '' for null. */
function market_whole_pct_text($n): string {
    if ($n === null || $n === '' || !is_numeric($n)) return '';
    $n = (float)$n;
    if ($n == 0.0) return 'Even';
    return ($n > 0 ? '+' : '−') . rtrim(rtrim(number_format(abs($n), 1), '0'), '.') . '%';
}

/** "+1.2 pts", "−0.5 pts"; '' for null. */
function market_pts_text(?float $pts): string {
    if ($pts === null) return '';
    $p = round(abs($pts), 1);
    if ($p == 0.0) return 'Even';
    return ($pts > 0 ? '+' : '−') . number_format($p, 1) . ' pts';
}

/** 1, −1 or 0 for a change; 0 for null. */
function market_dir(?float $f): int { return $f === null ? 0 : ($f > 0.0005 ? 1 : ($f < -0.0005 ? -1 : 0)); }

// ── The report page's payload ────────────────────────────────────────────────

/** The month's data plus the words the page needs, for window.MH_MARKET. $urls: post (the snapshot builder), self (this page). */
function market_boot(array $data, array $urls): array {
    $m  = $data['month'];
    $tr = array_column($data['county_trend'], 'month');
    return [
        'month'      => $m,
        'label'      => market_month_label($m),            // September 2026
        'name'       => market_month_name($m),             // September
        'short'      => market_month_short($m),            // Sep 2026
        'ly_short'   => market_month_short(market_month_ly($m)),   // Sep 2025
        'ly_label'   => market_month_label(market_month_ly($m)),
        'ly_year'    => market_year(market_month_ly($m)),
        'trend_from' => $tr ? market_month_short(min($tr)) : '',
        'trend_to'   => $tr ? market_month_short(max($tr)) : '',
        'as_of'      => (string)($data['meta']['current_as_of'] ?? ''),
        'months'     => array_map(fn($x) => ['m' => $x, 'label' => market_month_label($x)], $data['months']),
        'urls'       => $urls,
        'boards'     => $data['boards'],
        'county'     => $data['county'],
        'trend'      => $data['county_trend'],
        'towns'      => $data['towns'],
        'front'      => $data['front_range'],
        'feed_towns' => array_values((array)($data['towns_feed'] ?? [])),   // the Communities towns from our own feed (inc/market_feed.php)
        'feed_note'  => (string)($data['feed_note'] ?? ''),
    ];
}

// ── Snapshot posts ───────────────────────────────────────────────────────────

/**
 * One card per PLACE (Nikki, 2026-10-18, after the first version's seven
 * market cards: "one city or county at a time", Summit and Steamboat apart):
 * every county in county.json, every Aspen Glenwood MLS town in towns.json,
 * and the Denver Metro figures from front_range.json. Keyed by a slug the
 * builder posts back. market: the board it belongs to (the picker groups
 * by it, and the report's "Snapshot post for this market" link opens the
 * market's first card). photo: the suggested stock backdrop (assets/stock).
 * place: the towns under the title; NOT drawn since 2026-10-19 (Nikki: one
 * location name on the card, no town names beneath it), kept for the record.
 */
function market_card_defs(): array {
    return [
        // Aspen Glenwood MLS: the two counties, then the eight town reports (the site's Roaring Fork Valley)
        'pitkin'          => ['title' => 'Pitkin County',    'place' => 'Aspen, Snowmass Village',          'county' => 'Pitkin County',     'market' => 'aspen',      'photo' => 'aspen-aerial-fall-1110909378'],
        'garfield'        => ['title' => 'Garfield County',  'place' => 'Glenwood Springs, Carbondale',     'county' => 'Garfield County',   'market' => 'aspen',      'photo' => 'carbondale-mount-sopris-ranch-summer-1698598412'],
        'town-aspen'      => ['title' => 'Aspen',            'place' => 'Roaring Fork Valley', 'town' => 'Aspen',            'market' => 'aspen', 'photo' => 'aspen-buttermilk-fall-snow-542481942'],
        'town-snowmass'   => ['title' => 'Snowmass Village', 'place' => 'Roaring Fork Valley', 'town' => 'Snowmass Village', 'market' => 'aspen', 'photo' => 'snowmass-ski-slopes-winter-panorama-410667845'],
        'town-woody-creek'=> ['title' => 'Woody Creek',      'place' => 'Roaring Fork Valley', 'town' => 'Woody Creek',      'market' => 'aspen', 'photo' => 'aspen-aerial-fall-1110909378'],
        'town-old-snowmass' => ['title' => 'Old Snowmass',   'place' => 'Roaring Fork Valley', 'town' => 'Old Snowmass',     'market' => 'aspen', 'photo' => 'snowmass-ski-slopes-winter-panorama-410667845'],
        'town-basalt'     => ['title' => 'Basalt',           'place' => 'Roaring Fork Valley', 'town' => 'Basalt',           'market' => 'aspen', 'photo' => 'colorado-fall-mountain-highway-379603669'],
        'town-missouri-heights' => ['title' => 'Missouri Heights', 'place' => 'Roaring Fork Valley', 'town' => 'Missouri Heights', 'market' => 'aspen', 'photo' => 'colorado-fall-mountain-highway-379603669'],
        'town-carbondale' => ['title' => 'Carbondale',       'place' => 'Roaring Fork Valley', 'town' => 'Carbondale',       'market' => 'aspen', 'photo' => 'carbondale-mount-sopris-ranch-summer-1698598412'],
        'town-glenwood'   => ['title' => 'Glenwood Springs', 'place' => 'Roaring Fork Valley', 'town' => 'Glenwood Springs', 'market' => 'aspen', 'photo' => 'colorado-fall-mountain-highway-379603669'],
        // Vail MLS
        'eagle'           => ['title' => 'Eagle County',     'place' => 'Vail, Beaver Creek, Avon, Edwards', 'county' => 'Eagle County',     'market' => 'vail',       'photo' => 'vail-aspens-fall-1746156502'],
        // Telluride MLS
        'san-miguel'      => ['title' => 'San Miguel County', 'place' => 'Telluride, Mountain Village',      'county' => 'San Miguel County', 'market' => 'telluride',  'photo' => 'telluride-aerial-main-street-fall-1111462754'],
        // CREN
        'montrose'        => ['title' => 'Montrose County',  'place' => 'Montrose',                          'county' => 'Montrose County',   'market' => 'cren',       'photo' => 'ridgway-mount-sneffels-summer-198784289'],
        'ouray'           => ['title' => 'Ouray County',     'place' => 'Ridgway, Ouray',                    'county' => 'Ouray County',      'market' => 'cren',       'photo' => 'ouray-town-aerial-summer-sunset-384322006'],
        'la-plata'        => ['title' => 'La Plata County',  'place' => 'Durango',                           'county' => 'La Plata County',   'market' => 'cren',       'photo' => 'durango-silverton-train-fall-519473031'],
        // Altitude MLS: Summit and Routt apart (Nikki, 2026-10-18; the site's Communities split them 2026-10-08)
        'summit'          => ['title' => 'Summit County',    'place' => 'Breckenridge, Keystone, Frisco',    'county' => 'Summit County',     'market' => 'altitude',   'photo' => 'breckenridge-town-winter-dusk-382409211'],
        'routt'           => ['title' => 'Routt County',     'place' => 'Steamboat Springs',                 'county' => 'Routt County',      'market' => 'routt',      'photo' => 'steamboat-ski-resort-winter-503720128'],
        // REcolorado
        'denver-metro'    => ['title' => 'Denver Metro',     'place' => '11-county metro · All residential', 'metro' => 'recolorado',         'market' => 'recolorado', 'photo' => 'denver-union-station-skyline-sunrise-438466179'],
        'denver'          => ['title' => 'Denver County',    'place' => 'Denver',                            'county' => 'Denver County',     'market' => 'recolorado', 'photo' => 'denver-union-station-skyline-sunrise-438466179'],
        // elevateMLS
        'el-paso'         => ['title' => 'El Paso County',   'place' => 'Colorado Springs',                  'county' => 'El Paso County',    'market' => 'elevate',    'photo' => 'colorado-springs-aerial-dusk-373849837'],
        'teller'          => ['title' => 'Teller County',    'place' => 'Woodland Park, Cripple Creek',      'county' => 'Teller County',     'market' => 'elevate',    'photo' => 'colorado-springs-garden-of-the-gods-sunset-518817778'],
    ];
}

/**
 * The location under a stock photo's thumbnail on the builder (Nikki,
 * 2026-10-18: thumbnails with the location, not a dropdown of captions):
 * from the file name's first words; the caption's first phrase otherwise.
 */
function market_stock_location(string $name, string $caption): string {
    static $map = ['aspen-snowmass' => 'Aspen Snowmass', 'aspen-highlands' => 'Aspen Highlands', 'aspen' => 'Aspen', 'snowmass' => 'Snowmass',
        'carbondale' => 'Carbondale', 'glenwood-springs' => 'Glenwood Springs', 'independence-pass' => 'Independence Pass', 'telluride' => 'Telluride', 'vail' => 'Vail',
        'beaver-creek' => 'Beaver Creek', 'steamboat' => 'Steamboat Springs', 'breckenridge' => 'Breckenridge', 'arapahoe-basin' => 'Arapahoe Basin',
        'dillon-silverthorne' => 'Dillon & Silverthorne', 'crested-butte' => 'Crested Butte', 'ridgway' => 'Ridgway', 'ouray' => 'Ouray', 'durango' => 'Durango',
        'last-dollar-road' => 'Ridgway to Telluride', 'denver' => 'Denver', 'red-rocks' => 'Red Rocks', 'golden' => 'Golden', 'boulder' => 'Boulder',
        'fort-collins' => 'Fort Collins', 'colorado-springs' => 'Colorado Springs', 'colorado' => 'Colorado', 'mountain-highway' => 'Colorado',
        'smoky-mountains' => 'Smoky Mountains', 'sawtooth' => 'Sawtooth Mountains', 'athabasca' => 'Alberta', 'swiss-alps' => 'Swiss Alps',
        'zermatt' => 'Zermatt', 'bavaria' => 'Bavaria', 'gondola' => 'Alpine', 'chairlift' => 'Alpine', 'alpine' => 'Alpine'];
    foreach ($map as $prefix => $loc) if (str_starts_with($name, $prefix . '-') || $name === $prefix) return $loc;
    $first = trim(explode(',', $caption)[0]);
    return $first !== '' ? $first : $name;
}
/** Every card for a month: the static defs plus the feed towns (market_feed_card_defs(); none until market_attach_feed() ran). */
function market_cards(array $data): array {
    $static = market_card_defs();
    $covered = array_values(array_filter(array_map(fn($d) => $d['town'] ?? null, $static)));
    return $static + market_feed_card_defs($data, $covered);
}

/** The card key for a request: a card key as is, a market id (the report's links) to its first card, else the first card. */
function market_card_key(string $want, array $data = []): string {
    $defs = $data ? market_cards($data) : market_card_defs();
    if (isset($defs[$want])) return $want;
    foreach ($defs as $k => $d) if ($d['market'] === $want) return $k;
    return array_key_first($defs);
}

/** The two looks, as the Instagram posts have them. */
function market_card_themes(): array { return ['dark' => 'Dark', 'light' => 'Light']; }

/** The two sizes: a 4:5 post (Instagram portrait, 1080 x 1350) and a 9:16 story (1080 x 1920). */
function market_card_formats(): array { return ['post' => 'Post · 4:5', 'story' => 'Story · 9:16']; }

/** The property type the card leads with. */
function market_card_ptypes(): array { return ['SF' => 'Single-family homes', 'TC' => 'Townhomes & condos']; }

/** A county row from county.json by county and property type key (SF | TC); null when the month has none. */
function market_county_row(array $data, string $county, string $pt): ?array {
    $short = preg_replace('/\s+County$/', '', $county);
    foreach ($data['county'] as $r) if (($r['key'] ?? '') === "$short|$pt") return $r;
    return null;
}

/** A town row from towns.json (the Aspen Glenwood MLS town reports), or null. */
function market_town_row(array $data, string $town, string $pt): ?array {
    foreach ($data['towns'] as $r) if (($r['key'] ?? '') === "$town|$pt") return $r;
    return null;
}

/** The front_range.json row for a market, or null. */
function market_front_row(array $data, string $market): ?array {
    foreach ($data['front_range'] as $r) if (($r['market'] ?? '') === $market) return $r;
    return null;
}

/** Fewer than 10 closed sales in the month: show year to date (the artifact's rule 4). */
function market_thin(?array $r): bool { return $r !== null && (int)($r['sold'] ?? 0) < 10; }

/** "Single-family homes" / "Townhomes & condos". */
function market_ptype_words(string $pt): string { return $pt === 'TC' ? 'Townhomes & condos' : 'Single-family homes'; }

/**
 * The figures a card can show under the average, in builder order: key =>
 * the picker's words. Each source offers a subset (`pool_keys` on the model);
 * the agent picks three (Nikki, 2026-10-18), defaults fill the rest.
 */
function market_stat_catalog(): array {
    return [
        'sold'      => 'Closed sales',
        'dom'       => 'Avg days on market',
        'ppsf'      => 'Median price/SqFt',   // short, so the label stays on one line on the card (Nikki, 2026-10-19)
        'inventory' => 'Homes for sale',
        'supply'    => 'Months of supply',
        'pct'       => '% of list price',
        'median'    => 'Median sale price',   // a row since 2026-10-20: the average leads the card (Jon's decision, via Nikki)
        'new'       => 'New listings',
    ];
}

/** "+19% vs September 2025", "Even with …", '' for none. */
function market_vs(?float $f, string $when): string {
    if ($f === null) return '';
    $t = market_change_text($f);
    return $t === 'Even' ? "Even with {$when}" : "{$t} vs {$when}";
}

/**
 * The pool for a CAR county or town row: key => [label, value, note], the
 * year-to-date variants when the month is thin. $w: the month words.
 */
function market_pool_car(array $r, array $w, bool $thin): array {
    $pool = [];
    $n = fn($v) => $v !== null && $v !== '';
    if ($thin) {
        $ly = $w['lyYr'];
        if ($n($r['ytd_sold'] ?? null))           $pool['sold']    = ['Closed sales, YTD', market_int($r['ytd_sold']), $n($r['ytd_sold_ly'] ?? null) ? market_int($r['ytd_sold_ly']) . " in {$ly}" : ''];
        if ($n($r['ytd_days_on_market'] ?? null)) $pool['dom']     = ['Avg days on market, YTD', market_int($r['ytd_days_on_market']), $n($r['ytd_days_on_market_ly'] ?? null) ? market_int($r['ytd_days_on_market_ly']) . " in {$ly}" : ''];
        if ($n($r['ytd_pct_of_list'] ?? null))    $pool['pct']     = ['% of list received, YTD', market_dec1($r['ytd_pct_of_list']) . '%', $n($r['ytd_pct_of_list_ly'] ?? null) ? market_dec1($r['ytd_pct_of_list_ly']) . "% in {$ly}" : ''];
        if ($n($r['ytd_median'] ?? null))         $pool['median']  = ['Median sale price, YTD', market_money($r['ytd_median']), market_vs(market_change($r['ytd_median'], $r['ytd_median_ly'] ?? null), "the same period of {$ly}")];
        if ($n($r['ytd_new_listings'] ?? null))   $pool['new']     = ['New listings, YTD', market_int($r['ytd_new_listings']), $n($r['ytd_new_listings_ly'] ?? null) ? market_int($r['ytd_new_listings_ly']) . " in {$ly}" : ''];
    } else {
        $lyMon = $w['lyMon'];
        if ($n($r['sold'] ?? null))           { $sc = market_change($r['sold'], $r['sold_ly'] ?? null); $pool['sold'] = ['Closed sales', market_int($r['sold']), $sc === null ? "None in {$lyMon}" : market_vs($sc, $lyMon)]; }
        if ($n($r['days_on_market'] ?? null)) $pool['dom']     = ['Avg days on market', market_int($r['days_on_market']), $n($r['days_on_market_ly'] ?? null) ? market_int($r['days_on_market_ly']) . ' a year ago' : ''];
        if ($n($r['pct_of_list'] ?? null))    $pool['pct']     = ['% of list price', market_dec1($r['pct_of_list']) . '%', $n($r['pct_of_list_ly'] ?? null) ? market_dec1($r['pct_of_list_ly']) . '% a year ago' : ''];
        if ($n($r['median'] ?? null))         $pool['median']  = ['Median sale price', market_money($r['median']), market_vs(market_change($r['median'], $r['median_ly'] ?? null), $lyMon)];
        if ($n($r['new_listings'] ?? null))   $pool['new']     = ['New listings', market_int($r['new_listings']), market_vs(market_change($r['new_listings'], $r['new_listings_ly'] ?? null), $lyMon)];
    }
    if ($n($r['inventory'] ?? null))     $pool['inventory'] = ['Homes for sale', market_int($r['inventory']), $n($r['inventory_ly'] ?? null) ? market_int($r['inventory_ly']) . ' a year ago' : ''];
    if ($n($r['months_supply'] ?? null)) $pool['supply']    = ['Months of supply', market_dec1($r['months_supply']), $n($r['months_supply_ly'] ?? null) ? market_dec1($r['months_supply_ly']) . ' a year ago' : ''];
    return $pool;
}

/** The pool for REcolorado's own metro figures (front_range.json). */
function market_pool_metro(array $f, array $w): array {
    $lyMon = $w['lyMon'];
    $whole = function ($pct) use ($lyMon) { $t = market_whole_pct_text($pct); return $t === '' ? '' : ($t === 'Even' ? "Even with {$lyMon}" : "{$t} vs {$lyMon}"); };
    $pool = [];
    if (($f['closed'] ?? null) !== null) $pool['sold'] = ['Closed sales', market_int($f['closed']), $whole($f['closed_change_pct'] ?? null)];
    if (($f['median_days_in_mls'] ?? null) !== null) {
        $dc = $f['days_change'] ?? null;
        $pool['dom'] = ['Median days in MLS', market_int($f['median_days_in_mls']), $dc === null ? '' : ((float)$dc < 0 ? market_int(abs((float)$dc)) . " days faster than {$lyMon}" : ((float)$dc > 0 ? market_int($dc) . " days slower than {$lyMon}" : "Same as {$lyMon}"))];
    }
    if (($f['active'] ?? null) !== null)             $pool['inventory'] = ['Active listings', market_int($f['active']), $whole($f['active_change_pct'] ?? null)];
    if (($f['new_listings'] ?? null) !== null)       $pool['new']       = ['New listings', market_int($f['new_listings']), $whole($f['new_listings_change_pct'] ?? null)];
    if (($f['weeks_of_inventory'] ?? null) !== null) $pool['supply']    = ['Weeks of inventory', market_int($f['weeks_of_inventory']), ''];
    return $pool;
}

/** Three keys from the pool: the agent's picks that the pool has, then the source's defaults, then whatever is left. */
function market_pick_stats(array $pool, array $want, array $defaults): array {
    $out = [];
    foreach ($want as $k) if (isset($pool[$k]) && !in_array($k, $out, true)) $out[] = $k;
    foreach ($defaults as $k) if (count($out) < 3 && isset($pool[$k]) && !in_array($k, $out, true)) $out[] = $k;
    foreach (array_keys($pool) as $k) if (count($out) < 3 && !in_array($k, $out, true)) $out[] = $k;
    return array_slice($out, 0, 3);
}

/**
 * Everything the card shows, built from the month's data (no HTML; the
 * renderer draws it). One place, one property type, the average sale price large (the median until 2026-10-20) and
 * three figures under it, which the agent picks from the source's pool
 * (Nikki, 2026-10-18; defaults: closed sales, avg days on market, homes for
 * sale, or price per sq ft where no inventory exists yet). Nothing else on
 * the card. $opts: ptype SF|TC, stats [up to three catalog keys].
 * Null when the month has no row.
 *
 * ['key', 'eyebrow' (short: the month is in sub), 'title', 'sub',
 *  'hero' => [label, value, change, dir], 'stats' => [[label, value, note] x3],
 *  'stat_keys' => the three keys drawn, 'pool_keys' => every key this source offers,
 *  'credit' (kept for the record, not drawn)]
 */
function market_card_model(array $data, string $key, array $opts = []): ?array {
    $defs = market_cards($data);
    if (!isset($defs[$key])) return null;
    $d  = $defs[$key];
    $pt = ($opts['ptype'] ?? 'SF') === 'TC' ? 'TC' : 'SF';
    $m  = $data['month'];
    $w  = ['month' => market_month_label($m), 'monthName' => market_month_name($m), 'lyLab' => market_month_label(market_month_ly($m)),
           'lyMon' => market_month_short(market_month_ly($m)), 'lyYr' => market_year(market_month_ly($m))];
    $yr = market_year($m);
    $card = ['key' => $key, 'market' => $d['market'], 'title' => $d['title'], 'eyebrow' => 'Market snapshot', 'sub' => '', 'hero' => null,
             'stats' => [], 'stat_keys' => [], 'pool_keys' => [], 'credit' => ''];
    $want = array_values(array_filter((array)($opts['stats'] ?? []), fn($k) => isset(market_stat_catalog()[$k])));

    if (!empty($d['feed'])) {   // a town from our own feed (inc/market_feed.php)
        $town = $data['towns_feed'][$d['feed']] ?? null;
        $body = $town ? market_feed_pool($town, $pt, $w) : null;
        if (!$body) return null;
        $card['eyebrow'] = $body['eyebrow']; $card['hero'] = $body['hero'];
        $pool = $body['pool'];
        $defaults = ['sold', 'dom', isset($pool['inventory']) ? 'inventory' : 'ppsf'];
        $card['sub'] = "{$w['month']} · " . market_ptype_words($pt);
        $card['credit'] = 'Source: ' . $town['market'] . ' listing data, compiled by Mont Haus International Realty.';
    } elseif (!empty($d['metro'])) {
        $f = market_front_row($data, $d['metro']);
        if (!$f) return null;
        // The average leads since 2026-10-20 (Jon); REcolorado's Market Watch figure goes in front_range.json as
        // `average` + `average_change_pct` at the monthly refresh. A month without one still leads with the median.
        $hasAvg = ($f['average'] ?? null) !== null;
        $mc = $hasAvg ? ($f['average_change_pct'] ?? null) : ($f['median_change_pct'] ?? null);
        $card['sub']  = "{$w['month']} · All residential";
        $card['hero'] = ['label' => $hasAvg ? 'Average closed price' : 'Median closed price', 'value' => market_money($hasAvg ? $f['average'] : ($f['median'] ?? null), true),
                         'change' => $mc === null ? '' : (market_whole_pct_text($mc) === 'Even' ? "Even with {$w['lyLab']}" : market_whole_pct_text($mc) . " vs {$w['lyLab']}"),
                         'dir' => market_dir($mc === null ? null : (float)$mc)];
        $pool = market_pool_metro($f, $w);
        $defaults = ['sold', 'dom', 'inventory'];
        $card['credit'] = "Source: REcolorado Market Watch, {$w['month']}. Data courtesy of REcolorado.";
    } else {
        $r = !empty($d['town']) ? market_town_row($data, $d['town'], $pt) : market_county_row($data, $d['county'], $pt);
        if (!$r || ($r['sold'] ?? null) === null) return null;
        $card['sub'] = "{$w['month']} · " . market_ptype_words($pt);
        $thin = false;   // always the month's own figures, however few sales (Nikki, 2026-10-19: no year-to-date switch; the county card is there for a steadier number). market_thin() and the YTD pools stay for the report.
        if ($thin) {
            $ch = market_change($r['ytd_average'] ?? null, $r['ytd_average_ly'] ?? null);
            $card['eyebrow'] = 'Market snapshot · Year to date';
            $card['hero'] = ['label' => 'Average sale price, January to ' . $w['monthName'], 'value' => market_money($r['ytd_average'] ?? null),
                             'change' => market_vs($ch, "the same period of {$w['lyYr']}"), 'dir' => market_dir($ch)];
        } else {
            // The AVERAGE sale price leads the card since 2026-10-20 (Jon Boxer's decision, via Nikki); the median is a pickable row.
            $ch = market_change($r['average'] ?? null, $r['average_ly'] ?? null);
            $card['hero'] = ['label' => 'Average sale price', 'value' => market_money($r['average'] ?? null),
                             'change' => $ch === null ? "No sales in {$w['lyMon']}" : market_vs($ch, $w['lyLab']), 'dir' => market_dir($ch)];
        }
        $pool = market_pool_car($r, $w, $thin);
        // Price per sq ft is not in CAR's reports: it comes from our own feed for the county or town (Nikki, 2026-10-19).
        $fp = function_exists('market_feed_ppsf_stat') ? market_feed_ppsf_stat(market_feed_place_for_card($data, $d), $pt, $w, $thin) : null;
        if ($fp) $pool['ppsf'] = $fp;
        $defaults = ['sold', 'dom', 'inventory'];
        $card['credit'] = !empty($d['town'])
            ? "Source: Aspen/Glenwood Springs MLS Inc. Local Market Update, {$d['town']}, {$w['month']}. Report © {$yr} ShowingTime Plus, LLC."
            : "Source: Colorado Association of REALTORS® Local Market Update, {$d['county']}, {$w['month']}. Report © {$yr} ShowingTime Plus, LLC.";
        if ($fp) $card['credit'] .= ' Price per sq ft from the MLS feeds Mont Haus receives (IDX data).';
    }
    $keys = market_pick_stats($pool, $want, $defaults);
    $card['stat_keys'] = $keys;
    $card['pool_keys'] = array_keys($pool);
    $card['stats'] = array_map(fn($k) => $pool[$k], $keys);
    return $card;
}

/** The catalog keys a card offers for a property type (the builder disables the rest); [] when the card has no figures. */
function market_card_stats_available(array $data, string $key, string $pt): array {
    $card = market_card_model($data, $key, ['ptype' => $pt]);
    return $card ? $card['pool_keys'] : [];
}

/** "market-snapshot-pitkin-sep-2026.png" (a story adds -story, condos add -condos). */
function market_card_filename(array $o): string {
    return 'market-snapshot-' . $o['market'] . ($o['ptype'] === 'TC' ? '-condos' : '') . '-' . strtolower(str_replace(' ', '-', market_month_short($o['month'])))
         . ($o['format'] === 'story' ? '-story' : '') . '.png';
}
