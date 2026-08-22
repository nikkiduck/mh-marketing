<?php
/**
 * logout.php
 * Destroys the local session, then — when SSO_FEDERATED_LOGOUT is on —
 * hands the browser to Microsoft to end the directory session too.
 *
 * Link from any page: <a href="/logout.php">Sign out</a>
 */

require_once __DIR__ . '/inc/sso.php';   // pulls in auth.php: session config + start

$federated = sso_enabled()
    && defined('SSO_FEDERATED_LOGOUT')
    && SSO_FEDERATED_LOGOUT
    && (($_SESSION['auth_method'] ?? '') === 'microsoft');

// Unset all session variables
$_SESSION = [];

// Destroy the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

if ($federated) {
    // Microsoft signs the user out, then returns them to our login page.
    // post_logout_redirect_uri must also be registered on the app registration.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $home   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'marketing.monthaus.com') . '/login.php';

    header('Location: ' . sso_logout_endpoint() . '?' . http_build_query([
        'post_logout_redirect_uri' => $home,
    ]), true, 302);
    exit();
}

header('Location: /login.php');
exit();
