<?php
/**
 * video_saved.php — the Video tool's saved videos (Nikki, 2026-11-27: "save
 * the video for editing later ... all my settings and photos are there").
 * Super admin only. See "Saved videos" in inc/video.php.
 *
 *   GET                                        → JSON {saved: [{id, name, saved}]}, newest first
 *   GET ?id=<id>                               → JSON {id, name, saved, data}
 *   POST data=<JSON> [+ id] [+ name] + csrf_token → saved (a new one, or over
 *        the one named); JSON {id, saved: [...]}
 *   POST delete=<id> + csrf_token              → JSON {saved: [...]}
 *
 * What is saved is the form as the page holds it (its boxes, the photos
 * picked and what was set on each), not a video: opening one fills the form
 * again, and the video is made from it as from any other.
 */
require_once __DIR__ . '/inc/video.php';

$ctx = plan_gate($conn);
session_write_close();
$json = function (array $a, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_csrf_ok()) $json(['error' => 'That page had been open too long. Reload it and try again.'], 400);
    if (isset($_POST['delete'])) { video_saved_delete($ctx, (string)$_POST['delete']); $json(['saved' => video_saved_list($ctx)]); }
    $raw = (string)($_POST['data'] ?? '');
    $data = strlen($raw) <= VIDEO_SAVED_MAX_BYTES ? json_decode($raw, true) : null;
    if (!is_array($data)) $json(['error' => 'That could not be saved. Please try again.'], 400);
    $id = video_saved_write($ctx, (string)($_POST['id'] ?? ''), (string)($_POST['name'] ?? ''), $data);
    if ($id === null) $json(['error' => 'That could not be saved. Please try again.'], 500);
    $json(['id' => $id, 'saved' => video_saved_list($ctx)]);
}

if (isset($_GET['id'])) {
    $s = video_saved_read($ctx, (string)$_GET['id']);
    $json($s ?? ['error' => 'That saved video is no longer here.'], $s ? 200 : 404);
}
$json(['saved' => video_saved_list($ctx)]);
