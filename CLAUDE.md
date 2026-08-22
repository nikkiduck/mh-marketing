# marketing.monthaus.com — Project Conventions

The Mont Haus marketing tool, standalone on its own subdomain.
PHP 8 / MySQL 8 on an AWS Lightsail instance (Debian, LAMP blueprint).

Replicated from `monthausint.com/marketing`, and fully independent of it.
Its own code, its own database, its own cron. Nothing is shared with the hub
except the Spark MLS API credentials — so a fix made here does not reach
monthausint.com, and a fix made there does not reach here.

## How to work on this project

**Check, don't speculate.** Read the actual file before making any claim about
what it contains or does. Don't offer theories about causes that could be
settled by reading code or running a query.

When something breaks right after a change, **suspect that change first** — not
the server, the host, or the platform. A parse error produces no output when
CLI `display_errors` is off, so "nothing happened" often means "the file is
broken," not "the cron didn't fire."

Server state and the live database are not visible from here. When the answer
depends on one of those, say so plainly and supply the single command or query
that resolves it, rather than presenting a guess as a likely cause.

## SQL — read this before writing any migration

**Never use `IF NOT EXISTS` (or `IF EXISTS`) with `ALTER TABLE`.** It is
MariaDB-only syntax; MySQL throws a syntax error. Verified still true on
MySQL 8.0 — moving off SiteGround did not change this.
This applies to `ADD COLUMN`, `DROP COLUMN`, `ADD INDEX`, and every other `ALTER TABLE`
clause.

```sql
-- WRONG — syntax error
ALTER TABLE foo ADD COLUMN IF NOT EXISTS bar VARCHAR(255) NULL;

-- RIGHT
ALTER TABLE foo ADD COLUMN bar VARCHAR(255) NULL;
```

`IF NOT EXISTS` **is** fine on `CREATE TABLE` — that's standard MySQL and works.

Practical consequences:

- Migrations are **not** idempotent. Write them to be run exactly once, and put a
  `-- STATUS: already run (date)` comment at the top after they've been applied.
- Re-running a migration errors with "Duplicate column name" — that's expected and
  harmless, not a bug to chase.
- If a script genuinely needs to be safe to re-run, check `INFORMATION_SCHEMA.COLUMNS`
  in PHP first, or just tell Nikki which statements to skip.

Migrations live in `sql/`. **This site has its own database** — nothing here
touches the hub. `sql/bootstrap.sql` is the one-time import that created it
from a hub export; everything after that is an ordinary migration.

Note that `assets_tab_v2.sql` was never run on the hub, so
`marketing_asset_links` does not exist there. `agent.php` catches the error
and logs "marketing_asset_links not available" — which is why the Assets &
Docs link list appears to do nothing on monthausint.com. It works here.

## Sign-in — Microsoft Entra ID

`inc/sso.php` implements the OIDC authorization-code flow with PKCE. It has **no
Composer dependency**: the JWKS fetch, the JWK-to-PEM conversion and the RS256
verification are all in that file, on top of ext/openssl and ext/curl.

Rules for touching it:

- **The algorithm is pinned in code.** `sso_verify_id_token()` requires
  `alg === RS256` before it looks at anything else. Never read the algorithm
  from the token and act on it — that is how `alg: none` and RS256→HS256
  forgeries get in.
- **Every claim check is load-bearing.** `iss`, `aud`, `tid`, `exp`, `nbf` and
  `nonce` are each rejecting a specific attack. `tid` in particular is what
  stops a token from another Microsoft tenant. Don't relax one to fix a
  sign-in problem; find out why the claim is wrong instead.
- **`sso_resolve_user()` never creates accounts.** An unknown Microsoft account
  is refused. Keep it that way.
- **Both sign-in paths set identical session keys.** `login.php` and
  `sso_establish_session()` populate the same eight values, which is why
  `auth.php` needed no changes. If you add a session key, add it to both.

