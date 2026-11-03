<?php
/**
 * plan_photos.php — the photos a Social Media Plan template can pick from.
 * GET ?kind=property|broker|location|branding&subject=<listing id | agent id
 * | location key | season> → JSON {photos: [{id, thumb, large, own, caption,
 * group}], suggest: <the id to start with>}. A listing's photos come from the
 * site (remembered in the session so a posted one can be checked), a
 * broker's headshot and face crop from this portal's agent-photos, the stock
 * library by region (the subject's region first), the person's own uploads
 * last. Admins only. See inc/plan.php.
 */
require_once __DIR__ . '/inc/plan.php';

$ctx = plan_gate($conn);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$kind = (string)($_GET['kind'] ?? '');
$subject = (string)($_GET['subject'] ?? '');
$out = []; $suggest = '';
if ($kind === 'property') {
    $l = plan_listing($conn, $subject);
    if (!$l) { http_response_code(404); echo json_encode(['error' => 'not a Mont Haus listing in the feed']); exit; }
    $out = plan_listing_tiles($l);
    $suggest = $out[0]['id'] ?? '';
    $out = array_merge($out, plan_upload_tiles($ctx));
} elseif ($kind === 'broker') {
    $a = plan_agents($conn)[(int)$subject] ?? null;
    if (!$a) { http_response_code(404); echo json_encode(['error' => 'not an approved agent']); exit; }
    // The figure is always the broker's cutout; these tiles are the BACKDROP behind it (Nikki, 2026-11-02:
    // "if I choose an alternate photo it should change the background, not the headshot"). None, their
    // own photos, then the stock library with the broker's region first, which is the suggestion.
    $out = array_merge([plan_tile('none', '', '', 'Backdrop', 'No backdrop, the plain ground') + ['none' => true]], plan_upload_tiles($ctx), plan_stock_tiles($a['region']));
    foreach ($out as $t) if (str_starts_with($t['id'], 'stock:')) { $suggest = $t['id']; break; }
} elseif ($kind === 'location') {
    $loc = plan_location($subject);
    if (!$loc) { http_response_code(404); echo json_encode(['error' => 'not a location']); exit; }
    $out = array_merge(plan_upload_tiles($ctx), plan_stock_tiles($loc['region']));
    $suggest = $loc['photo'] !== '' ? 'stock:' . $loc['photo'] : ($out[0]['id'] ?? '');
} elseif ($kind === 'branding') {
    $season = isset(plan_seasons()[$subject]) ? $subject : 'always';
    $out = plan_season_tiles($season);
    $suggest = $out[0]['id'] ?? '';
    $out = array_merge($out, plan_stock_tiles(), plan_upload_tiles($ctx));
    if ($suggest === '') $suggest = $out[0]['id'] ?? '';
} else {
    http_response_code(400); echo json_encode(['error' => 'kind?']); exit;
}
echo json_encode(['count' => count($out), 'suggest' => $suggest, 'photos' => array_values($out)], JSON_UNESCAPED_SLASHES);
