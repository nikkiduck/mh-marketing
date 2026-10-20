<?php
/**
 * portal/market_render.php — a Market Snapshot post as HTML for the preview
 * frame. POST (the builder form, with csrf_token) → text/html, the same
 * document portal/market_export.php screenshots. Any portal login, or an
 * admin without a linked account (market_gate()). See inc/market.php.
 */
require_once __DIR__ . '/../inc/market.php';

$ctx = market_gate($conn);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    http_response_code(400);
    echo social_placeholder_html('That page had been open too long. Please reload it.');
    exit;
}
$r = market_card_html(market_card_options_from_request($_POST, $ctx), false, $ctx);
echo is_string($r) ? social_placeholder_html($r) : $r['html'];
