<?php
/**
 * portal/social_upload.php — store the agent's own photo for a post.
 * POST multipart (photo, csrf_token) → JSON {id: "upload:<hex>", thumb, large}
 * or {error}. Saved under CREATIVES_DIR/social/<intake id>/uploads/ by
 * social_store_upload(); served back by social_asset.php. See inc/social.php.
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = portal_context($conn);
social_gate();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    http_response_code(400);
    echo json_encode(['error' => 'That page had been open too long. Please reload it and try again.']);
    exit;
}
$r = social_store_upload($ctx, 'photo');
if (isset($r['error'])) { http_response_code(422); echo json_encode(['error' => $r['error']]); exit; }
echo json_encode(['id' => 'upload:' . $r['id'], 'thumb' => social_upload_url($ctx, $r['id'], 400), 'large' => social_upload_url($ctx, $r['id'], 1600)],
                 JSON_UNESCAPED_SLASHES);
