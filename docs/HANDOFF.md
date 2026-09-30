# HANDOFF: marketing.monthaus.com, moving to Claude Code

Written 2026-09-30 at the point the project moves from the Claude app (Cowork
Project "marketing.monthaus.com") to Claude Code. **Revised 2026-09-30 15:10
MT** after checking the working folder: the first version missed the work done
between Sep 28 evening and Sep 30 19:34 (both roster UI requests were built, the
agent stepper was added, and the pre-commit security fixes were already made).

Read this once at the start of the first Claude Code session. After that,
`CLAUDE.md` is the standing reference and this file can be archived into `docs/`.

**Read order for a new session:** this file, then `CLAUDE.md` (all of it; it is
long because every section records something that actually broke), then
`README.md`. Open the plan docs in `docs/` only when working in that area.

---

## 1. What this project is

The Mont Haus internal marketing tool, running standalone at
**https://marketing.monthaus.com**. It was replicated from
`/Users/nikkiduck/Documents/Weber Boxer Group/Mont Haus/monthausint.com/marketing`
(the "hub") and made fully independent: own code, own database, own cron, own
sign-in (Microsoft Entra ID). A fix here does not reach the hub and vice versa.

It has since grown well past the replication. It is now the system of record for:

- the **agent roster** (one table, `marketing_intakes`, for marketing and the
  public website) with Anyprop MLS sync, a JSON feed to site.monthaus.com, and
  a drag-and-drop **Website Order** page
- per-agent **marketing work**: onboarding checklist, collateral orders,
  advertising campaigns, assets and docs, notes, headshots and bios
- **billing / collections** and **splitting one vendor invoice across agents**
- **Hot Sheets** (listing change emails) and the **Paperless Pipeline** webhook
  (in progress, moving here from the hub)
- **Dynamic QR codes** served from qr.monthaus.com (built 2026-09-28, not live yet)

Owner and only operator: **Nikki Boxer** (`nikki.boxer@monthaus.com`). Nikki
runs all SQL and all server commands herself.

## 2. Stack and where things live

| | |
|---|---|
| Local project folder | `/Users/nikkiduck/Mont Haus Master/marketing.monthaus.com` on the Mac that holds it; other machines reach the same folder over SMB as `/Volumes/nikkiduck/Mont Haus Master/marketing.monthaus.com`. One folder, two paths. |
| Original hub source | `/Users/nikkiduck/Documents/Weber Boxer Group/Mont Haus/monthausint.com/` (reference only, never edit) |
| Language | PHP 8.4, plain PHP pages, mysqli, no framework, no build step |
| Server | AWS Lightsail, `us-west-2`, Debian/Ubuntu LAMP blueprint, static IP 35.161.76.244, ssh user `admin` |
| Web root | `/var/www/marketing.monthaus.com` |
| Vhost | `/etc/apache2/sites-available/marketing.monthaus.com.conf` (source of truth: `deploy/marketing.monthaus.com.conf`) |
| Receipts (outside web root) | `/var/www/receipts` |
| Cron logs | `/var/log/mh-marketing/` |
| Apache error log | `/var/log/apache2/error.log` |
| Database | Lightsail managed MySQL 8.4, `dbmarketing_monthaus`, VPC only, TLS, app user `mh_app` (no DDL) |
| SQL client | TablePlus over an SSH tunnel through the instance |
| Deploy | Panic **Nova** over SFTP (publishing config `.nova/Publishing/MH Marketing.json`), file by file. No CI, no git deploy |
| DNS | AWS DNS zone for monthaus.com |
| Sign-in | Microsoft Entra ID (OIDC + PKCE, `inc/sso.php`); super_admin keeps a break-glass password |
| Email | PHPMailer (committed in `vendor/`, no Composer on the server) |
| MLS | Spark API (legacy crons) and Anyprop / RESO (new roster sync) |
| Related sites | `site.monthaus.com` (new public site, separate project), `monthausint.com` (hub, still running) |

Secrets live only on the server in `inc/db.php` and `inc/sso_config.php`
(both gitignored; samples in `deploy/`). Claude Code cannot see the server or
the live database. Anything that depends on them must be answered by giving
Nikki one command or query to run, not by guessing.

## 3. How Nikki wants to work (carry these over)

These were set in the Claude app and will not transfer on their own:

1. **Server commands with full absolute paths**, so each one can be pasted
   straight into the Lightsail browser console. Never `cd` then a relative path.
2. **No em dashes in written copy** (anything user-facing or that she will paste
   somewhere). Code comments and docs already in the repo can stay as they are.
3. **SQL is delivered as a file in `sql/`** with a `-- STATUS: not yet run`
   header. Nikki changes it to `run` after running it in TablePlus. Never
   rewrite a file she has stamped `run`; write the next-numbered file instead.
