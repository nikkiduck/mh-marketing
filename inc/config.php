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

// Creative shown on the agent portal: ad images and collateral proofs
// (sql/creatives_v1.sql, inc/creatives.php). Same two-levels-up reasoning as
// RECEIPTS_DIR: /var/www/creatives, outside the web root, served only through
// creative.php (admin) and portal/asset.php (the owning agent).
define('CREATIVES_DIR', __DIR__ . '/../../creatives/');

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
// Team beta (Nikki, 2026-10-10): Jonathan, Jean-Michel, Jackson Horn, Megan
// Walz, Scott Weber, Bryan Cournoyer (Sara stays from the 2026-10-06 pilot).
// Each also needs a login with a role and users.intake_id set on users.php
// (docs/AGENT_PORTAL_PLAN.md, section 2). Clear the list to open the site to
// every Mont Haus login.
define('ACCESS_ALLOWLIST', 'nikki.boxer@monthaus.com,jonathan.boxer@monthaus.com,jm.drai@monthaus.com,mary.lappe@monthaus.com,'
    . 'scott.weber@monthaus.com,sara.perkowski@monthaus.com,bryan.cournoyer@monthaus.com,jackson.horn@monthaus.com,'
    . 'megan.walz@monthaus.com');

/**
 * ADVERTISING_SHEET_URL — the working digital-advertising spreadsheet.
 *
 * Linked from the top of billing.php. It lives in SharePoint and still holds
 * detail this app has not absorbed yet, so it sits next to the totals rather
 * than in somebody's bookmarks.
 *
 * Here rather than hard-coded in the page so a re-shared link is a config
 * change, not a code edit. SharePoint share URLs carry a token in `?e=` and do
 * get regenerated.
 *
 * Empty or undefined hides the link — billing.php checks before rendering it,
 * so publishing that page without this one degrades quietly instead of fataling.
 */
define('ADVERTISING_SHEET_URL',
    'https://monthausllc.sharepoint.com/:x:/s/marketing/IQD2mCUD7tywR4KMubl8HJhhAai2pArG9tMmGohgSNm9etk?e=UF2ZSX');

/**
 * Hot Sheets: where listings come from. site.monthaus.com is the only system
 * that pulls listings from Anyprop; cron/sync_hot_sheet_listings.php reads this
 * feed. The token (LISTINGS_FEED_TOKEN) is a secret and lives in inc/db.php.
 */
/**
 * The public website these agents appear on: the "view public profile" link on
 * the agent page, and where the marketing portal points people. Change to
 * https://monthaus.com at go-live.
 */
define('PUBLIC_SITE_URL', 'https://site.monthaus.com');

define('LISTINGS_FEED_URL', 'https://site.monthaus.com/api/listings.php');

/**
 * "Sync to Website" on the roster. It asks the website to pull this portal's
 * roster feed right now, which is the same work its nightly cron does
 * (inc/site_sync.php). The shared secret is AGENT_SYNC_TOKEN in inc/db.php.
 *
 * Empty or undefined hides the button, so this portal still works on its own.
 */
define('SITE_AGENT_SYNC_URL', 'https://site.monthaus.com/api/sync_agents.php');


/**
 * Hot Sheets: who the new system may email. While the hub's Hot Sheets still
 * serves everyone, this is the ONLY gate that matters: cron/send_hot_sheet.php
 * skips (and logs as 'blocked') any address not listed here, whatever
 * hs_subscribers says. Comma-separated. Empty string = no restriction, which is
 * the go-live switch, not something to set casually.
 */
define('HOT_SHEET_ALLOWED_RECIPIENTS', 'nikki.boxer@monthaus.com');

// Sender, and where the email's images are served from (public, no sign-in).
define('HOT_SHEET_FROM_EMAIL', 'hotsheet@monthaus.com');
define('HOT_SHEET_FROM_NAME',  'Mont Haus Hot Sheet');
define('HOT_SHEET_ASSET_BASE', SITE_URL . '/assets/hotsheet/');

/**
 * Paperless Pipeline (cron/parse_pipeline_events.php, pipeline_review.php).
 * Comma-separated. The webhook secret is a credential and lives in inc/db.php.
 *
 * EXCLUDED: accounts Paperless attaches to every deal that are never agents
 * (tc@ is the Paperless admin / transaction-coordination account).
 * CONDITIONAL: attached by role, sometimes genuinely on the deal (the managing
 * broker). Never credited automatically; the review card offers to add them
 * when they are listed first.
 * NOTIFY: who is told when something is waiting in the review queue.
 */
define('PIPELINE_EXCLUDED_AGENT_EMAILS',    'tc@monthaus.com');
define('PIPELINE_CONDITIONAL_AGENT_EMAILS', 'jm.drai@monthaus.com');
define('PIPELINE_NOTIFY_EMAILS',            'nikki.boxer@monthaus.com');
// Roster changes from the MLS syncs (new agents, agents no longer with MH in
// the MLS, agents back again). Comma separated. inc/roster_alerts.php.
define('ROSTER_ALERT_EMAILS',               'nikki.boxer@monthaus.com');

/**
 * Dynamic QR codes (qr_codes.php, qr.php, inc/qr.php).
 *
 * QR_BASE_URL is the address printed inside every code. NEVER change it once
 * codes are in print: every sign would stop working.
 * QR_FALLBACK_URL is where unknown, paused or broken codes land.
 * QR_ALLOWED_DOMAIN: destinations must be on this domain or a subdomain.
 * Profile-page codes follow PUBLIC_SITE_URL above, so they move to
 * monthaus.com by themselves when that constant changes at go-live.
 */
define('QR_BASE_URL',       'https://qr.monthaus.com');
define('QR_FALLBACK_URL',   'https://monthaus.com');
define('QR_ALLOWED_DOMAIN', 'monthaus.com');
define('QR_ADD_UTM',        true);
