<?php
/**
 * creative.php — serves an uploaded creative to an admin: the image or PDF
 * on an advertising creative, or the proof on a collateral order
 * (sql/creatives_v1.sql, inc/creatives.php).
 *
 *   creative.php?asset=12            → the creative's uploaded file, inline
 *   creative.php?asset=12&thumb=1    → its thumbnail (JPEG made at upload)
 *   creative.php?asset=12&dl=1       → download
 *   creative.php?order=42[&thumb=1|&dl=1]   → a collateral order's proof
 *
 * Files live outside the web root (CREATIVES_DIR) and are only reachable
 * through here and through portal/asset.php, which asks a different question
 * ("does this belong to the signed-in agent?") in its own file, as
 * receipt.php and portal/receipt.php do. Admin session required here.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/creatives.php';
require_login();
require_role('admin');

$asset_id = (int)($_GET['asset'] ?? 0);
$order_id = (int)($_GET['order'] ?? 0);
if (!$asset_id && !$order_id) { http_response_code(400); exit('Missing asset or order.'); }

// Table and columns chosen here, never from input; only a bound integer reaches the query.
if ($asset_id) {
    $sql  = "SELECT image_file, image_orig_name, image_thumb FROM marketing_campaign_assets WHERE id = ? LIMIT 1";
    $want = $asset_id;
} else {
    $sql  = "SELECT proof_file, proof_orig_name, proof_thumb FROM marketing_collateral_orders WHERE id = ? LIMIT 1";
    $want = $order_id;
}
$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); exit('Database error: has sql/creatives_v1.sql been run?'); }
$stmt->bind_param('i', $want);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$file  = (string)($row['image_file'] ?? $row['proof_file'] ?? '');
$name  = (string)($row['image_orig_name'] ?? $row['proof_orig_name'] ?? '');
$tfile = (string)($row['image_thumb'] ?? $row['proof_thumb'] ?? '');
if ($file === '') { http_response_code(404); exit('No file on this item.'); }

$thumb = !empty($_GET['thumb']);
if ($thumb && $tfile === '') { http_response_code(404); exit('No thumbnail for this file.'); }
mk_send_creative($thumb ? $tfile : $file, $thumb ? null : $name, !empty($_GET['dl']) && !$thumb);
