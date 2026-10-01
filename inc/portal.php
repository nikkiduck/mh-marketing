<?php
/**
 * portal.php — shared code for the agent portal (portal/*.php).
 * Plan: docs/AGENT_PORTAL_PLAN.md. Built 2026-10-01.
 *
 * THE RULE: a portal page never takes an agent or account id from the request.
 * portal_context() works out whose portal this is from the signed-in user
 * (users.intake_id, read from the database on every request) and every query on
 * every portal page is scoped by the ids it returns. When a page acts on one
 * item it was handed an id for (a QR code, say), it checks that item belongs to
 * the context's ids before touching it.
 *
 * Admins may preview any account with ?preview=<intake id>; the page then
 * carries a banner, and portal_url() keeps the parameter on every link and
 * form. A non-admin's ?preview= is ignored.
 *
 * Every refusal is a 403 page that says which gate stopped the person, never a
 * redirect (see require_role() in auth.php for why a guard must not redirect).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

/** A plain, branded 403 that names the reason. */
function portal_stop(string $title, string $why): never {
    http_response_code(403);
    $email = htmlspecialchars((string)($_SESSION['user_email'] ?? ''), ENT_QUOTES);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . '</title>'
       . '<style>body{font-family:Jost,system-ui,-apple-system,sans-serif;max-width:34rem;'
       . 'margin:16vh auto;padding:0 1.5rem;color:#1c1a17;line-height:1.6;background:#faf8f4}'
       . 'h1{font-family:"Cormorant Garamond",Georgia,serif;font-weight:500;font-size:2rem;margin:0 0 .6rem}'
       . 'p{color:#4a4539}code{background:#efe9dd;padding:.1rem .35rem;border-radius:3px;font-size:.9em}'
       . 'a{color:#8a6d36}</style></head><body>'
       . '<h1>' . htmlspecialchars($title) . '</h1>'
       . '<p>' . $why . '</p>'
       . ($email !== '' ? '<p style="font-size:.9em;color:#8a8370">Signed in as <code>' . $email . '</code>. '
       . '<a href="/logout.php">Sign out</a></p>' : '')
       . '</body></html>';
    exit;
}

/**
 * Whose portal this is. Returns:
 *   user     the signed-in users row
 *   acct     the marketing_intakes row being shown (an agent, or a team)
 *   ids      account id plus, for a team, its members' ids: what QR codes are
 *            scoped by (a code belongs to a sign, not to billing)
 *   preview  true when an admin is looking at someone else's portal
 */
function portal_context(mysqli $conn): array {
    require_login();   // includes the ACCESS_ALLOWLIST gate

    if (!mk_column_exists($conn, 'users', 'intake_id')) {
        portal_stop('Not ready yet', 'The agent portal is not switched on yet '
            . '(<code>sql/portal_identity_v1.sql</code> has not been run).');
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st = $conn->prepare("SELECT id, first_name, last_name, email, role, is_active, intake_id FROM users WHERE id = ? LIMIT 1");
    $st->bind_param('i', $uid);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$user || !(int)$user['is_active']) {
        portal_stop('No access', 'This account is not active. Please contact Nikki.');
    }

    $preview = false;
    $iid = (int)($user['intake_id'] ?? 0);
    if (is_elevated_admin() && isset($_GET['preview'])) {
        $iid = (int)$_GET['preview'];
        $preview = true;
    }
    if ($iid <= 0) {
        if (is_elevated_admin()) {
            portal_stop('Choose an agent to preview', 'Admins see the portal through an agent\'s eyes. '
                . 'Open it from an agent with <code>/portal/?preview=&lt;id&gt;</code>, '
                . 'or link your own login to an account on the <a href="/users.php">Users</a> page.');
        }
        portal_stop('Almost there', 'Your sign-in works, but it is not connected to your marketing '
            . 'account yet. Nikki will set that up; there is nothing you need to do.');
    }

    $cols = 'id, agent_name, slug, is_active, status, headshot_url, headshot_face_url'
          . (mk_column_exists($conn, 'marketing_intakes', 'entity_type') ? ', entity_type' : '');
    $st = $conn->prepare("SELECT {$cols} FROM marketing_intakes WHERE id = ? LIMIT 1");
    $st->bind_param('i', $iid);
    $st->execute();
    $acct = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$acct || !(int)$acct['is_active'] || ($acct['status'] ?? '') === 'archived') {
        portal_stop('No access', 'This marketing account is no longer active. Please contact Nikki.');
    }
    if (($acct['entity_type'] ?? 'agent') === 'staff') {
        portal_stop('No access', 'The agent portal is for agents.');
    }

    $ids = [(int)$acct['id']];
    if (($acct['entity_type'] ?? 'agent') === 'team' && mk_table_exists($conn, 'team_members')) {
        $st = $conn->prepare("SELECT member_id FROM team_members WHERE team_id = ?");
        $tid = (int)$acct['id'];
        $st->bind_param('i', $tid);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $m) $ids[] = (int)$m['member_id'];
        $st->close();
    }

    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return ['user' => $user, 'acct' => $acct, 'ids' => array_values(array_unique($ids)), 'preview' => $preview];
}

