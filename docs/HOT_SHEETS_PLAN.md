# Hot Sheets in marketing.monthaus.com: plan

**Status:** listing pipeline BUILT 2026-09-21 (site feed + final-status
tracking on site.monthaus.com; `hot_sheets_v1.sql` + `cron/sync_hot_sheet_listings.php`
here). Emails, subscribers and the hub import BUILT the same day
(`hot_sheets_v2.sql`, send cron, preview, unsubscribe). Paperless Pipeline
BUILT 2026-09-21 (`hot_sheets_v3_pipeline.sql`); Zapier still points at the hub.
**Written:** 2026-09-21.
**Source system:** `monthausint.com/hot-sheets` (about 6,500 lines of PHP), which
keeps running on Spark until the new site is fully live.

## Decisions (Nikki, 2026-09-21)

- **Listings come from the public site, not from a second MLS sync.**
  site.monthaus.com is the only system that pulls listings from Anyprop and
  serves them to this portal over a token-protected feed, the mirror image of
  the agent feed going the other way. One Anyprop consumer, one quota.
- **Anyprop only.** Constellation was a trial and is finished. Once the boards
  are approved, one Anyprop feed carries all six: Aspen (agsmls), Vail, CREN,
  REColorado, Elevate, Altitude.
- **Listing links go to `https://site.monthaus.com/listing.php?src=ap&m={market}&k={listing_key}`**
  until the site replaces the Lofty site at monthaus.com. One constant
  (`LISTING_URL_BASE`), so the switch is a config change. Lofty goes away.
- **The hub's Hot Sheets keeps running** until the new site is live. The new
  system runs alongside it, sending only to Nikki, until the two agree.

## What exists today (read 2026-09-21)

- `sync_listings.php` (cron, 4h): Spark, Mont Haus listings by office. Diffs
  against the last pull and logs new / status / price rows to `listing_changes`.
  Listings that drop out are re-checked for Closed.
- `send_hot_sheet.php` (cron, 13:00 UTC): SendGrid. One content for everybody;
  `subscribers.frequency` daily/weekly only decides whether today is a send day.
  A sales email and a rentals email. Saved filtered sheets query Spark live.
- Paperless Pipeline: Zapier → `pipeline_webhook.php` → `pipeline_events` →
  hourly parser → `pipeline_transactions` review queue → admin promotes in
  `pipeline_review.php` → `manual_listings` (pocket listings, buyer reps).
- Agents: `mh_brokers` + `mh_broker_teams`, matched by name or email.

## Bugs to fix in the move, not carry over

- A listing that goes Active → Pending → Closed inside one week never logs the
  Closed row (`log_change()` skips a second un-notified row of any status type),
  so it never shows as sold.
- A promoted Pipeline deal whose status is neither pending nor closed maps to
  Active, so a cancelled deal would publish as an active pocket listing.
- No unsubscribe link or `List-Unsubscribe` header anywhere.
- Zapier secret in the webhook query string (lands in access logs): move it to
  the `X-Pipeline-Token` header the webhook already accepts.
- Spark tokens in comments in `sync_mh_brokers.php` (hub and this repo): treat
  as exposed.
- Minor: hot sheet Pause/Resume executes an unbound statement; bulk welcome
  reads its date from POST while the form sends GET; Pipeline nav badge queries
  after `$conn->close()`; weekly check mixes MySQL and PHP clocks; no CSRF on
  any form.

## Architecture

    Anyprop ──► site.monthaus.com (ap_listings, all boards)
                   │  api/listings.php  (Bearer token)
                   ▼
    marketing.monthaus.com
       hs_listing_state   last snapshot of every Mont Haus listing
       hs_listing_changes new / status / price / closed, one row per transition
       manual_listings    pocket + buyer rep (typed, or promoted from Pipeline)
       hs_subscribers     people, markets, frequency, unsubscribe token
       send cron ────────► SendGrid

    Paperless ──► Zapier ──► marketing api/pipeline_webhook.php

**Which listings are Mont Haus:** the list or co-list agent is one of our
`agent_mls_ids` identities on that board (team aliases included), or the list
office is one of our offices. Identity-based matching is new: today it is office
only, which misses an agent whose board record sits under another office.

**Change tracking stays here.** The sync stores what it saw last time and logs
every transition (no "one pending row per type" rule). The email then shows the
latest state per listing, so nothing is lost between sends.

## What the site has to provide

Specified in `site.monthaus.com/docs/HANDOFF-marketing-listings-feed.md`. In short:

1. `api/listings.php?scope=mh`: every Mont Haus listing, sale and rental,
   Active / Active Under Contract / Pending, plus anything that left those
   statuses in the last 30 days with its final status, close price and close date.
