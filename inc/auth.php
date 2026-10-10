<?php
/**
 * auth.php
 * Reusable authentication guard.
 * Include at the top of any page that requires a logged-in user:
 *   require_once 'auth.php';
 *
 * For admin-only pages, pass 'admin' as the required role:
 *   require_once 'auth.php';
 *   require_role('admin');
 */

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
// Lax, not Strict. Microsoft returns the browser here by a cross-site
// top-level GET after sign-in; Strict withholds the session cookie on
// that navigation, so the OAuth state check would never find its state.
// Lax still blocks cross-site POSTs and subresource requests.
ini_set('session.cookie_samesite', 'Lax');

require_once __DIR__ . '/config.php';   // SITE_URL, HUB_URL, RECEIPTS_DIR

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Redirects to login if user is not authenticated.
 * If a forced password change is pending, redirects to change_password.php.
 * Call this at the top of every protected page.
 */
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit();
    }
    // Enforce password change before allowing access to any other page
    if (!empty($_SESSION['must_change_password'])) {
        $current_page = basename($_SERVER['PHP_SELF'] ?? '');
        if ($current_page !== 'change_password.php') {
            header('Location: /change_password.php');
            exit();
        }
    }

    require_allowlisted();
    mh_session_intake_id();   // while $conn is still open (see the function)
}

/**
 * The marketing account this login is linked to (users.intake_id), kept in
 * the session and refreshed at most every 5 minutes while a database
 * connection is open. 0 when none. The admin header's "My Portal" link reads
 * this: index.php and agent.php close $conn before the header renders, so the
 * nav itself cannot query (found 2026-10-10, when Jonathan's link never
 * showed). Guarded: a missing column, a closed connection or a test stub just
 * leaves the cached value alone.
 */
function mh_session_intake_id(): int {
    if (empty($_SESSION['user_id'])) return 0;
    if (time() - (int)($_SESSION['user_intake_checked'] ?? 0) > 300) {
        $conn = $GLOBALS['conn'] ?? null;
        if ($conn instanceof mysqli) {
            try {
                $st = $conn->prepare("SELECT intake_id FROM users WHERE id = ? LIMIT 1");
                if ($st) {
                    $uid = (int)$_SESSION['user_id'];
                    $st->bind_param('i', $uid); $st->execute();
                    $row = $st->get_result()->fetch_assoc(); $st->close();
                    $_SESSION['user_intake_id']      = (int)($row['intake_id'] ?? 0);
                    $_SESSION['user_intake_checked'] = time();
                }
            } catch (Throwable $e) { /* leave the cached value */ }
        }
    }
    return (int)($_SESSION['user_intake_id'] ?? 0);
}

/**
 * Limited-rollout gate. See ACCESS_ALLOWLIST in config.php.
 *
 * Called from require_login(), so it applies to every protected page and to
 * both sign-in paths. Returns silently when the list is undefined or empty,
 * which is how the site gets opened up to everyone later.
 *
 * 403, never a redirect — same reason as require_role(): a redirect from a
 * guard can point at a page that runs the same guard.
 */
function require_allowlisted(): void {
    if (!defined('ACCESS_ALLOWLIST')) {
        return;
    }
    $raw = trim((string) ACCESS_ALLOWLIST);
    if ($raw === '') {
        return;                      // empty list = no restriction
    }

    $allowed = array_filter(array_map('trim', explode(',', strtolower($raw))));
    $email   = strtolower(trim((string) ($_SESSION['user_email'] ?? '')));

    if ($email !== '' && in_array($email, $allowed, true)) {
        return;
    }

    http_response_code(403);
    $shown = htmlspecialchars($email !== '' ? $email : '(unknown)', ENT_QUOTES);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Not yet available</title>'
       . '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:34rem;'
       . 'margin:18vh auto;padding:0 1.5rem;color:#111;line-height:1.6}'
       . 'h1{font-size:1.4rem;margin:0 0 .6rem}p{color:#444}'
       . 'code{background:#f2f2f2;padding:.1rem .35rem;border-radius:3px;font-size:.9em}'
       . 'a{color:#1a5276}</style></head><body>'
       . '<h1>This tool is not open yet</h1>'
       . '<p>Mont Haus Marketing is still being built and is limited to a few '
       . 'accounts for now. You signed in successfully as <code>' . $shown . '</code>, '
       . 'but it is not one of them.</p>'
       . '<p>Nikki will let you know when it opens up. '
       . '<a href="/logout.php">Sign out</a>.</p>'
       . '</body></html>';
    exit();
}

