<?php
/**
 * portal/receipt.php — an agent's own collateral receipt (2026-10-01; Nikki:
 * agents may see their receipts). The admin receipt.php stays admin-only: that
 * one asks "is this an admin", this one asks "does this order belong to the
 * signed-in account", and keeping the two questions in two files is what stops
 * the wrong branch ever running.
 *
 * Takes an order id only (never a path or file name), checks the order's
 * intake_id is the portal account, then streams the file with receipt.php's
 * hardening: basename() on the stored name, a MIME allowlist, nosniff, no-store.
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);
$oid = (int)($_GET['order'] ?? 0);
$acct = (int)$ctx['acct']['id'];

$row = null;
if ($oid > 0) {
    $st = $conn->prepare("SELECT receipt_file, receipt_orig_name FROM marketing_collateral_orders WHERE id = ? AND intake_id = ? LIMIT 1");
    $st->bind_param('ii', $oid, $acct);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
}
if (!$row || empty($row['receipt_file'])) { http_response_code(404); exit('That receipt could not be found.'); }

$stored = basename((string)$row['receipt_file']);
$path   = RECEIPTS_DIR . $stored;
if (!is_file($path)) { http_response_code(404); exit('That receipt could not be found.'); }

$mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) $mime = 'application/octet-stream';
$filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)($row['receipt_orig_name'] ?: $stored));

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
readfile($path);
