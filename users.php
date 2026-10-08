<?php
/**
 * users.php — add a portal account, and change what an existing one can do.
 *
 * super_admin only. This is the only surface that writes users.role, and the
 * only one that creates a users row at all: sso_resolve_user() deliberately
 * never creates accounts, so an unknown Microsoft account is refused until
 * somebody adds it here. That refusal is the design (see CLAUDE.md), and this
 * page is what makes it workable rather than a phpMyAdmin errand.
 *
 * ── Accounts are created without a password ──────────────────────────────────
 *
 * A new user gets first_name, last_name, email, role and nothing else. They
 * sign in with Microsoft; sso_resolve_user() matches on email the first time
 * and stamps entra_object_id, after which the address can change and the link
 * holds. There is no temp password to email, leak, or forget to rotate.
 *
 * The one exception is break-glass, and it is not created here: at least one
 * super_admin must keep a working password so there is a way in when the Entra
 * client secret expires. This page cannot set one — it only warns when none is
 * left, because that is the failure you want to hear about months before it
 * matters, not on the morning it does.
 *
 * ── No migration ─────────────────────────────────────────────────────────────
 *
 * Every column this page touches already exists: role got its third value in
 * sql/alter_users_role_v1.sql and password became nullable in sql/sso_schema.sql,
 * both already run. Nothing to deploy but the file. Both of those are guarded
 * anyway (see mk_role_enum_has_super_admin() and the entra_object_id guard),
 * because deploying a page ahead of its migration fails silently here in exactly
 * the way CLAUDE.md describes.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/schema.php';
require_role('super_admin');          // calls require_login() itself

$me       = current_user();
$me_id    = (int)($me['id'] ?? 0);
$ROLES    = ['agent', 'admin', 'super_admin'];
$ROLE_LBL = [
    'agent'       => 'Agent',
    'admin'       => 'Admin',
    'super_admin' => 'Super admin',
];

// ── CSRF ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/**
 * Can the column actually STORE 'super_admin' yet?
 *
 * users.role was enum('agent','admin') before alter_users_role_v1.sql. Writing
 * a value an enum does not carry is the worst kind of failure this app has:
 * outside strict mode MySQL stores the empty string, $hierarchy[''] misses,
 * $user_level becomes -1, and that person is below every threshold — locked out
 * of every page with no error anywhere. It is the known origin of the blank role
 * on nikki.boxer@monthaus.com.
 *
 * So the option is removed from the form rather than offered and silently
 * mangled. Scaffolding for the deploy window, like mk_column_exists() — once the
 * migration is everywhere this can go.
 */
function mk_role_enum_has_super_admin(mysqli $conn): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    $s = $conn->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
          LIMIT 1"
    );
    if ($s) {
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        $ok = $row && stripos((string)$row['COLUMN_TYPE'], "'super_admin'") !== false;
    }
    return $ok;
}

$has_sa_enum = mk_role_enum_has_super_admin($conn);
$has_entra   = mk_column_exists($conn, 'users', 'entra_object_id');

