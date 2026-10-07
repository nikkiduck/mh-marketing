<?php
/**
 * portal/hotsheets.php — the agent's own Hot Sheet subscription (2026-10-07).
 *
 * Nikki: agents choose their areas and how often themselves. This page is the
 * signed-in PERSON's subscription, found by their sign-in address (users.email),
 * never by an id from the request: Jonathan, Scott and Sara share the Weber
 * Boxer Group account but each has their own Hot Sheets ("Hot Sheet choices
 * stay per person", docs/AGENT_PORTAL_PLAN.md). Saving creates the row when
 * there is none (the person subscribing themselves is consent), and someone
 * who unsubscribed from an email footer can start again here for the same
 * reason. The marketing checklist item follows (mk_hs_sync_task()).
 *
 * What they can set: daily or twice a week (Monday + Thursday), and which of
 * the six areas. An area with nothing to show is not sent, and the page says
 * so, so nobody wonders where Tuesday's Gunnison Valley email went.
 */
require_once __DIR__ . '/../inc/portal.php';
require_once __DIR__ . '/../inc/boards.php';
require_once __DIR__ . '/../inc/agent_lifecycle.php';

$ctx = portal_context($conn);

if (!mk_table_exists($conn, 'hs_subscribers') || !mk_column_exists($conn, 'hs_subscribers', 'areas')) {
    portal_stop('Not ready yet', 'Hot Sheet choices are not switched on yet (<code>sql/hot_sheets_v4_areas.sql</code> has not been run).');
}

$email = strtolower(trim((string)$ctx['user']['email']));
if ($ctx['preview']) {
    // An admin previewing sees the account's own subscription, not their own.
    $sub = mk_hs_subscription($conn, (int)$ctx['acct']['id']);
    $email = $sub['email'] ?? mk_agent_email($conn->query("SELECT mh_email, mls_email FROM marketing_intakes WHERE id = " . (int)$ctx['acct']['id'])->fetch_assoc() ?: []);
} else {
    $st = $conn->prepare("SELECT * FROM hs_subscribers WHERE email = ? LIMIT 1");
    $st->bind_param('s', $email); $st->execute();
    $sub = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
}
// The roster row this address belongs to (for the checklist), else the account when it is a person.
$intake_id = 0;
$st = $conn->prepare("SELECT id FROM marketing_intakes WHERE is_active = 1 AND (LOWER(mh_email) = ? OR LOWER(mls_email) = ?) ORDER BY (LOWER(mh_email) = ?) DESC, id LIMIT 1");
$st->bind_param('sss', $email, $email, $email); $st->execute();
$intake_id = (int)($st->get_result()->fetch_assoc()['id'] ?? 0);
$st->close();
if ($intake_id === 0 && ($ctx['acct']['entity_type'] ?? 'agent') !== 'team') $intake_id = (int)$ctx['acct']['id'];

$areas = mk_area_names();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!portal_csrf_ok()) {
        $_SESSION['pt_hs_flash'] = ['err', 'That page had been open too long. Please try again.'];
    } elseif ($ctx['preview']) {
        $_SESSION['pt_hs_flash'] = ['err', 'This is a preview: change their Hot Sheets from their agent page.'];
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['pt_hs_flash'] = ['err', 'Your sign-in has no email address, so there is nothing to send to. Please email ' . PORTAL_MARKETING_EMAIL . '.'];
    } elseif ($action === 'save') {
        $freq  = (string)($_POST['frequency'] ?? 'twice_weekly');
        $picks = array_map('strval', (array)($_POST['areas'] ?? []));
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
    } elseif ($action === 'pause' && $sub) {
        $conn->query("UPDATE hs_subscribers SET is_active = 0 WHERE id = " . (int)$sub['id']);
        mk_hs_sync_task($conn, $intake_id);
        $_SESSION['pt_hs_flash'] = ['ok', 'Paused. You will not get any Hot Sheets until you start them again here.'];
    }
    header('Location: ' . portal_url($ctx, '/portal/hotsheets.php')); exit;
}

