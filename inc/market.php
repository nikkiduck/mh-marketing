<?php
/**
 * inc/market.php — the Monthly Market Report and the Market Snapshot posts on
 * the portal (2026-10-18): the parts that need the portal (the gate, the
 * builder's checked options, the stock backdrop, the export). Everything
 * pure (loading a month, formatting, the card model) is inc/market_data.php,
 * and the card's HTML is inc/market_card_render.php; both run without a
 * database. See inc/market_data.php's header for the whole tool.
 */
require_once __DIR__ . '/social.php';          // social_export_png(), social_stock(), social_marks(), the portal gates
require_once __DIR__ . '/market_data.php';
require_once __DIR__ . '/market_card_render.php';
require_once __DIR__ . '/boards.php';              // mk_areas(): the Communities towns

// ── Access ───────────────────────────────────────────────────────────────────

/**
 * Who may call the render and export endpoints: any portal login (the usual
 * portal_context() gate, with its ?preview= rules) or an admin, who needs no
 * linked account because a snapshot post holds no account data. Returns the
 * portal context, or null for an admin without one. Makes sure a CSRF token
 * exists either way (the admin pages set none of their own).
 */
function market_gate(mysqli $conn): ?array {
    require_login();
    $ctx = null;
    if (!is_elevated_admin() || isset($_GET['preview'])) $ctx = portal_context($conn);
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $ctx;
}

/**
 * The builder's choices, checked: market (a card), month (on file), format,
 * theme, ptype, mark (the Instagram posts' watermarks), stats (up to three
 * catalog keys, the rows under the median), backdrop ('' or a stock photo
 * name, or "upload:<id>", one of the person's own photos from the Instagram
 * tool's uploads, when $ctx is theirs; 2026-10-19). Everything else is dropped.
 */
function market_card_options_from_request(array $in, ?array $ctx = null): array {
    $month  = market_pick_month((string)($in['month'] ?? ''));
    $data   = $month !== '' ? market_load($month) : null;
    if ($data) market_attach_feed($data);
    $market = market_card_key((string)($in['market'] ?? ''), $data ?? []);   // a card key (a feed town's too), or a market id from the report's links
    $format = isset(market_card_formats()[(string)($in['format'] ?? '')]) ? (string)$in['format'] : 'post';
    $theme  = isset(market_card_themes()[(string)($in['theme'] ?? '')]) ? (string)$in['theme'] : 'dark';
    $ptype  = (string)($in['ptype'] ?? 'SF') === 'TC' ? 'TC' : 'SF';
    $mark   = isset(social_marks()[(string)($in['mark'] ?? '')]) ? (string)$in['mark'] : 'triangle';
    $stats  = array_values(array_slice(array_filter(array_map('strval', (array)($in['stats'] ?? [])), fn($k) => isset(market_stat_catalog()[$k])), 0, 3));   // the three rows, from the catalog
    $bd     = (string)($in['backdrop'] ?? '');
    if (str_starts_with($bd, 'upload:')) {
        $backdrop = ($ctx && social_upload_file($ctx, substr($bd, 7))) ? $bd : '';   // only a photo this person uploaded
    } else {
        $backdrop = ($bd !== '' && preg_match('/^[a-z0-9-]+$/', $bd) && isset(social_stock()[$bd])) ? $bd : '';
    }
    return ['market' => $market, 'month' => $month, 'format' => $format, 'theme' => $theme,
            'ptype' => $ptype, 'mark' => $mark, 'stats' => $stats, 'backdrop' => $backdrop];
}

/** The stock photo's (or the person's own photo's) URL for the preview, or its file on disk for the export (Chromium has no session). */
function market_backdrop_src(string $name, bool $export, ?array $ctx = null): string {
    if ($name === '') return '';
    if (str_starts_with($name, 'upload:')) {
        if (!$ctx) return '';
        $id = substr($name, 7);
        if ($export) { $f = social_upload_file($ctx, $id); return $f ? 'file://' . $f : ''; }
        return social_upload_url($ctx, $id, 1600);
    }
    if ($export) {
        $f = realpath(__DIR__ . '/../assets/stock/' . $name . '.jpg');
        return $f ? 'file://' . $f : '';
    }
    return social_stock_url($name, 2048);
}