// Agent portal (docs/AGENT_PORTAL_PLAN.md): which marketing account each login
// sees. Guarded for the deploy window before sql/portal_identity_v1.sql runs.
// Several logins may share one account (Weber Boxer Group's three people), so
// this is a plain choice, never derived from the email.
$has_portal = mk_column_exists($conn, 'users', 'intake_id');
$accounts   = [];   // id => label
if ($has_portal) {
    $has_et = mk_column_exists($conn, 'marketing_intakes', 'entity_type');
    $res = $conn->query("SELECT id, agent_name" . ($has_et ? ', entity_type' : '') . "
                           FROM marketing_intakes
                          WHERE is_active = 1 AND status <> 'archived'"
                        . ($has_et ? " AND entity_type <> 'staff'" : '') . "
                          ORDER BY agent_name");
    if ($res) {
        while ($a = $res->fetch_assoc()) {
            $accounts[(int)$a['id']] = $a['agent_name'] . (($a['entity_type'] ?? '') === 'team' ? ' (team)' : '');
        }
    }
}
/** The posted portal account: 0 for none, -1 when it is not a real choice. */
function mk_posted_account(array $accounts): int {
    $iid = (int)($_POST['intake_id'] ?? 0);
    return ($iid === 0 || isset($accounts[$iid])) ? $iid : -1;
}
function mk_save_account(mysqli $conn, int $user_id, int $iid): void {
    $val = $iid > 0 ? $iid : null;
    $s = $conn->prepare("UPDATE users SET intake_id = ? WHERE id = ?");
    if ($s) { $s->bind_param('ii', $val, $user_id); $s->execute(); $s->close(); }
}
if (!$has_sa_enum) {
    // Offering a value the column cannot hold is how somebody gets locked out.
    $ROLES = ['agent', 'admin'];
}

/** Roles that may actually be assigned from the form. */
function mk_valid_role(string $r, array $roles): bool {
    return in_array($r, $roles, true);
}

/** How many ACTIVE super_admins are there, excluding one id? */
function mk_other_active_super_admins(mysqli $conn, int $except_id): int {
    $s = $conn->prepare(
        "SELECT COUNT(*) AS n FROM users
          WHERE role = 'super_admin' AND is_active = 1 AND id <> ?"
    );
    if (!$s) return 0;
    $s->bind_param('i', $except_id);
    $s->execute();
    $n = (int)($s->get_result()->fetch_assoc()['n'] ?? 0);
    $s->close();
    return $n;
}

/** Is this email already on an account other than $except_id? */
function mk_email_taken(mysqli $conn, string $email, int $except_id = 0): bool {
    $s = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1");
    if (!$s) return false;
    $lower = strtolower($email);
    $s->bind_param('si', $lower, $except_id);
    $s->execute();
    $hit = (bool) $s->get_result()->fetch_assoc();
    $s->close();
    return $hit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
//
// Post/redirect/get throughout: every branch ends in a redirect carrying a
// message key, so a refresh never re-runs a role change.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $redirect = function (string $kind, string $msg, int $focus = 0) {
        $q = 'msg=' . urlencode($msg) . '&k=' . urlencode($kind);
        if ($focus > 0) $q .= '&u=' . $focus;
        header('Location: /users.php?' . $q . ($focus > 0 ? '#u-' . $focus : ''));
        exit();
    };

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $redirect('err', 'That form had expired. Nothing was changed — try again.');
    }

    $action = $_POST['_action'] ?? '';

    // ── Add ──────────────────────────────────────────────────────────────────
    if ($action === 'add_user') {
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name']  ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role  = (string)($_POST['role'] ?? 'agent');

        if ($first === '' || $last === '') {
            $redirect('err', 'First and last name are both required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $redirect('err', 'That is not a valid email address.');
        }
        if (!mk_valid_role($role, $ROLES)) {
            $redirect('err', 'Unknown role — nothing was created.');
        }
        if (mk_email_taken($conn, $email)) {
            $redirect('err', 'There is already an account for ' . $email . '.');
        }
        $iid = $has_portal ? mk_posted_account($accounts) : 0;
        if ($iid < 0) {
            $redirect('err', 'That marketing account is not available — nothing was created.');
        }

        // password stays NULL: this account signs in with Microsoft.
        $null = null;
        $s = $conn->prepare(
            "INSERT INTO users (first_name, last_name, email, password, role, is_active, must_change_password)
             VALUES (?, ?, ?, ?, ?, 1, 0)"
        );
        if (!$s) {
            $redirect('err', 'Could not create the account — the insert failed to prepare.');
        }
        $s->bind_param('sssss', $first, $last, $email, $null, $role);
        $created = $s->execute();
        $new_id  = $created ? (int)$conn->insert_id : 0;
        $s->close();

        if (!$created) {
            // A race on the unique email index lands here rather than in the
            // check above.
            $redirect('err', 'Could not create the account. It may already exist.');
        }
        if ($has_portal && $iid > 0) mk_save_account($conn, $new_id, $iid);
        $redirect('ok', $first . ' ' . $last . ' added as ' . ($ROLE_LBL[$role] ?? $role)
            . '. They sign in with Microsoft — no password to send.', $new_id);
    }

    // ── Edit name / email / role ─────────────────────────────────────────────
    if ($action === 'save_user') {
        $id    = (int)($_POST['id'] ?? 0);
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name']  ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role  = (string)($_POST['role'] ?? '');

        if ($id <= 0) {
            $redirect('err', 'No account was named — nothing changed.');
        }
        if ($first === '' || $last === '') {
            $redirect('err', 'First and last name are both required.', $id);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $redirect('err', 'That is not a valid email address.', $id);
        }
        if (!mk_valid_role($role, $ROLES)) {
            $redirect('err', 'Unknown role — nothing changed.', $id);
        }
        if (mk_email_taken($conn, $email, $id)) {
            $redirect('err', 'Another account already uses ' . $email . '.', $id);
        }
        $iid = $has_portal ? mk_posted_account($accounts) : 0;
        if ($iid < 0) {
            $redirect('err', 'That marketing account is not available — nothing changed.', $id);
        }

        // Read the current row: the two guards below both need to know what it
        // is now, not what the form says it should be.
        $cur = null;
        $s = $conn->prepare("SELECT id, role, is_active, email FROM users WHERE id = ? LIMIT 1");
        if ($s) { $s->bind_param('i', $id); $s->execute(); $cur = $s->get_result()->fetch_assoc(); $s->close(); }
        if (!$cur) {
            $redirect('err', 'That account no longer exists.');
        }

        // Guard 1 — you cannot change your own role.
        //
        // Not paternalism: the role is copied into the session at sign-in, so
        // demoting yourself leaves a session that still works until it ends and
        // an account that cannot get back in afterwards. Confusing at the exact
        // moment it matters. Another super_admin can do it.
        if ($id === $me_id && $role !== $cur['role']) {
            $redirect('err', 'You cannot change your own role. Ask another super admin to do it.', $id);
        }

        // Guard 2 — never demote the last active super_admin.
        if ($cur['role'] === 'super_admin' && $role !== 'super_admin'
            && mk_other_active_super_admins($conn, $id) === 0) {
            $redirect('err', 'That is the only active super admin. Promote somebody else first.', $id);
        }

        $s = $conn->prepare(
            "UPDATE users SET first_name = ?, last_name = ?, email = ?, role = ? WHERE id = ?"
        );
        if (!$s) {
            $redirect('err', 'Could not save — the update failed to prepare.', $id);
        }
        $s->bind_param('ssssi', $first, $last, $email, $role, $id);
        $s->execute();
        $s->close();
        if ($has_portal) mk_save_account($conn, $id, $iid);

        $note = '';
        if (strtolower((string)$cur['email']) !== $email && $has_entra) {
            // Worth saying out loud, because the obvious worry is the wrong one.
            $note = ' The Microsoft link follows the account, not the address, so'
                  . ' sign-in is unaffected.';
        }
        $redirect('ok', 'Saved ' . $first . ' ' . $last . '.' . $note, $id);
    }

    // ── Activate / deactivate ────────────────────────────────────────────────
    if ($action === 'set_active') {
        $id = (int)($_POST['id'] ?? 0);
        $on = !empty($_POST['on']);

        if ($id <= 0) {
            $redirect('err', 'No account was named — nothing changed.');
        }
        if ($id === $me_id && !$on) {
            $redirect('err', 'You cannot deactivate your own account.', $id);
        }

        $cur = null;
        $s = $conn->prepare("SELECT id, first_name, last_name, role FROM users WHERE id = ? LIMIT 1");
        if ($s) { $s->bind_param('i', $id); $s->execute(); $cur = $s->get_result()->fetch_assoc(); $s->close(); }
        if (!$cur) {
            $redirect('err', 'That account no longer exists.');
        }

        if (!$on && $cur['role'] === 'super_admin' && mk_other_active_super_admins($conn, $id) === 0) {
            $redirect('err', 'That is the only active super admin. Promote somebody else first.', $id);
        }

        $val = $on ? 1 : 0;
        $s = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        if (!$s) {
            $redirect('err', 'Could not save — the update failed to prepare.', $id);
        }
        $s->bind_param('ii', $val, $id);
        $s->execute();
        $s->close();

        $who = trim(($cur['first_name'] ?? '') . ' ' . ($cur['last_name'] ?? ''));
        $redirect('ok', $who . ($on ? ' reactivated.' : ' deactivated — they can no longer sign in.'), $id);
    }

    $redirect('err', 'Unknown action — nothing changed.');
}

