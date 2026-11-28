<?php
/**
 * video_photos.php — the photos the agents' Video tool picks from (the admin
 * page asks plan_photos.php, which answers the same two questions for every
 * listing in the feed). Reached through portal/video_photos.php.
 *
 *   GET ?kind=property&subject=<one of the person's listings> → the listing's
 *       photos (remembered in the session so a posted one can be checked),
 *       then the person's own uploads
 *   GET ?kind=library → the person's uploads, then the stock library by region
 *   → JSON {count, suggest, photos: [{id, thumb, large, own, caption, group}]}
 */
require_once __DIR__ . '/inc/video.php';

$ctx = video_gate($conn);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$kind = (string)($_GET['kind'] ?? '');
$out = []; $suggest = '';
if ($kind === 'property') {
    $l = video_listing($conn, $ctx, (string)($_GET['subject'] ?? ''));
    if (!$l) { http_response_code(404); echo json_encode(['error' => 'not one of your listings']); exit; }
    $out = plan_listing_tiles($l);
    $suggest = $out[0]['id'] ?? '';
    $out = array_merge($out, plan_upload_tiles($ctx));
} elseif ($kind === 'library') {
    $out = array_merge(plan_upload_tiles($ctx), plan_stock_tiles());
} else {
    http_response_code(400); echo json_encode(['error' => 'kind?']); exit;
}
echo json_encode(['count' => count($out), 'suggest' => $suggest, 'photos' => array_values($out)], JSON_UNESCAPED_SLASHES);
