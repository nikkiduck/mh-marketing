<?php
/**
 * oauth_start.php — begins a Microsoft Entra ID sign-in.
 *
 * Generates state / nonce / PKCE, then redirects the browser to Microsoft.
 * Reachable directly (that is how you test before the login page is wired up):
 *   https://marketing.monthaus.com/oauth_start.php
 *
 * Optional query parameters:
 *   ?return_to=/agent.php?id=12   path on this site to land on afterwards
 *   ?silent=1                     prompt=none — used by the auto sign-in bounce
 */

require_once __DIR__ . '/inc/sso.php';

if (!sso_enabled()) {
    header('Location: /login.php?error=sso_unconfigured');
    exit();
}

// Already signed in — nothing to do.
if (!empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit();
}

$return_to = (string) ($_GET['return_to'] ?? '/index.php');
$silent    = !empty($_GET['silent']);

// One silent attempt per session. Without this guard a tenant that will not
// authenticate silently sends the browser into a redirect loop.
if ($silent) {
    if (!empty($_SESSION['sso_silent_tried'])) {
        header('Location: /login.php');
        exit();
    }
    $_SESSION['sso_silent_tried'] = true;
}

header('Location: ' . sso_authorize_url($return_to, $silent), true, 302);
exit();