2. The site must stop losing listings that leave the active pull: today
   `sync_anyprop.php` only upserts what the active query returns, so a sale
   simply stops updating. It needs to re-check dropped keys once and record the
   final status.
3. `?scope=facets`: distinct cities / subdivisions / areas per board (replaces
   `sync_neighborhoods.php`, which only ever saw 1,000 listings per board).
4. `?scope=lookup&q=address`: for Pipeline enrichment (photo, city, MLS #).
5. Later, for client hot sheets: `?scope=search` with filters, returning listing
   office name and board for attribution.

## Phases

1. **Tables + one-time import** (`sql/hot_sheets_v1.sql`, `cron/import_hot_sheets.php`):
   subscribers, saved sheets, manual listings, Pipeline tables, copied from the
   hub database. `mh_brokers` names mapped to roster rows.
2. **Listing sync** from the site feed, with change log. Runs against Anyprop
   trial data now, all six boards when production arrives.
3. **Emails**: port the template and data builder; links to site.monthaus.com;
   unsubscribe. One email for everyone for now (see Recipients).
4. **Pipeline**: webhook (header token), parser, review page, agent matching
   against the roster, enrichment from the site feed. Zapier stays pointed at
   the hub until cutover.
5. **Cutover** when the site goes live: Zapier to the new URL, hub crons off,
   `LISTING_URL_BASE` to monthaus.com.
6. **Client hot sheet builder**: separate project. Needs every board's rules on
   emailing other brokerages' listings (the site's `_mls.php` already carries
   names and disclaimers), per-agent sending identity, and unsubscribe handling
   that satisfies CAN-SPAM.

## Recipients (Nikki, 2026-09-21)

- **Now:** the new system sends to `nikki.boxer@monthaus.com` only. Enforced in
  code by `HOT_SHEET_ALLOWED_RECIPIENTS` (config): any address not on it is
  skipped and logged, whatever the subscriber table says. The hub's Hot Sheets
  keeps serving everyone else.
- **Later:** a roster agent is subscribed by default when added. The
  subscriber row links to `marketing_intakes.id`; archiving the agent ends it.
- **Content:** everyone gets the same emails (all Mont Haus listings, every
  board). Per-market emails come later, once there are enough agents and
  listings outside Aspen and Vail. Store a market preference per subscriber now
  so nothing needs a migration then.
- The site's development password on listing pages is not a concern: it goes
  away at go-live, and until then only Nikki receives these emails.

## Runbook: listing pipeline (built 2026-09-21)

Site server:

1. Run `ap_close_fields.sql` in TablePlus against the site DB (adds close
   price / date, merges the old `agsmls` rows into `aspen`).
2. Upload `sync_anyprop.php` and `webroot/api/listings.php`.
3. Add to `/var/www/site/config.php`: `LISTINGS_FEED_TOKEN` (generate with
   `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`) and `LISTING_URL_BASE`.
   Also set `AP_MH_OFFICE_IDS` to `['805522330']` if it is still empty.
4. Check the feed:
   `curl -s -H "Authorization: Bearer YOUR_LISTINGS_TOKEN" "https://site.monthaus.com/api/listings.php?scope=mh" | head -c 1500`

Marketing server:

5. Run `sql/hot_sheets_v1.sql` in TablePlus.
6. Upload `inc/config.php` and `cron/sync_hot_sheet_listings.php`; add
   `LISTINGS_FEED_TOKEN` (same value) to `/var/www/marketing.monthaus.com/inc/db.php`.
7. `php /var/www/marketing.monthaus.com/cron/sync_hot_sheet_listings.php --dry-run`
   then without `--dry-run`. The first live run records a baseline and logs nothing.

Verified in scratch MySQL 8.0 with a local stand-in for Anyprop: the migration
(duplicate `agsmls` rows dropped, favorites follow the rename); a CREN listing
credited through an agent identity sold and was recorded Closed with its price,
and stayed Closed on the next run; a listing gone from Anyprop became Off
Market; feed 401 without / with wrong / with `?token=`; the marketing sync's
baseline, then Active → Pending with a price cut, then Closed within the same
week (both rows logged, plus `closed` with the sale price), a new listing, a
listing that stopped being ours and came back; an empty feed and a wrong token
both exit 1 with nothing written.

## Runbook: emails (built 2026-09-21)

Files: `sql/hot_sheets_v2.sql`; `inc/hs_helpers.php`, `inc/hs_template.php`
(ported from the hub), `inc/hs_data.php`, `inc/hs_mail.php`, `inc/config.php`;
`cron/send_hot_sheet.php`, `cron/import_hot_sheets.php`;
`hot_sheet_preview.php` (admin), `unsubscribe.php` (public);
`assets/hotsheet/` (banners + photo placeholder). On the hub:
`hot-sheets/export_for_marketing.php`.

