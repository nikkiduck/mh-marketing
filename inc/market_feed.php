<?php
/**
 * inc/market_feed.php — town figures from our own MLS feed (2026-10-18).
 *
 * The site computes them (site `webroot/api/market_stats.php`, from its
 * ap_sales table: every closed listing on every board since 2025-01-01;
 * site docs/HANDOFF-market-stats.md) and this portal reads them for the
 * month: inc/market.php fetches and caches the JSON; this file turns it into
 * the Market Report's town rows and the snapshot cards' town entries.
 * Standalone on purpose (no portal.php, no database): the sample harness
 * feeds it a saved response.
 *
 * Which towns: the site's Communities list, every town of every area (the
 * registry's `areas`, inc/boards.php mk_areas()), with NO minimum (Nikki,
 * 2026-10-18: Ridgway may have a couple of sales and it is still worth
 * seeing). A town with no sales at all in the year is left out, since there
 * is nothing to show. Which board a town's figure comes from: the board with
 * the most closed sales for that town this year (Breckenridge sells on
 * Altitude 117 times and on REcolorado 41; Ridgway on CREN 30 and the
 * Telluride MLS 18), so no map to keep. A thin month (under 10 sales) shows
 * year to date, the cards' rule.
 *
 * Figures per town and class (SF, TC), each for the month, the same month a
 * year ago, year to date and year to date a year ago: sold, median, average,
 * avg_dom, median_dom, pct_of_list, price_per_sqft, dom_basis; plus
 * inventory (active, under_contract, months_supply) once the site has a
 * month-end snapshot (the first is 2026-11-01 for October). Days on market is
 * the board's own figure, else on-market to contract, else on-market to close
 * (CREN, elevateMLS, REcolorado), as dom_basis says.
 */

/** The feed's city spellings for a Communities town (lower-cased keys). */
function market_town_city_keys(string $key, string $label): array {
    static $alias = [
        'old-snowmass'      => ['snowmass'],
        'steamboat-springs' => ['steamboat springs', 'steamboat'],
        'crested-butte'     => ['crested butte', 'mt. crested butte', 'mount crested butte'],
        'greater-denver'    => ['denver'],
        'mountain-village'  => ['mountain village'],
    ];
    return $alias[$key] ?? [strtolower(trim(preg_replace('/\s+/', ' ', $label)))];
}

/** The stock photo suggested behind a town's card, by its Communities key (assets/stock); null for none. */
function market_town_photo(string $key): ?string {
    static $map = [
        'vail' => 'vail-aspens-fall-1746156502', 'beaver-creek' => 'beaver-creek-ski-resort-winter-571078727',
        'avon' => 'vail-summit-gore-range-summer-sunset-217581553', 'edwards' => 'vail-summit-gore-range-summer-sunset-217581553',
        'minturn' => 'vail-mountain-gore-range-winter-401309793', 'eagle' => 'vail-summit-gore-range-summer-sunset-217581553', 'gypsum' => 'vail-summit-gore-range-summer-sunset-217581553',
        'breckenridge' => 'breckenridge-town-winter-dusk-382409211', 'keystone' => 'breckenridge-town-winter-dusk-382409211', 'copper-mountain' => 'breckenridge-town-winter-dusk-382409211',
        'frisco' => 'breckenridge-town-winter-dusk-382409211', 'dillon' => 'breckenridge-town-winter-dusk-382409211', 'silverthorne' => 'breckenridge-town-winter-dusk-382409211',
        'steamboat-springs' => 'steamboat-ski-resort-winter-503720128', 'oak-creek' => 'steamboat-yampa-river-winter-1374732175', 'clark' => 'steamboat-yampa-river-winter-1374732175', 'yampa' => 'steamboat-yampa-river-winter-1374732175',
        'crested-butte' => 'crested-butte-panorama-summer-1935731679', 'gunnison' => 'crested-butte-town-snodgrass-summer-309032886',
        'telluride' => 'telluride-town-overlook-summer-413597756', 'mountain-village' => 'telluride-aerial-main-street-fall-1111462754',
        'ridgway' => 'ridgway-mount-sneffels-fall-198784448', 'ouray' => 'ridgway-san-juans-fall-sunrise-256750697', 'montrose' => 'ridgway-mount-sneffels-summer-198784289',
        'durango' => 'ridgway-san-juans-fall-sunrise-256750697', 'pagosa-springs' => 'ridgway-san-juans-fall-sunrise-256750697',
        'greater-denver' => 'denver-union-station-skyline-sunrise-438466179', 'boulder' => 'boulder-flatirons-milky-way-378561606', 'evergreen' => 'red-rocks-denver-sunrise-207456426',
    ];
    return $map[$key] ?? null;
}

