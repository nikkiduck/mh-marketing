<?php
/**
 * subscribers.php — who gets the Hot Sheets.
 *
 * The marketing portal's version of the hub's hot-sheets/subscribers.php, on
 * the one roster: a subscriber is normally an agent (intake_id), and the agent
 * page's Subscriptions card links here (Nikki, 2026-09-25).
 *
 * Adding someone here subscribes them. Paused keeps the record but stops the
 * email; unsubscribed is the person's own choice, made from the email footer,
 * and is never undone from this page (a fresh Subscribe is needed, which is
 * deliberate: it is a record of consent).
 *
 * While HOT_SHEET_ALLOWED_RECIPIENTS is set, only those addresses actually
 * receive anything, whatever this list says. The page shows that.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/schema.php';
require_once __DIR__ . '/inc/agent_roster.php';
require_once __DIR__ . '/inc/agent_lifecycle.php';
require_login();
require_role('admin');

if (!mk_table_exists($conn, 'hs_subscribers')) {
    http_response_code(500);
    exit('Hot Sheets are not set up yet: run sql/hot_sheets_v2.sql.');
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    $sid    = (int)($_POST['id'] ?? 0);

    if ($action === 'add') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $freq  = ($_POST['frequency'] ?? 'weekly') === 'daily' ? 'daily' : 'weekly';
        $iid   = (int)($_POST['intake_id'] ?? 0) ?: null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = ['err', 'That does not look like an email address.'];
        } else {
            $s = $conn->prepare("SELECT id, is_active, unsubscribed_at FROM hs_subscribers WHERE email = ? LIMIT 1");
            $s->bind_param('s', $email); $s->execute();
            $have = $s->get_result()->fetch_assoc(); $s->close();
            if ($have && $have['unsubscribed_at']) {
                $flash = ['err', $email . ' unsubscribed themselves. They have to subscribe again from a Hot Sheet email.'];
            } elseif ($have) {
                $conn->query("UPDATE hs_subscribers SET is_active = 1, frequency = '{$freq}'"
                           . ($iid ? ', intake_id = ' . $iid : '') . " WHERE id = " . (int)$have['id']);
                $flash = ['ok', $email . ' is on the list.'];
            } else {
                $tok = bin2hex(random_bytes(16));
                $s = $conn->prepare("INSERT INTO hs_subscribers (email, intake_id, frequency, unsubscribe_token) VALUES (?, ?, ?, ?)");
                $s->bind_param('siss', $email, $iid, $freq, $tok); $s->execute(); $s->close();
                $flash = ['ok', 'Added ' . $email . '.'];
            }
        }
    }

    if ($sid && $action === 'frequency') {
        $freq = ($_POST['frequency'] ?? 'weekly') === 'daily' ? 'daily' : 'weekly';
        $conn->query("UPDATE hs_subscribers SET frequency = '{$freq}' WHERE id = {$sid}");
        $flash = ['ok', 'Frequency changed.'];
    }
    if ($sid && ($action === 'pause' || $action === 'resume')) {
        $on = $action === 'resume' ? 1 : 0;
        $conn->query("UPDATE hs_subscribers SET is_active = {$on} WHERE id = {$sid} AND unsubscribed_at IS NULL");
        $flash = ['ok', $on ? 'Emails started again.' : 'Emails paused.'];
    }
    if ($sid && $action === 'remove') {
        // Removing the record entirely: for a wrong address or a test, not for
        // someone who asked to stop (pause keeps the record, and their own
        // unsubscribe is recorded by unsubscribe.php).
        $conn->query("DELETE FROM hs_subscribers WHERE id = {$sid}");
        $flash = ['ok', 'Removed from the list.'];
    }
    // The checklist item and the switch on the agent page stay in step.
    if ($sid || $action === 'add') {
        $row = $conn->query("SELECT intake_id, email FROM hs_subscribers WHERE id = " . ($sid ?: 0))->fetch_assoc();
        $iid = (int)($row['intake_id'] ?? 0);
        if ($iid) {
            $done = mk_hs_subscribed($conn, $iid) ? 'done' : 'open';
            $conn->query("UPDATE marketing_tasks SET status = '{$done}', completed_at = " . ($done === 'done' ? 'NOW()' : 'NULL')
                       . " WHERE intake_id = {$iid} AND category = 'onboarding' AND title = '" . $conn->real_escape_string(MK_HS_TASK) . "'");
        }
    }
    $_SESSION['hs_flash'] = $flash;
    header('Location: subscribers.php' . (!empty($_POST['q']) ? '?q=' . urlencode((string)$_POST['q']) : '')); exit;
}

$flash = $_SESSION['hs_flash'] ?? null;
unset($_SESSION['hs_flash']);
$q = trim((string)($_GET['q'] ?? ''));

$rows = $conn->query("SELECT s.*, mi.agent_name, mi.slug, mi.status AS agent_status
                        FROM hs_subscribers s
                   LEFT JOIN marketing_intakes mi ON mi.id = s.intake_id
                    ORDER BY s.unsubscribed_at IS NOT NULL, s.is_active DESC, s.email")->fetch_all(MYSQLI_ASSOC);

// Agents with no subscription, for the add form.
$not_on = $conn->query("SELECT mi.id, mi.agent_name, mi.mh_email, mi.mls_email
                          FROM marketing_intakes mi
                         WHERE mi.is_active = 1 AND mi.status NOT IN ('archived')" . mk_team_sql($conn, 'mi') . "
                           AND NOT EXISTS (SELECT 1 FROM hs_subscribers s
                                            WHERE s.is_active = 1 AND s.unsubscribed_at IS NULL
                                              AND (s.intake_id = mi.id
                                                   OR (mi.mh_email <> '' AND LOWER(s.email) = LOWER(mi.mh_email))))
                         ORDER BY mi.agent_name")->fetch_all(MYSQLI_ASSOC);

$allowed = array_values(array_filter(array_map('trim', explode(',', defined('HOT_SHEET_ALLOWED_RECIPIENTS') ? HOT_SHEET_ALLOWED_RECIPIENTS : ''))));
$counts  = ['on' => 0, 'paused' => 0, 'off' => 0];
foreach ($rows as $r) {
    $counts[$r['unsubscribed_at'] ? 'off' : ((int)$r['is_active'] ? 'on' : 'paused')]++;
}
$conn->close();
// ── Nothing below this line may touch the database. ─────────────────────────

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Hot Sheet Subscribers | Mont Haus Marketing</title>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <style>
    .layout-extended .pc-container { margin-top:70px; padding-top:24px; top:0; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    .mh-header-inner { width:100%; padding:0 15px; display:flex; align-items:center; justify-content:space-between; height:70px; }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; font-size:.8rem; font-weight:500;
                   color:#75BDB6; text-decoration:none; border-radius:6px; white-space:nowrap; transition:color .2s, background .2s; }
    .mh-nav-link:hover  { color:#fff; background:rgba(117,189,182,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(117,189,182,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }
    .hdr-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .wrap { max-width:1100px; margin:32px auto 60px; padding:0 20px; }
    .mk-page-header { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .card { background:#fff; border-radius:6px; padding:22px 26px; margin-bottom:18px; }
    .card-title { font-size:14px; font-weight:700; margin:0 0 16px; }
    .sub-table { width:100%; border-collapse:collapse; }
    .sub-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; padding:8px 10px; }
    .sub-table td { padding:10px; border-top:1px solid #f1ece0; vertical-align:middle; font-size:14px; }
    .sub-email { font-weight:600; }
    .sub-who a { text-decoration:none; }
    .pill { display:inline-block; font-size:11px; padding:2px 9px; border-radius:10px; white-space:nowrap; }
    .pill.on     { background:#f0fdf4; color:#15803d; }
    .pill.paused { background:#fff7ed; color:#c2410c; }
    .pill.off    { background:#f3f4f6; color:#6b7280; }
    .row-actions { display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap; }
    .btn-xs { padding:4px 10px; font-size:11px; line-height:1.3; white-space:nowrap; }
    .form-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
    .form-input, select { padding:8px 10px; border:1px solid #d1d5db; border-radius:4px; font-size:14px; }
    .fld-label { display:block; font-size:12px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; margin-bottom:4px; }
    .flash { padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:14px; }
    .flash.ok  { background:#f0fdf4; color:#15803d; }
    .flash.err { background:#fef2f2; color:#b91c1c; }
    .note { background:#fbf7ec; border:1px solid #e3dccb; padding:12px 14px; font-size:13px; color:#5c4a28; margin-bottom:18px; }
    .hint { font-size:12px; color:#9ca3af; }
    .hl { background:#fdf6e3; }
    @media (max-width:820px) { .sub-table thead { display:none; } .sub-table td { display:block; border:0; padding:3px 0; }
                               .sub-table tr { display:block; border-top:1px solid #f1ece0; padding:12px 0; } }
  </style>
</head>
<body class="layout-extended" data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">

<?php $mh_active = 'marketing'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
  <div class="wrap">

    <div class="mk-page-header">
      <div>
        <h1>Hot Sheet Subscribers</h1>
        <p class="hint" style="margin:4px 0 0;">
          <?= $counts['on'] ?> receiving &middot; <?= $counts['paused'] ?> paused &middot; <?= $counts['off'] ?> unsubscribed
        </p>
      </div>
      <div class="hdr-actions">
        <a class="btn btn-outline btn-sm" href="index.php"><i class="ti ti-users"></i> Agent Roster</a>
        <a class="btn btn-outline btn-sm" href="hot_sheet_preview.php"><i class="ti ti-mail"></i> Preview the email</a>
      </div>
    </div>

    <?php if ($flash): ?><div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>

    <?php if ($allowed): ?>
      <div class="note">
        <strong>Test mode.</strong> Whatever this list says, the Hot Sheets only go to
        <?= e(implode(', ', $allowed)) ?>. Clear <code>HOT_SHEET_ALLOWED_RECIPIENTS</code> in
        <code>inc/config.php</code> when everyone should receive them.
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-title">Add someone</div>
      <form method="POST" class="form-row">
        <input type="hidden" name="_action" value="add">
        <div style="flex:1;min-width:240px;">
          <span class="fld-label">Agent</span>
          <select name="intake_id" class="form-input" style="width:100%;" id="agentPick">
            <option value="">Someone who is not on the roster</option>
            <?php foreach ($not_on as $a):
              $em = strtolower(trim((string)($a['mh_email'] ?: $a['mls_email']))); ?>
              <option value="<?= (int)$a['id'] ?>" data-email="<?= e($em) ?>"><?= e($a['agent_name']) ?><?= $em ? ' — ' . e($em) : ' (no email on file)' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1;min-width:220px;">
          <span class="fld-label">Email</span>
          <input type="email" name="email" id="subEmail" class="form-input" style="width:100%;" placeholder="name@monthaus.com" required>
        </div>
        <div>
          <span class="fld-label">How often</span>
          <select name="frequency" class="form-input">
            <option value="weekly">Weekly</option>
            <option value="daily">Daily</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Add</button>
      </form>
      <p class="hint" style="margin:10px 0 0;">Agents already receiving the Hot Sheets are not in this list.</p>
    </div>

    <div class="card">
      <div class="card-title">Everyone on the list</div>
      <table class="sub-table">
        <thead><tr><th>Email</th><th>Agent</th><th>How often</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $state = $r['unsubscribed_at'] ? 'off' : ((int)$r['is_active'] ? 'on' : 'paused');
          $label = ['on' => 'Receiving', 'paused' => 'Paused', 'off' => 'Unsubscribed'][$state];
          $hit   = $q !== '' && strcasecmp($q, (string)$r['email']) === 0;
        ?>
          <tr<?= $hit ? ' class="hl" id="found"' : '' ?>>
            <td class="sub-email"><?= e($r['email']) ?></td>
            <td class="sub-who">
              <?php if ($r['intake_id'] && $r['agent_name']): ?>
                <a href="agent.php?id=<?= (int)$r['intake_id'] ?>&tab=profile"><?= e($r['agent_name']) ?></a>
              <?php else: ?><span class="hint">not on the roster</span><?php endif; ?>
            </td>
            <td>
              <?php if ($state !== 'off'): ?>
                <form method="POST" style="margin:0;">
                  <input type="hidden" name="_action" value="frequency">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="q" value="<?= e($q) ?>">
                  <select name="frequency" class="form-input" style="padding:4px 8px;font-size:13px;" onchange="this.form.submit()">
                    <option value="weekly" <?= $r['frequency'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                    <option value="daily"  <?= $r['frequency'] === 'daily'  ? 'selected' : '' ?>>Daily</option>
                  </select>
                </form>
              <?php else: ?><span class="hint">&middot;</span><?php endif; ?>
            </td>
            <td>
              <span class="pill <?= $state ?>"><?= $label ?></span>
              <?php if ($state === 'off'): ?>
                <div class="hint">on <?= e(date('M j, Y', strtotime((string)$r['unsubscribed_at']))) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="row-actions">
                <?php if ($state === 'on'): ?>
                  <form method="POST" style="margin:0;"><input type="hidden" name="_action" value="pause">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
                    <button class="btn btn-outline btn-xs" type="submit">Pause</button></form>
                <?php elseif ($state === 'paused'): ?>
                  <form method="POST" style="margin:0;"><input type="hidden" name="_action" value="resume">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
                    <button class="btn btn-primary btn-xs" type="submit">Start again</button></form>
                <?php endif; ?>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Remove <?= e($r['email']) ?> from the list? Use Pause instead if they only want a break.');">
                  <input type="hidden" name="_action" value="remove">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
                  <button class="btn btn-outline btn-xs" type="submit">Remove</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="hint">Nobody is subscribed yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <p class="hint" style="margin:14px 0 0;">
        Pause keeps the record and stops the email. Unsubscribed is the person's own choice from the email
        footer, and cannot be undone here: they have to subscribe again themselves.
      </p>
    </div>

  </div>
</div>

<script>
// Picking an agent fills their address in.
(function () {
  var pick = document.getElementById('agentPick'), email = document.getElementById('subEmail');
  if (!pick || !email) return;
  pick.addEventListener('change', function () {
    var opt = pick.options[pick.selectedIndex];
    if (opt && opt.dataset.email) email.value = opt.dataset.email;
  });
})();
var found = document.getElementById('found');
if (found) found.scrollIntoView({ block: 'center' });
</script>
</body>
</html>
