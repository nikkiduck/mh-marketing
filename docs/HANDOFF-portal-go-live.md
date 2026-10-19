# Marketing portal go-live checklist (audit of 2026-10-19)

A read-only scan of the whole portal, admin and agent sides, taken while the
pilot was still on: the code (every gate, allowlist, beta flag, dev-only hook,
hard-coded address and "coming soon"), the live server (config flags, cron,
health state, Apache errors, certificates, disk, private paths), the migrations
against the live schema, the four render harnesses run on the server, and the
`users`, `marketing_intakes` and `hs_subscribers` tables. Nothing was changed.

## What is healthy already

- Every table and column the 53 migrations create exists on the live database.
- All four render harnesses pass on the server: render_portal 180, render_users
  58, render_billing 33, render_order_split 45 assertions, no PHP notices.
- Cron: the five jobs run and log a closing tally; check_health reports all OK
  and its state file is empty; no ✗ line in any log.
- The Apache error log has no PHP errors in its recent lines; `display_errors`
  is off.
- The site endpoints the portal depends on all answer 200 with the tokens:
  the listings feed, the board registry, FUB standing, market statistics.
- Certificates: marketing.monthaus.com to 2026-11-19, qr.monthaus.com to
  2026-12-29, certbot's timer active (last ran today). Disk 10% of 40 GB.
  logrotate rule present. No `.git` in the docroot.
- Private paths answer 403 (inc, sql, tests, docs, inc/config.php); the two
  debug pages redirect to login and require admin. Every docroot page except
  the sign-in, password, unsubscribe, qr and shared-secret API pages requires
  login, and every admin page requires role admin (users.php super_admin).
- Security headers on both vhosts: nosniff, X-Frame-Options, Referrer-Policy,
  HSTS. No CSP (optional hardening, not blocking).
- No TODO, FIXME or stub in app code.

## The two switches Nikki named

1. **Open sign-in: `ACCESS_ALLOWLIST` to `''`** (inc/config.php, the server
   copy and the local one; the comment above it says the same). Nine
   addresses are on it today. Once empty, anyone with a Microsoft sign-in AND
   a row in `users` gets in; an unknown Microsoft account is refused with
   "There is no Mont Haus portal account for you. Contact Nikki" (inc/sso.php),
   so step 3 below has to happen first or at the same time.
2. **Open Hot Sheets: `HOT_SHEET_ALLOWED_RECIPIENTS` to `''`** (inc/config.php;
   its comment calls this the go-live switch). The daily send at 13:00 UTC
   (07:00 Mountain) then reaches all 21 active subscribers (3 daily, 18 twice
   weekly) instead of Nikki alone; 20 people start receiving. The same list
   also gates the roster alerts and pipeline notices (inc/roster_alerts.php,
   cron/parse_pipeline_events.php), which keep going to Nikki only because
   their own recipient lists say so.

## Before opening the doors (actions)

3. **Nine active agents have no portal login** (a `users` row linked to their
   marketing account; created on users.php, never automatically): Emma Casson,
   Grant Langham (web pending), Kimberlee Coates, Lynn Emmert, Minette
   Stapleton, Noah Walz, Sierrah Smith, Steve Harriage, and the "Nikki Boxer"
   agent row (36; Nikki's super_admin login is deliberately unlinked and uses
   `?preview=`). Each also needs a Microsoft 365 account on monthaus.com.
   Fifteen agents and two admins are linked today. Bonnie Scott and Kellee
   Anderson are offboarded (is_active 0): confirm they are disabled in Entra
   too, which is the real revocation.
4. **Remove the Spark leftovers, which carry tokens in a committed file.**
   `cron/sync_mh_brokers.php` has two trailing comments that are the Spark
   access tokens themselves (lines 39 and 43), and the file is in the deploy
   manifest and on the server. Spark is retired (2026-10-01), so delete
   `cron/sync_mh_brokers.php`, `cron/sync_roster.php`, `spark_debug.php`, the
   `SPARK_ACCESS_TOKEN` / `VAIL_SPARK_ACCESS_TOKEN` constants and the unused
   `HUB_URL`, and remove them from the manifest and the server. The tokens stay
   in git history; Spark's account can be closed with the vendor.
5. **Two emails still send from the retired hub's domain:**
   forgot-password.php and inc/mailer.php use `noreply@monthausint.com`.
   Change to a monthaus.com sender (hotsheet@ and portal@ already send through
   SendGrid from monthaus.com).
6. **Decide the three "coming soon" lines** shown to agents: "More templates
   coming soon." on Templates and the Instagram tool, "More coming soon." on
   the home Templates card. Keep or drop.
7. **Pipeline webhook:** Zapier still posts to the hub (api/pipeline_webhook.php's
   note), so `parse_pipeline_events` runs every 15 minutes and parses nothing.
   Point Zapier at this portal's webhook with its shared secret, or leave the
   pipeline review for later; either way it is a decision, not a bug.

## Decisions for Nikki (not blocking)

8. **`SSO_AUTO_REDIRECT`** is false (inc/sso_config.php): agents see a "Sign in
   with Microsoft" button. True sends them straight to Microsoft, which the
   sample file suggests once sign-in is proven, and it has been since the
   pilot. `SSO_FEDERATED_LOGOUT` false is right for personal machines.
   Also note the Entra client secret's expiry date (docs/ENTRA_SETUP.md): sign-in
   stops the day it lapses.
9. **Who gets the alerts.** check_health's address is hard-coded
   (nikki.boxer@), and ROSTER_ALERT_EMAILS / PIPELINE_NOTIFY_EMAILS are Nikki
   only. Fine for one owner; add marketing@ or JB if the team should see them.
10. **brand-guidelines noindex.** The public brand page carries an
    X-Robots-Tag noindex "while the page is being built" (brand-guidelines/
    .htaccess). Delete it when the page should be searchable.
11. **Website Order** button on the roster is hidden on purpose (the site is
    alphabetical); the page still answers by URL.

## When the public site goes live (monthaus.com)

Three lines in inc/config.php switch together: `PUBLIC_SITE_URL`,
`LISTINGS_FEED_URL`, `SITE_AGENT_SYNC_URL` (the board registry, FUB standing
and market statistics URLs derive from them). Also: the hard-coded fallback in
inc/social.php (`https://site.monthaus.com`), the placeholder in
pipeline_review.php, and the agent-page note "Until it goes live that is the
password-protected preview" (agent.php). QR destinations already point at
monthaus.com. The site's own runbook is site docs/HANDOFF-go-live.md.

## Order of operations, suggested

1. Create the nine logins on users.php (and the M365 accounts where missing).
2. Delete the Spark files and constants; fix the two sender addresses; decide
   the "coming soon" lines. Deploy, commit.
3. Set `ACCESS_ALLOWLIST` to `''` and `HOT_SHEET_ALLOWED_RECIPIENTS` to `''`
   on the server (and locally); consider `SSO_AUTO_REDIRECT` true.
4. Watch the next 13:00 UTC Hot Sheet send's tally ("N sent, 0 blocked") and
   the first few sign-ins; check_health keeps emailing if anything stalls.