1. Run `sql/hot_sheets_v2.sql` in TablePlus. Upload the files above.
2. Hub (SiteGround SSH): `php export_for_marketing.php > ~/hot_sheets_export.json`
   in the hot-sheets folder; copy the file to `/home/admin/` on marketing.
3. `php /var/www/marketing.monthaus.com/cron/import_hot_sheets.php /home/admin/hot_sheets_export.json --dry-run`,
   then without. Re-run 2 and 3 to refresh pocket listings / buyer reps
   until Pipeline moves here.
4. Look at `https://marketing.monthaus.com/hot_sheet_preview.php` (and `?type=rentals`).
5. `php /var/www/marketing.monthaus.com/cron/send_hot_sheet.php --dry-run`
6. `php /var/www/marketing.monthaus.com/cron/send_hot_sheet.php --to=nikki.boxer@monthaus.com`

Design notes:
- `HOT_SHEET_ALLOWED_RECIPIENTS` is checked inside `hs_send_email()`, the
  last step before SendGrid. Everyone else is logged `blocked` in `hs_sends`.
- Latest Updates is a computed window (`hs_update_window()`: since the most
  recent Monday 00:00 Mountain, on Mondays the one before). No notified_at, no
  Monday clearing, so a missed or doubled send cannot drop or repeat changes.
- `hs_sends` stops a second real send of the same email on the same Mountain
  date. A `--to` test is logged as `test` and does not count.
- Unsubscribe: GET only shows a button (link scanners open every URL); POST
  unsubscribes; RFC 8058 one-click POST supported. An import never re-activates
  an address that unsubscribed here.

Verified in scratch MySQL 8.0 with a stand-in for SendGrid: import (twice,
idempotent; orphan change skipped; unsubscribes survive re-import); dry run;
`--to` allowed and not allowed; full run (non-allowlisted blocked and logged,
same-day repeat skipped); SendGrid payload carries List-Unsubscribe headers;
email rendered in Chromium and checked by eye; unsubscribe GET / POST /
one-click / bad token; preview pages 200.

## Runbook: Paperless Pipeline (built 2026-09-21)

Files: `sql/hot_sheets_v3_pipeline.sql`, `inc/pipeline.php`, `inc/config.php`
(PIPELINE_* lists), `api/pipeline_webhook.php` (public), `cron/parse_pipeline_events.php`,
`cron/import_hot_sheets.php` (now also mirrors the Pipeline queue),
`pipeline_review.php`, `pipeline_events.php` (admin). Hub: the updated
`hot-sheets/export_for_marketing.php`. Secret: `PIPELINE_WEBHOOK_SECRET` in `inc/db.php`.

**Until go-live** the hub's queue is the one that is worked. Each export +
import copies it here, review decisions included (the hub is authoritative),
so this site's emails carry the same pocket listings and buyer reps. Do not
review here in the meantime: the next import overwrites it.

**At go-live:**
1. Zapier: Webhooks by Zapier POST to
   `https://marketing.monthaus.com/api/pipeline_webhook.php`, with the header
   `X-Pipeline-Token: <PIPELINE_WEBHOOK_SECRET>` (no `?token=`).
2. One last export + import from the hub, then stop importing.
3. Cron: minute 49 every hour,
   `/usr/bin/php /var/www/marketing.monthaus.com/cron/parse_pipeline_events.php >> /var/log/mh-marketing/pipeline_parse.log 2>&1`
4. Review at `https://marketing.monthaus.com/pipeline_review.php`.

Fixed in the port:
- A cancelled / withdrawn / expired deal can no longer be promoted (the hub
  published it as an Active pocket listing). `pl_map_status()` returns null
  for anything not active, pending or closed; the card says so and only offers
  Dismiss (& remove, if it was already on the hot sheet).
- `--reparse` no longer sends every promoted deal back to review.
- Promotion keeps the MLS number, board and postal code (the hub dropped them).
- Buyer-rep links go to the site's listing page; no Lofty.
- The webhook secret is header-only.

Verified in scratch MySQL 8.0: import of hub events + transactions (promoted
row keeps its manual listing, twice idempotent); webhook 405 / 401 without
token / 401 with `?token=` / 200 with header; parser (roster matching,
TC excluded, managing broker conditional and hinted, unmatched agent flagged,
MLS address match, promoted deal reopened by a new Closed event, review alert
sent); `--reparse` left the promoted row promoted; review page: promote
closed (close price + status change logged), promote new buyer rep (photo,
MLS #, site link from the lookup), cancelled refused, dismiss.