Run the test suite after any change to `inc/sso.php` — see `TESTING.md`. It mints
forged tokens and asserts each one is rejected.

### SameSite must stay Lax

`inc/auth.php` sets `session.cookie_samesite` to **`Lax`**, not `Strict`.

This is not an oversight. Microsoft returns the browser to
`oauth_callback.php` by a cross-site top-level navigation. Under `Strict` the
browser withholds the session cookie on that request, so the callback finds no
`sso_state` and every sign-in fails with "that sign-in link has expired."

`Lax` still blocks cross-site POSTs and subresource requests. The callback is
additionally protected by the `state` parameter, which is the mechanism
actually designed for this.

Related: the authorize request uses `response_mode=query`. Do not switch it to
`form_post` — that arrives as a cross-site POST, which even `Lax` strips the
cookie from.

### Break-glass access

`SSO_PASSWORD_LOGIN_ROLES` in `inc/sso_config.php` is `super_admin`. At least one
super_admin must always keep a working password:

```sql
SELECT email FROM users WHERE role='super_admin' AND password IS NOT NULL AND password <> '';
```

If the Entra client secret expires — it does, silently, on its expiry date —
that password is the only way back in.

## Spark MLS API — v1 vs RESO OData

The agent keys stored in `office_roster.agent_key` / `vail_agent_key` are
**Spark v1 account UUIDs** (26 digits, e.g. `20090111180204477889000000`).

RESO OData's `ListAgentKey` is a *different* value. Filtering OData by a v1
account UUID returns HTTP 200 with zero results — it fails silently rather than
erroring, so it looks like "this agent has no listings."

To find listings for a stored agent key, use v1:

```
https://replication.sparkapi.com/v1/listings?_filter=(ListAgentKey Eq 'KEY' Or CoListAgentKey Eq 'KEY')&_expand=Photos
```

v1 uses capitalised operators (`Eq`, `And`, `Or`) and returns `D.Results[].StandardFields`.
OData uses lowercase (`eq`, `and`) and returns `value[]` with flat fields.

`https://replication.sparkapi.com/v1/accounts/<key>` confirms whether a value is
a valid v1 account id, and returns the agent's name, MlsId and office.

`mls_key_debug.php` tests a key against both APIs. It is denied in `.htaccess`
by default — comment that block out when you need it, and put it back after.

## Contacts

- **Nikki Boxer** — `nikki.boxer@monthaus.com`. Use this for any alert,
  notification, or default recipient in these tools. Not `nikki@monthaus.com`.
- **Jean-Michel Drai** — managing broker. Broker record email is
  `jm.drai@monthaus.com`; `tc@monthaus.com` is the Paperless Pipeline admin /
  transaction-coordination account and is not an agent.

## Server paths (Lightsail)

This site runs on its own AWS Lightsail instance in `us-west-2`.
Nothing here is SiteGround — do not carry over paths or assumptions from the hub.

Debian, Apache, PHP — the **Lightsail LAMP blueprint**, which is AWS's own
since the Bitnami blueprints were retired. Standard Debian layout; ignore any
tutorial that mentions `/opt/bitnami` or the `bitnami` user.

```
ssh user   admin
web root   /var/www/marketing.monthaus.com   (pages only)
includes   /var/www/marketing.monthaus.com/inc/   (db.php, sso_config.php)
receipts   /var/www/receipts              (RECEIPTS_DIR — outside the web root)
cron logs  /var/log/mh-marketing/
services   systemctl (apache2)
```

Deploy is over SFTP from Nova. Full setup — SSH keys, Nova config, Apache,
TLS, cron — is in `DEPLOY_LIGHTSAIL.md`.

The web root is owned `admin:www-data` with setgid directories (2775), so
files uploaded by Nova stay group-readable by Apache. If a fresh upload 403s,
check the group before reaching for chmod.

### AllowOverride must stay All in the vhost

