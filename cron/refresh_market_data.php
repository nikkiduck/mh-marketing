<?php
/**
 * cron/refresh_market_data.php — the Monthly Market Report refreshes itself
 * (2026-11-05, Nikki: "we need to make this automatic each month").
 *
 * What used to be a by-hand session around the 12th (docs/HANDOFF-market-report.md
 * and the artifact's CLAUDE.md, section 7) is this script, run daily by cron
 * from the 5th to the 25th. For month M (the last full month) it:
 *
 *   1. asks CAR's sortable tables for M (ShowingTime+, 0SF.htm and 0TC.htm).
 *      Not there yet = CAR has not posted the month: log it and stop. Never
 *      fill in numbers we do not have. On the 15th and after, one "still
 *      waiting" email.
 *   2. reads the 12 county PDFs (pdftotext, poppler-utils) → county.json,
 *      and the "Current as of" date from their footer;
 *   3. reads the 8 Aspen Glenwood MLS town PDFs → towns.json (a town whose
 *      PDF is not up yet is "late", filled in on a later day's run);
 *   4. reads REcolorado's "<Month YYYY> Housing Market Reports" post and
 *      PPAR's headline → front_range.json (a figure the post does not state
 *      is null, never guessed, and named in the email);
 *   5. appends M to county_trend.json from the tables (24 months kept),
 *      rewrites the month and dates in boards.json, writes meta.json;
 *   6. CHECKS: every county's sold, new listings, median and inventory, and
 *      their changes, must match CAR's tables exactly. Any mismatch = nothing
 *      is published and the email says what disagreed;
 *   7. publishes the folder to MARKET_DATA_RUNTIME_DIR/<YYYY-MM>/
 *      (/var/log/mh-marketing/market_data, outside the repo like the boards
 *      cache; inc/market_data.php reads it before inc/market_data/). The
 *      report, the cards and the Month picker follow by themselves;
 *   8. emails MARKET_REPORT_EMAILS (JB and Nikki): the month, the notable
 *      moves, anything late or missing.
 *
 * A month already on file and complete is left alone (--force rebuilds it).
 * A month on file with something late is rebuilt each day until it is
 * complete or the 25th passes.
 *
 * Flags: --month=YYYY-MM (default: last full month), --from=YYYY-MM (the
 * month whose boards.json and trend to carry forward; default: the newest
 * earlier month on file), --dry-run (build and check, publish nowhere, send
 * nothing), --out=DIR (write the six files there instead of publishing;
 * implies no email), --force, --no-email.
 * Log lines carry a UTC stamp; "✓ Done" / "✗" for cron/check_health.php.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
date_default_timezone_set('UTC');
set_time_limit(0);

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';          // SENDGRID_API_KEY for hs_mail
require_once __DIR__ . '/../inc/hs_mail.php';
require_once __DIR__ . '/../inc/market_data.php';  // market_months(), market_load(), MARKET_DATA_RUNTIME_DIR

// ── Options ──────────────────────────────────────────────────────────────────
$opts    = getopt('', ['month::', 'from::', 'dry-run', 'out::', 'force', 'no-email']);
$dry     = isset($opts['dry-run']);
$force   = isset($opts['force']);
$outDir  = isset($opts['out']) && $opts['out'] !== false ? rtrim((string)$opts['out'], '/') : '';
$noMail  = $dry || $outDir !== '' || isset($opts['no-email']);
$month   = isset($opts['month']) && $opts['month'] !== false ? (string)$opts['month'] : date('Y-m', strtotime('first day of last month'));
$fromMonth = isset($opts['from']) && $opts['from'] !== false ? (string)$opts['from'] : '';   // the month to carry boards.json and the trend forward from (default: the newest earlier month on file)
if (!preg_match('/^\d{4}-\d{2}$/', $month) || ($fromMonth !== '' && !preg_match('/^\d{4}-\d{2}$/', $fromMonth))) { fwrite(STDERR, "bad --month / --from\n"); exit(2); }

const MR_UA        = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const MR_CAR_BASE  = 'https://marketstatsreports.showingtime.com/CAR-Colorado_hqac0/sst/';
const MR_AGMLS     = 'https://aspenglenwoodmls.com/statistics/';
const MR_RECO      = 'https://recolorado.com/';
const MR_PPAR      = 'https://ppar.com/pikes-peak-region-real-estate-market-trends/';
const MR_TREND_MONTHS = 24;
const MR_LATE_NOTICE_DAY = 15;   // "still waiting on CAR" email from this day of the month

/** The 12 counties and the board each one is reported under (the artifact's fixed list). */
const MR_COUNTIES = [
    'Pitkin' => 'aspen', 'Garfield' => 'aspen', 'Eagle' => 'vail', 'San Miguel' => 'telluride',
    'Ouray' => 'cren', 'Summit' => 'altitude', 'Routt' => 'routt', 'El Paso' => 'elevate',
    'Teller' => 'elevate', 'Montrose' => 'cren', 'La Plata' => 'cren', 'Denver' => 'recolorado',
];
/** The 8 Aspen Glenwood MLS town reports (file name stem on aspenglenwoodmls.com). */
const MR_TOWNS = [
    'Aspen' => 'Aspen', 'Snowmass Village' => 'Snowmass-Village', 'Woody Creek' => 'Woody-Creek',
    'Old Snowmass' => 'Old-Snowmass', 'Basalt' => 'Basalt', 'Missouri Heights' => 'Missouri-Heights',
    'Carbondale' => 'Carbondale', 'Glenwood Springs' => 'Glenwood-Springs',
];
const MR_PTYPES = ['SF' => 'Single family', 'TC' => 'Townhouse-condo'];

$logDir   = defined('LOG_DIR') ? LOG_DIR : '/var/log/mh-marketing';
$stateFile = $logDir . '/market_refresh.state.json';

