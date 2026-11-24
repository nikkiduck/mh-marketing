<?php
/**
 * portal/social_photos.php — the photos to pick from for a listing.
 * GET ?listing=market|listing_key (one of the account's) → JSON
 * {photos: [{id, thumb, large, own}]}: the listing's photos from the site,
 * primary first (remembered in the session so the chosen one can be checked
 * when rendering), then the agent's own uploads (own = true, id "upload:…").
 * See inc/social.php.
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = social_context($conn);   // or the admin's own context with ?preview=admin (2026-10-28)
social_gate();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// The stock library as tiles, by region. A listing's photos carry it too when asked (?stock=1: the Listing
// Post/Story's Stock Library tab, 2026-11-24; the flyer never asks).
$stock_tiles = function (): array {
    $t = [];
    foreach (social_stock_grouped() as $region => $list) {
        foreach ($list as $name => $caption) $t[] = ['id' => 'stock:' . $name, 'thumb' => social_stock_url($name, 400), 'large' => social_stock_url($name, 2048), 'own' => false, 'stock' => true, 'caption' => $caption, 'group' => $region];
    }
    return $t;
};
if ((string)($_GET['listing'] ?? '') === '__none') {   // the Other kind: the agent's uploads only (2026-10-15)
    $out = [];
    foreach (social_uploads($ctx) as $id) $out[] = ['id' => 'upload:' . $id, 'thumb' => social_upload_url($ctx, $id, 400), 'large' => social_upload_url($ctx, $id, 1600), 'own' => true];
    // Then the Mont Haus stock library, each with its caption for the tooltip (2026-10-15), by region (2026-10-19).
    $out = array_merge($out, $stock_tiles());
    echo json_encode(['listing' => '', 'count' => count($out), 'headline' => '', 'photos' => $out], JSON_UNESCAPED_SLASHES);
    exit;
}
$l = social_listing($conn, $ctx, (string)($_GET['listing'] ?? ''));
if (!$l) { http_response_code(404); echo json_encode(['error' => 'not one of your listings']); exit; }

$urls = social_photos((string)$l['market'], (string)$l['listing_key']);
if (!$urls && (string)$l['primary_photo'] !== '') $urls = [(string)$l['primary_photo']];
$out = array_map(fn($u) => ['id' => $u, 'thumb' => social_img($u, 400), 'large' => social_img($u, 1600), 'own' => false], $urls);
foreach (social_uploads($ctx) as $id) {
    $out[] = ['id' => 'upload:' . $id, 'thumb' => social_upload_url($ctx, $id, 400), 'large' => social_upload_url($ctx, $id, 1600), 'own' => true];
}
if (!empty($_GET['stock'])) $out = array_merge($out, $stock_tiles());
$remarks  = (string)($_SESSION['social_remarks'][$l['id']] ?? '');
$headline = social_headline_from_remarks($remarks);   // the flyer's suggestion
// `remarks`: the listing's description, for the brochure flyer to start from (2026-11-17).
echo json_encode(['listing' => $l['id'], 'count' => count($out), 'headline' => $headline, 'remarks' => $remarks, 'photos' => $out], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