4. **Check, don't speculate.** Read the file before making claims; when
   something breaks right after a change, suspect the change first.
5. Say plainly, in the reply, when a migration must run before a feature works,
   and list exactly which files need uploading for a change.
6. Alerts and default recipients go to `nikki.boxer@monthaus.com`, never
   `nikki@monthaus.com`.

## 4. State of the code right now (checked 2026-09-30 15:10)

### Git is far behind the working folder

One branch, `main`. Only two commits: the original replication (`ea67a36`) and
one "updates" commit (`82dc871`). Everything since, roughly Sept 21 to Sept 30,
is uncommitted:

- **Modified (11):** `.gitignore`, `.htaccess`, `CLAUDE.md`, `agent.php`,
  `index.php`, `roster.php`, `inc/_nav.php`, `inc/_onboarding.php`,
  `inc/config.php`, `deploy/db.sample.php`, `deploy/marketing.monthaus.com.conf`
- **Untracked:** the whole roster/Anyprop system, Hot Sheets, Pipeline, QR
  codes, `website_order.php`, `api/`, ten new crons, six new migrations,
  `import/`, `assets/icc/`, `assets/hotsheet/`, `assets/css/mh-theme.css`,
  `deploy/manifest.md5`, `deploy/qr.monthaus.com.conf`, the plan docs, this
  file, and three screenshots in `Claude outputs/` (add that folder to
  `.gitignore` rather than committing them)

Suggested first task: review and commit in logical chunks (security/deploy
config, roster + Website Order + stepper, hot sheets, pipeline, QR, docs) so
there is a baseline to diff against.

### Pre-commit security fixes: DONE locally (2026-09-30 19:33), server side still open

The first version of this file listed these as to-do. They were done in the
folder minutes after it was written. Verify, don't redo:

1. **`AGENT_SYNC_TOKEN` is out of `inc/config.php`.** It is now expected in
   `inc/db.php` (placeholder in `deploy/db.sample.php`, alongside
   `ROSTER_FEED_TOKEN`, `LISTINGS_FEED_TOKEN`, `PIPELINE_WEBHOOK_SECRET` and the
   Anyprop credentials). `grep -rn "TOKEN\|SECRET\|API_KEY" inc/config.php`
   finds only comments. The token was never in git history
   (`git log -S AGENT_SYNC_TOKEN` is empty), so no rotation is needed for git.
2. **`hot_sheets_export.json` is out of the folder** and gitignored; the import
   reads it from `/home/admin/hot_sheets_export.json`.
3. **`import/` is denied** in both `.htaccess` (RedirectMatch) and
   `deploy/marketing.monthaus.com.conf` (`<DirectoryMatch>`).

**What Nikki still has to do on the server, in this order** (the order
matters for step 1):