// ── Load ─────────────────────────────────────────────────────────────────────
//
// entra_object_id is guarded: this page's whole content is the user list, and a
// failed query would render it as "no users" with nothing on screen saying why.
$sso_cols = ($has_entra ? ', entra_object_id, sso_linked_at' : '') . ($has_portal ? ', intake_id' : '');
$sql = "SELECT id, first_name, last_name, email, role, is_active, last_login, created_at,
               (password IS NOT NULL AND password <> '') AS has_password
               $sso_cols
          FROM users
      ORDER BY is_active DESC,
               FIELD(role, 'super_admin', 'admin', 'agent'),
               last_name, first_name";
$users = [];
if ($res = $conn->query($sql)) {
    while ($r = $res->fetch_assoc()) { $users[] = $r; }
}

// ── The allowlist is a SECOND gate, and it is not this page's ────────────────
//
// ACCESS_ALLOWLIST in inc/config.php is enforced in require_login(), so it
// covers every page and both sign-in paths and is entirely independent of
// users.role. Promoting somebody here does not let them in while that list is
// set — the two have to agree, and the failure mode is a person who signs in
// perfectly and gets a 403 they cannot explain.
//
// Deliberately shown, not managed. Making it editable from a web page would
// mean either a migration moving the gate into the database or this page
// rewriting config.php at runtime; the first is a real change to how access
// works and the second is a web page with write access to its own config.
// While the list is short and temporary, saying plainly who is on it and where
// to change it is worth more than a checkbox.
$allowlist        = defined('ACCESS_ALLOWLIST') ? trim((string) ACCESS_ALLOWLIST) : '';
$allowlist_active = ($allowlist !== '');
$allowed_emails   = $allowlist_active
    ? array_filter(array_map('trim', explode(',', strtolower($allowlist))))
    : [];

