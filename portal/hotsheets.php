<?php
/**
 * portal/hotsheets.php — saves the agent's own Hot Sheet subscription. Since
 * 2026-10-12 the form lives on My Profile (inc/portal_hotsheets.php renders
 * it there); this file takes the POST and sends the person back to the
 * profile. A GET just goes to the profile too. Every change is emailed to
 * marketing@ and noted on the agent page (portal_change_alert).
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/portal_hotsheets.php';

$ctx = portal_context($conn);
$back = portal_url($ctx, '/portal/profile.php') . '#hotsheets';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }

if (!portal_hs_ready($conn)) {
    portal_stop('Not ready yet', 'Hot Sheet choices are not switched on yet (<code>sql/hot_sheets_v4_areas.sql</code> has not been run).');
}

$hs = portal_hs_load($conn, $ctx);
$sub = $hs['sub']; $email = $hs['email']; $intake_id = $hs['intake_id']; $areas = $hs['areas'];
$action = (string)($_POST['action'] ?? '');

if (!portal_csrf_ok()) {
    $_SESSION['pt_hs_flash'] = ['err', 'That page had been open too long. Please try again.'];
} elseif ($ctx['preview']) {
    $_SESSION['pt_hs_flash'] = ['err', 'This is a preview: change their Hot Sheets from their agent page.'];
} elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['pt_hs_flash'] = ['err', 'Your sign-in has no email address, so there is nothing to send to. Please email ' . PORTAL_MARKETING_EMAIL . '.'];
} elseif ($action === 'save') {
    $freq  = (string)($_POST['frequency'] ?? 'twice_weekly');
    if (!in_array($freq, ['daily', 'twice_weekly'], true)) $freq = 'twice_weekly';
    $picks = array_map('strval', (array)($_POST['areas'] ?? []));
    $before = ['on' => $hs['on'], 'areas' => $hs['ticked'], 'freq' => $hs['freq']];
    if (!$sub) {
        $tok = bin2hex(random_bytes(16));
        $iid = $intake_id ?: null;
        $st = $conn->prepare("INSERT INTO hs_subscribers (email, intake_id, frequency, areas, unsubscribe_token) VALUES (?, ?, 'twice_weekly', '[]', ?)");
        $st->bind_param('sis', $email, $iid, $tok); $st->execute();
        $sub = ['id' => (int)$conn->insert_id, 'is_active' => 1, 'unsubscribed_at' => null];
        $st->close();
    }
    mk_hs_save_prefs($conn, (int)$sub['id'], $freq, $picks);
    // Saving means they want them: a paused or self-unsubscribed row starts again.
    $conn->query("UPDATE hs_subscribers SET is_active = 1, unsubscribed_at = NULL WHERE id = " . (int)$sub['id']);
    mk_hs_sync_task($conn, $intake_id);
    $chosen = array_values(array_intersect(array_keys($areas), $picks));
    $_SESSION['pt_hs_flash'] = ['ok', $chosen
        ? 'Saved. You will get the ' . implode(', ', array_map('mk_area_name', $chosen)) . ' Hot Sheet' . (count($chosen) === 1 ? '' : 's')
          . ' ' . ($freq === 'daily' ? 'every day' : 'on Mondays and Thursdays') . ', on days there is something to show.'
        : 'Saved, but no areas are selected, so nothing will be sent until you tick one.'];
    // What changed, for marketing@ and the agent page.
    $names = fn(array $keys) => implode(', ', array_map('mk_area_name', $keys));
    $changes = [];
    if (!$before['on']) $changes[] = ['Hot Sheets', 'Off', 'On'];
    if (array_values($before['areas']) !== $chosen) $changes[] = ['Areas', $names($before['areas']), $names($chosen)];
    if ($before['freq'] !== $freq || !$before['on']) $changes[] = ['How often', $before['on'] ? mk_hs_frequency_label($before['freq']) : '', mk_hs_frequency_label($freq)];
    portal_change_alert($conn, $ctx, 'Hot Sheet subscription', $changes);
} elseif ($action === 'pause' && $sub) {
    $conn->query("UPDATE hs_subscribers SET is_active = 0 WHERE id = " . (int)$sub['id']);
    mk_hs_sync_task($conn, $intake_id);
    $_SESSION['pt_hs_flash'] = ['ok', 'Paused. You will not get any Hot Sheets until you start them again here.'];
    portal_change_alert($conn, $ctx, 'Hot Sheet subscription', [['Hot Sheets', 'On', 'Paused']]);
}
header('Location: ' . $back); exit;
