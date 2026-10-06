<?php
/**
 * portal/asset.php — an agent's own creative: the image or PDF on one of
 * their advertising creatives, or the proof on one of their print orders.
 * docs/AGENT_PORTAL_PLAN.md section 5; sql/creatives_v1.sql.
 *
 *   asset.php?asset=12[&thumb=1|&dl=1]   a creative on one of THEIR placements
 *   asset.php?order=42[&thumb=1|&dl=1]   the proof on one of THEIR orders
 *
 * Takes an id only (never a path or a file name). The query itself is scoped
 * to the portal account, so an id belonging to someone else finds no row and
 * is a 404 with nothing in it. The admin creative.php asks "is this an
 * admin" instead; the two questions stay in two files on purpose, exactly as
 * receipt.php and portal/receipt.php do. Streaming (basename(), MIME
 * allowlist, nosniff, no-store) is mk_send_creative().
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/creatives.php';

$ctx  = portal_context($conn);
$acct = (int)$ctx['acct']['id'];
$aid  = (int)($_GET['asset'] ?? 0);
$oid  = (int)($_GET['order'] ?? 0);

$row = null;
if ($aid > 0 && mk_column_exists($conn, 'marketing_campaign_assets', 'image_file')) {
    $st = $conn->prepare("SELECT a.image_file, a.image_orig_name, a.image_thumb
                            FROM marketing_campaign_assets a
                            JOIN marketing_campaigns c ON c.id = a.campaign_id
                           WHERE a.id = ? AND c.intake_id = ? LIMIT 1");
    $st->bind_param('ii', $aid, $acct);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
} elseif ($oid > 0 && mk_column_exists($conn, 'marketing_collateral_orders', 'proof_file')) {
    $st = $conn->prepare("SELECT proof_file, proof_orig_name, proof_thumb
                            FROM marketing_collateral_orders WHERE id = ? AND intake_id = ? LIMIT 1");
    $st->bind_param('ii', $oid, $acct);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
}
$file  = (string)($row['image_file'] ?? $row['proof_file'] ?? '');
$name  = (string)($row['image_orig_name'] ?? $row['proof_orig_name'] ?? '');
$tfile = (string)($row['image_thumb'] ?? $row['proof_thumb'] ?? '');
if ($file === '') { http_response_code(404); exit('That file could not be found.'); }

$thumb = !empty($_GET['thumb']);
if ($thumb && $tfile === '') { http_response_code(404); exit('That file could not be found.'); }
mk_send_creative($thumb ? $tfile : $file, $thumb ? null : $name, !empty($_GET['dl']) && !$thumb);
