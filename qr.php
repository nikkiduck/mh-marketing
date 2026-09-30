<?php
/**
 * qr.php — the redirect behind every printed Mont Haus QR code.
 *
 * qr.monthaus.com has no pages of its own: its vhost
 * (deploy/qr.monthaus.com.conf) rewrites EVERY request to this file, which
 * looks the path up in qr_codes and sends a 302 to wherever that code points
 * today. Public on purpose (no auth.php): it is scanned by the public.
 *
 * Three rules this file keeps:
 *
 *   1. Always a 302, never a 301. Phones and browsers cache a 301 for good,
 *      so a permanent redirect would quietly stop a code from ever being
 *      repointed for whoever scanned it once. Cache-Control: no-store as well.
 *
 *   2. Never an error page. Unknown code, paused code, database down, PHP
 *      fatal: every one of them ends at QR_FALLBACK_URL. inc/db.php die()s
 *      when it cannot connect, so a shutdown function catches any exit that
 *      happens before we have finished and turns it into the fallback.
 *
 *   3. Only destinations that pass qr_dest_error(). A stored URL is checked
 *      again here, so an edit made directly in the database cannot turn this
 *      into an open redirect.
 *
 * On marketing.monthaus.com itself this file has nothing to do and sends you
 * to the admin page.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/qr.php';

function qr_go(string $url): void {
    $GLOBALS['qr_done'] = true;
    while (ob_get_level()) ob_end_clean();
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex');
    header('Location: ' . $url, true, 302);
    exit;
}

$host    = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
$qr_host = strtolower((string) parse_url(QR_BASE_URL, PHP_URL_HOST));
if ($host !== $qr_host) {
    header('Location: /qr_codes.php', true, 302);
    exit;
}

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$code = strtolower(trim(rawurldecode($path), '/'));

if ($code === 'robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /\n";
    exit;
}
if ($code === 'favicon.ico') {
    http_response_code(204);
    exit;
}

// Anything that is not the shape of a code goes straight to the fallback,
// without touching the database. Trailing punctuation from a mistyped URL
// ("qr.monthaus.com/jb-sign.") is forgiven by normalising first.
$code = qr_normalize_code($code);
if ($code === '' || qr_code_error($code) !== '') {
    qr_go(QR_FALLBACK_URL);
}

// From here on, any exit we did not choose (db.php's die(), a fatal) becomes
// the fallback instead of an error page.
$GLOBALS['qr_done'] = false;
ob_start();
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['qr_done'])) return;
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) {
        header('Cache-Control: no-store, private');
        header('Location: ' . QR_FALLBACK_URL, true, 302);
    }
});

require __DIR__ . '/inc/db.php';

$row = null;
$s = $conn->prepare(
    "SELECT q.id, q.dest_type, q.dest_url, q.is_active, mi.slug
       FROM qr_codes q
  LEFT JOIN marketing_intakes mi ON mi.id = q.intake_id
      WHERE q.code = ?
      LIMIT 1"
);
if ($s) {
    $s->bind_param('s', $code);
    if ($s->execute()) $row = $s->get_result()->fetch_assoc();
    $s->close();
} else {
    // Most likely: sql/qr_codes_v1.sql has not been run yet.
    error_log('qr.php: ' . $conn->error);
}

if (!$row) {
    qr_go(QR_FALLBACK_URL);
}

// Count the scan, paused codes included: a paused sign still being scanned is
// worth knowing. HEAD requests are link checkers, not people.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    $qid = (int) $row['id'];
    $day = qr_today();
    $conn->query("UPDATE qr_codes SET scan_count = scan_count + 1, last_scan_at = UTC_TIMESTAMP() WHERE id = {$qid}");
    $s = $conn->prepare(
        "INSERT INTO qr_scans_daily (qr_id, scan_date, scans) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE scans = scans + 1"
    );
    if ($s) { $s->bind_param('is', $qid, $day); $s->execute(); $s->close(); }
}

$target = qr_resolve($row);
qr_go($target !== null ? qr_with_utm($target, $code) : QR_FALLBACK_URL);
