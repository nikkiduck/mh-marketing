<?php
/**
 * video_export.php — the Video tool's MP4. Super admin only. See inc/video.php.
 *
 *   POST (the builder form, with csrf_token)  → the video is started in the
 *        background (video_job_start()); JSON {job} or {error}
 *   POST stop=<id> (with csrf_token)          → the video being made is stopped
 *        (video_job_stop()); JSON {stopped}
 *   GET ?job=<id>                             → JSON {state, step, pct, …}
 *   GET ?job=<id>&file=1[&dl=1]               → the finished MP4 (byte
 *        ranges honoured, so the page can play it; dl=1 as a download)
 *
 * A job belongs to the person who started it: the id is looked up in their
 * own folder only.
 */
require_once __DIR__ . '/inc/video.php';

$ctx = video_gate($conn);
$json = function (array $a, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($a);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_csrf_ok()) $json(['error' => 'That page had been open too long. Reload it and try again.'], 400);
    if (isset($_POST['stop'])) { session_write_close(); $json(['stopped' => video_job_stop($ctx, (string)$_POST['stop'])]); }
    $v = video_from_request($conn, $ctx, $_POST);
    if (is_string($v)) $json(['error' => $v]);
    $id = video_job_start($ctx, $v);
    if ($id === null) $json(['error' => 'The video could not be started. Please try again in a minute.']);
    $json(['job' => $id]);
}

$id = (string)($_GET['job'] ?? '');
if (!empty($_GET['file'])) {
    $dir = video_job_dir($ctx, $id);
    $s = $dir !== '' ? video_job_status($ctx, $id) : null;
    if (!$s || $s['state'] !== 'done' || !is_file("{$dir}/out.mp4")) { http_response_code(404); exit('Not found'); }
    video_send_file("{$dir}/out.mp4", 'video/mp4', !empty($_GET['dl']) ? $s['name'] : null);
}
session_write_close();
$s = video_job_status($ctx, $id);
$json($s ?? ['state' => 'failed', 'error' => 'That video is no longer here. Make it again.'], $s ? 200 : 404);
