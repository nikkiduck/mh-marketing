<?php
/**
 * login.php — Mont Haus Marketing sign-in.
 *
 * Microsoft Entra ID is the primary path. The email/password form is kept as
 * break-glass access for the roles named in SSO_PASSWORD_LOGIN_ROLES, so that
 * an expired client secret or an Entra outage cannot lock everyone out.
 *
 * Session cookie settings and session_start() both live in auth.php.
 */

require_once __DIR__ . '/inc/sso.php';   // pulls in auth.php, so the session is live

// Already signed in.
//
// !empty(), NOT isset(). require_login() in inc/auth.php tests empty(), and
// if the two disagree the browser ends up in an infinite redirect: a falsy
// user_id (0, '', null) makes login.php say "signed in, go to index" while
// index.php says "not signed in, go to login", forever. Every check on
// user_id in this app must use empty()/!empty() for that reason.
if (!empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit();
}

require_once __DIR__ . '/inc/db.php';

$sso_on = sso_enabled();

// Optionally bounce straight to Microsoft. prompt=none first, so a user who
// already has a Microsoft session sees a flicker rather than a login screen;
// oauth_start.php guarantees only one silent attempt per session.
if (
    $sso_on
    && defined('SSO_AUTO_REDIRECT') && SSO_AUTO_REDIRECT
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && !isset($_GET['error'])
    && !isset($_GET['staff'])
    && empty($_SESSION['sso_silent_tried'])
) {
    header('Location: /oauth_start.php?silent=1');
    exit();
}

// Error handed over by oauth_callback.php.
$error = '';
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'sso_unconfigured') {
        $error = 'Microsoft sign-in is not configured on this server yet.';
    } else {
        $error = (string) ($_SESSION['sso_error'] ?? 'Sign-in could not be completed.');
    }
    unset($_SESSION['sso_error']);
}

/** Roles still permitted to sign in with a password. Empty = none. */
function password_login_roles(): array
{
    if (!defined('SSO_PASSWORD_LOGIN_ROLES')) {
        return ['agent', 'admin', 'super_admin'];   // SSO not configured yet
    }
    $raw = array_map('trim', explode(',', (string) SSO_PASSWORD_LOGIN_ROLES));
    return array_values(array_filter($raw, static fn($r) => $r !== ''));
}

