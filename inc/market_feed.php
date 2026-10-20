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
        'breckenridge' => 'breckenridge-town-winter-dusk-382409211', 'keystone' => 'arapahoe-basin-ski-area-aerial-winter-105068310', 'copper-mountain' => 'breckenridge-town-winter-dusk-382409211',
        'frisco' => 'dillon-silverthorne-fall-75673092', 'dillon' => 'dillon-silverthorne-lake-dillon-summer-568906174', 'silverthorne' => 'dillon-silverthorne-lake-dillon-summer-568906174',
        'leadville' => 'independence-pass-fall-aspens-381152733', 'twin-lakes' => 'independence-pass-fall-aspens-381152733',
        'steamboat-springs' => 'steamboat-ski-resort-winter-503720128', 'oak-creek' => 'steamboat-yampa-river-winter-1374732175', 'clark' => 'steamboat-yampa-river-winter-1374732175', 'yampa' => 'steamboat-yampa-river-winter-1374732175',
        'crested-butte' => 'crested-butte-panorama-summer-1935731679', 'gunnison' => 'crested-butte-town-snodgrass-summer-309032886',
        'telluride' => 'telluride-town-overlook-summer-413597756', 'mountain-village' => 'telluride-aerial-main-street-fall-1111462754',
        'ridgway' => 'ridgway-mount-sneffels-fall-198784448', 'ouray' => 'ouray-town-aerial-summer-sunset-384322006', 'montrose' => 'ridgway-mount-sneffels-summer-198784289',
        'durango' => 'durango-silverton-train-fall-519473031', 'pagosa-springs' => 'ridgway-san-juans-fall-sunrise-256750697',
        'greater-denver' => 'denver-union-station-skyline-sunrise-438466179', 'boulder' => 'boulder-downtown-aerial-sunset-1168002389', 'evergreen' => 'red-rocks-denver-sunrise-207456426',
        'colorado-springs' => 'colorado-springs-aerial-dusk-373849837',
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
 * The feed's county rows (city '' with a county; since 2026-10-19 the site
 * publishes one per board and county), the board with the most closed sales
 * in the county this year chosen as for the towns. Keyed by the county name
 * lower-cased, without " County".
 * Returns [county key => ['county','market','ytd','SF' => place|null,'TC' => place|null]].
 */
function market_feed_counties(array $feed, array $board_order = []): array {
    $by = [];   // county key => market => class => place
    foreach ((array)($feed['places'] ?? []) as $p) {
        $county = trim((string)($p['county'] ?? ''));
        if (trim((string)($p['city'] ?? '')) !== '' || $county === '') continue;
        $by[strtolower(preg_replace('/\s+County$/i', '', $county))][(string)$p['market']][(string)$p['class']] = $p;
    }
    $rank = array_flip($board_order);
    $out = [];
    foreach ($by as $ck => $markets) {
        $best = null;
        foreach ($markets as $mk => $classes) {
            $ytd = (int)($classes['SF']['ytd']['sold'] ?? 0) + (int)($classes['TC']['ytd']['sold'] ?? 0);
            if ($ytd === 0) continue;
            $cand = ['county' => (string)(($classes['SF'] ?? $classes['TC'])['county'] ?? $ck), 'market' => $mk, 'ytd' => $ytd, 'SF' => $classes['SF'] ?? null, 'TC' => $classes['TC'] ?? null];
            if ($best === null || $cand['ytd'] > $best['ytd'] || ($cand['ytd'] === $best['ytd'] && ($rank[$mk] ?? 99) < ($rank[$best['market']] ?? 99))) $best = $cand;
        }
        if ($best !== null) $out[$ck] = $best;
    }
    return $out;
}

/**
 * The feed place behind a CAR card (Nikki, 2026-10-19: offer price per sq
 * ft on the county and town cards too, from our own feed, since CAR does
 * not publish it): a county card's county in $data['counties_feed'], a
 * town card's town in $data['towns_feed'] by label. Null when the feed has
 * no figure for it.
 */
function market_feed_place_for_card(array $data, array $def): ?array {
    if (!empty($def['town'])) {
        foreach ((array)($data['towns_feed'] ?? []) as $t) if (strcasecmp((string)$t['label'], (string)$def['town']) === 0) return $t;
        return null;
    }
    if (!empty($def['county'])) {
        $ck = strtolower(trim(preg_replace('/\s+County$/i', '', (string)$def['county'])));
        return $data['counties_feed'][$ck] ?? null;
    }
    return null;
}

/**
 * The price-per-sq-ft row for a CAR card from its feed place, in the pool's
 * shape ([label, value, note]), the year-to-date variant when the card is
 * thin; null when the feed has none for the class.
 */
