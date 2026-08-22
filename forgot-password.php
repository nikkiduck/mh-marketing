<?php
/**
 * forgot-password.php
 * Accepts an email address and sends a password-reset link if the email is on file.
**/

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/mailer.php';

$message = '';
$error   = '';

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Look up the user — but don't reveal whether they exist
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            $stmt->close();

            if ($user) {
                // Generate a secure token valid for 1 hour
                $token      = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', time() + 3600);

                // Invalidate any existing unused tokens for this email
                $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
                $del->bind_param('s', $email);
                $del->execute();
                $del->close();

                // Store new token
                $ins = $conn->prepare(
                    "INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)"
                );
                $ins->bind_param('sss', $email, $token, $expires_at);
                $ins->execute();
                $ins->close();

                // Build reset URL
                $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host      = $_SERVER['HTTP_HOST'];
                $reset_url = $protocol . '://' . $host . '/reset-password.php?token=' . urlencode($token);

                // Send email via SendGrid
                $subject    = 'Mont Haus — Password Reset Request';
                $body_plain = "Hello,\r\n\r\n"
                            . "We received a request to reset the password for your Mont Haus Intranet account.\r\n\r\n"
                            . "Click the link below to reset your password (valid for 1 hour):\r\n"
                            . $reset_url . "\r\n\r\n"
                            . "If you did not request a password reset, please ignore this email.\r\n\r\n"
                            . "— Mont Haus";
                $body_html  = '<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:32px 24px;">'
                            . '<p style="font-size:15px;color:#333;">Hello,</p>'
                            . '<p style="font-size:15px;color:#333;">We received a request to reset the password for your Mont Haus Intranet account. Click below to reset your password — this link is valid for 1 hour.</p>'
                            . '<div style="text-align:center;margin:32px 0;">'
                            . '<a href="' . htmlspecialchars($reset_url) . '" style="display:inline-block;background:#1a1a1a;color:#fff;padding:12px 28px;text-decoration:none;font-weight:bold;font-size:14px;letter-spacing:.5px;">Reset Password</a>'
                            . '</div>'
                            . '<p style="font-size:13px;color:#888;">If you did not request a password reset, please ignore this email.</p>'
                            . '<p style="font-size:13px;color:#888;">— Mont Haus</p>'
                            . '</div>';
                $send_error = '';
                send_email($email, '', $subject, $body_html, $body_plain, [], $send_error, 'noreply@monthausint.com', 'Mont Haus');
            }

            // Always show the same message to prevent email enumeration
            $message = 'If you have an email on file, a password-reset link has been sent to you.';
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
    <title>Forgot Password — Mont Haus Intranet</title>
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
                    <h2 class="mb-2">Forgot your password?</h2>
                    <p class="mb-3">Enter your email address and we'll send you a reset link.</p>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($message)): ?>
                        <div class="alert alert-success" role="alert">
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="/forgot-password.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="mb-3">
                                <input
                                    type="email"
                                    class="form-control"
                                    name="email"
                                    placeholder="Email Address"
                                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                    required
                                    autofocus>
                            </div>
                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-primary">Send Reset Link</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <div class="mt-3">
                        <a href="/login.php" class="text-secondary">Back to Login</a>
                    </div>
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
<?php include __DIR__ . '/inc/_footer.php'; ?>
</body>
</html>
