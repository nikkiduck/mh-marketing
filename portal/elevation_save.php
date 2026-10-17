<?php
/**
 * portal/elevation_save.php — the Elevation Planner's saves (2026-10-17).
 * POST (form fields, csrf_token) → JSON. Always the signed-in person's own
 * rows (elevation_owner: the account id); an id from the request is used only
 * with that owner in the WHERE, so someone else's plan is simply not found.
 *
 *   action=working   plan, leads (JSON)   rewrite the working plan        → {ok, at}
 *   action=snapshot  plan, leads, name    a named copy of both parts      → {ok, item, saved: [...]}
 *   action=rename    id, name                                             → {ok, saved: [...]}
 *   action=delete    id                                                   → {ok, saved: [...]}
 *
 * Refused (with {error}) before the migration has run, in an admin preview
 * (look, never change), without the token, or when the JSON is not a plan.
 */
require_once __DIR__ . '/../inc/elevation.php';

$ctx = portal_context($conn);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function elevation_out(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function elevation_list_out(mysqli $conn, int $owner): array {
    return array_values(array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'created_at' => $r['created_at'], 'date' => elevation_date_words($r['created_at']), 'plan' => $r['plan'], 'leads' => $r['leads']],
                                  elevation_saved($conn, $owner)));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !portal_csrf_ok()) {
    elevation_out(400, ['error' => 'That page had been open too long. Please reload it and try again.']);
}
if ($ctx['preview']) elevation_out(403, ['error' => 'Preview: nothing is saved while you are looking at an agent\'s portal.']);
if (!elevation_ready($conn)) elevation_out(503, ['error' => 'Saving to your account is not switched on yet. Your entries stay in this browser for now.']);

$owner  = elevation_owner($ctx);
$action = (string)($_POST['action'] ?? '');

if ($action === 'working' || $action === 'snapshot') {
    $plan  = elevation_part_from_post('plan', 'plan');
    $leads = elevation_part_from_post('leads', 'leads');
    if ($plan === null || $leads === null) elevation_out(422, ['error' => 'The plan could not be read. Please reload the page and try again.']);
    if ($action === 'working') {
        if (!elevation_save_working($conn, $owner, $plan, $leads)) elevation_out(500, ['error' => 'The plan could not be saved just now. Your entries are kept in this browser until it works again.']);
        elevation_out(200, ['ok' => true, 'at' => date('c')]);
    }
    $name = elevation_clean_name($_POST['name'] ?? '');
    $id = elevation_snapshot($conn, $owner, $name, $plan, $leads);
    if ($id <= 0) elevation_out(500, ['error' => 'The plan could not be saved just now. Please try again.']);
    $list = elevation_list_out($conn, $owner);
    $item = null;
    foreach ($list as $r) if ((int)$r['id'] === $id) { $item = $r; break; }
    elevation_out(200, ['ok' => true, 'id' => $id, 'item' => $item, 'saved' => $list]);
}

if ($action === 'rename') {
    $id = (int)($_POST['id'] ?? 0); $name = elevation_clean_name($_POST['name'] ?? '');
    if ($id <= 0 || $name === '') elevation_out(422, ['error' => 'Give the plan a name.']);
    if (!elevation_get($conn, $owner, $id)) elevation_out(404, ['error' => 'That plan was not found.']);
    elevation_rename($conn, $owner, $id, $name);
    elevation_out(200, ['ok' => true, 'saved' => elevation_list_out($conn, $owner)]);
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 || !elevation_get($conn, $owner, $id)) elevation_out(404, ['error' => 'That plan was not found.']);
    elevation_delete($conn, $owner, $id);
    elevation_out(200, ['ok' => true, 'saved' => elevation_list_out($conn, $owner)]);
}

elevation_out(400, ['error' => 'Unknown action.']);