function market_feed_ppsf_stat(?array $place, string $pt, array $w, bool $thin): ?array {
    $p = $place[$pt] ?? null;
    if (!$p) return null;
    $ppsf = fn($v) => '$' . number_format((float)$v);
    $n = fn($v) => $v !== null && $v !== '';
    if ($thin) {
        $v = $p['ytd']['price_per_sqft'] ?? null; $ly = $p['ytd_ly']['price_per_sqft'] ?? null;
        return $n($v) ? ['Median price per sq ft, YTD', $ppsf($v), $n($ly) ? $ppsf($ly) . " in {$w['lyYr']}" : ''] : null;
    }
    $v = $p['month']['price_per_sqft'] ?? null; $ly = $p['ly']['price_per_sqft'] ?? null;
    return $n($v) ? ['Median price/SqFt', $ppsf($v), $n($ly) ? $ppsf($ly) . ' a year ago' : ''] : null;
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
 * A feed town's card body: the eyebrow, the hero (median) and the pool of
 * figures the agent may pick three from (market_stat_catalog() keys; the
 * card model chooses). Null when the town has nothing for this class. The
 * year-to-date variants when the month is thin (under 10 sales); homes for
 * sale and months of supply only once the site has a month-end snapshot.
 * $w: ['month','monthName','lyLab','lyMon','lyYr'].
 */
function market_feed_pool(array $town, string $pt, array $w): ?array {
    $p = $town[$pt] ?? null;
    if (!$p) return null;
    $m = $p['month'] ?? []; $ly = $p['ly'] ?? []; $ytd = $p['ytd'] ?? []; $ytdly = $p['ytd_ly'] ?? [];
    if ((int)($m['sold'] ?? 0) === 0 && (int)($ytd['sold'] ?? 0) === 0) return null;
    $inv  = $p['inventory'] ?? null;
    $n    = fn($v) => $v !== null && $v !== '';
    $ppsf = fn($v) => '$' . number_format((float)$v);
    $thin = (int)($m['sold'] ?? 0) < 10;
    $pool = [];
    if ($thin) {
        $lyY = $w['lyYr'];
        $ch = market_change($ytd['median'] ?? null, $ytdly['median'] ?? null);
        $hero = ['label' => 'Median sale price, January to ' . $w['monthName'], 'value' => market_money($ytd['median'] ?? null),
                 'change' => $ch === null ? ((int)($ytdly['sold'] ?? 0) === 0 ? "No sales in the same period of {$lyY}" : '') : market_vs($ch, "the same period of {$lyY}"), 'dir' => market_dir($ch)];
        $pool['sold'] = ['Closed sales, YTD', market_int($ytd['sold'] ?? null), market_int($ytdly['sold'] ?? null) . " in {$lyY}"];
        if ($n($ytd['avg_dom'] ?? null))        $pool['dom']     = ['Avg days on market, YTD', market_int($ytd['avg_dom']), $n($ytdly['avg_dom'] ?? null) ? market_int($ytdly['avg_dom']) . " in {$lyY}" : ''];
        if ($n($ytd['price_per_sqft'] ?? null)) $pool['ppsf']    = ['Median price per sq ft, YTD', $ppsf($ytd['price_per_sqft']), $n($ytdly['price_per_sqft'] ?? null) ? $ppsf($ytdly['price_per_sqft']) . " in {$lyY}" : ''];
        if ($n($ytd['pct_of_list'] ?? null))    $pool['pct']     = ['% of list received, YTD', market_dec1($ytd['pct_of_list']) . '%', $n($ytdly['pct_of_list'] ?? null) ? market_dec1($ytdly['pct_of_list']) . "% in {$lyY}" : ''];
        if ($n($ytd['average'] ?? null))        $pool['average'] = ['Average sale price, YTD', market_money($ytd['average']), market_vs(market_change($ytd['average'], $ytdly['average'] ?? null), "the same period of {$lyY}")];
    } else {
        $lyMon = $w['lyMon'];
        $ch = market_change($m['median'] ?? null, $ly['median'] ?? null);
        $sc = market_change($m['sold'] ?? null, $ly['sold'] ?? null);
        $hero = ['label' => 'Median sale price', 'value' => market_money($m['median'] ?? null),
                 'change' => $ch === null ? "No sales in {$lyMon}" : market_vs($ch, $w['lyLab']), 'dir' => market_dir($ch)];
        $pool['sold'] = ['Closed sales', market_int($m['sold'] ?? null), $sc === null ? "None in {$lyMon}" : market_vs($sc, $lyMon)];
        if ($n($m['avg_dom'] ?? null))        $pool['dom']     = ['Avg days on market', market_int($m['avg_dom']), $n($ly['avg_dom'] ?? null) ? market_int($ly['avg_dom']) . ' a year ago' : ''];
        if ($n($m['price_per_sqft'] ?? null)) $pool['ppsf']    = ['Median price/SqFt', $ppsf($m['price_per_sqft']), $n($ly['price_per_sqft'] ?? null) ? $ppsf($ly['price_per_sqft']) . ' a year ago' : ''];
        if ($n($m['pct_of_list'] ?? null))    $pool['pct']     = ['% of list price received', market_dec1($m['pct_of_list']) . '%', $n($ly['pct_of_list'] ?? null) ? market_dec1($ly['pct_of_list']) . '% a year ago' : ''];
        if ($n($m['average'] ?? null))        $pool['average'] = ['Average sale price', market_money($m['average']), market_vs(market_change($m['average'], $ly['average'] ?? null), $lyMon)];
    }
    if ($inv && $n($inv['active'] ?? null))        $pool['inventory'] = ['Homes for sale', market_int($inv['active']), ''];
    if ($inv && $n($inv['months_supply'] ?? null)) $pool['supply']    = ['Months of supply', market_dec1($inv['months_supply']), ''];
    return ['eyebrow' => $thin ? 'Market snapshot · Year to date' : 'Market snapshot', 'hero' => $hero, 'pool' => $pool];
}