// Show the password form expanded when it is the only way in, or when the
// user explicitly asked for it.
$allowed_pw_roles = $sso_on ? password_login_roles() : ['agent', 'admin', 'super_admin'];
$password_enabled = !empty($allowed_pw_roles);
$show_password    = !$sso_on || isset($_GET['staff']) || $_SERVER['REQUEST_METHOD'] === 'POST';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$password_enabled) {
        $error = 'Password sign-in is disabled. Please use the Microsoft button.';
    } elseif (!isset($_POST['csrf_token']) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } else {

            // --- Rate limiting: track failed attempts in session ---
            if (!isset($_SESSION['login_attempts'])) {
                $_SESSION['login_attempts']     = 0;
                $_SESSION['login_last_attempt'] = time();
            }

            $lockout_duration = 15 * 60;
            if ($_SESSION['login_attempts'] >= 5) {
                $time_since = time() - $_SESSION['login_last_attempt'];
                if ($time_since < $lockout_duration) {
                    $minutes_left = ceil(($lockout_duration - $time_since) / 60);
                    $error = "Too many failed attempts. Please try again in {$minutes_left} minute(s).";
                } else {
                    $_SESSION['login_attempts'] = 0;
                }
            }

            if ($error === '') {
                $stmt = $conn->prepare(
                    "SELECT id, first_name, last_name, email, password, role, is_active, mls_id, agent_key
                     FROM users
                     WHERE email = ?
                     LIMIT 1"
                );
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // An SSO-only account has a NULL password. password_verify()
                // would throw a deprecation on null, so guard the column first.
                $hash  = (string) ($user['password'] ?? '');
                $valid = $user && $hash !== '' && password_verify($password, $hash);

                if ($valid) {

                    if (!$user['is_active']) {
                        $error = 'Your account is inactive. Please contact your administrator.';

                    } elseif ($sso_on && !in_array($user['role'], $allowed_pw_roles, true)) {
                        // Correct password, but this role signs in with Microsoft.
                        $error = 'Please sign in with your Microsoft account using the button above.';

                    } else {
                        session_regenerate_id(true);

                        $_SESSION['login_attempts']  = 0;
                        $_SESSION['user_id']         = $user['id'];
                        $_SESSION['user_email']      = $user['email'];
                        $_SESSION['user_first_name'] = $user['first_name'];
                        $_SESSION['user_last_name']  = $user['last_name'];
                        $_SESSION['user_role']       = $user['role'];
                        $_SESSION['agent_mls_id']    = $user['mls_id']    ?? '';
                        $_SESSION['agent_key']       = $user['agent_key'] ?? '';
                        $_SESSION['auth_method']     = 'password';

                        $upd = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $upd->bind_param('i', $user['id']);
                        $upd->execute();
                        $upd->close();

                        $chk = $conn->prepare("SELECT must_change_password FROM users WHERE id = ? LIMIT 1");
                        $chk->bind_param('i', $user['id']);
                        $chk->execute();
                        $chk_row = $chk->get_result()->fetch_assoc();
                        $chk->close();

                        if (!empty($chk_row['must_change_password'])) {
                            $_SESSION['must_change_password'] = true;
                            header('Location: /change_password.php');
                            exit();
                        }

                        header('Location: /index.php');
                        exit();
                    }

                } else {
                    $_SESSION['login_attempts']++;
                    $_SESSION['login_last_attempt'] = time();
                    $error = 'Invalid email or password.';   // vague on purpose
                }
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mont Haus Marketing — Sign in</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/x-icon" />
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/fonts/phosphor/duotone/style.css">
    <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
    <link rel="stylesheet" href="/assets/fonts/feather.css">
    <link rel="stylesheet" href="/assets/fonts/fontawesome.css">
    <link rel="stylesheet" href="/assets/fonts/material.css">
    <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
    <link rel="stylesheet" href="/assets/css/style-preset.css">
    <style>
      /* Microsoft's brand guidance: white button, 1px #8C8C8C border,
         Segoe UI, and the four-square mark at 20px. */
      .ms-signin-btn {
        display:flex; align-items:center; justify-content:center; gap:12px;
        width:100%; height:48px;
        background:#fff; border:1px solid #8C8C8C; border-radius:2px;
        color:#5E5E5E; font-family:"Segoe UI",system-ui,-apple-system,sans-serif;
        font-size:15px; font-weight:600; text-decoration:none;
        transition:background .15s ease, border-color .15s ease;
      }
      .ms-signin-btn:hover  { background:#f3f3f3; color:#5E5E5E; border-color:#5E5E5E; }
      .ms-signin-btn:focus  { outline:2px solid #2F2F2F; outline-offset:2px; }
      .ms-signin-btn svg    { width:20px; height:20px; flex:none; }

      .login-divider {
        display:flex; align-items:center; gap:12px;
        margin:22px 0 18px; color:#9ca3af; font-size:.72rem;
        text-transform:uppercase; letter-spacing:.6px;
      }
      .login-divider::before,
      .login-divider::after { content:""; flex:1; height:1px; background:#e5e7eb; }

      .staff-toggle {
        background:none; border:none; padding:0;
        color:#6b7280; font-size:.78rem; text-decoration:underline; cursor:pointer;
      }
      .staff-toggle:hover { color:#374151; }
    </style>
</head>
<body data-pc-preset="preset-1" data-pc-sidebar-theme="light" data-pc-sidebar-caption="true" data-pc-direction="ltr" data-pc-theme="light">
<div class="loader-bg">
    <div class="loader-track"><div class="loader-fill"></div></div>
</div>
<div class="auth-main v2">
    <div class="bg-overlay bg-dark"></div>
    <div class="auth-wrapper">
        <div class="auth-sidecontent">
            <div class="auth-sidefooter">
                <img src="/assets/images/logo-white.svg" style="max-width:30%;" class="img-brand img-fluid" alt="Mont Haus International Realty" />
                <hr class="mb-3 mt-4" />
                <div class="row">
                    <div class="col-auto my-1">
                        <p class="m-0">Copyright &copy;<?= date('Y') ?> Mont Haus LLC. All Rights Reserved.</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="auth-form">
            <div class="card my-5 mx-3">
                <div class="card-body text-center fc-center">
                    <img src="/assets/images/triangle_marks_blk.svg" style="max-width:20%;" class="img-fluid mb-4 d-block mx-auto" alt="MH" />
                    <h2 class="mb-2">Mont Haus Marketing</h2>
                    <p class="mb-4 text-muted">Sign in with your Mont Haus Microsoft account.</p>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger text-start" role="alert">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($sso_on): ?>
                        <a class="ms-signin-btn" href="/oauth_start.php">
                            <svg viewBox="0 0 21 21" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                                <rect x="1"  y="1"  width="9" height="9" fill="#F25022"/>
                                <rect x="11" y="1"  width="9" height="9" fill="#7FBA00"/>
                                <rect x="1"  y="11" width="9" height="9" fill="#00A4EF"/>
                                <rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
                            </svg>
                            Sign in with Microsoft
                        </a>
                    <?php else: ?>
                        <div class="alert alert-warning text-start" role="alert">
                            Microsoft sign-in is not configured on this server.
                            Upload <code>sso_config.php</code> to enable it.
                        </div>
                    <?php endif; ?>

                    <?php if ($password_enabled): ?>

                        <?php if ($sso_on): ?>
                            <div class="login-divider">or</div>
                            <div<?= $show_password ? '' : ' hidden' ?> id="staffPanel">
                        <?php else: ?>
                            <div class="mt-4" id="staffPanel">
                        <?php endif; ?>

                            <?php if ($sso_on): ?>
                                <p class="text-muted mb-3" style="font-size:.78rem;">
                                    Staff sign-in — for administrator accounts only.
                                </p>
                            <?php endif; ?>

                            <form method="POST" action="/login.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <div class="mb-3">
                                    <input type="email" class="form-control" placeholder="Email Address"
                                           id="email" name="email"
                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                                </div>
                                <div class="mb-3">
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="floatingInput1" name="password" placeholder="Password" required>
                                        <button type="button" class="btn btn-outline-secondary" id="togglePassword" tabindex="-1" aria-label="Toggle password visibility">
                                            <i class="feather icon-eye" id="togglePasswordIcon"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="d-flex mt-1 justify-content-between align-items-center">
                                    <a href="/forgot-password.php"><h6 class="text-secondary f-w-400 mb-0">Forgot Password?</h6></a>
                                </div>
                                <div class="d-grid mt-4"><button type="submit" class="btn btn-primary">Sign in</button></div>
                            </form>
                        </div>

                        <?php if ($sso_on && !$show_password): ?>
                            <button type="button" class="staff-toggle mt-1" id="staffToggle">Staff sign-in</button>
                        <?php endif; ?>

                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/plugins/popper.min.js"></script>
<script src="/assets/js/plugins/simplebar.min.js"></script>
<script src="/assets/js/plugins/bootstrap.min.js"></script>
<script src="/assets/js/plugins/i18next.min.js"></script>
<script src="/assets/js/plugins/i18nextHttpBackend.min.js"></script>
<script src="/assets/js/icon/custom-font.js"></script>
<script src="/assets/js/script.js"></script>
<script src="/assets/js/theme.js"></script>
<script src="/assets/js/multi-lang.js"></script>
<script src="/assets/js/plugins/feather.min.js"></script>
<script>
(function () {
    var toggle = document.getElementById('staffToggle');
    var panel  = document.getElementById('staffPanel');
    if (toggle && panel) {
        toggle.addEventListener('click', function () {
            panel.hidden = false;
            toggle.hidden = true;
            var email = document.getElementById('email');
            if (email) { email.focus(); }
        });
    }

    var pwToggle = document.getElementById('togglePassword');
    if (pwToggle) {
        pwToggle.addEventListener('click', function () {
            var input = document.getElementById('floatingInput1');
            var icon  = document.getElementById('togglePasswordIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('icon-eye', 'icon-eye-off');
            } else {
                input.type = 'password';
                icon.classList.replace('icon-eye-off', 'icon-eye');
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/inc/_footer.php'; ?>
</body>
</html>
