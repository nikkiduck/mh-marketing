<?php
/**
 * video_caption.php — the Caption box under the Video tool's preview (Nikki,
 * 2026-11-27: "a field for Caption ... pulling from the details of the
 * selected listing"). Super admin only.
 *
 *   GET ?listing=<id>  → JSON {caption} (video_caption() in inc/video.php), or {error}
 *
 * Nothing is written by a model: the caption is the listing's address, price
 * and public description as the feed gives them.
 */
require_once __DIR__ . '/inc/video.php';
require_once __DIR__ . '/inc/video_ai.php';   // video_listing_facts(): the description

$ctx = plan_gate($conn);
$json = function (array $a, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
};
$listing = plan_listing($conn, (string)($_GET['listing'] ?? ''));
if (!$listing) $json(['error' => 'Choose a listing first.'], 400);
$facts = video_listing_facts($listing);   // reads the session's copy when the site does not answer
session_write_close();
$json(['caption' => video_caption($listing, (string)$facts['remarks'])]);
