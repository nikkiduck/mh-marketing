<?php
/**
 * inc/market_flyer_render.php — the Market Report as a one-page PDF flyer for
 * a client (Nikki, 2026-11-08: "A button to create a PDF flyer of the stats
 * for a selected market. This is so agents can download and share with a
 * client.").
 *
 * One market (a tab of the report), one property type (the report's toggle),
 * one month: US Letter, the brokerage logo, the market's name and the MLS
 * that serves it, a block of tiles per county (closed sales, median and
 * average price, days on market, % of list, months of supply, homes for
 * sale, new listings, each against the same month last year, the steadier
 * year-to-date figure where there is one), the Aspen Glenwood town table when
 * the month has one, the metro figures for the Front Range markets, and a
 * footer with the agent's photo, name and contact lines beside the boards'
 * credit lines, which CAR's and ShowingTime's terms require.
 *
 * Standalone (no portal.php, no database), like inc/flyer_render.php: the
 * model comes from the month's data (inc/market_data.php), the HTML is the
 * same document for a browser review (?html=1, admins) and for headless
 * Chromium (flyer_export_pdf() in inc/flyer.php). Fonts are the listing
 * flyer's pair, Jost and Cormorant Garamond, by URL for a browser and
 * embedded for the export; so are the logo and the agent's headshot.
 */

const MARKET_FLYER_W = 612;   // pt, US Letter
const MARKET_FLYER_H = 792;

/**
 * Everything the flyer draws for one market, or null when the month has no
 * figures for it. $agent: ['name', 'phone', 'email', 'photo' => disk path or ''].
 */