function out(string $s): void { echo '[' . gmdate('Y-m-d H:i:s') . "] $s\n"; }

// ── Fetching ─────────────────────────────────────────────────────────────────

/** GET a URL: ['code' => int, 'body' => string, 'url' => final url]. */
function mr_get(string $url, int $timeout = 40): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => MR_UA,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/pdf,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_error($ch);
    if ($body === false) return ['code' => 0, 'body' => '', 'url' => $url, 'error' => $err];
    return ['code' => $code, 'body' => (string)$body, 'url' => $final];
}

/** A PDF's text, -layout, via poppler's pdftotext. Null when the bytes are not a PDF or the tool fails. */
function mr_pdf_text(string $bytes): ?string {
    if (strncmp($bytes, '%PDF', 4) !== 0) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'mr-');
    file_put_contents($tmp, $bytes);
    $txt = shell_exec('/usr/bin/pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    @unlink($tmp);
    return is_string($txt) && trim($txt) !== '' ? $txt : null;
}

// ── Numbers ──────────────────────────────────────────────────────────────────

/** "$8,452,500" → 8452500; "92.6%" → 92.6; "1,108" → 1108; "--", "" → null. Whole floats come back as int. */
function mr_num(?string $s) {
    if ($s === null) return null;
    $s = trim(str_replace(["\xc2\xa0", ','], ['', ''], $s));
    if ($s === '' || $s === '--' || $s === '-' || $s === '–') return null;
    if (!preg_match('/^[+\-]?\s*\$?\s*([0-9]+(?:\.[0-9]+)?)\s*%?$/', $s, $m)) return null;
    $neg = $s[0] === '-';
    $v = (float)$m[1] * ($neg ? -1 : 1);
    return mr_whole($v);
}
/** 12.0 → 12 (int), 14.9 → 14.9. */
function mr_whole($v) {
    if ($v === null) return null;
    $v = (float)$v;
    return (floor($v) == $v && abs($v) < 1e15) ? (int)$v : $v;
}
/** "+ 17.6%" → 17.6; "- 4.5%" → -4.5; "0.0%" → 0; "--" → null. */
function mr_pct(?string $s): ?float {
    if ($s === null) return null;
    $s = trim(str_replace("\xc2\xa0", ' ', $s));
    if ($s === '' || $s === '--') return null;
    if (!preg_match('/^([+\-−])?\s*([0-9]+(?:\.[0-9]+)?)\s*%$/u', $s, $m)) return null;
    $v = (float)$m[2];
    return ($m[1] === '-' || $m[1] === '−') ? -$v : $v;
}
/** (now − ly) / ly × 100, one decimal as CAR prints it; null when either is missing or ly is 0. */
function mr_change_pct($now, $ly): ?float {
    if ($now === null || $ly === null || (float)$ly == 0.0) return null;
    return round(((float)$now - (float)$ly) / (float)$ly * 100, 1);
}

// ── CAR sortable tables (source A) ───────────────────────────────────────────

/**
 * Table A rows: [county => ['closed','closed_chg','new','new_chg','median','median_chg','inv','inv_chg']].
 * Throws when the page is not the month asked for.
 */
function mr_parse_table(string $html, string $monthLabel): array {
    if (stripos($html, 'charset=windows-1252') !== false || !mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }
    $flat = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (stripos($flat, $monthLabel) === false) {
        throw new RuntimeException("table does not say \"$monthLabel\"" . (preg_match('/((?:January|February|March|April|May|June|July|August|September|October|November|December) \d{4})/', $flat, $m) ? " (it says {$m[1]})" : ''));
    }
    $out = [];
    foreach (preg_split('/<tr\b[^>]*>/i', $html) as $tr) {
        if (!preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $tr, $m)) continue;
        $cells = array_map(fn($c) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($c), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $m[1]);
        if (count($cells) < 9 || !preg_match('/^(.+?) County$/u', $cells[0], $cm)) continue;
        $out[$cm[1]] = [
            'closed' => mr_num($cells[1]), 'closed_chg' => mr_pct($cells[2]),
            'new'    => mr_num($cells[3]), 'new_chg'    => mr_pct($cells[4]),
            'median' => mr_num($cells[5]), 'median_chg' => mr_pct($cells[6]),
            'inv'    => mr_num($cells[7]), 'inv_chg'    => mr_pct($cells[8]),
        ];
    }
    if (!$out) throw new RuntimeException('no county rows found in the table');
    return $out;
}

// ── Local Market Update PDFs (sources B and C) ───────────────────────────────

const MR_METRICS = [
    'New Listings' => 'new_listings', 'Sold Listings' => 'sold', 'Median Sales Price' => 'median',
    'Average Sales Price' => 'average', 'Percent of List Price Received' => 'pct_of_list',
    'Days on Market Until Sale' => 'days_on_market', 'Inventory of Homes for Sale' => 'inventory',
    'Months Supply of Inventory' => 'months_supply',
];

/**
 * A Local Market Update's text → ['month' => 'September 2026', 'current_as_of' => 'October 5, 2026',
 * 'blocks' => ['SF' => [metric => [ly, now, chg, ytd_ly, ytd_now, ytd_chg]], 'TC' => ...]].
 * The first Key Metrics block is single family, the second townhouse-condo.
 */
