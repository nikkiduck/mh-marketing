<?php
/**
 * sso_config.sample.php
 *
 * Copy this to sso_config.php on the server and fill in the three values from
 * the Entra app registration. sso_config.php is gitignored and denied by
 * .htaccess — the same pattern db.php uses.
 *
 *   cp deploy/sso_config.sample.php inc/sso_config.php
 *
 * See ENTRA_SETUP.md for where each value comes from.
 */

// Directory (tenant) ID — Entra → App registrations → your app → Overview
define('SSO_TENANT_ID', 'PASTE_TENANT_ID_HERE');

// Application (client) ID — same Overview page
define('SSO_CLIENT_ID', 'PASTE_CLIENT_ID_HERE');

// Client secret VALUE (not the Secret ID) — Certificates & secrets.
// Shown once, at creation. Record the expiry date; sign-in stops working
// the day it lapses.
define('SSO_CLIENT_SECRET', 'PASTE_CLIENT_SECRET_VALUE_HERE');

// Must match the redirect URI on the app registration exactly:
// https, no trailing slash, exact case.
define('SSO_REDIRECT_URI', 'https://marketing.monthaus.com/oauth_callback.php');

// ── Behaviour ────────────────────────────────────────────────────────────────

// true  → /login.php bounces straight to Microsoft (prompt=none first).
//         Agents never see a login screen while their Microsoft session is live.
// false → /login.php shows a "Sign in with Microsoft" button.
// Leave this false until you have confirmed sign-in works.
define('SSO_AUTO_REDIRECT', false);

// true → signing out of this site also ends the Microsoft session.
// Right for shared machines; a nuisance on personal ones, because it signs
// the user out of Outlook and Teams in that browser too.
define('SSO_FEDERATED_LOGOUT', false);

// Roles still allowed to sign in with an email and password.
// The plan calls for break-glass access for super_admin only — that is the
// way back in if the client secret expires or Entra is unreachable.
// Set to [] to disable password sign-in entirely (not recommended).
define('SSO_PASSWORD_LOGIN_ROLES', 'super_admin');
