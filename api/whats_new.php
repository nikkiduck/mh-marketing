<?php
/**
 * api/whats_new.php — the What's New notices MH Send shows (2026-11-14).
 *
 * Read-only JSON of the B2B and Client Emails notices that are showing, newest first:
 *   { "generated_at": "...Z", "items": [ { id, area: "b2b" | "emails", icon, title, message, created_at (UTC) } ] }
 * The list is kept on this side (inc/whats_new.php, edited on whats_new.php); send.monthaus.com's
 * inc/whats_new.php reads this and caches it for a few minutes.
 *
 * NO sign-in and NO allowlist, like api/roster.php. Access is the shared secret MH Send already holds:
 *
 *   Authorization: Bearer <SEND_ROSTER_TOKEN>        (constant in inc/db.php)
 *
 * Header only, never ?token=. An undefined or short token refuses everything (fail closed).
 */

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/whats_new.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function wn_feed_fail(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$bearer = '';
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($hdr === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $hdr = $v;
}
if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $hdr, $m)) $bearer = $m[1];

require_once __DIR__ . '/../inc/db.php';   // defines SEND_ROSTER_TOKEN; nothing is queried

if (!defined('SEND_ROSTER_TOKEN') || strlen((string)SEND_ROSTER_TOKEN) < 24) wn_feed_fail(503, 'feed not configured');
if ($bearer === '' || !hash_equals((string)SEND_ROSTER_TOKEN, $bearer)) wn_feed_fail(401, 'unauthorized');

echo json_encode(['generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'items' => wn_feed()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
