<?php
/**
 * oauth_callback.php — completes a Microsoft Entra ID sign-in.
 *
 * This URL must be registered as the redirect URI on the Entra app
 * registration, character for character:
 *   https://marketing.monthaus.com/oauth_callback.php
 *
 * Order of operations, and none of it is optional:
 *   1. state matches the value we generated          (CSRF on the callback)
 *   2. code exchanged server-to-server over TLS      (the secret never leaves here)
 *   3. id_token signature verified against the JWKS  (RS256, kid-matched)
 *   4. iss / aud / tid / exp / nbf / nonce checked   (see sso_verify_id_token)
 *   5. claims resolved to a users row                (never auto-created)
 *   6. session established exactly as login.php does
 */

require_once __DIR__ . '/inc/sso.php';
require_once __DIR__ . '/inc/db.php';

/** Renders a dead end rather than looping the browser back to Microsoft. */
function sso_fail(string $message, string $detail = ''): never
{
    sso_clear_flow_state();
    if ($detail !== '') {
        error_log('SSO callback: ' . $detail);
    }
    $_SESSION['sso_error'] = $message;
    header('Location: /login.php?error=sso', true, 302);
    exit();
}

if (!sso_enabled()) {
    sso_fail('Microsoft sign-in is not configured on this server.');
}

// Microsoft reports its own failures here — including the expected
// "interaction_required" when a prompt=none attempt could not complete silently.
if (isset($_GET['error'])) {
    $code = (string) $_GET['error'];
    if (in_array($code, ['interaction_required', 'login_required', 'consent_required'], true)) {
        sso_clear_flow_state();
        header('Location: /login.php', true, 302);   // fall back to the button
        exit();
    }
    sso_fail(
        'Microsoft could not complete the sign-in.',
        $code . ': ' . (string) ($_GET['error_description'] ?? '')
    );
}

// ── 1. state ─────────────────────────────────────────────────────────────────
$state    = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['sso_state'] ?? '');

if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
    // Nearly always a stale tab, a bookmarked callback URL, or a browser that
    // dropped the session cookie — not an attack. Say something useful.
    sso_fail('That sign-in link has expired. Please start again.', 'state mismatch');
}

// A sign-in attempt is good for ten minutes.
if (time() - (int) ($_SESSION['sso_started'] ?? 0) > 600) {
    sso_fail('That sign-in attempt timed out. Please try again.', 'flow expired');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    sso_fail('Microsoft did not return an authorization code.');
}

$nonce    = (string) ($_SESSION['sso_nonce'] ?? '');
$verifier = (string) ($_SESSION['sso_verifier'] ?? '');
$return_to = (string) ($_SESSION['sso_return_to'] ?? '/index.php');

if ($nonce === '' || $verifier === '') {
    sso_fail('That sign-in link has expired. Please start again.', 'missing nonce/verifier');
}

// ── 2 & 3 & 4. exchange, then verify ─────────────────────────────────────────
try {
    $tokens = sso_exchange_code($code, $verifier);
    $claims = sso_verify_id_token((string) $tokens['id_token'], $nonce);
} catch (RuntimeException $e) {
    sso_fail('Sign-in could not be completed. Please try again.', $e->getMessage());
}

// The one-shot values have done their job. Retire them before we grant access.
sso_clear_flow_state();

// ── 5. resolve to a portal account ───────────────────────────────────────────
[$user, $error] = sso_resolve_user($conn, $claims);

if ($user === null) {
    sso_fail($error !== '' ? $error : 'No Mont Haus portal account matches that Microsoft account.');
}

if (empty($user['is_active'])) {
    sso_fail('That account is inactive. Please contact your administrator.');
}

// ── 6. sign in ───────────────────────────────────────────────────────────────
sso_establish_session($conn, $user);
$conn->close();

if (!empty($_SESSION['must_change_password'])) {
    header('Location: /change_password.php', true, 302);
    exit();
}

header('Location: ' . $return_to, true, 302);
exit();