`apache2.conf` sets `AllowOverride None` on `<Directory /var/www/>` and `All`
only on `<Directory "/var/www/html">`. Our docroot is neither, so it inherits
`None` — and `.htaccess` is then **silently ignored**, serving `db.php` and
`sso_config.php` as plain text.

`marketing.monthaus.com.conf` therefore sets `AllowOverride All` on its own
`<Directory>`, and repeats the credential denies as `<FilesMatch>` at config
level so they hold even if that line is ever lost.

After any Apache change, re-run the check before trusting it:

```bash
echo '<?php define("DB_PASS","x");' | sudo tee /var/www/marketing.monthaus.com/inc/db.php
curl -s -o /dev/null -w "%{http_code}\n" https://marketing.monthaus.com/inc/db.php
sudo rm /var/www/marketing.monthaus.com/inc/db.php
```

403 is the only acceptable answer.

### PHP version: Apache and CLI must match

The box carries PHP 5.6 through 8.5. Apache loads **8.4** (`mods-enabled/php8.4.load`)
and the CLI is pinned to 8.4 via `update-alternatives` so cron and web agree.
Install extensions as `php8.4-*`, never the bare `php-*` metapackage — that
targets Debian's default version, which is not necessarily the one running.

`curl` and `openssl` are required by `sso.php`; without them Microsoft
sign-in fails at the token exchange.

### MySQL — managed, not local

A **Lightsail managed MySQL 8.4** instance, `dbmarketing_monthaus`, reached
over the network. Not MySQL on this box.

The instance has 1 GB of RAM; Apache + PHP + MySQL together on that is where
OOM kills start. The LAMP blueprint's bundled MySQL is stopped and disabled
for the same reason — if you find it running again after a rebuild, disable it.

- Public mode is OFF. The database is reachable only from inside the VPC.
- `db.php` connects over TLS against `/etc/ssl/aws/rds-global-bundle.pem`.
  That is a confidentiality measure, not a connectivity one: PHP 8's mysqlnd
  handles 8.4's `caching_sha2_password` over an unencrypted socket perfectly
  well, so a connection with TLS accidentally dropped will look healthy while
  sending credentials in cleartext.
- The app user (`mh_app`) has `SELECT, INSERT, UPDATE, DELETE` and no DDL
  rights. Migrations run as the master user, by hand.
- Collation is `utf8mb4_0900_ai_ci` throughout — the hub was a mix of that and
  `utf8mb4_unicode_ci`, which is why `sync_roster.php` carries explicit
  `COLLATE` casts in its JOINs. Normalised here so the problem cannot recur.

### ONLY_FULL_GROUP_BY must stay off

`inc/db.php` sets `sql_mode` per connection, dropping `ONLY_FULL_GROUP_BY`:

```sql
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,
                        ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
```

Not cosmetic. The roster queries in `index.php` (line ~59) and `roster.php`
(line ~83) select non-aggregated columns alongside a `GROUP BY`. SiteGround
had the flag off, so they ran there for months; stock MySQL 8 turns it on and
**both pages 500 on the first request.** Verified by importing the schema into
a clean MySQL 8 and loading each page.

Per-connection rather than in a parameter group on purpose — the app carries
its own requirement, and a restored snapshot cannot silently undo it.

The real fix is rewriting those two queries with `ANY_VALUE()` or a correct
`GROUP BY`. Until someone does, the line stays.

### Cron

Two jobs, both pulling from the Spark API — not from the hub:

```
0  3 * * *  /usr/bin/php /var/www/marketing.monthaus.com/cron/sync_roster.php
30 3 * * *  /usr/bin/php /var/www/marketing.monthaus.com/cron/sync_mh_brokers.php
```

`sync_roster.php` also re-links `marketing_intakes.roster_id` at the end of
its run. That used to be the hub's job; it is this site's now.

## Migrations are run by hand

