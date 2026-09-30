# One agent roster: build plan

**Status:** Anyprop sync, import and feed BUILT 2026-09-21 (reordered so the
trial can be exercised before it ends Thu 2026-09-24). Editor + headshots not
started. See "Runbook" at the bottom.
**Written:** 2026-09-21
**Source:** `docs/HANDOFF-public-site-agent-integration.md`, plus Nikki's answers the same day.

Goal: every agent-facing fact (name, bio, headshots, contact, socials, MLS
identities, website approval, FUB participation) is edited in one place, this
portal, and flows one way to monthaus.com through a feed.

## Decisions (Nikki, 2026-09-21)

- **One agent table.** `marketing_intakes` becomes the roster. The name no longer
  fits, and that is accepted; renaming it would touch every query in the app for
  no functional gain. Hot Sheets fields will land on it later too.
- **Multiple boards per agent, and team IDs shared by several agents.** Jonathan
  Boxer will eventually have an identity on every board.
- **Bio:** the full formatted bio (`bio_text`) is the only bio. `bio_short` leaves
  the UI. The column is kept (not dropped) so nothing is lost.
- **Marketing wins** on the one-time import from the public site where both have
  a value. Only one site agent has a bio today.
- **Anyprop production feed** (about two weeks out) carries Aspen, Vail, CREN,
  REColorado, Elevate and Altitude. The trial (Aspen + CREN) ends Thu 2026-09-24.
- GD is installed on both servers.

## No downtime, no replica

The site does not need to be replicated or taken offline:

- Phases 1 and 3 (agent editor, headshots, feed) do not touch the MLS at all.
- Phase 2 is a **new** cron file. It is built and tested against saved Anyprop
  payloads with `--dry-run`, and goes into crontab only when production
  credentials arrive. The Spark crons keep running until that day, then come out.
- If Spark stops answering before then, the damage is bounded: nightly
  name/email refresh stops and the agent page's Listings grid goes empty.
  Tasks, Collateral, Advertising, Billing and the new editor are unaffected.

### Before Thursday

Save the trial's Member and Office payloads so Phase 2 can be developed after
the trial ends. On the site server:

    php /var/www/site/probe_anyprop_extras.php --only=offices,members

then copy `logs/anyprop_members.json` and `logs/anyprop_offices.json` into this
project at `tests/fixtures/`. They are real MLS field shapes, which is the only
thing the sync needs to be written against.

## Schema: `sql/agent_roster_v1.sql`

Run once, by hand, as the master user. No `IF NOT EXISTS` on any ALTER.

On `marketing_intakes`:

| Column | Purpose | Site equivalent |
|---|---|---|
| `slug` VARCHAR(60) UNIQUE NULL | DNS-safe key, headshot filenames, feed `key` | `agents.slug` (copied as-is so public URLs hold) |
| `web_status` ENUM(pending, approved, inactive) | shown on monthaus.com or not | `agents.status` |
| `web_approved_at` DATETIME | | `approved_at` |
| `sort_order` INT | | same |
| `in_fub` TINYINT | FUB lead rotation | same |
| `office` VARCHAR(40) | aspen / vail / colorado-springs / other | `agent_offices` (single-select there too) |
| `service_area` VARCHAR(255) | shown when office = other | same |
| `headshot_face_url` VARCHAR(512) | 400x400 face crop | `headshot_thumb_url` |
| `mls_full_name`, `mls_email`, `mls_phone` | MLS mirror, written only by the sync | `mls_*` |
| `departure_detected_at` DATETIME | set when no active identity remains | same |

`web_status` is separate from the existing `status` (pending / active /
archived). One answers "is marketing onboarding done", the other "does this
person appear on the website". They change for different reasons.

Existing columns that map directly: `agent_name` (display name), `agent_title`,
`bio_text`, `mh_email`, `cell_phone`, `social_*`, `website_url`, `headshot_url`.

New table `agent_mls_ids`:

    intake_id, market, mls_agent_id, agent_key, member_status, is_alias, last_seen_at
    UNIQUE (market, mls_agent_id, intake_id)