function market_flyer_model(array $data, string $market, string $pt, array $agent): ?array {
    $pt    = $pt === 'TC' ? 'TC' : 'SF';
    $ptype = $pt === 'TC' ? 'Townhouse-condo' : 'Single family';
    $board = null;
    foreach ($data['boards'] as $b) if (($b['market'] ?? '') === $market) { $board = $b; break; }
    if (!$board) return null;

    $m  = $data['month'];
    $w  = ['month' => market_month_label($m), 'monthName' => market_month_name($m), 'ly' => market_month_short(market_month_ly($m)),
           'lyLab' => market_month_label(market_month_ly($m)), 'lyYr' => market_year(market_month_ly($m)), 'yr' => market_year($m)];

    $otherPt  = $pt === 'TC' ? 'SF' : 'TC';
    $otherPtype = $otherPt === 'TC' ? 'Townhouse-condo' : 'Single family';
    $counties = array_values(array_filter($data['county'], fn($r) => ($r['market'] ?? '') === $market && ($r['ptype'] ?? '') === $ptype));
    $others   = array_values(array_filter($data['county'], fn($r) => ($r['market'] ?? '') === $market && ($r['ptype'] ?? '') === $otherPtype));
    $towns    = array_values(array_filter((array)($data['towns'] ?? []), fn($r) => ($r['market'] ?? '') === $market && ($r['ptype'] ?? '') === $ptype));
    $metro    = null;
    foreach ((array)($data['front_range'] ?? []) as $f) if (($f['market'] ?? '') === $market) { $metro = $f; break; }
    if (!$counties && !$metro) return null;

    // A county block: the eight tiles the report draws, in its order.
    $blocks = [];
    $countyBlock = function (array $r, string $words) use ($w, $board): array {
        $tiles = [
            market_flyer_tile('Closed sales', market_int($r['sold']), market_change($r['sold'], $r['sold_ly']), $w,
                              $r['ytd_sold'] !== null ? 'YTD ' . market_int($r['ytd_sold']) . market_flyer_paren(market_change($r['ytd_sold'], $r['ytd_sold_ly'])) : ''),
            market_flyer_tile('Median price', market_money($r['median']), market_change($r['median'], $r['median_ly']), $w,
                              $r['ytd_median'] !== null ? 'YTD ' . market_money($r['ytd_median']) . market_flyer_paren(market_change($r['ytd_median'], $r['ytd_median_ly'])) : ''),
            market_flyer_tile('Average price', market_money($r['average']), market_change($r['average'], $r['average_ly']), $w,
                              $r['ytd_average'] !== null ? 'YTD ' . market_money($r['ytd_average']) : ''),
            market_flyer_tile('Days on market', market_int($r['days_on_market']), market_change($r['days_on_market'], $r['days_on_market_ly']), $w,
                              $r['ytd_days_on_market'] !== null ? 'YTD ' . market_int($r['ytd_days_on_market']) : ''),
            market_flyer_tile('% of list received', $r['pct_of_list'] === null ? '–' : number_format((float)$r['pct_of_list'], 1) . '%',
                              null, $w, '', market_flyer_pts($r['pct_of_list'], $r['pct_of_list_ly'], $w)),
            market_flyer_tile('Months of supply', market_dec1($r['months_supply']), market_change($r['months_supply'], $r['months_supply_ly']), $w),
            market_flyer_tile('Homes for sale', market_int($r['inventory']), market_change($r['inventory'], $r['inventory_ly']), $w),
            market_flyer_tile('New listings', market_int($r['new_listings']), market_change($r['new_listings'], $r['new_listings_ly']), $w,
                              $r['ytd_new_listings'] !== null ? 'YTD ' . market_int($r['ytd_new_listings']) : ''),
        ];
        return ['title' => (string)$r['county'], 'sub' => $words . ' · ' . (string)($r['mls'] ?? $board['mls']), 'tiles' => $tiles,
                'thin' => ($r['sold'] ?? null) !== null && (int)$r['sold'] < 10];
    };
    foreach ($counties as $r) $blocks[] = $countyBlock($r, market_ptype_words($pt));

    // The metro figures (REcolorado's Market Watch, PPAR's headline): what the post states, all residential.
    $metroBlock = null;
    if ($metro) {
        $t = [];
        $pc = fn($v) => $v === null ? null : (float)$v / 100;
        $t[] = market_flyer_tile('Closed sales', market_int($metro['closed'] ?? null), $pc($metro['closed_change_pct'] ?? null), $w);
        if (($metro['average'] ?? null) !== null) $t[] = market_flyer_tile('Average price', market_money($metro['average']), $pc($metro['average_change_pct'] ?? null), $w);
        $t[] = market_flyer_tile('Median price', market_money($metro['median'] ?? null), $pc($metro['median_change_pct'] ?? null), $w);
        if (($metro['median_days_in_mls'] ?? null) !== null) $t[] = market_flyer_tile('Median days in MLS', market_int($metro['median_days_in_mls']), null, $w, '', market_flyer_days($metro['days_change'] ?? null, $w));
        if (($metro['new_listings'] ?? null) !== null) $t[] = market_flyer_tile('New listings', market_int($metro['new_listings']), $pc($metro['new_listings_change_pct'] ?? null), $w);
        if (($metro['pending'] ?? null) !== null) $t[] = market_flyer_tile('Pending', market_int($metro['pending']), $pc($metro['pending_change_pct'] ?? null), $w);
        if (($metro['active'] ?? null) !== null) $t[] = market_flyer_tile('Active listings', market_int($metro['active']), $pc($metro['active_change_pct'] ?? null), $w);
        if (($metro['weeks_of_inventory'] ?? null) !== null) $t[] = market_flyer_tile('Weeks of inventory', market_int($metro['weeks_of_inventory']), null, $w);
        if (($metro['avg_days_on_market'] ?? null) !== null) $t[] = market_flyer_tile('Average days on market', market_int($metro['avg_days_on_market']), null, $w);
        $metroBlock = ['title' => (string)$metro['region'], 'sub' => trim((string)($metro['mls'] ?? '') . ' · ' . (string)($metro['scope'] ?? 'All residential'), ' ·'), 'tiles' => array_slice($t, 0, 8)];
    }

    // Town table (Aspen Glenwood MLS): the report's columns, minus the two the page keeps for the screen.
    $townRows = [];
    foreach ($towns as $t) {
        $townRows[] = [
            'town'    => (string)$t['town'],
            'sold'    => market_int($t['sold']),            'sold_chg'   => market_flyer_signed(market_change($t['sold'], $t['sold_ly'])),
            'median'  => market_money($t['median']),        'median_chg' => market_flyer_signed(market_change($t['median'], $t['median_ly'])),
            'ytd_sold' => market_int($t['ytd_sold']),       'ytd_median' => market_money($t['ytd_median']),
            'ytd_chg' => market_flyer_signed(market_change($t['ytd_median'], $t['ytd_median_ly'])),
            'dom'     => market_int($t['days_on_market']),  'msi' => market_dec1($t['months_supply']), 'inv' => market_int($t['inventory']),
        ];
    }

    // Town by town from our own MLS feed (inc/market_feed.php), the report's second town table:
    // the Communities towns this board serves, for the chosen class, most year-to-date sales first.
    $feedRows = [];
    $cls = $pt;
    foreach ((array)($data['towns_feed'] ?? []) as $t) {
        if (($t['market'] ?? '') !== $market || empty($t[$cls])) continue;
        $p = $t[$cls]; $mo = (array)($p['month'] ?? []); $ly = (array)($p['ly'] ?? []); $ytd = (array)($p['ytd'] ?? []); $yl = (array)($p['ytd_ly'] ?? []);
        if (empty($mo['sold']) && empty($ytd['sold'])) continue;
        $feedRows[] = [
            'town'    => (string)$t['label'], 'ytd_n' => (int)($ytd['sold'] ?? 0),
            'sold'    => market_int($mo['sold'] ?? null), 'sold_chg' => market_flyer_signed(market_change($mo['sold'] ?? null, $ly['sold'] ?? null)),
            'median'  => !empty($mo['sold']) ? market_money($mo['median'] ?? null) : '–', 'median_chg' => market_flyer_signed(market_change($mo['median'] ?? null, $ly['median'] ?? null)),
            'ppsf'    => ($mo['price_per_sqft'] ?? null) !== null ? '$' . market_int($mo['price_per_sqft']) : '–',
            'dom'     => !empty($mo['sold']) ? market_int($mo['avg_dom'] ?? null) : '–',
            'ytd_sold' => market_int($ytd['sold'] ?? null), 'ytd_median' => market_money($ytd['median'] ?? null),
            'ytd_chg' => market_flyer_signed(market_change($ytd['median'] ?? null, $yl['median'] ?? null)),
            'inv'     => isset($p['inventory']['active']) ? market_int($p['inventory']['active']) : '–',
        ];
    }
    usort($feedRows, fn($a, $b) => [$b['ytd_n'], $a['town']] <=> [$a['ytd_n'], $b['town']]);

    // What fits on the page, in order of worth to a client: the chosen type's
    // counties and the metro figures always; then the board's town table, our
    // feed's town table, and the other property type's counties, each only when
    // there is room (about 546 pt of body under the header and over the footer).
    // (Measured on the first samples, 2026-11-08: a block of eight tiles is 125 pt, a
    // table row 15 pt, each section adds the 12 pt gap, and the footer grows a
    // line per credit, so the body has about 500 pt to give.)
    // Measured on the samples of 2026-11-08: a block of eight tiles is about 153 pt
    // plus the 12 pt gap, a block of one tile row 60 pt less, a table 48 pt plus
    // 16 pt a row, and the body has about 546 pt between the header and the footer.
    $budget = 546.0;
    $cost = fn(array $b) => 12.0 + 153.0 - (count($b['tiles']) <= 4 ? 60.0 : 0.0) + (!empty($b['thin']) ? 14.0 : 0.0);
    foreach ($blocks as $b) $budget -= $cost($b);
    if ($metroBlock) $budget -= $cost($metroBlock);
    $townRowsOut = []; $feedOut = []; $otherBlocks = [];
    if ($townRows && $budget >= 48 + 16 * count($townRows)) { $townRowsOut = $townRows; $budget -= 48 + 16 * count($townRows); }
    if ($feedRows) {   // as many towns as fit, biggest first: all of them, or three at least
        $n = min(count($feedRows), (int)floor(($budget - 48) / 16));
        if ($n >= 1 && ($n === count($feedRows) || $n >= 3)) { $feedOut = array_slice($feedRows, 0, $n); $budget -= 48 + 16 * $n; }
    }
    if ($others) {
        $ob = array_map(fn($r) => $countyBlock($r, market_ptype_words($otherPt)), $others);
        $need = array_sum(array_map($cost, $ob));
        if ($budget >= $need) { $otherBlocks = $ob; $budget -= $need; }
    }

    $credits = [];
    if ($counties && !empty($board['county_credit'])) $credits[] = (string)$board['county_credit'];
    if ($towns && !empty($board['credit']) && $board['credit'] !== ($board['county_credit'] ?? '')) $credits[] = (string)$board['credit'];
    if ($metro) {
        $credits[] = $market === 'recolorado'
            ? "Metro figures from REcolorado Market Watch, {$w['month']}. Data courtesy of REcolorado."
            : "Regional figures from the Pikes Peak REALTOR® Services (elevateMLS) market trends page, {$w['month']}.";
    }
    if (!$counties && $metro && !empty($board['credit'])) $credits[] = (string)$board['credit'];
    if ($feedOut) $credits[] = 'Town figures from the MLS listing data Mont Haus receives (IDX), compiled by Mont Haus International Realty; for sale is the count at month end.';

    return [
        'market'  => $market,
        'name'    => (string)$board['name'],
        'mls'     => (string)$board['mls'],
        'areas'   => (string)($board['areas'] ?? ''),
        'month'   => $m,
        'label'   => $w['month'],
        'ly'      => $w['ly'],   // "Oct 2025", for the table headings
        'ptype'   => $pt,
        'ptwords' => $otherBlocks ? market_ptype_words($pt) . ' and ' . lcfirst(market_ptype_words($otherPt)) : market_ptype_words($pt),
        'as_of'   => (string)($data['meta']['current_as_of'] ?? ''),
        'blocks'  => $blocks,
        'metro'   => $metroBlock,
        'towns'   => $townRowsOut,
        'feed'    => $feedOut,
        'other'   => $otherBlocks,
        'credits' => array_values(array_unique($credits)),
        'agent'   => $agent,
    ];
}

