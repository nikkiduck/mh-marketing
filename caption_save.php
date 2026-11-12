<?php
/**
 * caption_save.php — keeps or resets one listing's Suggested Caption
 * (inc/captions.php, 2026-11-12). POST with the CSRF token, id
 * ("market|listing_key", one of plan_listings()) and caption; reset=1 drops
 * the saved caption (back to the draft, or the one built from the feed).
 * Answers JSON: ok, caption (as stored), source. Super admin only.
 */
require_once __DIR__ . '/inc/plan.php';
$ctx = plan_gate($conn);
header('Content-Type: application/json; charset=utf-8');
$stop = function (int $code, string $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) $stop(400, 'The page had been open too long. Reload it and save again.');
$id = (string)($_POST['id'] ?? '');
$l = caption_id_ok($id) ? plan_listing($conn, $id) : null;
if (!$l) $stop(404, 'That listing is not in the feed.');
if (!empty($_POST['reset'])) {
    if (!caption_reset($id)) $stop(500, 'The caption could not be reset.');
} else {
    $text = caption_clean((string)($_POST['caption'] ?? ''));
    if ($text === '') $stop(400, 'The caption is empty.');
    $by = (string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? 'admin');
    if (!caption_save($id, $text, $by)) $stop(500, 'The caption could not be saved.');
}
$c = caption_for($conn, $l);
echo json_encode(['ok' => true, 'caption' => $c['caption'], 'source' => $c['source']], JSON_UNESCAPED_UNICODE);