/** Link inside the portal, keeping an admin's ?preview= on it. */
function portal_url(array $ctx, string $path, array $q = []): string {
    if ($ctx['preview']) $q = ['preview' => (int)$ctx['acct']['id']] + $q;
    return $path . ($q ? '?' . http_build_query($q) : '');
}

function portal_csrf_ok(): bool {
    return hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''));
}

function ph($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** First name for the greeting; a team shows its own name. */
function portal_greeting_name(array $ctx): string {
    if (($ctx['acct']['entity_type'] ?? 'agent') === 'team') return (string)$ctx['acct']['agent_name'];
    $fn = trim((string)($ctx['user']['first_name'] ?? ''));
    if ($ctx['preview'] || $fn === '') $fn = strtok(trim((string)$ctx['acct']['agent_name']), ' ') ?: '';
    return $fn;
}

/**
 * Page top: dark header with the logo, the nav, and the preview banner.
 * $active is the nav key of the current page.
 */
function portal_header(array $ctx, string $title, string $active): void {
    $nav = [
        'home' => ['Home', '/portal/'],
        'qr'   => ['QR codes', '/portal/qr.php'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ph($title) ?> · Mont Haus</title>
<link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/portal.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/portal.css') ?>">
</head>
<body>
<?php if ($ctx['preview']): ?>
  <div class="pt-preview">Preview: you are seeing <strong><?= ph($ctx['acct']['agent_name']) ?></strong>'s portal as they see it.
    <a href="/agent.php?id=<?= (int)$ctx['acct']['id'] ?>">Back to their page</a></div>
<?php endif; ?>
<header class="pt-top">
  <div class="pt-top-in">
    <a class="pt-logo" href="<?= ph(portal_url($ctx, '/portal/')) ?>"><img src="/assets/images/logo-white.svg" alt="Mont Haus"></a>
    <a class="pt-signout" href="/logout.php">Sign out</a>
  </div>
  <nav class="pt-nav">
    <?php foreach ($nav as $k => [$label, $path]): ?>
      <a href="<?= ph(portal_url($ctx, $path)) ?>" class="<?= $k === $active ? 'on' : '' ?>"><?= ph($label) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main class="pt-main">
    <?php
}

function portal_footer(): void {
    ?>
</main>
<footer class="pt-foot">
  <div class="pt-foot-q">Questions?</div>
  <div>Email <a href="mailto:nikki.boxer@monthaus.com">nikki.boxer@monthaus.com</a></div>
  <div>Text <a href="sms:+19709484300">970.948.4300</a></div>
</footer>
</body>
</html>
    <?php
}