/**
 * The Communities towns resolved against the feed: one entry per town that
 * had any closed sale this year, with the board chosen and its SF and TC
 * places. $areas is the registry's areas ([{key, area, towns:[{key,label}]}]);
 * $board_order the market ids in report order, for ties.
 * Returns [town key => ['key','label','area','area_key','market','city','ytd','SF' => place|null,'TC' => place|null]].
 */
function market_feed_towns(array $feed, array $areas, array $board_order = []): array {
    $by = [];   // city key => market => class => place
    foreach ((array)($feed['places'] ?? []) as $p) {
        $city = trim((string)($p['city'] ?? ''));
        if ($city === '') continue;
        $by[strtolower(preg_replace('/\s+/', ' ', $city))][(string)$p['market']][(string)$p['class']] = $p;
    }
    $rank = array_flip($board_order);
    $out = [];
    foreach ($areas as $a) {
        foreach ((array)($a['towns'] ?? []) as $t) {
            $best = null;
            foreach (market_town_city_keys($t['key'], $t['label']) as $ck) {
                foreach ($by[$ck] ?? [] as $mk => $classes) {
                    $ytd = (int)($classes['SF']['ytd']['sold'] ?? 0) + (int)($classes['TC']['ytd']['sold'] ?? 0);
                    $ytd_ly = (int)($classes['SF']['ytd_ly']['sold'] ?? 0) + (int)($classes['TC']['ytd_ly']['sold'] ?? 0);
                    if ($ytd + $ytd_ly === 0) continue;
                    $cand = ['market' => $mk, 'city' => (string)(($classes['SF'] ?? $classes['TC'])['city'] ?? $ck), 'ytd' => $ytd,
                             'SF' => $classes['SF'] ?? null, 'TC' => $classes['TC'] ?? null];
                    if ($best === null || $cand['ytd'] > $best['ytd'] || ($cand['ytd'] === $best['ytd'] && ($rank[$mk] ?? 99) < ($rank[$best['market']] ?? 99))) $best = $cand;
                }
            }
            if ($best === null) continue;
            $out[$t['key']] = ['key' => $t['key'], 'label' => $t['label'], 'area' => (string)$a['area'], 'area_key' => (string)($a['key'] ?? '')] + $best;
        }
    }
    return $out;
}

/**
 * Card definitions for the feed towns, in the static defs' shape, keyed
 * feed-<town key>; towns that already have a CAR town report card (the
 * Roaring Fork Valley) are left out. Needs $data['towns_feed'].
 */
function market_feed_card_defs(array $data, array $covered_labels): array {
    $out = [];
    foreach ((array)($data['towns_feed'] ?? []) as $t) {
        if (in_array($t['label'], $covered_labels, true)) continue;
        $out['feed-' . $t['key']] = ['title' => $t['label'], 'place' => $t['area'], 'feed' => $t['key'], 'market' => $t['market'], 'photo' => market_town_photo($t['key'])];
    }
    return $out;
}

/**
 * The card model's body for a feed town (the renderer's words: hero and
 * three rows), or null when the town has nothing for this class. The third
 * row is homes for sale once a snapshot exists, else price per square foot
 * (Nikki, 2026-10-18). $words: ['month','lyLab','lyMon','lyYr','monthName'].
 */
