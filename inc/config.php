<?php
/**
 * config.php — non-secret site settings for marketing.monthaus.com.
 *
 * Safe to commit. Credentials belong in db.php and sso_config.php, both of
 * which are gitignored and denied by .htaccess.
 */

// Canonical origin for this app. Used to build absolute URLs.
define('SITE_URL', 'https://marketing.monthaus.com');

// Where the "Hub" link in the header points. Change this to
// https://portal.monthaus.com/ once the portal is live.
define('HUB_URL', 'https://monthausint.com/');

// Receipt uploads are stored OUTSIDE the document root so they can never be
// fetched directly — receipt.php streams them after an auth check.
// config.php lives in inc/, so this is TWO levels up: from
// /var/www/marketing.monthaus.com/inc/ to /var/www/receipts/.
// One level would land inside the web root, which is the thing this
// constant exists to avoid.
define('RECEIPTS_DIR', __DIR__ . '/../../receipts/');

/**
 * ACCESS_ALLOWLIST — temporary limited rollout.
 *
 * While the agent-facing side is still being built, only these people may use
 * the site. Comma-separated emails, case-insensitive.
 *
 * This is enforced in require_login() (inc/auth.php), so it covers EVERY page
 * and BOTH sign-in paths — Microsoft and break-glass password alike. A person
 * outside the list can authenticate successfully and still gets a 403, which
 * is deliberate: the gate does not depend on anyone remembering to add a check
 * to a new page.
 *
 * It is also independent of users.role. Promoting someone to admin does not
 * grant them access while this list is set — the two have to agree.
 *
 * TO OPEN THE SITE UP: set this to an empty string, or delete the line.
 * An empty or undefined value disables the restriction entirely and access
 * falls back to the normal role checks.
 */
define('ACCESS_ALLOWLIST', 'nikki.boxer@monthaus.com,jonathan.boxer@monthaus.com,jm.drai@monthaus.com');
