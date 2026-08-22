<?php
/**
 * change_password.php
 * Allows any logged-in user (agent or admin) to change their own password.
 */

require_once __DIR__ . '/inc/auth.php';
require_login();

require_once __DIR__ . '/inc/db.php';

$user    = current_user();
$error   = '';
$success = '';

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid request. Please try again.';
    } else {
        $current_pw = $_POST['current_password'] ?? '';
        $new_pw     = $_POST['new_password']     ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';

        if (empty($current_pw) || empty($new_pw) || empty($confirm_pw)) {
            $error = 'All fields are required.';
        } elseif (strlen($new_pw) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new_pw !== $confirm_pw) {
            $error = 'New passwords do not match.';
        } else {
            // Fetch current hashed password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || !password_verify($current_pw, $row['password'])) {
                $error = 'Your current password is incorrect.';
            } else {
                // Update to new password and clear any forced-change flag
                $hashed = password_hash($new_pw, PASSWORD_BCRYPT);
                $upd = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
                $upd->bind_param('si', $hashed, $user['id']);
                $upd->execute();
                $upd->close();

                // Clear session flag
                unset($_SESSION['must_change_password']);

                $success = 'Your password has been updated successfully.';

                // Regenerate CSRF
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        }
    }
}

$conn->close();
$full_name = trim($user['first_name'] . ' ' . $user['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password — Mont Haus Intranet</title>
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
             <img src="/assets/images/logo-white.svg" style="max-width:30%;" class="img-brand img-fluid" alt="Mont Haus International Realty" />
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

                   <img src="/assets/images/triangle_marks_blk.svg" style="max-width:20%;" class="img-fluid mb-5 d-block mx-auto" alt="MH" />
                    <h2 class="mb-1 text-center">Change Password</h2>
                    <p class="text-center mb-4" style="color:#888; font-size:.88rem;">
                        Logged in as <strong><?php echo htmlspecialchars($full_name); ?></strong>
                    </p>

                    <?php if (!empty($_SESSION['must_change_password'])): ?>
                    <div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
                        <i class="ti ti-shield-lock fs-5 mt-1 flex-shrink-0"></i>
                        <div>
                            <strong>Password change required.</strong> For your security, you must set a new password before continuing. Enter your temporary password below, then choose a permanent one.
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                        <i class="ti ti-alert-circle fs-5"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                    <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
                        <i class="ti ti-circle-check fs-5"></i>
                        <div>
                            <?php echo htmlspecialchars($success); ?>
                            <a href="/index.php" class="alert-link ms-1">Go to Hub →</a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="/change_password.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <!-- Current password -->
                        <div class="mb-3">
                            <label for="current_password" class="form-label fw-semibold" style="font-size:.83rem;">Current Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="current_password" name="current_password"
                                       placeholder="Your current password" required autofocus>
                                <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="current_password" tabindex="-1">
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

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="ti ti-lock me-1"></i> Update Password
                            </button>
                        </div>
                        <?php if (empty($_SESSION['must_change_password'])): ?>
                        <div class="text-center">
                            <a href="/index.php" class="text-secondary" style="font-size:.85rem;">
                                <i class="ti ti-arrow-left me-1"></i>Back to Hub
                            </a>
                        </div>
                        <?php endif; ?>

                    </form>

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