/** One tile: label, value, "+12.5% vs Oct 2025" (or $alt when given), and a small second line. */
function market_flyer_tile(string $label, string $value, ?float $chg, array $w, string $small = '', ?string $alt = null): array {
    $s  = $chg === null ? '' : market_flyer_signed($chg);
    $vs = $alt ?? ($s === '' ? '' : ($s === 'Even' ? 'Even with ' . $w['ly'] : $s . ' vs ' . $w['ly']));
    return ['label' => $label, 'value' => $value, 'vs' => $vs, 'dir' => $alt !== null ? 0 : market_dir($chg), 'small' => $small];
}

function market_flyer_signed(?float $f): string {
    if ($f === null) return '–';
    $p = $f * 100;
    if (abs($p) < 0.05) return 'Even';
    return ($p > 0 ? '+' : '−') . number_format(abs($p), 1) . '%';
}

function market_flyer_paren(?float $f): string { return $f === null ? '' : ' (' . market_flyer_signed($f) . ')'; }

/** Percentage points for % of list: "+3.0 pts vs Oct 2025". */
function market_flyer_pts($now, $ly, array $w): ?string {
    if ($now === null || $ly === null) return '';
    $d = (float)$now - (float)$ly;
    if (abs($d) < 0.05) return 'Even with ' . $w['ly'];
    return ($d > 0 ? '+' : '−') . number_format(abs($d), 1) . ' pts vs ' . $w['ly'];
}

