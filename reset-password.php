<?php
/**
 * reset-password.php
 * Validates a password-reset token and allows the user to set a new password.
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

// Validate the token
if (empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    $stmt = $conn->prepare(
        "SELECT email FROM password_resets
         WHERE token = ? AND used = 0 AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        $valid = true;
        $email = $row['email'];
    } else {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid request. Please try again.';
        $valid = false;
    } else {
        $password        = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $password_confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);

            // Update the user's password
            $upd = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $upd->bind_param('ss', $hashed, $email);
            $upd->execute();
            $upd->close();

            // Mark token as used
            $used = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
            $used->bind_param('s', $token);
            $used->execute();
            $used->close();

            $success = 'Your password has been reset. You can now log in.';
            $valid   = false; // Hide the form
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
    <title>Reset Password — Mont Haus</title>
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
<!-- [ Pre-loader ] start -->
<div class="loader-bg">
    <div class="loader-track"><div class="loader-fill"></div></div>
</div>
<!-- [ Pre-loader ] End -->
<div class="auth-main v2">
    <div class="bg-overlay bg-dark"></div>
    <div class="auth-wrapper">
        <div class="auth-sidecontent">
            <div class="auth-sidefooter">
                <img src="/assets/images/logo-white.svg" style="max-width:30%;" class="img-brand img-fluid" alt="Mont Haus International Realty" />
                <hr class="mb-3 mt-4" />
                <div class="row">
                    <div class="col-auto my-1">
                        <p class="m-0">Copyright &copy;2026 Mont Haus LLC. All Rights Reserved.</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="auth-form">
            <div class="card my-5 mx-3">
                <div class="card-body text-center fc-center">
                   <img src="/assets/images/triangle_marks_blk.svg" style="max-width:20%;" class="img-fluid mb-5 d-block mx-auto" alt="MH" />
                    <h2 class="mb-2">Reset Your Password</h2>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                        <div class="mt-3">
                            <a href="/forgot-password.php" class="btn btn-outline-secondary">Request a new reset link</a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success" role="alert">
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                        <div class="mt-3">
                            <a href="/login.php" class="btn btn-primary">Go to Login</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($valid): ?>
                        <p class="mb-3">Enter your new password below.</p>
                        <form method="POST" action="/reset-password.php?token=<?php echo urlencode($token); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="mb-3">
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password"
                                           name="password" placeholder="New Password"
                                           minlength="8" required autofocus>
                                    <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="password" tabindex="-1">
                                        <i class="feather icon-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password_confirm"
                                           name="password_confirm" placeholder="Confirm New Password"
                                           minlength="8" required>
                                    <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="password_confirm" tabindex="-1">
                                        <i class="feather icon-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-primary">Reset Password</button>
                            </div>
                        </form>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>
<!-- Required Js -->
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
