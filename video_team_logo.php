<?php
/**
 * video_team_logo.php — a team's logo for the Video tool's closing card (the
 * quick version, Nikki 2026-11-26; see "Team logos" in inc/video.php). Super
 * admin only.
 *
 *   GET  ?team=<key>                                  → the logo itself
 *   POST team=<key> + logo (a file) + dark=0|1 + csrf_token → JSON {ok, team}
 *   POST team=<key> + remove=1 + csrf_token                 → JSON {ok, team}
 *
 * `dark` is what the person uploading says of the file: a dark logo is shown
 * in white on the card.
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
    if (video_portal()) $json(['error' => 'A team logo is set by the marketing team.'], 403);   // agents see a team's logo; they do not change it
    if (!portal_csrf_ok()) $json(['error' => 'That page had been open too long. Reload it and try again.'], 400);
    $key = (string)($_POST['team'] ?? '');
    if (!video_team($key)) $json(['error' => 'Choose a team first.'], 400);
    if (!empty($_POST['remove'])) { video_team_logo_remove($key); $json(['ok' => true, 'team' => $key, 'has' => false]); }
    $r = video_team_logo_store($key, (array)($_FILES['logo'] ?? []), !empty($_POST['dark']));
    if (!empty($r['error'])) $json($r, 400);
    $json(['ok' => true, 'team' => $key, 'has' => true]);
}

$lg = video_team_logo((string)($_GET['team'] ?? ''));
if (!$lg) { http_response_code(404); exit; }
// An SVG is only ever shown as a picture; these keep it from running anything if it is opened by its address.
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');
header('X-Content-Type-Options: nosniff');
video_send_file($lg['file'], VIDEO_LOGO_TYPES[$lg['ext']]);