Seeded from `marketing_agent_mls_ids` (board -> market, spark_key -> agent_key).
The old table stays until nothing reads it.

## Phase 1: editor and headshots

- A **Website** card on the agent page's Overview tab: website status buttons
  (Approve / Deactivate), FUB participant, sort order, office, service area,
  slug, both photo uploads with the crop tool, board identities (add / alias /
  remove). Not an eighth tab: the 860px tab breakpoint was measured for seven.
- Contact, socials and bio stay where they already are on that page.
- **Headshots live outside the web root** at `/var/www/agent-photos`, like
  receipts, so a deploy from Nova can never overwrite or remove them. A small
  public page, `photo.php?s={slug}&v=`, serves them with no sign-in: it only
  ever serves `{slug}.jpg` / `{slug}-face.jpg` from that one directory.
- GD pipeline ported from the site: 1000px long edge; 400x400 face, from the
  crop tool or a top-biased auto-crop. `?v=` changes on every write.
- Roster (`index.php`): a filter for web status, so pending agents are findable.

## Phase 1b: one-time import from the public site

- `export_agents_for_marketing.php`, run once on the **site** server, writes one
  JSON file: agents, identities, office, and both images base64-embedded (so no
  fetch against the site's password gate is needed).
- `cron/import_site_agents.php`, run once here, matches each agent to a
  `marketing_intakes` row by MLS ID, then email, then name. Fills only blank
  fields (marketing wins), always takes slug, web status, sort order, in_fub,
  office and identities. Unmatched agents become new rows. `--dry-run` prints
  every decision first.

## Phase 3: the feed

`api/roster.php`, shape exactly as in the handoff. Public page, no sign-in and
no allowlist; a token in the `Authorization: Bearer` header, compared with
`hash_equals()`, constant `ROSTER_FEED_TOKEN` in `inc/db.php`. Includes
inactive agents. Only hosted headshots are published; a legacy Dropbox link in
`headshot_url` is left out rather than shipped as an image URL.

## Phase 2: Anyprop roster sync

`cron/sync_anyprop_roster.php`, the site's `sync_roster.php` logic writing to
this schema: known identity refreshes `mls_*`; unknown identity attaches by
email; still unknown creates a row (`web_status` pending); an identity missing
from the feed goes inactive, and an agent with none left is deactivated.
Curated fields are never written. Aliases get a heartbeat only.

Then `office_roster` retires: the roster reads `marketing_intakes` alone, and
the UNION in `index.php` goes away. Agents known only from the MLS get
`status = 'roster'`, which renders as today's pale-grey no-intake card.
`mh_brokers`, `intake.php`'s Spark lookup, `mls_key_debug.php` and the
agent page's Spark Listings grid are replaced or removed in the same pass.

## Order (revised 2026-09-21)

Nikki wants to see Anyprop agents land in this database and reach the website
while the trial still runs, so the sync moved first:

1. **Built:** `agent_roster_v1.sql`, `cron/sync_anyprop_roster.php`,
   `cron/import_site_agents.php` (+ the site's `export_agents_for_marketing.php`),
   `api/roster.php`, and on the site `sync_agents_from_marketing.php`.
   `index.php` / `roster.php` understand `status = 'roster'` ("Create Intake"
   promotes the same row) and show a badge for every board identity.
2. Next: the Website card on the agent page, headshot pipeline + `photo.php`.
3. When production credentials arrive: sync into crontab, Spark crons out,
   then `office_roster` / `mh_brokers` retire.

## Runbook

Order matters: the import must run before the website reads the feed, or every
agent the site has approved arrives as `pending`.

On marketing:

1. Run `sql/agent_roster_v1.sql` in TablePlus (master user). Mark it run.
2. Deploy `inc/agent_roster.php`, `cron/sync_anyprop_roster.php`,
   `cron/import_site_agents.php`, `api/roster.php`, `index.php`, `roster.php`.
3. Add to `inc/db.php`: `ANYPROP_USERNAME`, `ANYPROP_PASSWORD`,
   `ROSTER_FEED_TOKEN`, `ANYPROP_MH_OFFICE_IDS`, `ANYPROP_MH_MEMBER_IDS`
   (see `deploy/db.sample.php`). Aspen's office is 805522330 (probe,
   2026-09-21). CREN's office never matched a name search, so CREN is found
   through two known agents (13985 Jonathan Boxer, 13986 Jackson Horn), whose
   own records name the office; the rest of that office is then pulled.

On the site server:

4. `php export_agents_for_marketing.php > /tmp/site_agents.json`, copy the file
   to the marketing server.

On marketing:

5. `php cron/import_site_agents.php /tmp/site_agents.json --dry-run`, read it, then without `--dry-run`.
6. `php cron/sync_anyprop_roster.php --dry-run --save=/tmp/anyprop_members.json`,
   read it, then without `--dry-run`. Keep the saved file: it is the fixture
   for testing after the trial ends (`--fixture=`).
7. `curl -s -H "Authorization: Bearer $TOKEN" https://marketing.monthaus.com/api/roster.php | head -c 2000`

On the site server:

8. Add `MARKETING_FEED_URL` / `MARKETING_FEED_TOKEN` to `config.php`.
9. Take `sync_roster.php` out of crontab (it would fight over agent_mls_ids).
10. `php sync_agents_from_marketing.php --dry-run`, read it, then live.
11. Add `AGENT_SYNC_TOKEN` to both `config.php` (site) and `inc/db.php`
    (marketing), same value, 24+ characters. That turns on "Sync to Website"
    on the roster: it posts to the site's `webroot/api/sync_agents.php`, which
    runs step 10 there and then and hands the log back
    (`inc/site_sync.php`). Nothing about the nightly cron changes.

Re-running 5, 6 and 10 is safe; a second run of each reports no changes.

### Verified in a scratch MySQL 8.0 (2026-09-21)

bootstrap + every run migration + v1; import from a 5-agent site export;
sync against a fixture and against a local stand-in for the Anyprop API
(token, Office discovery, case-sensitive OSN, @odata.nextLink paging, bad
credentials → exit 1 and nothing written); departure (identity dropped →
Inactive → web_status inactive; seeded never-seen identity left alone); feed
401 without / with wrong / with ?token=; site consumer created, updated,
second run 0 changes, existing site headshot kept when the feed has none;
index.php renders each person once, promote seeds tasks on the same row.

## Teams (added 2026-09-22)

Weber Boxer Group advertises and is billed as one entity, but on the website
and in the MLS its people are individuals: Jonathan Boxer, Scott Weber, Sara
Perkowski. So a team is a `marketing_intakes` row with `entity_type = 'team'`
(sql/agent_roster_v2_teams.sql), with its people in `team_members`.

- The team row keeps campaigns, collateral, tasks, notes and billing.
- It is never in the website feed, never matched to a person by email or name
  (Anyprop sync, site import, Pipeline), never credited on a listing, never
  given a slug. All of that goes through `mk_team_sql()` in inc/agent_roster.php.
- Members are ordinary agent rows with their own profiles. The team MLS ID
  (aspen WebBoxGrp) is an alias identity only on the people who should be
  credited for team listings (split_team.php --credit). For WB that is
  Jonathan Boxer and Scott Weber (WB sales). Sara Perkowski is on the team as
  an assistant: she is a member but does not get the team ID. Her rentals are
  listed in the MLS under her own ID with Jon as co-list, and are credited
  exactly as the MLS has them. (Nikki, 2026-09-22)
- The team's initials are WB everywhere (the `initials` override column,
  set by split_team.php --initials).