/**
 * The complete HTML for one snapshot post from the builder's choices: the
 * model from the month's data, the renderer's options, the fonts and marks by
 * URL for the preview and inlined for the export. A string is the reason
 * there is nothing to draw.
 */
function market_card_html(array $o, bool $export = false, ?array $ctx = null): string|array {
    if ($o['month'] === '') return 'No market data is on file yet.';
    $data = market_load($o['month']);
    if (!$data) return 'The data for ' . market_month_label($o['month']) . ' could not be read.';
    market_attach_feed($data);
    $card = market_card_model($data, $o['market'], ['ptype' => $o['ptype'], 'stats' => $o['stats'] ?? []]);
    if (!$card) return (market_cards($data)[$o['market']]['title'] ?? $o['market']) . ' has no figures for ' . market_month_label($o['month']) . '.';
    $html = market_card_render($card, ['format' => $o['format'], 'theme' => $o['theme'], 'mark' => $o['mark'],
                                       'backdrop' => market_backdrop_src($o['backdrop'], $export, $ctx)], null, $export);
    return ['html' => $html, 'card' => $card];
}

// ── Town figures from our own feed ───────────────────────────────────────────

/** Where a month's feed response is cached: outside the web root, beside the creatives. */
function market_feed_cache(string $month): string {
    return rtrim(CREATIVES_DIR, '/') . '/market/towns_feed_' . $month . '.json';
}

/**
 * The site's market_stats response for a month (site api/market_stats.php,
 * every place, no minimum), cached: six hours for the current report month,
 * a week for older months (a closed month barely moves), the stale copy when
 * the site does not answer. Null when there is nothing at all.
 */
function market_feed(string $month): ?array {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) return null;
    $file = market_feed_cache($month);
    $ttl  = $month < date('Y-m', strtotime('first day of last month')) ? 7 * 86400 : 6 * 3600;
    $stale = null;
    if (is_file($file)) {
        $stale = json_decode((string)file_get_contents($file), true);
        if (is_array($stale) && time() - filemtime($file) < $ttl) return $stale;
    }
    if (!defined('LISTINGS_FEED_URL') || !defined('LISTINGS_FEED_TOKEN') || (string)LISTINGS_FEED_TOKEN === '') return is_array($stale) ? $stale : null;
    $url = dirname(LISTINGS_FEED_URL) . '/market_stats.php?month=' . rawurlencode($month) . '&min=0';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . LISTINGS_FEED_TOKEN, 'Accept: application/json']]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = ($code === 200 && $body) ? json_decode((string)$body, true) : null;
    if (is_array($j) && isset($j['places'])) {
        $dir = dirname($file);
        if (!is_dir($dir)) { @mkdir($dir, 02775, true); @chmod($dir, 02775); }
        if (is_dir($dir) && is_writable($dir)) { @file_put_contents($file, (string)$body); @chmod($file, 0664); }
        return $j;
    }
    error_log("market_feed: {$url} answered HTTP {$code}" . (is_array($stale) ? ' (stale cache used)' : ''));
    return is_array($stale) ? $stale : null;
}

/** Puts the month's feed towns on $data ('towns_feed', 'feed_note'); empty when the feed is not reachable. */
function market_attach_feed(array &$data): void {
    if (isset($data['towns_feed'])) return;
    $feed = market_feed((string)$data['month']);
    $data['towns_feed'] = $feed ? market_feed_towns($feed, mk_areas(), array_column($data['boards'], 'market')) : [];
    $data['counties_feed'] = $feed ? market_feed_counties($feed, array_column($data['boards'], 'market')) : [];
    $data['feed_note']  = (string)($feed['note'] ?? '');
}