/** Days change for the metro line: "4 days fewer than Oct 2025". */
function market_flyer_days($d, array $w): ?string {
    if ($d === null) return '';
    $d = (int)$d;
    if ($d === 0) return 'Same as ' . $w['ly'];
    return abs($d) . ' day' . (abs($d) === 1 ? '' : 's') . ($d < 0 ? ' fewer' : ' more') . ' than ' . $w['ly'];
}

/** "market-report-roaring-fork-valley-oct-2026.pdf" (condos add -condos). */
function market_flyer_filename(array $f): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $f['name']), '-')) ?: $f['market'];
    return 'market-report-' . $slug . ($f['ptype'] === 'TC' ? '-condos' : '') . '-' . strtolower(str_replace(' ', '-', market_month_short($f['month']))) . '.pdf';
}

/** The complete HTML document. $export embeds the fonts, logo and headshot for Chromium. */
function market_flyer_render(array $f, ?string $base = null, bool $export = false): string {
    $base = rtrim($base ?? (defined('SITE_URL') ? SITE_URL : ''), '/');
    $e    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $W = MARKET_FLYER_W; $H = MARKET_FLYER_H;

    $asset = function (string $rel, string $mime) use ($base, $export): string {
        $disk = __DIR__ . '/../' . $rel;
        if ($export && is_file($disk)) return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($disk));
        return $base . '/' . $rel;
    };
    $fJost = $asset('assets/fonts/mh/Jost-normal.woff2', 'font/woff2');
    $fCorm = $asset('assets/fonts/mh/CormorantGaramond-normal.woff2', 'font/woff2');
    $logo  = $asset('assets/images/monthaus_black-logo_FINAL.svg', 'image/svg+xml');

    // The agent's headshot: a disk path (export) or a URL; nothing when there is none.
    $photo = '';
    $pp = (string)($f['agent']['photo'] ?? '');
    if ($pp !== '') {
        if ($export && is_file($pp)) {
            $mime = function_exists('mime_content_type') ? (mime_content_type($pp) ?: 'image/jpeg') : 'image/jpeg';
            $photo = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($pp));
        } elseif (!$export) {
            $photo = (string)($f['agent']['photo_url'] ?? '');
        }
    }

    // The slate scheme (main.css light theme, 2026-10-28), in print.
    $css = <<<CSS
