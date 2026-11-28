<?php
/**
 * video_clip.php — the Video tool's uploaded clips. Super admin only. See
 * inc/video.php.
 *
 *   POST ?id=<16 hex>&offset=<bytes so far>&last=0|1&name=<file name>, the
 *        body one piece of the file (application/octet-stream, up to
 *        VIDEO_CHUNK_BYTES), the CSRF token in the X-CSRF-Token header
 *        (there is no form to carry it)                 → JSON {ok, done, clip}
 *   POST delete=<id> + csrf_token (an ordinary form post) → JSON {ok}
 *   GET ?id=<id>                                        → the clip itself,
 *        byte ranges honoured, for the preview's <video>
 *
 * A phone clip is far larger than PHP's upload limits here (2M a file, 8M a
 * post), which is why it arrives in pieces and why the pieces are read from
 * the request body, not from $_FILES.
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
    if (isset($_POST['delete'])) {
        if (!portal_csrf_ok()) $json(['error' => 'Reload the page and try again.'], 400);
        video_clip_delete($ctx, (string)$_POST['delete']);
        $json(['ok' => true]);
    }
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $json(['error' => 'That page had been open too long. Reload it and upload again.'], 400);
    session_write_close();   // the pieces of one upload must not queue behind the page's other requests
    $bytes = (string)file_get_contents('php://input');
    if ($bytes === '') $json(['error' => 'A piece of the upload arrived empty. Please try again.'], 400);
    $json(video_clip_chunk($ctx, (string)($_GET['id'] ?? ''), (int)($_GET['offset'] ?? -1), !empty($_GET['last']), (string)($_GET['name'] ?? ''), $bytes));
}

$c = video_clip($ctx, (string)($_GET['id'] ?? ''));
if (!$c) { http_response_code(404); exit('Not found'); }
video_send_file($c['file'], 'video/mp4');
