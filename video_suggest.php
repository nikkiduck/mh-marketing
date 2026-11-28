<?php
/**
 * video_suggest.php — Suggest Texts for a listing reel (Nikki, 2026-11-27;
 * see inc/video_ai.php). Super admin only.
 *
 *   POST listing=<id> + photos[] (the picked photos in play order, the cover
 *        first) + avoid[] (texts already suggested and passed over) +
 *        csrf_token
 *   → JSON {ok, texts: {<photo id>: <text>}} for the photos after the cover,
 *     or {error}.
 *
 * Listing videos only: the texts come from the listing's public description
 * and details, and from the photos themselves.
 */
require_once __DIR__ . '/inc/video.php';
require_once __DIR__ . '/inc/video_ai.php';

$ctx = video_gate($conn);
$json = function (array $a, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) $json(['error' => 'That page had been open too long. Reload it and try again.'], 400);
if (!video_ai_enabled()) $json(['error' => 'Text suggestions are not switched on.'], 400);

$listing = video_listing($conn, $ctx, (string)($_POST['listing'] ?? ''));
if (!$listing) $json(['error' => 'Choose a listing first.'], 400);
if (video_portal() && video_ai_used($ctx) >= VIDEO_AI_DAILY) $json(['error' => 'You have used today\'s ' . VIDEO_AI_DAILY . ' text suggestions. They are back tomorrow; the boxes can still be typed in.'], 429);
$ids = array_slice(array_values(array_filter(array_map('strval', (array)($_POST['photos'] ?? [])), 'strlen')), 0, VIDEO_MAX_PHOTOS);
if (count($ids) < 2) $json(['error' => 'Pick at least two photos: the cover has its own text.'], 400);

// Everything the session holds is read before the long request, then the session is let go so the page's preview is not kept waiting.
$facts = video_listing_facts($listing);
$pairs = array_map(fn($id) => plan_photo($ctx, $id, $listing), $ids);
session_write_close();
$photos = [];
foreach ($ids as $k => $id) $photos[] = video_ai_photo($ctx, $id, $pairs[$k]);

try { $texts = video_ai_suggest($facts, $photos, (array)($_POST['avoid'] ?? [])); }
catch (RuntimeException $e) { $json(['error' => $e->getMessage()], 422); }

video_ai_used($ctx, true);
$out = [];
foreach ($texts as $at => $t) $out[$ids[$at]] = $t;
$json(['ok' => true, 'texts' => $out]);
