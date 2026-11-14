<?php
/**
 * portal/whats_new_seen.php — records that the signed-in person has seen the What's New notices up to an id
 * (2026-11-14). Called by the header's script (inc/portal.php) when the bell's list is opened or the banner is
 * closed: POST csrf_token + id. The marker only ever moves forward (wn_portal_mark()).
 *
 * In an admin's preview of someone's portal nothing is recorded: looking at a portal must not mark the agent's
 * notices as seen. Answers JSON either way; the page never waits on it.
 */
require_once __DIR__ . '/../inc/portal.php';

$ctx = portal_context($conn);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) { http_response_code(400); echo json_encode(['ok' => false]); exit; }
if ($ctx['preview']) { echo json_encode(['ok' => true, 'saved' => false]); exit; }

$ok = false;
try { $ok = wn_portal_mark($conn, (int)($ctx['user']['id'] ?? 0), (int)($_POST['id'] ?? 0)); }
catch (Throwable $e) { error_log('whats_new_seen: ' . $e->getMessage()); }
echo json_encode(['ok' => $ok, 'saved' => $ok]);
