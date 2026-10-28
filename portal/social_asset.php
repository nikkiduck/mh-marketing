<?php
/**
 * portal/social_asset.php — one of the agent's uploaded photos.
 * GET ?id=<hex>&w=400|1600 → image/jpeg (400 = the thumbnail). Only files
 * under THIS account's upload folder are ever served (social_upload_file()).
 * See inc/social.php.
 */
require_once __DIR__ . '/../inc/social.php';

$ctx = social_context($conn);   // or the admin's own context with ?preview=admin (2026-10-28)
social_gate();

$id = (string)($_GET['id'] ?? '');
$f  = social_upload_file($ctx, $id, (int)($_GET['w'] ?? 1600) <= 400);
if (!$f) { http_response_code(404); header('Content-Type: text/plain'); echo 'Not found'; exit; }

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($f));
header('Cache-Control: private, max-age=86400');
header('X-Robots-Tag: noindex');
readfile($f);
