<?php
/**
 * api/pipeline_webhook.php — Paperless Pipeline events, posted by Zapier.
 *
 * Ported from monthausint.com/hot-sheets/pipeline_webhook.php. Authenticates,
 * stores the raw body in hs_pipeline_events, returns 200. Parses NOTHING:
 * cron/parse_pipeline_events.php does that from the stored rows, so a parser
 * fix never needs Zapier to replay anything.
 *
 * PUBLIC (no auth.php, no allowlist). The secret travels in a header ONLY:
 *
 *   X-Pipeline-Token: <PIPELINE_WEBHOOK_SECRET>      (constant in inc/db.php)
 *
 * The hub also took ?token=, which put the secret in every access log line
 * and in Zapier's task history. In Zapier: Webhooks by Zapier → POST →
 * Headers → X-Pipeline-Token.
 *
 * Zapier stays pointed at the hub until go-live; see docs/HOT_SHEETS_PLAN.md.
 */

ini_set('display_errors', '0');   // errors would land in Zapier's logs
header('Content-Type: application/json');

function pl_respond(int $code, string $msg, array $extra = []): never {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => $code < 400, 'message' => $msg], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pl_respond(405, 'POST only');

require_once __DIR__ . '/../inc/db.php';

if (!defined('PIPELINE_WEBHOOK_SECRET') || strlen((string)PIPELINE_WEBHOOK_SECRET) < 24) {
    error_log('pipeline_webhook: PIPELINE_WEBHOOK_SECRET not set');
    pl_respond(503, 'Not configured');
}
$provided = (string)($_SERVER['HTTP_X_PIPELINE_TOKEN'] ?? '');
if ($provided === '' || !hash_equals((string)PIPELINE_WEBHOOK_SECRET, $provided)) {
    error_log('pipeline_webhook: bad token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    pl_respond(401, 'Unauthorized');
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    // Form-encoded posts leave php://input readable too, but be safe.
    if (!empty($_POST)) $raw = json_encode($_POST);
    else pl_respond(400, 'Empty body');
}
if (strlen($raw) > 256 * 1024) pl_respond(413, 'Payload too large');

$decoded = json_decode($raw, true);
if (!is_array($decoded) && !empty($_POST)) $raw = json_encode($_POST);   // form-encoded: keep a JSON copy

$headers = [];
foreach (['HTTP_USER_AGENT', 'HTTP_X_FORWARDED_FOR', 'CONTENT_TYPE'] as $h) {
    if (!empty($_SERVER[$h])) $headers[$h] = $_SERVER[$h];
}
$hdr = json_encode($headers);
$ip  = $_SERVER['REMOTE_ADDR'] ?? null;

$s = $conn->prepare("INSERT INTO hs_pipeline_events (payload, headers, source_ip) VALUES (?, ?, ?)");
if (!$s) { error_log('pipeline_webhook: prepare failed: ' . $conn->error); pl_respond(500, 'Storage error'); }
$s->bind_param('sss', $raw, $hdr, $ip);
if (!$s->execute()) { error_log('pipeline_webhook: insert failed: ' . $s->error); pl_respond(500, 'Storage error'); }
$id = (int)$conn->insert_id;
$s->close();
$conn->close();

pl_respond(200, 'Stored', ['event_id' => $id]);