function mr_parse_lmu(string $text): array {
    $res = ['month' => null, 'current_as_of' => null, 'blocks' => []];
    if (preg_match('/Local Market Update for ((?:January|February|March|April|May|June|July|August|September|October|November|December) \d{4})/u', $text, $m)) $res['month'] = $m[1];
    if (preg_match('/Current as of ([A-Z][a-z]+ \d{1,2}, \d{4})/u', $text, $m)) $res['current_as_of'] = $m[1];
    $block = null;
    foreach (preg_split('/\R/u', $text) as $line) {
        $t = trim($line);
        if ($t === '') continue;
        if (preg_match('/^Single Family\b/u', $t) && !isset($res['blocks']['SF'])) { $block = 'SF'; $res['blocks']['SF'] = []; continue; }
        if (preg_match('/^Townhouse[\/\-]Condo\b/u', $t) && !isset($res['blocks']['TC'])) { $block = 'TC'; $res['blocks']['TC'] = []; continue; }
        if (preg_match('/^Median Sales Price\s+[–-]\s+/u', $t)) { $block = null; continue; }   // the charts after the tables
        if ($block === null) continue;
        foreach (MR_METRICS as $label => $key) {
            if (preg_match('/^' . preg_quote($label, '/') . '\*?\s{2,}(.+)$/u', $t, $m)) {
                $vals = preg_split('/\s{2,}/u', trim($m[1]));
                $res['blocks'][$block][$key] = array_pad(array_slice($vals, 0, 6), 6, null);
                break;
            }
        }
    }
    return $res;
}

/** One county/town row's metric fields from a parsed block. */
function mr_row_from_block(array $b): array {
    $r = [];
    foreach (['new_listings', 'sold', 'median', 'average', 'pct_of_list', 'days_on_market'] as $k) {
        $v = $b[$k] ?? [null, null, null, null, null, null];
        $r[$k] = mr_num($v[1]); $r[$k . '_ly'] = mr_num($v[0]);
        $r['ytd_' . $k] = mr_num($v[4]); $r['ytd_' . $k . '_ly'] = mr_num($v[3]);
    }
    foreach (['inventory', 'months_supply'] as $k) {
        $v = $b[$k] ?? [null, null, null, null, null, null];
        $r[$k] = mr_num($v[1]); $r[$k . '_ly'] = mr_num($v[0]);
    }
    // No sales in a period = no price, no % of list, no days: the PDF prints
    // "$0" and "0" there, the hand-made months wrote null (Old Snowmass,
    // Woody Creek), and a $0 median would read as a figure.
    foreach ([['', ''], ['', '_ly'], ['ytd_', ''], ['ytd_', '_ly']] as [$pre, $suf]) {
        if (empty($r[$pre . 'sold' . $suf])) {
            foreach (['median', 'average', 'pct_of_list', 'days_on_market'] as $k) $r[$pre . $k . $suf] = null;
        }
    }
    return $r;
}
/** A block with no listings at all (a town type the MLS does not have: Missouri Heights condos). */
function mr_block_empty(array $r): bool {
    foreach (['new_listings', 'new_listings_ly', 'ytd_new_listings', 'ytd_new_listings_ly', 'sold', 'sold_ly', 'ytd_sold', 'ytd_sold_ly', 'inventory', 'inventory_ly'] as $k) {
        if (!empty($r[$k])) return false;
    }
    return true;
}

// ── REcolorado's post (source D) and PPAR's headline (source E) ──────────────

/** The post's text, article body only, sentences kept. */
function mr_html_text(string $html): string {
    $html = preg_replace('#<(script|style|header|footer|nav)\b[^>]*>.*?</\1>#is', '', $html);
    if (preg_match('#<article\b.*?</article>#is', $html, $m) || preg_match('#<main\b.*?</main>#is', $html, $m)) $html = $m[0];
    $t = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/li|/h\d|/div)\b[^>]*>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace(["\xc2\xa0", '’'], [' ', "'"], $t);
    return preg_replace('/[ \t]+/u', ' ', $t);
}
/** Sentences of a text. */
function mr_sentences(string $t): array {
    return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+|\n+/u', $t)), fn($s) => $s !== ''));
}
/**
 * The clause about one metric: the first sentence matching the regex that also
 * carries a figure (REcolorado opens with a summary sentence that names every
 * metric and no number), cut down to the clause with the keyword when the
 * sentence joins two metrics (", while the median closed price fell 2% to
 * $575,000"). $needDollar: a price clause must carry a $ figure.
 */
function mr_find(array $sentences, string $re, bool $needDollar = false): ?string {
    foreach ($sentences as $s) {
        if (!preg_match($re, $s) || !preg_match('/\d/', $s)) continue;
        if ($needDollar && !str_contains($s, '$')) continue;
        $clauses = preg_split('/,\s*(?:while|and|but|whereas|as)\s+|;\s+/i', $s) ?: [$s];
        if (count($clauses) > 1) {
            foreach ($clauses as $c) if (preg_match($re, $c) && preg_match('/\d/', $c) && (!$needDollar || str_contains($c, '$'))) return $c;
        }
        return $s;
    }
    return null;
}
const MR_DOWN = '(decreas|declin|fell|fall|dropp|drop|down|lower|slow|shorten|shorter|fewer|less|slipp|slip|dipp|dip|eas(?:ed|ing)|soften|retreat|shrink|shrank|contract)';
const MR_UP   = '(increas|rose|rising|rise|up|grew|grow|higher|more|gain|climb|advanc|jump|surg|expand|lengthen|longer)';
/**
 * The figures in one of REcolorado's clauses: ['now', 'ly', 'pct'] where pct is the
 * whole percent they state, signed by the direction word next to it ("down 13%",
 * "a 21% decrease", "decrease of 20%", "fell 2%"); "from A to B" gives both
 * values, "from A" the last year's, "to B" this year's; a days clause may say
 * "four days shorter than last September".
 */