$flash = $_SESSION['pt_hs_flash'] ?? null;
unset($_SESSION['pt_hs_flash']);
$conn->close();
// ── Nothing below this line may touch the database. ─────────────────────────

$on      = $sub && (int)$sub['is_active'] && !$sub['unsubscribed_at'];
$ticked  = $sub ? mk_areas_decode($sub['areas']) : [];
$freq    = $sub ? (string)$sub['frequency'] : 'twice_weekly';
if ($freq === 'weekly') $freq = 'twice_weekly';

portal_header($ctx, 'Internal Hot Sheets', 'hotsheets');
?>
  <h1 class="pt-h1">Internal Hot Sheets</h1>
  <p class="pt-lead">Emailed Mont Haus listings, pocket listings, buyer reps and rentals, for the areas you work.
    One email per area, with the latest activity at the top.</p>

  <?php if ($flash): ?>
    <div class="pt-flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= ph($flash[1]) ?></div>
  <?php endif; ?>

  <p style="margin:0 0 18px;">
    <?php if ($on && $ticked): ?>
      <span class="pt-status">On</span>
      <span class="pt-help" style="margin-left:8px;">Going to <?= ph($email) ?>.</span>
    <?php elseif ($on): ?>
      <span class="pt-status off">No areas yet</span>
      <span class="pt-help" style="margin-left:8px;">Tick at least one area below.</span>
    <?php elseif ($sub && $sub['unsubscribed_at']): ?>
      <span class="pt-status off">Unsubscribed</span>
      <span class="pt-help" style="margin-left:8px;">You unsubscribed from an email. Save below to start again.</span>
    <?php elseif ($sub): ?>
      <span class="pt-status off">Paused</span>
      <span class="pt-help" style="margin-left:8px;">Save below to start them again.</span>
    <?php else: ?>
      <span class="pt-status off">Not subscribed</span>
      <span class="pt-help" style="margin-left:8px;">Choose your areas and save to start.</span>
    <?php endif; ?>
  </p>

  <form method="post" class="pt-form" action="<?= ph(portal_url($ctx, '/portal/hotsheets.php')) ?>">
    <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="save">

    <h2 class="pt-h2">Which areas</h2>
    <div class="pt-checks">
      <?php foreach ($areas as $k => $name): ?>
        <label class="pt-check">
          <input type="checkbox" name="areas[]" value="<?= ph($k) ?>" <?= in_array($k, $ticked, true) ? 'checked' : '' ?>>
          <span><?= ph($name) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="pt-note">An area's email only goes out on days it has something to show. If an area has no Mont Haus
      listings or rentals right now, nothing is sent.</div>

    <h2 class="pt-h2">How often</h2>
    <label class="pt-choice">
      <input type="radio" name="frequency" value="twice_weekly" <?= $freq !== 'daily' ? 'checked' : '' ?>>
      <b>Twice a week</b>
      <small>Mondays and Thursdays, in the morning.</small>
    </label>
    <label class="pt-choice">
      <input type="radio" name="frequency" value="daily" <?= $freq === 'daily' ? 'checked' : '' ?>>
      <b>Every day</b>
      <small>Each morning, with everything that changed since Monday.</small>
    </label>

    <div class="pt-row">
      <button type="submit" class="pt-btn main"><?= $on ? 'Save' : 'Save and start' ?></button>
    </div>
  </form>

  <?php if ($on): ?>
  <form method="post" action="<?= ph(portal_url($ctx, '/portal/hotsheets.php')) ?>" style="margin-top:28px;"
        onsubmit="return confirm('Pause your Hot Sheets? You can start them again here any time.');">
    <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="pause">
    <p class="pt-help">Need a break? <button type="submit" class="pt-btn quiet" style="padding:8px 14px;font-size:15px;">Pause my Hot Sheets</button></p>
  </form>
  <?php endif; ?>
<?php portal_footer();
