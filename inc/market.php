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
 * theme, ptype, mark (the Instagram posts' watermarks), logo (top | bottom),
 * backdrop ('' or a stock photo name). Everything else is dropped.
 */
function market_card_options_from_request(array $in): array {
    $market = market_card_key((string)($in['market'] ?? ''));   // a card key, or a market id from the report's links
    $format = isset(market_card_formats()[(string)($in['format'] ?? '')]) ? (string)$in['format'] : 'post';
    $theme  = isset(market_card_themes()[(string)($in['theme'] ?? '')]) ? (string)$in['theme'] : 'dark';
    $ptype  = (string)($in['ptype'] ?? 'SF') === 'TC' ? 'TC' : 'SF';
    $mark   = isset(social_marks()[(string)($in['mark'] ?? '')]) ? (string)$in['mark'] : 'triangle';
    $logo   = in_array((string)($in['logo'] ?? ''), ['top', 'bottom'], true) ? (string)$in['logo'] : 'top';
    $bd     = (string)($in['backdrop'] ?? '');
    $backdrop = ($bd !== '' && preg_match('/^[a-z0-9-]+$/', $bd) && isset(social_stock()[$bd])) ? $bd : '';
    return ['market' => $market, 'month' => market_pick_month((string)($in['month'] ?? '')), 'format' => $format, 'theme' => $theme,
            'ptype' => $ptype, 'mark' => $mark, 'logo' => $logo, 'backdrop' => $backdrop];
}

/** The stock photo's URL for the preview, or its file on disk for the export (Chromium has no session). */
function market_backdrop_src(string $name, bool $export): string {
    if ($name === '') return '';
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
function market_card_html(array $o, bool $export = false): string|array {
    if ($o['month'] === '') return 'No market data is on file yet.';
    $data = market_load($o['month']);
    if (!$data) return 'The data for ' . market_month_label($o['month']) . ' could not be read.';
    $card = market_card_model($data, $o['market'], ['ptype' => $o['ptype']]);
    if (!$card) return (market_card_defs()[$o['market']]['title'] ?? $o['market']) . ' has no figures for ' . market_month_label($o['month']) . '.';
    $html = market_card_render($card, ['format' => $o['format'], 'theme' => $o['theme'], 'mark' => $o['mark'], 'logo' => $o['logo'],
                                       'backdrop' => market_backdrop_src($o['backdrop'], $export)], null, $export);
    return ['html' => $html, 'card' => $card];
}