@font-face{font-family:'Jost';font-style:normal;font-weight:100 900;src:url({$fJost}) format('woff2')}
@font-face{font-family:'Cormorant Garamond';font-style:normal;font-weight:300 700;src:url({$fCorm}) format('woff2')}
@page{size:{$W}pt {$H}pt;margin:0}
html,body{margin:0;padding:0}
body{width:{$W}pt;height:{$H}pt;overflow:hidden;background:#fff;color:#1f242c;font-family:'Jost','Helvetica Neue',Arial,sans-serif;font-size:9.5pt;line-height:1.35;
     -webkit-font-smoothing:antialiased;box-sizing:border-box;padding:34pt 38pt 30pt;display:flex;flex-direction:column;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.serif{font-family:'Cormorant Garamond',Georgia,serif}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:20pt;flex:none;padding-bottom:12pt;border-bottom:1.5pt solid #1f242c}
.head .l{min-width:0;flex:1}
.eyebrow{font-size:7.5pt;letter-spacing:.22em;text-transform:uppercase;color:#4a5566;font-weight:500;margin:0 0 5pt}
.title{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:30pt;line-height:1.02;margin:0;color:#1f242c}
.sub{font-size:9.5pt;color:#5c6574;margin:5pt 0 0;line-height:1.4}
.sub b{color:#1f242c;font-weight:500}
.head .logo{height:46pt;width:auto;flex:none;margin-top:2pt}
.body{flex:1;min-height:0;overflow:hidden;display:flex;flex-direction:column;gap:12pt;padding-top:12pt}
.block{flex:none}
.bhead{display:flex;align-items:baseline;justify-content:space-between;gap:12pt;margin:0 0 5pt}
.bhead h2{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:16pt;line-height:1.1;margin:0}
.bhead span{font-size:7.5pt;letter-spacing:.14em;text-transform:uppercase;color:#6b7a8f}
.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:5pt}
.tile{background:#f6f7f9;border:.6pt solid #dfe3e8;padding:6pt 8pt 5pt;min-height:46pt;display:flex;flex-direction:column;justify-content:flex-start}
.tile .lab{font-size:6.8pt;letter-spacing:.14em;text-transform:uppercase;color:#6b7a8f;line-height:1.2}
.tile .v{font-family:'Cormorant Garamond',Georgia,serif;font-size:17pt;font-weight:500;line-height:1.05;margin-top:3pt;color:#1f242c;white-space:nowrap}
.tile .vs{font-size:7.4pt;color:#5c6574;margin-top:2pt;line-height:1.25}
.tile .vs.up::before{content:'▲ ';font-size:5.5pt;color:#3d6b4f;vertical-align:1pt}
.tile .vs.down::before{content:'▼ ';font-size:5.5pt;color:#8a4b3c;vertical-align:1pt}
.tile .sm{font-size:6.8pt;color:#8a94a3;margin-top:1pt;line-height:1.25}
.thin{font-size:7.4pt;color:#8a5a12;margin:4pt 0 0}
table{width:100%;border-collapse:collapse;font-size:8pt;table-layout:auto}
th{font-weight:500;font-size:6.3pt;letter-spacing:.1em;text-transform:uppercase;color:#6b7a8f;text-align:right;padding:0 3pt 4pt;border-bottom:.75pt solid #1f242c;white-space:normal;line-height:1.2;vertical-align:bottom}
td{text-align:right;padding:3pt 3pt;border-bottom:.5pt solid #dfe3e8;white-space:nowrap;font-variant-numeric:tabular-nums}
th.l,td.l{text-align:left;padding-left:0}
td.l{font-weight:500}
td.chg{color:#5c6574;font-size:7.6pt}
.foot{flex:none;border-top:1.5pt solid #1f242c;margin-top:auto;padding-top:11pt;display:flex;align-items:flex-start;gap:14pt}
.who{display:flex;align-items:center;gap:10pt;flex:none;max-width:250pt}
.who .ph{width:50pt;height:50pt;border-radius:50%;object-fit:cover;flex:none;background:#e9edf2}
.who .nm{font-family:'Cormorant Garamond',Georgia,serif;font-size:17pt;font-weight:500;line-height:1.1;white-space:nowrap}
.who .ct{font-size:7.6pt;letter-spacing:.06em;text-transform:uppercase;color:#5c6574;line-height:1.5;margin-top:2pt}
.who .ct b{color:#1f242c;font-weight:500}
.credit{flex:1;min-width:0;font-size:6.2pt;line-height:1.4;color:#8a94a3;text-align:right}
.credit p{margin:0 0 2pt}
.credit .brand{color:#4a5566;font-weight:500;letter-spacing:.1em;text-transform:uppercase;font-size:6.6pt;margin-top:3pt}
CSS;

    $blockHtml = function (array $b) use ($e): string {
        $h = '<section class="block"><div class="bhead"><h2>' . $e($b['title']) . '</h2><span>' . $e($b['sub']) . '</span></div><div class="tiles">';
        foreach ($b['tiles'] as $t) {
            $cls = $t['dir'] > 0 ? ' up' : ($t['dir'] < 0 ? ' down' : '');
            $h .= '<div class="tile"><div class="lab">' . $e($t['label']) . '</div><div class="v">' . $e($t['value']) . '</div>';
            if ($t['vs'] !== '') $h .= '<div class="vs' . $cls . '">' . $e($t['vs']) . '</div>';
            if ($t['small'] !== '') $h .= '<div class="sm">' . $e($t['small']) . '</div>';
            $h .= '</div>';
        }
        $h .= '</div>';
        if (!empty($b['thin'])) $h .= '<p class="thin">Fewer than ten sales this month: the year-to-date figures are the steadier read.</p>';
        return $h . '</section>';
    };

    $body = '';
    foreach ($f['blocks'] as $b) $body .= $blockHtml($b);
    if ($f['metro']) $body .= $blockHtml($f['metro']);
    if ($f['towns']) {
        $body .= '<section class="block"><div class="bhead"><h2>Town by town</h2><span>' . $e($f['mls']) . ' town reports</span></div>'
               . '<table><thead><tr><th class="l">Town</th><th>Closed</th><th>vs ' . $e($f['ly']) . '</th><th>Median</th><th>vs ' . $e($f['ly']) . '</th>'
               . '<th>Closed YTD</th><th>Median YTD</th><th>YTD vs ' . $e(substr($f['ly'], -4)) . '</th><th>Days on mkt</th><th>Mo. supply</th><th>For sale</th></tr></thead><tbody>';
        foreach ($f['towns'] as $t) {
            $body .= '<tr><td class="l">' . $e($t['town']) . '</td><td>' . $e($t['sold']) . '</td><td class="chg">' . $e($t['sold_chg']) . '</td><td>' . $e($t['median']) . '</td><td class="chg">' . $e($t['median_chg']) . '</td>'
                   . '<td>' . $e($t['ytd_sold']) . '</td><td>' . $e($t['ytd_median']) . '</td><td class="chg">' . $e($t['ytd_chg']) . '</td><td>' . $e($t['dom']) . '</td><td>' . $e($t['msi']) . '</td><td>' . $e($t['inv']) . '</td></tr>';
        }
        $body .= '</tbody></table></section>';
    }
    if (!empty($f['feed'])) {
        $body .= '<section class="block"><div class="bhead"><h2>Town by town, from the MLS feed</h2><span>' . $e($f['ptwords'] === market_ptype_words($f['ptype']) ? $f['ptwords'] : market_ptype_words($f['ptype'])) . '</span></div>'
               . '<table><thead><tr><th class="l">Town</th><th>Closed</th><th>vs ' . $e($f['ly']) . '</th><th>Median</th><th>vs ' . $e($f['ly']) . '</th><th>$ / sq ft</th><th>Days on mkt</th>'
               . '<th>Closed YTD</th><th>Median YTD</th><th>YTD vs ' . $e(substr($f['ly'], -4)) . '</th><th>For sale</th></tr></thead><tbody>';
        foreach ($f['feed'] as $t) {
            $body .= '<tr><td class="l">' . $e($t['town']) . '</td><td>' . $e($t['sold']) . '</td><td class="chg">' . $e($t['sold_chg']) . '</td><td>' . $e($t['median']) . '</td><td class="chg">' . $e($t['median_chg']) . '</td>'
                   . '<td>' . $e($t['ppsf']) . '</td><td>' . $e($t['dom']) . '</td><td>' . $e($t['ytd_sold']) . '</td><td>' . $e($t['ytd_median']) . '</td><td class="chg">' . $e($t['ytd_chg']) . '</td><td>' . $e($t['inv']) . '</td></tr>';
        }
        $body .= '</tbody></table></section>';
    }
    foreach ((array)($f['other'] ?? []) as $b) $body .= $blockHtml($b);

    $a = $f['agent'];
    $who = '<div class="who">';
    if ($photo !== '') $who .= '<img class="ph" src="' . $e($photo) . '" alt="">';
    $who .= '<div><div class="nm">' . $e($a['name'] !== '' ? $a['name'] : 'Mont Haus International Realty') . '</div><div class="ct">';
    $lines = [];
    if (($a['phone'] ?? '') !== '') $lines[] = '<b>' . $e($a['phone']) . '</b>';
    if (($a['email'] ?? '') !== '') $lines[] = $e($a['email']);
    $lines[] = 'monthaus.com';
    $who .= implode('<br>', $lines) . '</div></div></div>';

    $credit = '<div class="credit">';
    foreach ($f['credits'] as $c) $credit .= '<p>' . $e($c) . '</p>';
    $credit .= '<p>Monthly figures in small markets swing on a handful of sales; year-to-date figures are steadier. <span style="white-space:nowrap">Prepared ' . $e(date('M j, Y')) . '.</span></p>';
    $credit .= '<p class="brand">Mont Haus International Realty</p></div>';

    $sub = '<b>' . $e($f['mls']) . '</b>' . ($f['areas'] !== '' ? ' · ' . $e($f['areas']) : '');

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $e($f['name'] . ', ' . $f['label']) . '</title><style>' . $css . '</style></head><body>'
         . '<header class="head"><div class="l"><p class="eyebrow">Market Report · ' . $e($f['label']) . ' · ' . $e($f['ptwords']) . '</p>'
         . '<h1 class="title">' . $e($f['name']) . '</h1><p class="sub">' . $sub . '</p></div>'
         . '<img class="logo" src="' . $e($logo) . '" alt="Mont Haus International Realty"></header>'
         . '<main class="body">' . $body . '</main>'
         . '<footer class="foot">' . $who . $credit . '</footer>'
         . '</body></html>';
}
