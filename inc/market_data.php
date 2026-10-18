<?php
/**
 * inc/market_data.php — the Monthly Market Report data layer (2026-10-18):
 * loading a month, the words for months and numbers, and the snapshot card
 * model. Standalone on purpose (no portal.php, no database), so
 * tests/render_market_sample.php can render every output from the data files
 * alone; inc/market.php adds the parts that need the portal.
 *
 * The tool was built into the portal from JB's Claude artifacts
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
 * closed sales in the month) shows year-to-date figures on the card; percent
 * changes are recomputed from this year and last year, one decimal, a
 * trailing .0 dropped (+30%, −4.3%); "% of list" changes are points.
 */
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
    ];
}

// ── Snapshot posts ───────────────────────────────────────────────────────────

/**
 * One card per market, as JB's seven were drawn. layout: hero (one county),
 * list (two or three counties), metro (REcolorado's own figures). photo: the
 * stock photo suggested as a backdrop (assets/stock, see social_stock()).
 */
function market_card_defs(): array {
    return [
        'aspen'      => ['title' => 'Aspen & Snowmass', 'layout' => 'hero', 'county' => 'Pitkin County',
                         'credit_mls' => 'the Aspen/Glenwood Springs MLS Inc.', 'photo' => 'aspen-aerial-fall-1110909378'],
        'vail'       => ['title' => 'Vail Valley', 'layout' => 'hero', 'county' => 'Eagle County',
                         'credit_mls' => 'the Vail Multi List Service', 'photo' => 'vail-aspens-fall-1746156502'],
        'telluride'  => ['title' => 'Telluride', 'layout' => 'hero', 'county' => 'San Miguel County',
                         'credit_mls' => 'the Telluride MLS', 'photo' => 'telluride-aerial-main-street-fall-1111462754'],
        'cren'       => ['title' => 'Montrose, Ridgway & Durango', 'layout' => 'list',
                         'rows' => [['Montrose County', ''], ['Ouray County', 'Ridgway & Ouray'], ['La Plata County', 'Durango']],
                         'credit_mls' => 'the Colorado Real Estate Network (CREN)', 'photo' => 'ridgway-mount-sneffels-fall-198784448'],
        'altitude'   => ['title' => 'Summit County & Steamboat', 'layout' => 'list', 'other_line' => true,
                         'rows' => [['Summit County', 'Breckenridge, Keystone, Frisco'], ['Routt County', 'Steamboat Springs']],
                         'credit_mls' => 'Altitude MLS', 'photo' => 'breckenridge-town-winter-dusk-382409211'],
        'recolorado' => ['title' => 'Denver Metro', 'layout' => 'metro', 'county' => 'Denver County',
                         'credit_mls' => 'REcolorado', 'photo' => 'denver-union-station-skyline-sunrise-438466179'],
        'elevate'    => ['title' => 'Colorado Springs', 'layout' => 'hero', 'county' => 'El Paso County', 'foot' => 'ppar',
                         'credit_mls' => 'elevateMLS', 'photo' => null],
    ];
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

/** The front_range.json row for a market, or null. */
function market_front_row(array $data, string $market): ?array {
    foreach ($data['front_range'] as $r) if (($r['market'] ?? '') === $market) return $r;
    return null;
}

/** Fewer than 10 closed sales in the month: show year to date (the artifact's rule 4). */
function market_thin(?array $r): bool { return $r !== null && (int)($r['sold'] ?? 0) < 10; }

/** "Single-family homes" / "Townhomes & condos", and the short "Townhomes & condos" line opener for the OTHER type. */
function market_ptype_words(string $pt): string { return $pt === 'TC' ? 'Townhomes & condos' : 'Single-family homes'; }

/**
 * Everything the card shows, built from the month's data (no HTML; the
 * renderer draws it). $opts: ptype SF|TC. Null for a market with no card or
 * no county row this month.
 *
 * ['eyebrow', 'title', 'sub', 'layout', 'hero' => [label, value, change, dir],
 *  'stats' => [[label, value, note], x4], 'rows' => [[title, value, change, dir, lines[]]],
 *  'foot', 'credit']
 */
function market_card_model(array $data, string $market, array $opts = []): ?array {
    $defs = market_card_defs();
    if (!isset($defs[$market])) return null;
    $d  = $defs[$market];
    $pt = ($opts['ptype'] ?? 'SF') === 'TC' ? 'TC' : 'SF';
    $ot = $pt === 'TC' ? 'SF' : 'TC';
    $m  = $data['month'];
    $month = market_month_label($m);                 // September 2026
    $lyMon = market_month_short(market_month_ly($m)); // Sep 2025
    $lyYr  = market_year(market_month_ly($m));
    $yr    = market_year($m);
    $card  = ['market' => $market, 'layout' => $d['layout'], 'title' => $d['title'],
              'eyebrow' => "Market snapshot · {$month}", 'sub' => '', 'hero' => null, 'stats' => [], 'rows' => [], 'foot' => '', 'credit' => ''];

    // One county's "other type" line: "Townhomes & condos: $5.05M median on 13 sales" (YTD when thin).
    $otherLine = function (?array $o) use ($ot): string {
        if (!$o || ($o['sold'] ?? null) === null) return '';
        $w = $ot === 'TC' ? 'Townhomes & condos' : 'Single-family homes';
        if (market_thin($o)) return "{$w} YTD: " . market_money($o['ytd_median']) . ' median on ' . market_int($o['ytd_sold']) . ' sales';
        return "{$w}: " . market_money($o['median']) . ' median on ' . market_int($o['sold']) . ' sales';
    };

    if ($d['layout'] === 'hero') {
        $r = market_county_row($data, $d['county'], $pt);
        if (!$r) return null;
        $o = market_county_row($data, $d['county'], $ot);
        if (market_thin($r)) {
            $ch = market_change($r['ytd_median'] ?? null, $r['ytd_median_ly'] ?? null);
            $card['eyebrow'] = "Market snapshot · Year to date, {$month}";
            $card['sub']  = "{$d['county']} · " . market_ptype_words($pt);
            $card['hero'] = ['label' => 'Median sale price, January to ' . market_month_name($m), 'value' => market_money($r['ytd_median'] ?? null),
                             'change' => $ch === null ? '' : (market_change_text($ch) === 'Even' ? "Even with the same period of {$lyYr}" : market_change_text($ch) . " vs the same period of {$lyYr}"), 'dir' => market_dir($ch)];
            $card['stats'] = [
                ['Closed sales, YTD',    market_int($r['ytd_sold'] ?? null),            market_int($r['ytd_sold_ly'] ?? null) . " in {$lyYr}"],
                ['Days on market, YTD',  market_int($r['ytd_days_on_market'] ?? null),  market_int($r['ytd_days_on_market_ly'] ?? null) . " in {$lyYr}"],
                ['Of list price, YTD',   market_dec1($r['ytd_pct_of_list'] ?? null) . '%', market_dec1($r['ytd_pct_of_list_ly'] ?? null) . "% in {$lyYr}"],
                ['Months of supply',     market_dec1($r['months_supply'] ?? null),      market_int($r['inventory'] ?? null) . ' homes for sale'],
            ];
        } else {
            $ch = market_change($r['median'] ?? null, $r['median_ly'] ?? null);
            $sc = market_change($r['sold'] ?? null, $r['sold_ly'] ?? null);
            $card['sub']  = "{$d['county']} · " . market_ptype_words($pt);
            $card['hero'] = ['label' => 'Median sale price', 'value' => market_money($r['median'] ?? null),
                             'change' => $ch === null ? "No sales in {$lyMon}" : (market_change_text($ch) === 'Even' ? "Even with " . market_month_label(market_month_ly($m)) : market_change_text($ch) . ' vs ' . market_month_label(market_month_ly($m))), 'dir' => market_dir($ch)];
            $card['stats'] = [
                ['Closed sales',           market_int($r['sold'] ?? null),           $sc === null ? "None in {$lyMon}" : (market_change_text($sc) === 'Even' ? "Even with {$lyMon}" : market_change_text($sc) . " vs {$lyMon}")],
                ['Days on market',         market_int($r['days_on_market'] ?? null), market_int($r['days_on_market_ly'] ?? null) . ' a year ago'],
                ['Of list price received', market_dec1($r['pct_of_list'] ?? null) . '%', market_dec1($r['pct_of_list_ly'] ?? null) . '% a year ago'],
                ['Months of supply',       market_dec1($r['months_supply'] ?? null), market_dec1($r['months_supply_ly'] ?? null) . ' a year ago'],
            ];
        }
        if (($d['foot'] ?? '') === 'ppar') {
            $f = market_front_row($data, 'elevate');
            $card['foot'] = $f ? 'Pikes Peak region, all property types: ' . market_int($f['closed'] ?? null) . ' sales, ' . market_money($f['median'] ?? null, true) . ' median' : $otherLine($o);
            $card['credit'] = "Source: Colorado Association of REALTORS® Local Market Update, {$d['county']}, {$month}, data from Colorado MLSs including {$d['credit_mls']}, report © {$yr} ShowingTime Plus, LLC. Region figures: Pikes Peak Association of REALTORS®, elevateMLS.";
        } else {
            $card['foot'] = $otherLine($o);
            $card['credit'] = "Source: Colorado Association of REALTORS® Local Market Update, {$d['county']}, {$month}. Data from Colorado MLSs including {$d['credit_mls']}. Report © {$yr} ShowingTime Plus, LLC.";
        }
        return $card;
    }

    if ($d['layout'] === 'metro') {
        $f = market_front_row($data, $market);
        if (!$f) return null;
        $r = market_county_row($data, $d['county'], $pt);
        $mc = $f['median_change_pct'] ?? null;
        $card['sub']  = (string)($f['region'] ?? '') === 'Denver Metro (11 counties)' ? '11-county metro · All residential' : trim((string)($f['region'] ?? '') . ' · ' . (string)($f['scope'] ?? ''), ' ·');
        $card['hero'] = ['label' => 'Median closed price', 'value' => market_money($f['median'] ?? null, true),
                         'change' => $mc === null ? '' : (market_whole_pct_text($mc) === 'Even' ? 'Even with ' . market_month_label(market_month_ly($m)) : market_whole_pct_text($mc) . ' vs ' . market_month_label(market_month_ly($m))),
                         'dir' => market_dir($mc === null ? null : (float)$mc)];
        $dc = $f['days_change'] ?? null;
        $daysNote = $dc === null ? '' : ((float)$dc < 0 ? market_int(abs((float)$dc)) . " days faster than {$lyMon}" : ((float)$dc > 0 ? market_int($dc) . " days slower than {$lyMon}" : "Same as {$lyMon}"));
        $card['stats'] = [
            ['Closed sales',        market_int($f['closed'] ?? null), ($t = market_whole_pct_text($f['closed_change_pct'] ?? null)) === '' ? '' : ($t === 'Even' ? "Even with {$lyMon}" : "{$t} vs {$lyMon}")],
            ['Median days in MLS',  market_int($f['median_days_in_mls'] ?? null), $daysNote],
            ['Active listings',     market_int($f['active'] ?? null), trim(market_whole_pct_text($f['active_change_pct'] ?? null) . (($f['weeks_of_inventory'] ?? null) !== null ? ' · ' . market_int($f['weeks_of_inventory']) . ' weeks of inventory' : ''), ' ·')],
            ['New listings',        market_int($f['new_listings'] ?? null), ($t = market_whole_pct_text($f['new_listings_change_pct'] ?? null)) === '' ? '' : ($t === 'Even' ? "Even with {$lyMon}" : "{$t} vs {$lyMon}")],
        ];
        if ($r) {
            $ch = market_change($r['median'] ?? null, $r['median_ly'] ?? null);
            $card['foot'] = "{$d['county']} " . ($pt === 'TC' ? 'townhome & condo' : 'single-family') . ' median: ' . market_money($r['median'] ?? null, true) . ($ch !== null ? ', ' . market_change_text($ch) : '');
        }
        $card['credit'] = "Source: REcolorado Market Watch, {$month}. Data courtesy of REcolorado. {$d['county']} figure: Colorado Association of REALTORS® Local Market Update. Report © {$yr} ShowingTime Plus, LLC.";
        return $card;
    }

    // list: two or three counties, the median large on each row
    $card['sub'] = ($pt === 'TC' ? 'Townhome & condo' : 'Single-family') . ' median sale price' . (count($d['rows']) > 2 ? ' by county' : '');
    $names = [];
    foreach ($d['rows'] as [$county, $sub]) {
        $r = market_county_row($data, $county, $pt);
        if (!$r) continue;
        $names[] = preg_replace('/\s+County$/', '', $county);
        $ch = market_change($r['median'] ?? null, $r['median_ly'] ?? null);
        $lines = [];
        if (market_thin($r)) {
            $lines[] = market_int($r['sold']) . ' sales · year to date ' . market_money($r['ytd_median'] ?? null, true) . ' median on ' . market_int($r['ytd_sold'] ?? null) . ' sales';
        } else {
            $lines[] = market_int($r['sold']) . ' sales · ' . market_int($r['days_on_market'] ?? null) . ' days on market · ' . market_dec1($r['months_supply'] ?? null) . ' months of supply';
        }
        if (!empty($d['other_line'])) {
            $o = market_county_row($data, $county, $ot);
            if ($o && ($o['sold'] ?? null) !== null) {
                $oc = market_change($o['median'] ?? null, $o['median_ly'] ?? null);
                $lines[] = $otherLine($o) . ($oc !== null && !market_thin($o) ? ', ' . market_change_text($oc) : '');
            }
        }
        $card['rows'][] = ['title' => $county . ($sub !== '' ? " · {$sub}" : ''), 'value' => market_money($r['median'] ?? null, true),
                           'change' => $ch === null ? "No sales in {$lyMon}" : (market_change_text($ch) === 'Even' ? "Even with {$lyMon}" : market_change_text($ch) . " vs {$lyMon}"),
                           'dir' => market_dir($ch), 'lines' => $lines];
    }
    if (!$card['rows']) return null;
    $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) . ' counties' : $names[0] . ' County';
    $card['credit'] = "Source: Colorado Association of REALTORS® Local Market Updates, {$list}, {$month}. Data from Colorado MLSs including {$d['credit_mls']}. Report © {$yr} ShowingTime Plus, LLC.";
    return $card;
}

/** "market-snapshot-aspen-sep-2026.png" (a story adds -story). */
function market_card_filename(array $o): string {
    return 'market-snapshot-' . $o['market'] . ($o['ptype'] === 'TC' ? '-condos' : '') . '-' . strtolower(str_replace(' ', '-', market_month_short($o['month'])))
         . ($o['format'] === 'story' ? '-story' : '') . '.png';
}