- The old WBG row carried Jonathan's email, so his identities, slug and
  website profile had landed on the team. cron/split_team.php moves them to
  his own row (tested on a copy: identities, slug, FUB, subscription moved;
  feed lists Jonathan, Scott, Sara and not the team; WebBoxGrp listings credit Jonathan and Scott only).
- Roster: teams show a "Team · N" tag and file under their name; members show
  the team's initials (WB).
- Onboard now also assigns the website slug right away.
- Still to build (agent page rebuild): adding/removing team members in the UI.

## Active = with Mont Haus in the MLS (2026-09-22)

Nikki: the roster is the main agent management tool, so Active means "with
Mont Haus", not "onboarded by marketing".

- **Active**: the MLS lists them under a Mont Haus office. The Anyprop sync
  adds a new agent as Active by itself, with the marketing checklist
  (mk_onboard). No automatic Hot Sheet subscription: the checklist item
  "Hot Sheets: Subscribe" subscribes them when ticked (un-ticking stops the
  emails), after Nikki explains the Hot Sheets in the marketing meeting. Their website profile waits for
  approval (web_status 'pending'). New Agent still adds someone by hand.
- **Onboarding**: only the marketing checklist. The Onboarding tab lists Active
  agents with open checklist items; the row shows checklist progress (6/10).
  The row's "Mark checklist complete" button ticks everything open except
  "Hot Sheets: Subscribe", which it ticks only if they already get them.