1. Before uploading the new `inc/config.php`, make sure the server's
   `/var/www/marketing.monthaus.com/inc/db.php` defines `AGENT_SYNC_TOKEN`
   (same value as the site's config). Check with:
   `grep -c AGENT_SYNC_TOKEN /var/www/marketing.monthaus.com/inc/db.php`
   A `0` means add it first; otherwise "Sync to Website" stops working the
   moment the new `config.php` lands (it shows an error, not a fatal).
2. Upload `inc/config.php` and `.htaccess`.
3. Update the vhost from the uploaded `deploy/` copy and reload:
   `sudo cp /var/www/marketing.monthaus.com/deploy/marketing.monthaus.com.conf /etc/apache2/sites-available/marketing.monthaus.com.conf && sudo /usr/sbin/apache2ctl configtest && sudo systemctl reload apache2`
4. Remove any docroot copy of the export (harmless if absent):
   `sudo rm -f /var/www/marketing.monthaus.com/hot_sheets_export.json`
5. Confirm `https://marketing.monthaus.com/import/profiles.csv` returns 404.

### Housekeeping in the folder

- `MH Marketing/` holds only a stray `.nova` folder. Safe to ignore; do not upload.
- `Claude outputs/` holds screenshots from earlier sessions. Not part of the site.
- `brand-guidelines/` is a separate static page (own `.htaccess`) with a
  `_prefill-backup/`. Leave it alone unless asked.
- `.DS_Store` files are gitignored already.

## 5. Migration status (verify, do not trust)

From the `STATUS:` headers today (`grep -m1 -i -- '-- *STATUS:' sql/*.sql`):

| File | Header says | Creates |
|---|---|---|
| `agent_roster_v1.sql` | not yet run | `agent_mls_ids`, columns on `marketing_intakes` (incl. `web_status`, `sort_order`) |
| `agent_roster_v2_teams.sql` | not yet run | `team_members`, `entity_type` |
| `hot_sheets_v1.sql` | not yet run | `hs_listing_state`, `hs_listing_changes` |
| `hot_sheets_v2.sql` | not yet run | `hs_subscribers`, `hs_sends`, `hs_manual_listings`, `hs_manual_listing_changes` |
| `hot_sheets_v3_pipeline.sql` | not yet run | `hs_pipeline_events`, `hs_pipeline_transactions` |
| `qr_codes_v1.sql` | not yet run | `qr_codes`, `qr_code_changes`, `qr_scans_daily` |
| everything else | run or superseded | |

**These headers are probably stale.** `CLAUDE.md` describes the roster as
rebuilt and in use since 2026-09-21 with teams and Anyprop sync, which needs
the roster migrations, and `website_order.php` only shows anything when
`marketing_intakes.web_status` exists. Before telling Nikki to run anything,
have her run this in TablePlus and update the headers from the result:

```sql
SELECT table_name FROM information_schema.tables
WHERE table_schema = 'dbmarketing_monthaus'
  AND table_name IN ('agent_mls_ids','team_members','hs_listing_state',
    'hs_listing_changes','hs_subscribers','hs_sends','hs_manual_listings',
    'hs_manual_listing_changes','hs_pipeline_events','hs_pipeline_transactions',
    'qr_codes','qr_code_changes','qr_scans_daily')
ORDER BY table_name;
```

One conflicting note to resolve at the same time: an earlier chat recorded that
"the engine on the instance requires `utf8mb4_unicode_ci`", while `CLAUDE.md`
and every migration use `utf8mb4_0900_ai_ci` on managed MySQL 8.4. That remark
likely referred to the disabled local MariaDB. Confirm with `SELECT VERSION();`
in TablePlus. If it says 8.4, the migrations are right as written.

## 6. Cron: the documented list is out of date

`CLAUDE.md` (Server paths > Cron) lists two jobs. There are now **twelve**
scripts in `cron/`. Ask Nikki for the live list before changing anything:

```bash
sudo crontab -u admin -l; sudo crontab -l; ls -la /etc/cron.d/
```

| Script | Kind | Notes |
|---|---|---|
| `sync_roster.php` | nightly (documented) | Spark, legacy `office_roster` |
| `sync_mh_brokers.php` | nightly (documented) | Spark, legacy |
| `sync_anyprop_roster.php` | should be scheduled | the real roster sync; writes `mls_*` only |
| `sync_hot_sheet_listings.php` | should be scheduled | listing change log from site.monthaus.com feed |
| `send_hot_sheet.php` | should be scheduled | emails; locked to Nikki by `HOT_SHEET_ALLOWED_RECIPIENTS` until go-live |
| `parse_pipeline_events.php` | should be scheduled | Paperless Pipeline webhook queue |
| `import_hot_sheets.php` | one-off / until go-live | mirrors the hub's review queue; reads `/home/admin/hot_sheets_export.json` |
| `import_profiles.php`, `import_site_agents.php`, `merge_agents.php`, `split_team.php`, `activate_roster.php` | one-off tools | see `docs/AGENT_ROSTER_PLAN.md` |

Once confirmed, update the Cron section of `CLAUDE.md`.

## 7. Work in flight and open requests

Newest first.

1. **Leadership page + non-agent staff** (`docs/HANDOFF-leadership-and-staff.md`,
   written 2026-09-29 by the site.monthaus.com session). Not started here
   (no `sql/leadership_v1.sql` yet). Phases 1 to 3 are this project:
   `sql/leadership_v1.sql` (`entity_type` gains `'staff'`, `leadership_show`,
   `leadership_sort`), the staff audit of every agent-only query
   (`mk_team_sql()` callers), staff add form and leadership controls, and a new
   top-level `leadership` array in `api/roster.php`. The audit is the part that
   matters. Note that `website_order.php` and the agent stepper are new
   `mk_team_sql()` callers and belong in that audit.
2. **Agent roster UI requests (asked 2026-09-28): BUILT 2026-09-29, not yet
   documented in `CLAUDE.md`.**
   - **Line breaks in titles.** The title on `agent.php` is now a 2-row
     textarea; a newline means "break the broker card here". Saving normalises
     `\r\n`, trims around breaks and collapses blank lines (`agent.php` ~line
     838). The profile shows it with `nl2br`. `api/roster.php` passes the
     newline through as-is, so the public card breaks only if
     site.monthaus.com renders `title` with `nl2br` (separate project;
     confirm there, e.g. Steve Harriage's card).
   - **Drag-and-drop website order.** New page `website_order.php` (admin
     only), linked from the roster as "Website Order". Shows approved,
     active, non-team agents four across like the public grid; drag to
     reorder (hand-written HTML5 drag, no library, on purpose) with arrow
     buttons for touch screens. Save writes `sort_order` 1..n in one
     transaction, ignoring ids not currently on the site, then offers "Sync to
     Website". The per-agent `sort_order` box on `agent.php` still exists.
   - To do: add both to `CLAUDE.md` (roster section), and confirm with Nikki
     that `website_order.php`, `agent.php` and `index.php` are uploaded (see
     section 8, manifest check).
3. **Stepping between agents (built 2026-09-29, documented in `CLAUDE.md`).**
   Prev/next chevrons, a dropdown and "7 of 26" on `agent.php`; Alt+arrow keys.
4. **Dynamic QR codes go-live** (built 2026-09-28). Remaining, all on Nikki's
   side: DNS A record `qr` to 35.161.76.244, run `sql/qr_codes_v1.sql`, upload,
   then the one-time server steps in `CLAUDE.md` > Dynamic QR codes > Server
   setup. QR destinations are pages on monthaus.com, not this site. Most
   brokers will have one code for yard signs and open houses; some will have
   several.
5. **Hot Sheets and Paperless Pipeline migration from the hub** (in progress,
   `docs/HOT_SHEETS_PLAN.md`). Go-live means: widen or clear
   `HOT_SHEET_ALLOWED_RECIPIENTS`, stop the hub's copy, schedule the crons.
6. **Public site go-live.** `PUBLIC_SITE_URL` and `LISTINGS_FEED_URL` point at
   `site.monthaus.com`; at go-live they become monthaus.com. QR `profile` codes
   follow automatically. `site.monthaus.com/admin/agent.php` is read-only by
   design; this portal is where agents are edited. Background in
   `docs/HANDOFF-public-site-agent-integration.md`.
7. **Limited rollout.** `ACCESS_ALLOWLIST` currently lets in nikki.boxer,
   jonathan.boxer, jm.drai and **mary.lappe** (`CLAUDE.md` > Limited rollout
   still says three; update it). Opening the tool up means emptying that
   constant.
8. **Agent portal** (`docs/AGENT_PORTAL_PLAN.md`): proposal only, nothing
   built. Phase 0 ("close the holes") must come before any agent gets an account.
9. **Known debt:** the `GROUP BY` queries in `index.php` and `roster.php` that
   need `ONLY_FULL_GROUP_BY` off. Rewriting them with `ANY_VALUE()` would let
   the `sql_mode` line in `db.php` go.

## 8. Testing and deploying from Claude Code

- Offline tests (need local PHP 8 with openssl; `brew install php` if missing):
  ```bash
  php tests/test_sso.php
  php tests/test_campaign_billing.php
  php tests/test_invoice_split.php
  php tests/render_billing.php
  php tests/render_order_split.php
  php tests/render_users.php
  ```
  Exit code 0 is a pass. Also `php -l` every PHP file you touch.
- `deploy/manifest.md5` (67 entries, includes `website_order.php`) matches the
  local folder exactly as of 2026-09-30 15:10, including the 19:33
  `config.php`. Its header still says "Generated 2026-09-29 03:08"; fix the
  date next time it is regenerated. It does not cover `.htaccess` or the vhost.
- After a change, list the exact files Nikki must upload. Then regenerate
  `deploy/manifest.md5`, have her upload it to `/home/admin/manifest.md5`,
  and give her the check:
  ```bash
  cd /var/www/marketing.monthaus.com && md5sum -c /home/admin/manifest.md5 2>&1 | grep -v ': OK$'
  ```
  Silence means the server matches (see `CLAUDE.md` > Deploying files).
  **Run this once before the first Claude Code change** to learn what from
  Sep 28 to 30 is still waiting to be uploaded.
  A half-deploy shows up as JavaScript that "stops working"; check where the
  HTML ends and the Apache error log before touching JS.
- File modes on the server: dirs `2775`, files `0664`, `inc/db.php` and
  `inc/sso_config.php` `0660`, `inc/config.php` `0640` with group `www-data`.
  Never suggest `chown www-data:www-data` on a file (it breaks saving from Nova).

## 9. First prompt to paste into Claude Code

> Read HANDOFF.md, then CLAUDE.md and README.md in full. Don't change any code
> yet. Then: (1) verify the pre-commit security fixes in section 4 are in place
> and tell me if anything is missing, (2) add website_order.php and the title
> line breaks to CLAUDE.md, fix the allowlist line and the cron section note,
> and add "Claude outputs/" to .gitignore, (3) propose a set of logical commits
> for the uncommitted work and wait for my OK. Give me the migration check
> query from section 5 and the server steps from section 4 so I can run them.