Nikki runs all SQL by hand — **TablePlus**, connected over an SSH tunnel
through the instance to the managed database. (There is also a phpMyAdmin on
the box at `/var/www/html/phpmyadmin`, restricted to `Require local`, but it
points at the disabled local MariaDB and is not used.) There is no migration
runner. So:

- Deliver SQL as a `.sql` file in `sql/`.
- Keep statements copy-pasteable and in the order they must run.
- **Batch large INSERTs.** A single 265-row / 32KB statement silently landed
  only 144 rows through the MariaDB client against MySQL 8.4 — no error, just
  a short table. `sql/build_bootstrap.py` now emits batches of 25. Anything
  over ~10KB in one statement deserves the same treatment.
- Call out clearly in chat when a schema change must be applied before a feature will work.

## PHP gotchas hit on this project

- **Never put a cron step like `*/1` inside a `/* */` block comment.** The `*/`
  closes the comment early, everything after it is parsed as code, and the file
  dies with a syntax error. This cost a long debugging session — it looked like
  a broken cron job because a fatal parse error produces no stdout when CLI
  `display_errors` is off, so the log stayed empty. Write cron examples in
  `//` line comments, or spell the step out in words.
- **`php -l <file>` is the fastest way to rule out syntax** when a CLI script
  produces no output at all. An empty log means the script never ran, not that
  it ran and found nothing.
- **Union return types coerce.** A function declared `: bool|string` that returns an
  `int` will have the int silently cast to string. This caused a "Database error: 12"
  bug in `intake.php` where `mkt_save()` returned a new row ID that an
  `is_string()` check then treated as an error message. Declare the full union
  (`bool|string|int`) when returning insert IDs.
- **`bind_param` type strings must match the argument count exactly.** Miscounted type
  strings have bitten this project more than once (e.g. `'ssssdsssssis'` with 11 params).
  Count them.
- Always null-guard `$conn->prepare()` before calling `bind_param` on the result.
- **`password_verify()` on a NULL hash throws a deprecation** in PHP 8.1+.
  SSO-only accounts have `password IS NULL`, so cast and check the column is
  non-empty before verifying. `login.php` already does.

## Layout / CSS

The framework CSS sets `.pc-container { position: relative; top: 74px }`. Overriding
`top` on a page leaves a phantom black gap. Use `top: 0; margin-top: 70px` instead.

The site header is `inc/_nav.php`, included by `index.php`, `agent.php` and
`intake.php`. It relies on `.mh-header-inner`, `.mh-logo`, `.mh-nav`,
`.mh-nav-link` and `.mh-nav-divider`, which those pages define in their own
inline `<style>` blocks; only the user-menu rules live in the partial. Set
`$nav_extra` before including to add a trailing crumb.

`roster.php` is the exception — it is a bare Tabler page with its own navbar
and does not use `_nav.php`. Including it there would render unstyled, because
the two pages load different CSS frameworks.

## Marketing tool structure

- `index.php` — agent roster. `office_roster` UNION ALL intake-only agents so newly
  added agents appear before they exist in the roster.
- `agent.php` — the primary editing surface. All adding/editing happens here and in its
  tabs: Overview | Tasks | Collateral | Advertising | Assets & Docs | Notes.
- `intake.php` — legacy form, largely superseded by `agent.php`.
- `_onboarding.php` — seeds the onboarding checklist task tree for a new agent. Safe to
  call repeatedly; skips if tasks already exist.
- `office_roster` is the master agent table; `marketing_intakes.roster_id` links to it.
  Saving an intake does find-or-create against `office_roster` by name.


### Limited rollout: ACCESS_ALLOWLIST

`inc/config.php` defines `ACCESS_ALLOWLIST`, a comma-separated list of emails.
`require_login()` enforces it via `require_allowlisted()`, so it covers every
protected page and both sign-in paths. Someone outside the list authenticates
successfully and then gets a 403 explaining the tool is not open yet.