function mr_reco_sentence(?string $s): array {
    $r = ['now' => null, 'ly' => null, 'pct' => null, 'diff' => null];
    if ($s === null) return $r;
    $s = str_replace(',', '', $s);
    $num = '\$?(\d+(?:\.\d+)?)';
    $isPctOrYear = fn(string $n) => preg_match('/^(19|20)\d\d$/', $n) || preg_match('/(?<![\d.])' . preg_quote($n, '/') . '\s*%/', $s);
    if (preg_match('/from ' . $num . '(?: days| listings| properties)? to ' . $num . '/i', $s, $m)) { $r['ly'] = mr_whole((float)$m[1]); $r['now'] = mr_whole((float)$m[2]); }
    elseif (preg_match('/from ' . $num . '/i', $s, $m, PREG_OFFSET_CAPTURE)) {
        $r['ly'] = mr_whole((float)$m[1][0]);
        if (preg_match_all('/' . $num . '/', substr($s, 0, (int)$m[0][1]), $all)) {
            foreach ($all[1] as $cand) { if ($isPctOrYear($cand)) continue; $r['now'] = mr_whole((float)$cand); break; }
        }
    }
    elseif (preg_match('/\bto ' . $num . '/i', $s, $m)) { $r['now'] = mr_whole((float)$m[1]); }
    else {
        if (preg_match_all('/' . $num . '/', $s, $all)) foreach ($all[1] as $cand) { if ($isPctOrYear($cand)) continue; $r['now'] = mr_whole((float)$cand); break; }
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $s, $m, PREG_OFFSET_CAPTURE)) {
        $p = (float)$m[1][0];
        $win = substr($s, max(0, $m[0][1] - 32), 32 + strlen($m[0][0]) + 16);   // the words beside the percent
        $down = preg_match('/\b' . MR_DOWN . '/i', $win); $up = preg_match('/\b' . MR_UP . '/i', $win);
        if (!$down && !$up) { $down = preg_match('/\b' . MR_DOWN . '/i', $s); $up = preg_match('/\b' . MR_UP . '/i', $s); }
        $r['pct'] = $down && !$up ? -$p : ($up && !$down ? $p : (($r['now'] !== null && $r['ly'] !== null) ? ($r['now'] >= $r['ly'] ? $p : -$p) : null));
    } elseif ($r['now'] !== null && $r['ly'] !== null && (float)$r['ly'] != 0.0) {
        $r['pct'] = (float)round(((float)$r['now'] - (float)$r['ly']) / (float)$r['ly'] * 100);
    }
    // "four days shorter than last September", "2 days longer"
    $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];
    if (preg_match('/\b(\d+|' . implode('|', array_keys($words)) . ') days? (shorter|fewer|less|longer|more)\b/i', $s, $m)) {
        $n = is_numeric($m[1]) ? (int)$m[1] : $words[strtolower($m[1])];
        $r['diff'] = in_array(strtolower($m[2]), ['shorter', 'fewer', 'less'], true) ? -$n : $n;
    }
    return $r;
}
/** The "Year-Over-Year" part of a REcolorado post (before "Month-Over-Month" and the rental section). */
function mr_reco_yoy(string $text): string {
    $t = $text;
    if (($i = stripos($t, 'Year-Over-Year')) !== false) $t = substr($t, $i);
    foreach (['Month-Over-Month', 'Rental Market'] as $stop) if (($j = stripos($t, $stop)) !== false) $t = substr($t, 0, $j);
    return $t;
}
/**
 * REcolorado's Denver Metro row for the month: the fields of front_range.json,
 * plus 'notes' for anything the post does not state. $lyMedian: last year's
 * median when the post states no change for it (from last year's post).
 */
function mr_parse_recolorado(string $text, ?callable $lastYearMedian): array {
    $S = mr_sentences(mr_reco_yoy($text));
    $notes = [];
    $closed  = mr_reco_sentence(mr_find($S, '/\b(listings closed|closed listings|closings|homes closed|sales closed)\b/i'));
    $median  = mr_reco_sentence(mr_find($S, '/\bmedian closed price\b/i', true));
    $days    = mr_reco_sentence(mr_find($S, '/\bDays in MLS\b/i'));
    $newl    = mr_reco_sentence(mr_find($S, '/\bnew listing/i'));
    $pending = mr_reco_sentence(mr_find($S, '/\bpending\b/i'));
    $active  = mr_reco_sentence(mr_find($S, '/\bactive (listings|inventory)\b/i'));
    $weeks = null;
    if (preg_match('/(\d+(?:\.\d+)?) weeks of inventory/i', mr_reco_yoy($text), $m)) $weeks = mr_whole((float)$m[1]);
    if ($median['now'] !== null && $median['pct'] === null && $lastYearMedian) {
        $ly = $lastYearMedian();
        if ($ly) { $median['ly'] = $ly; $median['pct'] = (float)round(((float)$median['now'] - (float)$ly) / (float)$ly * 100); $notes[] = 'median change computed from last year\'s post ($' . number_format((float)$ly) . ')'; }
    }
    $row = [
        'closed' => $closed['now'], 'closed_change_pct' => mr_whole($closed['pct']),
        'median' => $median['now'], 'median_change_pct' => mr_whole($median['pct']),
        'median_days_in_mls' => $days['now'],
        'days_change' => ($days['now'] !== null && $days['ly'] !== null) ? mr_whole((float)$days['now'] - (float)$days['ly']) : $days['diff'],
        'new_listings' => $newl['now'], 'new_listings_change_pct' => mr_whole($newl['pct']),
        'pending' => $pending['now'], 'pending_change_pct' => mr_whole($pending['pct']),
        'active' => $active['now'], 'active_change_pct' => mr_whole($active['pct']),
        'weeks_of_inventory' => $weeks,
    ];
    foreach ($row as $k => $v) if ($v === null) $notes[] = "REcolorado: $k not stated in the post";
    return ['row' => $row, 'notes' => $notes];
}
/**
 * The address of REcolorado's "<Month> Housing Market Reports" post for a month,
 * through WordPress's own search API (the slugs vary: october-2026-housing-market-reports,
 * october-housing-market-reports for October 2025). The right post is the one
 * whose title names the month and which was published in the month or two
 * after it. Null when none is up yet.
 */