/**
 * Role hierarchy (higher index = more privileged):
 *   agent (0) < admin (1) < super_admin (2)
 *
 * Restricts a page to a minimum role level.
 * 'super_admin' → only super_admin
 * 'admin'       → admin OR super_admin
 * 'agent'       → any authenticated user
 *
 * Always call require_login() first.
 */
function require_role(string $min_role): void {
    require_login();
    $hierarchy  = ['agent' => 0, 'admin' => 1, 'super_admin' => 2];
    $user_level = $hierarchy[$_SESSION['user_role'] ?? ''] ?? -1;
    $req_level  = $hierarchy[$min_role] ?? 99;
    if ($user_level < $req_level) {
        // 403 — never a redirect.
        //
        // This used to send the browser to /index.php. On the hub that was
        // harmless: /index.php was the hub's landing page. Here the marketing
        // app IS the docroot, and index.php itself calls
        // require_role('admin') — so the redirect pointed at a page that
        // immediately re-ran the same failing check. The result is an
        // infinite redirect, and because nothing errors it appears in no log:
        // just ERR_TOO_MANY_REDIRECTS in the browser and a wall of 302s in
        // the access log.
        //
        // A status code cannot loop. Any future guard must fail the same way.
        http_response_code(403);
        $email = htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES);
        $role  = htmlspecialchars($_SESSION['user_role']  ?? '(none)', ENT_QUOTES);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
           . '<title>Not authorised</title>'
           . '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:34rem;'
           . 'margin:18vh auto;padding:0 1.5rem;color:#111;line-height:1.6}'
           . 'h1{font-size:1.4rem;margin:0 0 .6rem}p{color:#444}'
           . 'code{background:#f2f2f2;padding:.1rem .35rem;border-radius:3px;font-size:.9em}'
           . 'a{color:#1a5276}</style></head><body>'
           . '<h1>You do not have access to this page</h1>'
           . '<p>Signed in as <code>' . $email . '</code> with the role '
           . '<code>' . $role . '</code>. This page needs <code>' . htmlspecialchars($min_role, ENT_QUOTES) . '</code> '
           . 'or higher.</p>'
           . '<p>If that looks wrong, ask an administrator to check your role, '
           . 'or <a href="/logout.php">sign out</a> and back in.</p>'
           . '</body></html>';
        exit();
    }
}

/**
 * Returns true if the current user is 'admin' OR 'super_admin'.
 * Use this for B2B features that both admin tiers can access
 * (e.g. seeing all agents' listings, sending as any broker).
 */
function is_elevated_admin(): bool {
    return in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'], true);
}

/**
 * Returns true only for 'super_admin'.
 * Use this to gate user-management actions (register, delete, role change, password reset).
 */
function is_super_admin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'super_admin';
}

/**
 * Returns the currently logged-in user's session data as an array.
 *
 * @return array
 */
function current_user(): array {
    return [
        'id'         => $_SESSION['user_id']         ?? null,
        'email'      => $_SESSION['user_email']      ?? '',
        'first_name' => $_SESSION['user_first_name'] ?? '',
        'last_name'  => $_SESSION['user_last_name']  ?? '',
        'role'       => $_SESSION['user_role']        ?? '',
    ];
}
