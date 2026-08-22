<?php
/**
 * set_password.php
 * New-agent account activation page.
 * Agent arrives here via invite link from register.php.
 * They enter their temporary password (set by admin) + choose a new one.
 */

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();

require_once __DIR__ . '/inc/db.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = '';
$valid   = false;
$email   = '';

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Validate the invite token ─────────────────────────────────────────────────
if (empty($token)) {
    $error = 'Invalid or missing invite link.';
} else {
    $stmt = $conn->prepare(
        "SELECT email FROM password_resets
         WHERE token = ? AND used = 0 AND expires_at > NOW() AND type = 'invite'
         LIMIT 1"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $valid = true;
        $email = $row['email'];
    } else {
        $error = 'This invite link is invalid or has expired. Please ask your admin to send a new one.';
    }
}

// ── Handle form submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {

    // CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid request. Please try again.';
        $valid = false;
    } else {
        $temp_pw    = $_POST['temp_password']    ?? '';
        $new_pw     = $_POST['new_password']     ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';

        if (empty($temp_pw) || empty($new_pw) || empty($confirm_pw)) {
            $error = 'All fields are required.';
        } elseif (strlen($new_pw) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new_pw !== $confirm_pw) {
            $error = 'New passwords do not match.';
        } else {
            // Verify the temporary password matches what's stored
            $usr = $conn->prepare(
                "SELECT id, password FROM users WHERE email = ? AND is_active = 1 LIMIT 1"
            );
            $usr->bind_param('s', $email);
            $usr->execute();
            $user = $usr->get_result()->fetch_assoc();
            $usr->close();

            if (!$user || !password_verify($temp_pw, $user['password'])) {
                $error = 'The temporary password you entered is incorrect.';
            } else {
                // All good — update to the new password and clear forced-change flag
                $hashed = password_hash($new_pw, PASSWORD_BCRYPT);
                $upd = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
                $upd->bind_param('si', $hashed, $user['id']);
                $upd->execute();
                $upd->close();

                // Mark token as used
                $use = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
                $use->bind_param('s', $token);
                $use->execute();
                $use->close();

                $success = 'Your password has been set! You can now log in.';
                $valid   = false;
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate Your Account — Mont Haus</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/x-icon" />
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/fonts/phosphor/duotone/style.css">
    <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
    <link rel="stylesheet" href="/assets/fonts/feather.css">
    <link rel="stylesheet" href="/assets/fonts/fontawesome.css">
    <link rel="stylesheet" href="/assets/fonts/material.css">
    <link rel="stylesheet" href="/assets/css/style.css" id="main-style-link">
    <link rel="stylesheet" href="/assets/css/style-preset.css">
</head>
<body data-pc-preset="preset-1" data-pc-sidebar-theme="light" data-pc-sidebar-caption="true" data-pc-direction="ltr" data-pc-theme="light">
<!-- Pre-loader -->
<div class="loader-bg">
    <div class="loader-track"><div class="loader-fill"></div></div>
</div>

<div class="auth-main v2">
    <div class="bg-overlay bg-dark"></div>
    <div class="auth-wrapper">

        <!-- Side panel -->
        <div class="auth-sidecontent">
            <div class="auth-sidefooter">
                <img src="/assets/images/logo-white.svg" style="max-width:50%;" class="img-brand img-fluid" alt="Mont Haus International Realty" />
                <hr class="mb-3 mt-4" />
                <div class="row">
                    <div class="col-auto my-1">
                        <p class="m-0">Copyright &copy;2026 Mont Haus LLC. All Rights Reserved.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form panel -->
        <div class="auth-form">
            <div class="card my-5 mx-3">
                <div class="card-body fc-center" style="padding: 2rem 2.5rem;">

                    <img src="/assets/images/triangle_marks_blk.svg" style="max-width:15%;" class="img-fluid mb-4 d-block mx-auto" alt="MH" />
                    <h2 class="mb-1 text-center">Activate Your Account</h2>
                    <p class="text-center mb-4" style="color:#888; font-size:.88rem;">Enter the temporary password from your invite email, then choose a new one.</p>

                    <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                        <i class="ti ti-alert-circle fs-5"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                    <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
                        <i class="ti ti-circle-check fs-5"></i>
                        <div><?php echo htmlspecialchars($success); ?></div>
                    </div>
                    <div class="d-grid mt-3">
                        <a href="/login.php" class="btn btn-primary">Go to Login</a>
                    </div>
                    <?php endif; ?>

                    <?php if ($valid): ?>
                    <form method="POST" action="/set_password.php?token=<?php echo urlencode($token); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <!-- Temporary password -->
                        <div class="mb-3">
                            <label for="temp_password" class="form-label fw-semibold" style="font-size:.83rem;">Temporary Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="temp_password" name="temp_password"
                                       placeholder="From your invite email" required autofocus>
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="temp_password" tabindex="-1">
                                    <i class="feather icon-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- New password -->
                        <div class="mb-3">
                            <label for="new_password" class="form-label fw-semibold" style="font-size:.83rem;">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" name="new_password"
                                       placeholder="Min. 8 characters" minlength="8" required>
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="new_password" tabindex="-1">
                                    <i class="feather icon-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Confirm new password -->
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label fw-semibold" style="font-size:.83rem;">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                       placeholder="Repeat new password" minlength="8" required>
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="confirm_password" tabindex="-1">
                                    <i class="feather icon-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="ti ti-shield-check me-1"></i> Set Password &amp; Activate
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>

                    <?php if (!$valid && empty($success)): ?>
                    <div class="text-center mt-3">
                        <a href="/login.php" class="text-secondary" style="font-size:.85rem;">← Back to Login</a>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>
</div>

<script src="/assets/js/plugins/popper.min.js"></script>
<script src="/assets/js/plugins/simplebar.min.js"></script>
<script src="/assets/js/plugins/bootstrap.min.js"></script>
<script src="/assets/js/icon/custom-font.js"></script>
<script src="/assets/js/script.js"></script>
<script src="/assets/js/theme.js"></script>
<script src="/assets/js/plugins/feather.min.js"></script>
<script>
document.querySelectorAll('.toggle-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(this.dataset.target);
        var icon  = this.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('icon-eye', 'icon-eye-off');
        } else {
            input.type = 'password';
            icon.classList.replace('icon-eye-off', 'icon-eye');
        }
    });
});
</script>

<?php include __DIR__ . '/inc/_footer.php'; ?>
</body>
</html>