function mr_reco_post_url(string $ym): ?string {
    $name = market_month_name($ym); $year = market_year($ym);
    $r = mr_get(MR_RECO . 'wp-json/wp/v2/posts?search=' . rawurlencode("$name $year housing market reports") . '&per_page=20&_fields=date,link,title');
    $posts = $r['code'] === 200 ? json_decode($r['body'], true) : null;
    if (is_array($posts)) {
        $lo = date('Y-m-d', strtotime("$ym-01 +1 month")); $hi = date('Y-m-d', strtotime("$ym-01 +3 months"));
        foreach ($posts as $p) {
            $title = html_entity_decode(strip_tags((string)($p['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $date  = substr((string)($p['date'] ?? ''), 0, 10);
            if (preg_match('/^' . preg_quote($name, '/') . '(?: ' . $year . ')? housing market reports?$/i', trim($title)) && $date >= $lo && $date < $hi) return (string)$p['link'];
        }
    }
    $guess = mr_get(MR_RECO . strtolower($name) . '-' . $year . '-housing-market-reports/');
    return $guess['code'] === 200 ? $guess['url'] : null;
}
/** PPAR's headline for the month: ['closed', 'median', 'avg_days'] or null when the page shows another month. */
function mr_parse_ppar(string $html, string $monthLabel): ?array {
    $t = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (!preg_match('/' . preg_quote($monthLabel, '/') . ':\s*([\d,]+)\s*Closed Sales\s*\|\s*\$\s*([\d,.]+)\s*([KM])?\s*Median Sale Price(.*?)(\d+)\s*Avg Days On Market/iu', $t, $m)) return null;
    $median = (float)str_replace(',', '', $m[2]) * ($m[3] === 'K' || $m[3] === 'k' ? 1000 : (($m[3] === 'M' || $m[3] === 'm') ? 1e6 : 1));
    return ['closed' => mr_num($m[1]), 'median' => mr_whole($median), 'avg_days' => (int)$m[5]];
}

// ── The run ──────────────────────────────────────────────────────────────────

$monthLabel = market_month_label($month);          // "October 2026"
$yyyymm     = str_replace('-', '', $month);        // 202610
$year       = (int)substr($month, 0, 4);
$mnum       = (int)substr($month, 5, 2);
$lyMonth    = market_month_ly($month);
$runtimeDir = MARKET_DATA_RUNTIME_DIR;
$dest       = $outDir !== '' ? $outDir : $runtimeDir . '/' . $month;
$state      = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$recipients = array_values(array_filter(array_map('trim', explode(',', defined('MARKET_REPORT_EMAILS') ? MARKET_REPORT_EMAILS : (defined('PIPELINE_NOTIFY_EMAILS') ? PIPELINE_NOTIFY_EMAILS : '')))));

out("── refresh_market_data: $monthLabel" . ($dry ? ' (DRY RUN)' : '') . ($force ? ' (force)' : '') . ($outDir !== '' ? " → $outDir" : ''));

function save_state(): void {
    global $state, $stateFile, $dry, $outDir;
    if ($dry || $outDir !== '') return;
    @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
function send_report(string $subject, array $lines, array $notes = []): void {
    global $recipients, $noMail, $monthLabel;
    $html = '<div style="font:14px/1.5 -apple-system,Helvetica,Arial,sans-serif;color:#1f242c;max-width:640px">'
          . '<h2 style="font-weight:600;font-size:18px;margin:0 0 12px">' . htmlspecialchars($subject) . '</h2>';
    foreach ($lines as $l) $html .= '<p style="margin:0 0 10px">' . $l . '</p>';
    if ($notes) {
        $html .= '<p style="margin:14px 0 4px;font-weight:600">Notes</p><ul style="margin:0 0 10px;padding-left:20px">';
        foreach ($notes as $n) $html .= '<li>' . htmlspecialchars($n) . '</li>';
        $html .= '</ul>';
    }
    $html .= '<p style="margin:16px 0 0;color:#6b7280;font-size:12px">Market Report: <a href="' . (defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://marketing.monthaus.com') . '/market_report.php">admin</a> · '
           . '<a href="' . (defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://marketing.monthaus.com') . '/portal/market.php">My Tools</a>. '
           . 'Sent by cron/refresh_market_data.php on the marketing server; log /var/log/mh-marketing/market_refresh.log.</p></div>';
    foreach ($recipients as $to) {
        if ($noMail) { out("  (email not sent: $to · $subject)"); continue; }
        [$st, $why] = hs_send_email($to, $subject, $html);
        out("  email → $to: $st" . ($why ? " ($why)" : ''));
    }
}

// 0. Already on file?
$existing = null;
if ($outDir === '' && is_file("$dest/meta.json")) {
    $existing = json_decode((string)file_get_contents("$dest/meta.json"), true) ?: [];
    $late = $existing['late'] ?? [];
    if (!$force && !$late) { out("✓ Done: $monthLabel is on file and complete (" . ($existing['generated'] ?? '?') . "); nothing to do."); exit(0); }
    if ($late) out('  on file since ' . ($existing['generated'] ?? '?') . ', still late: ' . implode('; ', $late) . ' → rebuilding');
}

// 1. CAR's tables for the month.
$tables = [];
foreach (['SF' => '0SF.htm', 'TC' => '0TC.htm'] as $pt => $file) {
    $r = mr_get(MR_CAR_BASE . $yyyymm . '/' . $file);
    if ($r['code'] !== 200) {
        out("CAR has not posted $monthLabel yet (table $file: HTTP {$r['code']}). Nothing done.");
        $today = (int)gmdate('j');
        if ($today >= MR_LATE_NOTICE_DAY && ($state['late_notice'] ?? '') !== $month) {
            send_report("Market Report: $monthLabel is still waiting on CAR", [
                'CAR (ShowingTime+) has not posted its ' . htmlspecialchars($monthLabel) . ' sortable statistics yet, so the Market Report and the Market Snapshot cards still show ' . htmlspecialchars(market_month_label($lyMonth === '' ? $month : date('Y-m', strtotime("$month-01 -1 month")))) . '.',
                'The refresh runs every morning and publishes by itself the day the tables appear. If CAR is late for a reason, nothing needs doing; this is the only reminder.',
            ]);
            $state['late_notice'] = $month; save_state();
        }
        out('✓ Done: waiting for CAR.');
        exit(0);
    }
    try { $tables[$pt] = mr_parse_table($r['body'], $monthLabel); }
    catch (RuntimeException $e) { out("✗ CAR table $file: " . $e->getMessage()); exit(1); }
    out("  CAR table $pt: " . count($tables[$pt]) . ' counties');
}
$missingCounties = [];
foreach (MR_COUNTIES as $c => $mk) foreach (['SF', 'TC'] as $pt) if (!isset($tables[$pt][$c])) $missingCounties[] = "$c ($pt)";
if ($missingCounties) { out('✗ CAR table is missing: ' . implode(', ', $missingCounties)); exit(1); }

// The previous month on file: boards.json and the trend come from it.
$prevMonths = $fromMonth !== '' ? [$fromMonth] : array_values(array_filter(market_months(), fn($m) => $m < $month));
if (!$prevMonths) { out('✗ no earlier month on file to carry boards.json and county_trend.json forward from (name one with --from=YYYY-MM)'); exit(1); }
$prev = market_load($prevMonths[0]);
if (!$prev) { out("✗ could not load {$prevMonths[0]}"); exit(1); }
out("  carrying forward from {$prevMonths[0]}");
$boardMls = [];
foreach ($prev['boards'] as $b) $boardMls[$b['market']] = $b['mls'] ?? '';

$late = []; $notes = [];

// 2. County PDFs.
$county = []; $carAsOf = null;
foreach (MR_COUNTIES as $c => $mk) {
    $file = str_replace(' ', '-', $c) . '-County.pdf';
    $r = mr_get(MR_CAR_BASE . $yyyymm . '/' . $file, 60);
    $txt = $r['code'] === 200 ? mr_pdf_text($r['body']) : null;
    if ($txt === null) { out("✗ county PDF $file: HTTP {$r['code']}" . ($r['code'] === 200 ? ' (not a readable PDF)' : '')); exit(1); }
    $p = mr_parse_lmu($txt);
    if ($p['month'] !== $monthLabel) { out("✗ $file says \"{$p['month']}\", expected \"$monthLabel\""); exit(1); }
    if ($p['current_as_of'] && !$carAsOf) $carAsOf = $p['current_as_of'];
    foreach (['SF', 'TC'] as $pt) {
        if (empty($p['blocks'][$pt])) { out("✗ $file has no " . MR_PTYPES[$pt] . ' block'); exit(1); }
        $county[] = ['key' => "$c|$pt", 'market' => $mk, 'mls' => $boardMls[$mk] ?? '', 'county' => "$c County", 'ptype' => MR_PTYPES[$pt]]
                  + mr_row_from_block($p['blocks'][$pt]);
    }
}
out('  county PDFs: ' . count($county) . " rows, current as of " . ($carAsOf ?? '?'));
if (!$carAsOf) { out('✗ no "Current as of" date in the county PDFs'); exit(1); }

// 3. Aspen Glenwood town PDFs.
$towns = []; $townsAsOf = null;
$idx = mr_get(MR_AGMLS);
if ($idx['code'] !== 200) { $late[] = "Aspen Glenwood MLS statistics page: HTTP {$idx['code']}"; }
else {
    foreach (MR_TOWNS as $town => $stem) {
        $re = '~https?://[^"\'\s]*?/wp-content/uploads/\d{4}/\d{2}/' . preg_quote($stem, '~') . '-' . $mnum . '-' . substr((string)$year, 2) . '\.pdf~i';
        if (!preg_match($re, $idx['body'], $m)) { $late[] = "$town town report ($stem-$mnum-" . substr((string)$year, 2) . '.pdf) not on aspenglenwoodmls.com yet'; continue; }
        $r = mr_get($m[0], 60);
        $txt = $r['code'] === 200 ? mr_pdf_text($r['body']) : null;
        if ($txt === null) { $late[] = "$town town report: HTTP {$r['code']}"; continue; }
        $p = mr_parse_lmu($txt);
        if ($p['month'] !== $monthLabel) { $late[] = "$town town report says \"{$p['month']}\""; continue; }
        if ($p['current_as_of'] && !$townsAsOf) $townsAsOf = $p['current_as_of'];
        foreach (['SF', 'TC'] as $pt) {
            if (empty($p['blocks'][$pt])) continue;
            $row = mr_row_from_block($p['blocks'][$pt]);
            if (mr_block_empty($row)) continue;   // a type the town does not have (Missouri Heights condos)
            $towns[] = ['key' => "$town|$pt", 'market' => 'aspen', 'town' => $town, 'ptype' => MR_PTYPES[$pt]] + $row;
        }
    }
}
out('  town PDFs: ' . count($towns) . ' rows' . ($townsAsOf ? ", current as of $townsAsOf" : ''));

// 4. REcolorado and PPAR.
$front = [];
$prevReco = null; $prevPpar = null;
foreach ($prev['front_range'] as $f) { if (($f['market'] ?? '') === 'recolorado') $prevReco = $f; if (($f['market'] ?? '') === 'elevate') $prevPpar = $f; }
$recoUrl = mr_reco_post_url($month);
$r = $recoUrl ? mr_get($recoUrl) : ['code' => 404, 'body' => ''];
if ($r['code'] !== 200) {
    $late[] = "REcolorado's $monthLabel Housing Market Reports post is not up yet";
    $front[] = ['region' => $prevReco['region'] ?? 'Denver Metro (11 counties)', 'market' => 'recolorado', 'mls' => 'REcolorado', 'scope' => $prevReco['scope'] ?? 'All residential',
                'closed' => null, 'closed_change_pct' => null, 'median' => null, 'median_change_pct' => null, 'median_days_in_mls' => null, 'days_change' => null,
                'new_listings' => null, 'new_listings_change_pct' => null, 'pending' => null, 'pending_change_pct' => null, 'active' => null, 'active_change_pct' => null, 'weeks_of_inventory' => null];
} else {
    $lastYearMedian = function () use ($lyMonth): ?int {
        $url = mr_reco_post_url($lyMonth);
        $r = $url ? mr_get($url) : ['code' => 404, 'body' => ''];
        if ($r['code'] !== 200) return null;
        $S = mr_sentences(mr_reco_yoy(mr_html_text($r['body'])));
        $m = mr_reco_sentence(mr_find($S, '/\bmedian closed price\b/i'));
        return $m['now'] !== null ? (int)$m['now'] : null;
    };
    $pr = mr_parse_recolorado(mr_html_text($r['body']), $lastYearMedian);
    $front[] = ['region' => $prevReco['region'] ?? 'Denver Metro (11 counties)', 'market' => 'recolorado', 'mls' => 'REcolorado', 'scope' => $prevReco['scope'] ?? 'All residential'] + $pr['row'];
    $notes = array_merge($notes, $pr['notes']);
    out("  REcolorado post: $recoUrl");
    out('  REcolorado: closed ' . ($pr['row']['closed'] ?? '–') . ' (' . ($pr['row']['closed_change_pct'] ?? '–') . '%), median ' . ($pr['row']['median'] ?? '–') . ' (' . ($pr['row']['median_change_pct'] ?? '–') . '%), ' . count($pr['notes']) . ' notes');
}
$r = mr_get(MR_PPAR);
$pp = $r['code'] === 200 ? mr_parse_ppar($r['body'], $monthLabel) : null;
if (!$pp) $late[] = "PPAR's page does not show $monthLabel yet" . ($r['code'] !== 200 ? " (HTTP {$r['code']})" : '');
$front[] = ['region' => $prevPpar['region'] ?? 'Pikes Peak Region', 'market' => 'elevate', 'mls' => 'elevateMLS', 'scope' => $prevPpar['scope'] ?? 'All property types',
            'closed' => $pp['closed'] ?? null, 'closed_change_pct' => null, 'median' => $pp['median'] ?? null, 'median_change_pct' => null,
            'median_days_in_mls' => null, 'days_change' => null, 'new_listings' => null, 'new_listings_change_pct' => null, 'pending' => null,
            'pending_change_pct' => null, 'active' => null, 'active_change_pct' => null, 'weeks_of_inventory' => null, 'avg_days_on_market' => $pp['avg_days'] ?? null];
out('  PPAR: ' . ($pp ? "closed {$pp['closed']}, median {$pp['median']}, {$pp['avg_days']} days" : 'not yet'));

// 5. Trend, boards, meta.
$trend = array_values(array_filter($prev['county_trend'], fn($t) => ($t['month'] ?? '') !== $month));
foreach (MR_COUNTIES as $c => $mk) foreach (['SF', 'TC'] as $pt) {
    $t = $tables[$pt][$c];
    $trend[] = ['county' => "$c County", 'market' => $mk, 'ptype' => MR_PTYPES[$pt], 'month' => $month,
                'sold' => $t['closed'], 'new_listings' => $t['new'], 'median' => $t['median'], 'inventory' => $t['inv']];
}
$months = array_values(array_unique(array_column($trend, 'month'))); sort($months);
$keep = array_slice($months, -MR_TREND_MONTHS);
$trend = array_values(array_filter($trend, fn($t) => in_array($t['month'], $keep, true)));
usort($trend, fn($a, $b) => [$a['county'], $a['ptype'], $a['month']] <=> [$b['county'], $b['ptype'], $b['month']]);

$prevLabel = market_month_label($prev['month']);
$prevAsOf  = (string)($prev['meta']['current_as_of'] ?? '');
$boards = [];
foreach ($prev['boards'] as $b) {
    foreach (['credit', 'county_credit', 'source_url', 'areas'] as $k) {
        if (!isset($b[$k]) || !is_string($b[$k])) continue;
        $v = str_replace(['/sst/' . str_replace('-', '', $prev['month']) . '/', $prevLabel], ['/sst/' . $yyyymm . '/', $monthLabel], $b[$k]);
        if ($prevAsOf !== '') $v = str_replace($prevAsOf, ($k === 'credit' && $townsAsOf) ? $townsAsOf : $carAsOf, $v);
        $v = preg_replace('/© \d{4} ShowingTime/u', "© $year ShowingTime", $v);
        $b[$k] = $v;
    }
    $boards[] = $b;
}
$meta = [
    'month' => $month,
    'current_as_of' => $carAsOf,
    'sources' => [
        'county'       => "Colorado Association of REALTORS Local Market Updates for $monthLabel (ShowingTime+, current as of $carAsOf), one row per county and property type, read from each county PDF.",
        'county_trend' => 'Closed sales, new listings, median price and homes for sale per county, ' . market_month_label($keep[0]) . " to $monthLabel, from CAR monthly sortable statistics pages as first published each month.",
        'towns'        => "Aspen Glenwood MLS Local Market Updates by town for $monthLabel (ShowingTime+, current as of " . ($townsAsOf ?? $carAsOf) . '), read from each town PDF.',
        'front_range'  => "Metro headline figures for $monthLabel: REcolorado Market Watch and the PPAR Pikes Peak market trends page (elevateMLS, all property types).",
    ],
    'generated' => gmdate('Y-m-d H:i') . ' UTC',
    'built_by'  => 'cron/refresh_market_data.php',
    'late'      => array_values($late),
    'notes'     => array_values(array_unique($notes)),
];

// 6. CHECK against the tables. Nothing is published on a mismatch.
$bad = [];
foreach ($county as $row) {
    [$c, $pt] = explode('|', $row['key']);
    $t = $tables[$pt][$c];
    foreach ([['sold', 'closed'], ['new_listings', 'new'], ['median', 'median'], ['inventory', 'inv']] as [$rk, $tk]) {
        if ((string)$row[$rk] !== (string)$t[$tk]) $bad[] = "{$row['key']} $rk: PDF {$row[$rk]}, table {$t[$tk]}";
        $mine = mr_change_pct($row[$rk], $row[$rk . '_ly']);
        $theirs = $t[$tk . '_chg'];
        if ($mine !== null && $theirs !== null && abs($mine - $theirs) > 0.051) $bad[] = "{$row['key']} $rk change: from the PDF " . sprintf('%+.1f%%', $mine) . ', table ' . sprintf('%+.1f%%', $theirs);
    }
}
if ($bad) {
    out('✗ CHECK FAILED, not published: ' . count($bad) . ' disagreement(s)');
    foreach ($bad as $b) out("    $b");
    send_report("Market Report: $monthLabel NOT published, the check failed", [
        'The county PDFs and CAR\'s sortable tables disagree, so nothing was published. The report still shows ' . htmlspecialchars($prevLabel) . '.',
        '<pre style="font-size:12px">' . htmlspecialchars(implode("\n", $bad)) . '</pre>',
        'Usually this means CAR re-published the tables after the PDFs (or the other way round); the run repeats tomorrow. If it persists, the parser needs a look.',
    ]);
    exit(1);
}
out('  check: ' . count($county) . ' county rows match the tables (sold, new listings, median, inventory, and their changes)');

// 7. Write and publish.
$files = ['boards' => $boards, 'county' => $county, 'county_trend' => $trend, 'towns' => $towns, 'front_range' => $front, 'meta' => $meta];
$json = fn($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
if ($dry) {
    out('  DRY RUN: not written. late=' . count($late) . ' notes=' . count($meta['notes']));
    foreach ($late as $l) out("    late: $l");
    foreach ($meta['notes'] as $n) out("    note: $n");
    out('✓ Done (dry run).');
    exit(0);
}
$tmp = $dest . '.tmp-' . getmypid();
if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0775, true)) { out('✗ cannot create ' . dirname($dest)); exit(1); }
if (!@mkdir($tmp, 0775, true)) { out("✗ cannot create $tmp"); exit(1); }
foreach ($files as $name => $data) {
    if (file_put_contents("$tmp/$name.json", $json($data)) === false) { out("✗ cannot write $tmp/$name.json"); exit(1); }
    @chmod("$tmp/$name.json", 0664);
}
$old = null;
if (is_dir($dest)) { $old = $dest . '.prev-' . getmypid(); rename($dest, $old); }
if (!rename($tmp, $dest)) { if ($old) rename($old, $dest); out("✗ cannot move $tmp into place"); exit(1); }
if ($old) { foreach (glob("$old/*") ?: [] as $f) @unlink($f); @rmdir($old); }
out("  published $dest (" . implode(', ', array_map(fn($n) => "$n.json", array_keys($files))) . ')');

// 8. The email: the month, the notable moves, anything late.
$prevLate = $existing['late'] ?? null;
$changed  = $existing === null || $force || $prevLate !== $meta['late'];
if ($outDir === '' && $changed) {
    $moves = [];
    foreach ($county as $row) {
        if ($row['ptype'] !== 'Single family') continue;
        $mc = market_change($row['median'], $row['median_ly']); $sc = market_change($row['sold'], $row['sold_ly']);
        if ($mc === null) continue;
        $moves[] = ['abs' => abs($mc), 'text' => htmlspecialchars($row['county']) . ' single family: median ' . market_money($row['median']) . ' (' . market_change_text($mc) . ' on the year), '
                    . market_int($row['sold']) . ' sales' . ($sc !== null ? ' (' . market_change_text($sc) . ')' : '')];
    }
    usort($moves, fn($a, $b) => $b['abs'] <=> $a['abs']);
    $lines = [
        htmlspecialchars($monthLabel) . ' is live on the Market Report and the Market Snapshot cards (CAR current as of ' . htmlspecialchars($carAsOf) . ').',
        '<strong>Notable moves</strong><br>' . implode('<br>', array_map(fn($m) => $m['text'], array_slice($moves, 0, 5))),
    ];
    if ($front[0]['closed'] !== null) $lines[] = 'Denver Metro (REcolorado): ' . market_int($front[0]['closed']) . ' closed (' . market_whole_pct_text($front[0]['closed_change_pct']) . '), median ' . market_money($front[0]['median']) . ' (' . market_whole_pct_text($front[0]['median_change_pct']) . ').';
    if ($pp) $lines[] = 'Pikes Peak (PPAR): ' . market_int($pp['closed']) . ' closed, median ' . market_money($pp['median']) . ', ' . $pp['avg_days'] . ' days on market.';
    if ($late) $lines[] = '<strong>Still to come</strong> (the run repeats each morning and fills these in): ' . htmlspecialchars(implode('; ', $late)) . '.';
    send_report("Market Report: $monthLabel is live" . ($late ? ' (part late)' : ''), $lines, $meta['notes']);
    $state['published'][$month] = $meta['generated']; save_state();
}
out('✓ Done: ' . $monthLabel . ' published' . ($late ? ', ' . count($late) . ' late' : ', complete') . '.');
