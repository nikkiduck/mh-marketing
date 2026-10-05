<?php
/**
 * receipt.php
 * Serves an uploaded collateral-order receipt.
 *
 * Receipts are stored OUTSIDE the document root and streamed through here so
 * they can't be fetched by guessing a URL — they carry vendor and cost detail.
 * Admin session required, same as the rest of /marketing.
 *
 *   receipt.php?order_id=42            → inline
 *   receipt.php?order_id=42&dl=1       → download
 *   receipt.php?invoice_id=3           → the combined invoice behind a split
 *   receipt.php?campaign_id=12         → an advertising placement's receipt
 *
 * invoice_id serves marketing_vendor_invoices, which order_split.php writes.
 * The two are separate physical files on purpose: every collateral order gets
 * its OWN copy of the invoice receipt, because clearing the receipt on one
 * order physically unlinks the file, and a shared one would break the link for
 * every other agent on that invoice.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_login();
require_role('admin');

$order_id   = (int)($_GET['order_id']   ?? 0);
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$campaign_id = (int)($_GET['campaign_id'] ?? 0);
if (!$order_id && !$invoice_id && !$campaign_id) { http_response_code(400); exit('Missing order_id, invoice_id or campaign_id.'); }

// Table and id chosen here, never interpolated from input — the only thing that
// reaches the query is a bound integer.
if ($order_id) {
    $sql  = "SELECT receipt_file, receipt_orig_name FROM marketing_collateral_orders WHERE id = ? LIMIT 1";
    $want = $order_id;
    $miss = 'No receipt on this order.';
} elseif ($campaign_id) {
    $sql  = "SELECT receipt_file, receipt_orig_name FROM marketing_campaigns WHERE id = ? LIMIT 1";
    $want = $campaign_id;
    $miss = 'No receipt on this placement.';
} else {
    $sql  = "SELECT receipt_file, receipt_orig_name FROM marketing_vendor_invoices WHERE id = ? LIMIT 1";
    $want = $invoice_id;
    $miss = 'No receipt on this invoice.';
}

// prepare() fails rather than throwing when marketing_vendor_invoices has not
// been created yet — see sql/vendor_invoices_v1.sql. A 500 with a real message
// beats a blank page.
$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); exit('Database error — has sql/vendor_invoices_v1.sql been run?'); }
$stmt->bind_param('i', $want);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['receipt_file'])) { http_response_code(404); exit($miss); }

// basename() defends against any traversal that reached the column
$stored = basename((string)$row['receipt_file']);
$path   = RECEIPTS_DIR . $stored;

if (!is_file($path)) { http_response_code(404); exit('Receipt file is missing from the server.'); }

$mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream')
                                             : 'application/octet-stream';
// Only ever serve what we accept on upload — never let an unexpected type
// through with a type the browser might execute.
$allowed = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allowed, true)) $mime = 'application/octet-stream';

$download = !empty($_GET['dl']);
$filename = $row['receipt_orig_name'] ?: $stored;
// Strip anything that could break the header
$filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string)$filename);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
     . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');

readfile($path);