- **Inactive**: the MLS stopped listing them under Mont Haus (no active
  personal identity left; departure_detected_at set). Red dot on the tab, red
  chip at the top, and an email to ROSTER_ALERT_EMAILS. Nothing is turned off
  automatically: Offboard (website off, FUB off, Hot Sheets off, archived) or
  "Still with MH" (clears the flag; the sync does not flag them again for the
  same missing identity). If the MLS lists them again they are Active again
  (emailed as "back").
- **Archived**: offboarded.
- The old statuses 'roster' and 'pending' mean Active. cron/activate_roster.php
  converts them once, and brings Spark-era office_roster people with no row in
  as Active. Run it nightly after sync_roster.php until Anyprop covers Vail.

- Inactive by hand (2026-09-22): clicking a row's Active badge marks them
  Inactive (status 'inactive'), for people the MLS lists under Mont Haus who
  are not active brokers (the TC, someone the COO still has to remove).
  Clicking Inactive makes them Active again. No red dot or email for these,
  and the website, Hot Sheets and FUB are untouched (Offboard does that).

## Bulk profiles (cron/import_profiles.php, 2026-09-22)

Headshots, bios and contact details in one pass, so a new portal does not need
20 trips through the agent page.

- `import/headshots/`: JPG, PNG or WEBP named after the agent (their name,
  email, or website key; punctuation and case ignored). Each becomes
  `agent-photos/<slug>.jpg` (long side 1400) and `<slug>-square.jpg` (600px,
  cropped from the top middle), both served from this portal, so the feed
  publishes them and the website pulls its own copies.
- `import/bios/`: .txt (blank line = new paragraph), .html or .md, named the
  same way. Run through mk_clean_bio().
- `import/profiles.csv`: headings row; one column identifies the agent (email,
  slug or name), the rest are optional: phone, title, bio, instagram,
  facebook, linkedin, tiktok, website, service area, office.
- `--create`: a spreadsheet row whose name matches nobody is added to the
  roster as an Active agent with the checklist, instead of being skipped. With
  "board" and "mls id" columns the identity is recorded too (last_seen_at NULL,
  so the departure sweep never touches it), which is how a Denver agent the
  Anyprop trial does not cover gets in ahead of the feed. When the feed does
  reach them, the sync matches that identity rather than creating a second row.
- `--from-mls` fills blank contact fields from the MLS mirror.
- Fills only what is blank unless `--overwrite`; anything matching nobody or
  two people is listed and skipped.

## One admin, not two (2026-09-23)

Nikki: one place for everything. Marketing owns agents; the site admin's agent
editor was a trap, since the feed overwrote whatever was typed there on the
next sync.

- Marketing's agent page gained a Profile tab with everything the site admin
  could do: website status and approval, web address, sort order, office,
  service area, FUB, headshot upload (with the square crop), bio, board
  identities and the Hot Sheet subscription.
- The site admin's agent page is read-only and links back with
  ?slug=<slug>, so no row ids cross between the two systems.
- Still to do: restyle the marketing portal in the site admin's look
  (Cormorant Garamond headings, Jost body, ivory page, white cards, gold
  accents, square corners), page by page.
