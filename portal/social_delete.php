<?php
/**
 * portal/social_delete.php — remove one of the signed-in person's uploads.
 * POST (id = the hex upload id, csrf_token) → JSON {ok: true} or {error}.
 * Only a file under THIS person's upload folder can be removed
 * (social_delete_upload()); posts already exported keep their PNGs.
 * See inc/social.php. (Nikki, 2026-10-18: "a little delete x".)
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = social_context($conn);   // or the admin's own context with ?preview=admin (2026-10-28)
social_gate();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    http_response_code(400);
    echo json_encode(['error' => 'That page had been open too long. Please reload it and try again.']);
    exit;
}
$id = (string)($_POST['id'] ?? '');
if (str_starts_with($id, 'upload:')) $id = substr($id, 7);
echo json_encode(social_delete_upload($ctx, $id) ? ['ok' => true] : ['error' => 'That photo was not found.'], JSON_UNESCAPED_SLASHES);