It is deliberately **independent of `users.role`**. Promoting someone to admin
does not grant access while the list is set — both have to agree. That is the
point: a role change can't silently widen access during the build.

**To open the site up:** set it to an empty string, or delete the line. Empty
or undefined disables the restriction and access falls back to role checks
alone.

Currently: nikki.boxer, jonathan.boxer, jm.drai.

### Offboarding

Deactivate, do not delete. `UPDATE users SET is_active = 0, password = NULL,
entra_object_id = NULL, role = 'agent'` — see
`sql/offboard_bonnie_scott.sql` for the full form and why.

`is_active` is checked by both `login.php` and `oauth_callback.php` before any
session is created, so clearing it blocks every route in. Keeping the row
preserves `last_login`, which is the record of who had access and when.

No table has a foreign key to `users`, but `marketing_intakes.created_by` and
`marketing_notes.created_by` are soft references — check them before any hard
delete.

And the database is not the real revocation: **disable the person in Entra.**
While their Microsoft account is live they can still authenticate; this app
only stops them at the door afterwards.

### require_role() returns 403 — it must never redirect

`require_role()` in `inc/auth.php` fails with `http_response_code(403)` and a
short page. It does **not** redirect, and nothing that guards a page should.

The hub's version sent the browser to `/index.php`, which was safe there
because `/index.php` was the hub's landing page. Here the marketing app *is*
the docroot and `index.php` line 11 calls `require_role('admin')` — so the
redirect pointed straight back at the check that had just failed. Infinite
loop, no error in any log, just `ERR_TOO_MANY_REDIRECTS` in the browser and a
column of 302s in `marketing_access.log`.

It only fires for a user below `admin`, which is why it survived every test
until a real sign-in with an empty `role` column hit it.

Two things follow:

- An unrecognised or empty `users.role` gives `$user_level = -1`, below every
  threshold. A blank role locks that user out of every page. Check the column
  before debugging anything else when a user reports being unable to get in.
- The 403 page prints the signed-in email and the role it saw. That is
  deliberate — it makes this class of problem diagnose itself instead of
  looking like a session bug.

### Session guards: always empty(), never isset()

`require_login()` in `inc/auth.php` tests `empty($_SESSION['user_id'])`.
Anything else that asks "is this user signed in?" must test the same way.

An `isset()` check somewhere else produced an infinite redirect on the first
real deployment: `login.php` said "signed in, go to /index.php" while
`index.php` said "not signed in, go to /login.php", because a falsy `user_id`
satisfies `isset()` but not `!empty()`. The browser shows only
ERR_TOO_MANY_REDIRECTS; curl, carrying no cookie, follows the chain once and
looks perfectly healthy — so reproduce this class of bug **with a session**,
not with curl.

Current guards, all consistent:

```
inc/auth.php:33     if (empty($_SESSION['user_id']))
login.php:21        if (!empty($_SESSION['user_id']))
oauth_start.php:22  if (!empty($_SESSION['user_id']))
```

## Directory layout

The docroot holds **only pages** — files that correspond to a URL. Everything
else lives in a subdirectory that both `.htaccess` and the vhost deny:

```
inc/     include-only PHP. db.php and sso_config.php live here, holding the
         database password and the Entra client secret.
sql/     migrations       tests/   the sign-in test suite
cron/    CLI-only         vendor/  PHPMailer
deploy/  vhost + samples  docs/    markdown
```

Two consequences worth remembering:

- Pages include with `__DIR__ . '/inc/x.php'`; files inside `inc/` include
  their siblings bare, as `__DIR__ . '/x.php'`.
- `RECEIPTS_DIR` in `inc/config.php` is `__DIR__ . '/../../receipts/'` —
  **two** levels up, because config.php sits one deeper than the docroot.
  One level would put receipt uploads inside the web root.

If you add a new include-only file, put it in `inc/`. If you add a page, it
goes at the docroot and is reachable — check whether it should be.