function market_feed_card_body(array $town, string $pt, array $words): ?array {
    $p = $town[$pt] ?? null;
    if (!$p) return null;
    $m = $p['month'] ?? []; $ly = $p['ly'] ?? []; $ytd = $p['ytd'] ?? []; $ytdly = $p['ytd_ly'] ?? [];
    if ((int)($m['sold'] ?? 0) === 0 && (int)($ytd['sold'] ?? 0) === 0) return null;
    $inv = $p['inventory'] ?? null;
    $vs = function (?float $f, string $when): string { if ($f === null) return ''; $t = market_change_text($f); return $t === 'Even' ? "Even with {$when}" : "{$t} vs {$when}"; };
    $ppsf = fn($v) => ($v === null || $v === '') ? '–' : '$' . number_format((float)$v);
    $thin = (int)($m['sold'] ?? 0) < 10;
    $out = ['eyebrow' => $thin ? 'Market snapshot · Year to date' : 'Market snapshot'];
    if ($thin) {
        $ch = market_change($ytd['median'] ?? null, $ytdly['median'] ?? null);
        $out['hero'] = ['label' => 'Median sale price, January to ' . $words['monthName'], 'value' => market_money($ytd['median'] ?? null),
                        'change' => $ch === null ? ((int)($ytdly['sold'] ?? 0) === 0 ? "No sales in the same period of {$words['lyYr']}" : '') : $vs($ch, "the same period of {$words['lyYr']}"), 'dir' => market_dir($ch)];
        $rows = [
            ['Closed sales, year to date',   market_int($ytd['sold'] ?? null),    market_int($ytdly['sold'] ?? null) . " in {$words['lyYr']}"],
            ['Days on market, year to date', market_int($ytd['avg_dom'] ?? null), ($ytdly['avg_dom'] ?? null) !== null ? market_int($ytdly['avg_dom']) . " in {$words['lyYr']}" : ''],
        ];
        $rows[] = $inv && ($inv['active'] ?? null) !== null
            ? ['Homes for sale', market_int($inv['active']), ($inv['months_supply'] ?? null) !== null ? market_dec1($inv['months_supply']) . ' months of supply' : '']
            : ['Price per sq ft, year to date', $ppsf($ytd['price_per_sqft'] ?? null), ($ytdly['price_per_sqft'] ?? null) !== null ? $ppsf($ytdly['price_per_sqft']) . " in {$words['lyYr']}" : ''];
    } else {
        $ch = market_change($m['median'] ?? null, $ly['median'] ?? null);
        $sc = market_change($m['sold'] ?? null, $ly['sold'] ?? null);
        $out['hero'] = ['label' => 'Median sale price', 'value' => market_money($m['median'] ?? null),
                        'change' => $ch === null ? "No sales in {$words['lyMon']}" : $vs($ch, $words['lyLab']), 'dir' => market_dir($ch)];
        $rows = [
            ['Closed sales',   market_int($m['sold'] ?? null),    $sc === null ? "None in {$words['lyMon']}" : $vs($sc, $words['lyMon'])],
            ['Days on market', market_int($m['avg_dom'] ?? null), ($ly['avg_dom'] ?? null) !== null ? market_int($ly['avg_dom']) . ' a year ago' : ''],
        ];
        $rows[] = $inv && ($inv['active'] ?? null) !== null
            ? ['Homes for sale', market_int($inv['active']), ($inv['months_supply'] ?? null) !== null ? market_dec1($inv['months_supply']) . ' months of supply' : '']
            : ['Price per sq ft', $ppsf($m['price_per_sqft'] ?? null), ($ly['price_per_sqft'] ?? null) !== null ? $ppsf($ly['price_per_sqft']) . ' a year ago' : ''];
    }
    $out['stats'] = $rows;
    return $out;
}