// Break-glass. Not enforced here — this page never touches passwords — but it
// is the check that stops mattering right up until the Entra secret expires.
$breakglass = 0;
$active_super_admins = 0;
foreach ($users as $u) {
    if ($u['role'] === 'super_admin' && (int)$u['is_active'] === 1) {
        $active_super_admins++;
        if (!empty($u['has_password'])) {
            $breakglass++;
        }
    }
}

// How many logins point at each portal account (a shared one gets a note).
$acct_use = [];
foreach ($users as $u) {
    $a = (int)($u['intake_id'] ?? 0);
    if ($a > 0) $acct_use[$a] = ($acct_use[$a] ?? 0) + 1;
}

$msg      = (string)($_GET['msg'] ?? '');
$msg_kind = ($_GET['k'] ?? '') === 'err' ? 'err' : 'ok';
$focus_id = (int)($_GET['u'] ?? 0);

$conn->close();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

function ago(?string $dt): string {
    if (!$dt || $dt === '0000-00-00 00:00:00') return '—';
    $t = strtotime($dt);
    if (!$t) return '—';
    return date('j M Y', $t);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Users — Mont Haus Marketing</title>
  <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml" />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
  <link rel="stylesheet" href="/assets/css/style-preset.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    :root { --bs-primary: #0184BB; }
    .pc-header    { padding:0; left:0; top:0; min-height:70px; }
    .pc-container { margin-left:0; top:0; margin-top:70px; min-height:calc(100vh - 70px); }
    .mh-header-inner {
      width:100%; padding:0 15px;
      display:flex; align-items:center; justify-content:space-between; height:70px;
    }
    .mh-logo img { height:50px; display:block; }
    .mh-nav { display:flex; align-items:center; gap:4px; }
    .mh-nav-link {
      display:inline-flex; align-items:center; gap:5px;
      padding:5px 12px; font-size:.8rem; font-weight:500;
      color:#75BDB6; text-decoration:none; border-radius:6px;
      white-space:nowrap; transition:color .2s, background .2s;
    }
    .mh-nav-link:hover  { color:#fff; background:rgba(117,189,182,.25); }
    .mh-nav-link.active { color:#fff; background:rgba(117,189,182,.45); }
    .mh-nav-divider { width:1px; height:20px; background:rgba(255,255,255,.2); margin:0 6px; }

    body { font-family:'Public Sans',sans-serif; background:#f8f9fa; }
    .wrap { max-width:1080px; margin:0 auto; padding:24px 18px 60px; }

    .us-head { display:flex; align-items:flex-end; justify-content:space-between;
               gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .us-head h1 { font-size:22px; font-weight:700; color:#1f2937; margin:0; }
    .us-head p  { margin:4px 0 0; font-size:13px; color:#6b7280; max-width:62ch; }

    .us-flash { border-radius:8px; padding:11px 14px; font-size:13.5px; margin-bottom:18px;
                display:flex; gap:9px; align-items:flex-start; line-height:1.5; }
    .us-flash i { font-size:16px; flex:none; margin-top:1px; }
    .us-flash.ok  { background:#e8f6ee; border:1px solid #b7e3c9; color:#1e6b3f; }
    .us-flash.err { background:#fdeaea; border:1px solid #f3c2c2; color:#9b2226; }

    .us-note { background:#fff; border:1px solid #e9ecef; border-left:3px solid #d0a24c;
               border-radius:8px; padding:13px 15px; font-size:13px; color:#4b5563;
               line-height:1.6; margin-bottom:16px; }
    .us-note.alarm { border-left-color:#c0392b; }
    .us-note strong { color:#1f2937; }
    .us-note code { background:#f2f2f2; padding:.1rem .35rem; border-radius:3px; font-size:.92em; }

    .us-card { background:#fff; border:1px solid #e9ecef; border-radius:10px;
               margin-bottom:22px; overflow:hidden; }
    .us-card-hd { padding:14px 18px; border-bottom:1px solid #f1f3f5;
                  display:flex; align-items:center; gap:9px; }
    .us-card-hd h2 { font-size:15px; font-weight:600; color:#1f2937; margin:0; }
    .us-card-bd { padding:16px 18px; }

    .us-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
               gap:12px; align-items:end; }
    .us-f label { display:block; font-size:11.5px; font-weight:600; color:#6b7280;
                  text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
    .us-f input, .us-f select {
      width:100%; padding:8px 10px; font-size:13.5px; font-family:inherit;
      border:1px solid #d7dbe0; border-radius:6px; background:#fff; color:#1f2937;
    }
    .us-f input:focus, .us-f select:focus { outline:2px solid #75BDB6; outline-offset:-1px; border-color:#75BDB6; }

    .btn-mh { display:inline-flex; align-items:center; gap:6px; cursor:pointer;
              padding:8px 15px; font-size:13px; font-weight:600; font-family:inherit;
              border-radius:6px; border:1px solid transparent; white-space:nowrap; }
    .btn-mh.primary { background:#1f2937; color:#fff; }
    .btn-mh.primary:hover { background:#111827; }
    .btn-mh.ghost { background:#fff; color:#374151; border-color:#d7dbe0; }
    .btn-mh.ghost:hover { background:#f8f9fa; }
    .btn-mh.danger { background:#fff; color:#9b2226; border-color:#f3c2c2; }
    .btn-mh.danger:hover { background:#fdeaea; }
    .btn-mh[disabled] { opacity:.45; cursor:not-allowed; }

    /* One row per user, expanding into its own edit form. min-width floors, never
       `width` — a `width` on a <th> is a suggestion the table overrules by
       squeezing whichever column has the narrowest content. See CLAUDE.md. */
    .us-table-scroll { overflow-x:auto; }
    table.us-list { width:100%; min-width:760px; border-collapse:collapse; }
    table.us-list th {
      text-align:left; font-size:11px; font-weight:700; color:#6b7280;
      text-transform:uppercase; letter-spacing:.5px;
      padding:9px 10px; border-bottom:1px solid #e9ecef; background:#fbfcfd;
    }
    table.us-list th.c-who    { min-width:230px; }
    table.us-list th.c-role   { min-width:120px; }
    table.us-list th.c-signin { min-width:130px; }
    table.us-list th.c-access { min-width:110px; }
    table.us-list th.c-act    { min-width:150px; }
    table.us-list td { padding:11px 10px; border-bottom:1px solid #f1f3f5;
                       font-size:13.5px; color:#374151; vertical-align:middle; }
    table.us-list tr.inactive td { background:#fcfcfc; }
    table.us-list tr.inactive .us-name { color:#9ca3af; }
    table.us-list tr.is-you td { background:#f4faf9; }

    .us-name { font-weight:600; color:#1f2937; display:block; }
    .us-email { font-size:12px; color:#6b7280; display:block; margin-top:1px; }

    .chip { display:inline-block; padding:2px 8px; border-radius:999px;
            font-size:11px; font-weight:600; letter-spacing:.2px; white-space:nowrap; }
    .chip.role-super_admin { background:#efe6fa; color:#5b21b6; }
    .chip.role-admin       { background:#e4f0fb; color:#1a5276; }
    .chip.role-agent       { background:#f1f3f5; color:#4b5563; }
    .chip.you              { background:#e8f6ee; color:#1e6b3f; margin-left:6px; }
    .chip.off              { background:#f1f3f5; color:#6b7280; }
    .chip.linked           { background:#e8f6ee; color:#1e6b3f; }
    .chip.pending          { background:#fdf3e2; color:#8a5a12; }
    .chip.noaccess         { background:#fdeaea; color:#9b2226; }
    .chip.key              { background:#fdf3e2; color:#8a5a12; }

    .us-actions { display:flex; gap:6px; flex-wrap:wrap; }
    .us-mini { padding:5px 10px; font-size:12px; }

    .us-edit { background:#f8fafb; }
    .us-edit > td { padding:16px 14px; }
    .us-edit-inner { display:flex; flex-direction:column; gap:12px; }
    .us-edit-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .us-edit-hint { font-size:12px; color:#6b7280; }

    .us-meta { font-size:12px; color:#9ca3af; }
  </style>
</head>
<body class="layout-extended">
<?php $nav_active = 'users'; include __DIR__ . '/inc/_nav.php'; ?>

<div class="pc-container">
<div class="wrap">

  <div class="us-head">
    <div>
      <h1>Users</h1>
      <p>Who has an account, and what each one can do. New accounts sign in with
         Microsoft — there is no password to send.</p>
    </div>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="us-flash <?= $msg_kind ?>">
      <i class="ti <?= $msg_kind === 'ok' ? 'ti-check' : 'ti-alert-triangle' ?>"></i>
      <span><?= h($msg) ?></span>
    </div>
  <?php endif; ?>

  <?php if (!$has_sa_enum): ?>
    <div class="us-note alarm">
      <strong>The database cannot store the super admin role yet.</strong>
      <code>users.role</code> is still <code>enum('agent','admin')</code>, so that
      option has been removed from the forms below — assigning it would silently
      store an empty role and lock the account out of every page.
      Run <code>sql/alter_users_role_v1.sql</code> to fix it.
    </div>
  <?php endif; ?>

  <?php if ($breakglass === 0): ?>
    <div class="us-note alarm">
      <strong>No super admin has a break-glass password.</strong>
      <code>SSO_PASSWORD_LOGIN_ROLES</code> allows a super admin to sign in with a
      password, and that is the only way back in when the Entra client secret
      expires — which it does, silently, on its expiry date. Set one for at least
      one super admin. This page never touches passwords, so it can warn but not
      fix it.
    </div>
  <?php endif; ?>

  <?php if ($allowlist_active): ?>
    <div class="us-note">
      <strong>The site is still on a limited rollout.</strong>
      <code>ACCESS_ALLOWLIST</code> in <code>inc/config.php</code> is a second gate,
      independent of role: anyone not on it signs in successfully and then gets a
      403. Adding someone here does <em>not</em> give them access until their
      address is on that line. Currently allowed —
      <code><?= h(implode(', ', $allowed_emails)) ?></code>.
      Clear the constant to open the site to everyone.
    </div>
  <?php endif; ?>

  <!-- ── Add ──────────────────────────────────────────────────────────────── -->
  <div class="us-card">
    <div class="us-card-hd">
      <i class="ti ti-user-plus" style="color:#75BDB6"></i>
      <h2>Add a user</h2>
    </div>
    <div class="us-card-bd">
      <form method="post" action="/users.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="_action" value="add_user">
        <div class="us-grid">
          <div class="us-f">
            <label for="nf">First name</label>
            <input id="nf" type="text" name="first_name" required maxlength="100">
          </div>
          <div class="us-f">
            <label for="nl">Last name</label>
            <input id="nl" type="text" name="last_name" required maxlength="100">
          </div>
          <div class="us-f">
            <label for="ne">Email</label>
            <input id="ne" type="email" name="email" required maxlength="255"
                   placeholder="first.last@monthaus.com">
          </div>
          <div class="us-f">
            <label for="nr">Role</label>
            <select id="nr" name="role">
              <?php foreach ($ROLES as $r): ?>
                <option value="<?= h($r) ?>"<?= $r === 'agent' ? ' selected' : '' ?>><?= h($ROLE_LBL[$r]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($has_portal): ?>
          <div class="us-f">
            <label for="na">Portal account</label>
            <select id="na" name="intake_id">
              <option value="0">None</option>
              <?php foreach ($accounts as $aid => $alabel): ?>
                <option value="<?= $aid ?>"><?= h($alabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="us-f">
            <button type="submit" class="btn-mh primary"><i class="ti ti-plus"></i> Add user</button>
          </div>
        </div>
        <p class="us-edit-hint" style="margin:12px 0 0">
          The email must be the address on their Microsoft account. It is matched
          once, at their first sign-in, and the account is linked by Microsoft's
          own object id from then on.
        </p>
      </form>
    </div>
  </div>

  <!-- ── List ─────────────────────────────────────────────────────────────── -->
  <div class="us-card">
    <div class="us-card-hd">
      <i class="ti ti-users" style="color:#75BDB6"></i>
      <h2><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?></h2>
    </div>

    <div class="us-table-scroll">
    <table class="us-list">
      <thead>
        <tr>
          <th class="c-who">User</th>
          <th class="c-role">Role</th>
          <th class="c-signin">Sign-in</th>
          <?php if ($allowlist_active): ?><th class="c-access">Access</th><?php endif; ?>
          <th class="c-act"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u):
        $uid      = (int)$u['id'];
        $is_you   = $uid === $me_id;
        $active   = (int)$u['is_active'] === 1;
        $role     = (string)$u['role'];
        $linked   = $has_entra && !empty($u['entra_object_id']);
        $on_list  = in_array(strtolower((string)$u['email']), $allowed_emails, true);
        // Open the edit form only when the save FAILED, so the mistake can be fixed in place.
        // After a successful save the row stays closed (the page still scrolls to it and the
        // green flash says what changed): an edit box left open read as "did that save?" (Nikki, 2026-10-08).
        $expanded = $focus_id === $uid && $msg_kind === 'err';
        $is_last_sa = $role === 'super_admin' && $active;
      ?>
        <tr id="u-<?= $uid ?>" class="<?= $active ? '' : 'inactive' ?> <?= $is_you ? 'is-you' : '' ?>">
          <td>
            <span class="us-name"><?= h(trim($u['first_name'] . ' ' . $u['last_name'])) ?><?php
              if ($is_you): ?><span class="chip you">you</span><?php endif; ?></span>
            <span class="us-email"><?= h($u['email']) ?></span>
          </td>
          <td>
            <span class="chip role-<?= h($role ?: 'agent') ?>"><?= h($ROLE_LBL[$role] ?? ($role === '' ? 'none' : $role)) ?></span>
            <?php if (!$active): ?><br><span class="chip off" style="margin-top:4px">Inactive</span><?php endif; ?>
            <?php if ($has_portal):
              $ua = (int)($u['intake_id'] ?? 0); ?>
              <?php if ($ua > 0): ?>
                <div class="us-meta" style="margin-top:4px" title="What they see in the agent portal">
                  Portal: <?= h($accounts[$ua] ?? ('account #' . $ua . ', not active')) ?><?= ($acct_use[$ua] ?? 0) > 1 ? ' · shared' : '' ?>
                </div>
              <?php elseif ($role === 'agent'): ?>
                <br><span class="chip pending" style="margin-top:4px" title="Signs in, but sees an 'almost there' page until linked">No portal account</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($linked): ?>
              <span class="chip linked">Microsoft</span>
            <?php else: ?>
              <span class="chip pending">Not linked</span>
            <?php endif; ?>
            <?php if (!empty($u['has_password'])): ?>
              <br><span class="chip key" style="margin-top:4px" title="Can also sign in with a password">Password</span>
            <?php endif; ?>
            <div class="us-meta" style="margin-top:4px">Last in <?= h(ago($u['last_login'] ?? null)) ?></div>
          </td>
          <?php if ($allowlist_active): ?>
            <td>
              <?php if ($on_list): ?>
                <span class="chip linked">Allowed</span>
              <?php else: ?>
                <span class="chip noaccess" title="Not on ACCESS_ALLOWLIST — will get a 403">No access yet</span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <td>
            <div class="us-actions">
              <button type="button" class="btn-mh ghost us-mini" data-toggle="e-<?= $uid ?>">
                <i class="ti ti-pencil"></i> Edit
              </button>
              <form method="post" action="/users.php" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="_action" value="set_active">
                <input type="hidden" name="id" value="<?= $uid ?>">
                <input type="hidden" name="on" value="<?= $active ? '0' : '1' ?>">
                <button type="submit"
                        class="btn-mh us-mini <?= $active ? 'danger' : 'ghost' ?>"
                        <?= ($is_you && $active) ? 'disabled title="You cannot deactivate your own account"' : '' ?>>
                  <i class="ti <?= $active ? 'ti-user-off' : 'ti-user-check' ?>"></i>
                  <?= $active ? 'Deactivate' : 'Reactivate' ?>
                </button>
              </form>
            </div>
          </td>
        </tr>

        <tr class="us-edit" id="e-<?= $uid ?>" <?= $expanded ? '' : 'hidden' ?>>
          <td colspan="<?= $allowlist_active ? 5 : 4 ?>">
            <form method="post" action="/users.php" class="us-edit-inner" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="_action" value="save_user">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <div class="us-grid">
                <div class="us-f">
                  <label for="f<?= $uid ?>">First name</label>
                  <input id="f<?= $uid ?>" type="text" name="first_name" maxlength="100"
                         value="<?= h($u['first_name']) ?>" required>
                </div>
                <div class="us-f">
                  <label for="l<?= $uid ?>">Last name</label>
                  <input id="l<?= $uid ?>" type="text" name="last_name" maxlength="100"
                         value="<?= h($u['last_name']) ?>" required>
                </div>
                <div class="us-f">
                  <label for="m<?= $uid ?>">Email</label>
                  <input id="m<?= $uid ?>" type="email" name="email" maxlength="255"
                         value="<?= h($u['email']) ?>" required>
                </div>
                <div class="us-f">
                  <label for="r<?= $uid ?>">Role</label>
                  <select id="r<?= $uid ?>" name="role" <?= $is_you ? 'disabled' : '' ?>>
                    <?php foreach ($ROLES as $r): ?>
                      <option value="<?= h($r) ?>"<?= $r === $role ? ' selected' : '' ?>><?= h($ROLE_LBL[$r]) ?></option>
                    <?php endforeach; ?>
                    <?php if ($role !== '' && !in_array($role, $ROLES, true)): ?>
                      <option value="<?= h($role) ?>" selected><?= h($role) ?></option>
                    <?php endif; ?>
                  </select>
                  <?php if ($is_you): ?>
                    <?php // Disabled inputs post nothing, so re-send the current
                          // value — the server compares it and rejects a change
                          // regardless, but a missing field would read as an
                          // invalid role and error instead of no-op. ?>
                    <input type="hidden" name="role" value="<?= h($role) ?>">
                  <?php endif; ?>
                </div>
                <?php if ($has_portal):
                  $ua = (int)($u['intake_id'] ?? 0); ?>
                <div class="us-f">
                  <label for="a<?= $uid ?>">Portal account</label>
                  <select id="a<?= $uid ?>" name="intake_id">
                    <option value="0">None</option>
                    <?php foreach ($accounts as $aid => $alabel): ?>
                      <option value="<?= $aid ?>"<?= $aid === $ua ? ' selected' : '' ?>><?= h($alabel) ?><?= ($acct_use[$aid] ?? 0) > 0 && $aid !== $ua ? ' · already linked' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>
              </div>
              <div class="us-edit-actions">
                <button type="submit" class="btn-mh primary us-mini"><i class="ti ti-device-floppy"></i> Save</button>
                <button type="button" class="btn-mh ghost us-mini" data-toggle="e-<?= $uid ?>">Cancel</button>
                <span class="us-edit-hint">
                  <?php if ($is_you): ?>
                    You cannot change your own role — the role is copied into your session
                    at sign-in, so it would not take effect until you signed out and could
                    not be undone by you afterwards. Ask another super admin.
                  <?php elseif ($is_last_sa && $active_super_admins === 1): ?>
                    The only active super admin. Promote somebody else before changing this.
                  <?php else: ?>
                    A role change takes effect at their next sign-in<?= $has_portal ? '; a portal account change, straight away. Several people can share one account (Weber Boxer Group).' : '.' ?>
                  <?php endif; ?>
                </span>
              </div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

</div><!-- /wrap -->
</div><!-- /pc-container -->

<script>
/* Expand a row into its edit form. Dependency-free, like the nav's hamburger:
   this page loads no library and should not start needing one. Rows use the
   `hidden` attribute rather than a class so an expanded row still reads as
   expanded with CSS off. */
document.querySelectorAll('[data-toggle]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var row = document.getElementById(btn.getAttribute('data-toggle'));
    if (!row) return;
    row.hidden = !row.hidden;
    if (!row.hidden) {
      var first = row.querySelector('input:not([type=hidden])');
      if (first) first.focus();
    }
  });
});
</script>
</body>
</html>
