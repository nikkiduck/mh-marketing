# Agent Portal: build plan

**Status (2026-10-06):** LIVE on the server: Phases 0 and 1 (roster.php guard,
agents land in /portal/, users.php account picker, inc/portal.php, home + QR
page) and the Spend page with receipts (portal/spend.php, portal/receipt.php).
sql/portal_identity_v1.sql and marketing_requests_v1.sql run.
BUILT 2026-10-06, awaiting deploy + migration: Phase 2 (creative uploads:
sql/creatives_v1.sql, inc/creatives.php, upload fields on agent.php's
Advertising creatives and Collateral orders, creative.php for admins,
portal/asset.php for agents) and the Phase 3 Advertising and Print orders
pages (portal/advertising.php, portal/orders.php, home cards, nav).
tests/render_portal.php: 113 assertions. Pilot (Nikki, 2026-10-06): Weber
Boxer Group (Jonathan, Scott, Sara), Bryan Cournoyer, Jackson Horn.
**Access check 2026-10-08:** no pilot agent can sign in yet. The server's
inc/config.php is the pre-a28cd18 copy (allowlist = the four admins, no
CREATIVES_DIR; /var/www/creatives does not exist), no `users.intake_id` is
set for anyone, and Jackson Horn has no `users` row at all (SSO denies an
unknown address by design; the row is created on users.php). To open the
pilot: deploy inc/config.php + .htaccess, create /var/www/creatives (all
done 2026-10-08), then on users.php link Bryan -> 5, Jackson (new row,
role agent) -> 6, Scott -> 21, Sara -> 23, Jonathan (admin) -> 30.
**Teams (Nikki, 2026-10-08):** a login is the person's; the team row is
never linked. Weber Boxer Group's advertising, orders, spend and QR codes
appear as a section on each member's portal (every member sees the full
team spend); Hot Sheets and FUB stay per person. Admins keep the admin
pages and get their own portal through the same link (decision 5 is
superseded). Team rows can still be previewed from the roster.
Still to build: Hot Sheet choices (Phase 4), the marketing menu and
requests inbox (Phase 5; the sample content is compiled with Nikki first).
**Written:** 2026-09-08. **Revised:** 2026-10-01 with Nikki's decisions (below)
and a fresh check of the code and live database.
**Scope:** Mont Haus agents sign in with Microsoft to `marketing.monthaus.com` and
see their own marketing: what they have spent and what Mont Haus covered, their
campaigns and creative, their orders, the collateral and ad opportunities they can
ask for, and their Hot Sheet choices. Questions and orders reach Nikki by email.

## Decisions (Nikki)

2026-09-08:
- Creative images are **uploaded to the server** (receipts pattern), not linked.
- A request is **recorded and emailed**; marketing turns it into a real order or
  placement by hand. Nothing an agent submits touches the money tables.

2026-10-01:
1. **Custom Hot Sheets with filters is a separate project**, not part of this one.
   Its goal is to search ALL listings, not just Mont Haus's, which makes it big.
   See "Not in this project" at the end. Choosing which **areas** their existing
   Hot Sheet covers stays in this project.
2. **Dummy-proof above all.** The least tech-savvy agent must be able to use it.
   Simpler than the admin pages, never a copy of them. Creative is shown **on the
   portal itself**, never as a Dropbox or Drive link. Pattern to start from: clear
   cards (title + thumbnail) that open a simply laid-out detail page. To be refined
   together on real screens.
3. **Spend, not billing.** Month by month, what the agent paid for vs what Mont
   Haus covered. **No invoiced/paid status anywhere**: the agent's bill comes from
   accounting and may include other items, so the portal shows marketing spend
   only, never invoices or payments.
4. **A marketing menu.** The collateral agents can order, with real examples
   (postcards etc.), and a section on digital ad opportunities. Nikki and Claude
   compile the content together before it is built.
5. **Weber Boxer Group is one marketing account.** Jonathan Boxer, Scott Weber and
   Sara Perkowski all see Weber Boxer Group's data, and only that. (Checked
   2026-10-01: all WB spend, 1 order and 2 campaigns, is on the team row, #8;
   none is on the three personal rows.) Hot Sheet choices stay per person.
6. **Not now:** assistants seeing an agent's portal; marketing allowances (some
   agents have one; to be worked in later).
8. **Undecided spend** (2026-10-01): shown as its own "Being finalised" figure
   and line, never folded into either column.
9. **Receipts** (2026-10-01): agents may see their own receipts
   (portal/receipt.php, ownership-checked; receipt.php stays admin-only).
7. **QR destinations** (2026-10-01): agents can change where their own QR codes
   go. SUPER simple, far less than the admin QR page: no creating, pausing,
   deleting or renaming codes; just "where does this code go". See section 4.

---

## 1. Where the code stands (checked 2026-10-01)

**Already in place**
- Microsoft (Entra) sign-in. `sso_resolve_user()` never creates accounts: Nikki
  creates the user, the agent signs in with their Mont Haus Microsoft account.
- `users` already holds **14 accounts with role `agent`** (carried over from the
  hub). Only `ACCESS_ALLOWLIST` (nikki.boxer, jonathan.boxer, jm.drai, mary.lappe)
  keeps them out today.
- `mh_agent_financials($conn, $intake_id)` is the single source of every figure
  (Financials tab, Billing, roster badge). The portal's spend page reuses it, read
  only. It splits every line Agent / Mont Haus / Undecided.
- Email from `@monthaus.com` works: Hot Sheets send from `hotsheet@monthaus.com`
  through SendGrid (the 2026-09-08 risk is resolved).
- `hs_subscribers` already has `intake_id`, `receives_listings`,
  `receives_rentals`, `frequency` and a **`markets` column that nothing reads
  yet** (NULL for all 24). Area choice is mostly a sender change.
- Teams: `marketing_intakes.entity_type = 'team'` plus `team_members`.

**Still missing / still open**
- **`roster.php` checks only `require_login()`, no role.** Every other admin page
  calls `require_role('admin')`. Fix before any agent can sign in (Phase 0).
- **No link between a login and a roster row.** `users` has no `intake_id`.
- **Creative is stored as links** (`marketing_campaign_assets.file_url`,
  `marketing_collateral_orders.file_url`: Dropbox/Drive URLs), which cannot be
  shown as pictures. Decision 2 makes image upload part of the core build.
- Staff rows are coming (`docs/HANDOFF-leadership-and-staff.md`,
  `entity_type = 'staff'`). Staff do not get the agent portal.

---

## 2. Identity: which marketing account a login sees

**`users.intake_id`**, set by hand in `users.php`, and **read from the database
on every portal request** (by `$_SESSION['user_id']`), not copied into the
session. (Revised 2026-10-01: the original plan added a ninth session key and
then re-verified it on every page anyway; reading it each time is simpler, takes
effect the moment `users.php` changes it, and leaves both sign-in paths
untouched.)

It points at the **marketing account** the person sees, which is usually their own
roster row and, for Weber Boxer Group, **the team row** (#8) for all three of
Jonathan, Scott and Sara. So several logins may share one account, which is why
this is a plain column chosen in `users.php` rather than anything derived:

- Nullable; null means "no portal" (admins, staff). A user with role `agent` and
  no `intake_id` gets a clear page saying so, never an empty one.
- `users.php` shows each account's name and whether others already point at it,
  and warns (not refuses) when a second login shares an account.
- Not matched by email at runtime: emails change, row ids do not (the lesson
  `sso_resolve_user()` already encodes).

**The security rule, stated once:** a portal page never takes an agent or account
id from the request (`$_GET`, `$_POST`, hidden fields). It comes from the session,
is re-verified against `users`, and every query is scoped by it.
`require_agent_scope()` in `inc/auth.php` returns the id or prints a 403 that says
which gate stopped the person (no login link, archived account, not on the
allowlist). Like `require_role()` it never redirects. Admins can preview any
account's portal with a visible "Preview" banner.

Three gates must agree for a pilot agent: allowlist entry, role `agent`,
`intake_id`. The 403 pages name the missing one.

---

## 3. Design principles (decision 2)

The audience is the least tech-savvy agent, often on a phone.

- **Cards, then a detail page.** Lists are big cards (thumbnail + title + one line
  of status); a card opens a page about that one thing. No dense tables, no tabs
  within tabs, no edit affordances.
- **Plain words.** "You paid" / "Mont Haus paid", not Agent / MH / Broker. Dates as
  "March 2026". Money rounded to dollars unless cents matter.
- **One obvious action per page.** E.g. "Ask a question about this", "Order more".
- **Pictures, not links.** Every creative, proof and catalog item is an image on
  the page; the original file is a secondary "Download" button.
- **Phone first,** then desktop. Large tap targets.
- **Nothing to break.** Read-only everywhere except the request/question form and
  Hot Sheet choices.
- Separate `portal/` pages, never role branches inside `agent.php` (4,400+ lines of
  admin affordances; one missed `if` would leak another agent's data).
- Built against real screens and adjusted with Nikki before the pilot.

---

## 4. The portal pages

```
portal/index.php       Home: a card per section with a plain-word count
portal/spend.php       Month by month: you paid / Mont Haus paid
portal/advertising.php Cards: each ad placement, picture of the creative; ?id= opens one
                       (creatives large, when it ran, its spend). Built 2026-10-06.
portal/orders.php      Cards: print orders with the proof and status; ?id= opens one
                       (proof large, quantity, where it is, tracking). Built 2026-10-06.
portal/menu.php        Marketing menu: collateral to order + digital ad opportunities
portal/ask.php         Ask a question / place an order (emails Nikki)
portal/hotsheet.php    Their Hot Sheet: on/off, listings/rentals, how often, which areas
portal/qr.php          Their QR codes: where each one goes, and change it
portal/asset.php       Streams a creative or proof (or its thumbnail) after an ownership check (by id only). Built 2026-10-06.
inc/portal.php         Shared helpers and renderers
```

**Spend (decision 3).** From `mh_agent_financials()`, read only. Each month shows
what the agent paid for and what Mont Haus covered, with the lines behind it on
tap. **No invoiced, paid, billed or balance-due anywhere**, and nothing read from
`marketing_billing_months`. Totals: this year, and all time.
Open question: lines whose "who pays" is still undecided (see section 8).

**Campaigns and creative.** From `marketing_campaigns` and
`marketing_campaign_assets`. Thumbnail from the uploaded image (section 5).

**Orders.** From `marketing_collateral_orders`: what, how many, status in plain
words, tracking link. Never the receipt, never internal notes.

**QR codes (decision 7).** One card per code that belongs to them: a
picture of the code (drawn with `assets/js/mh-qr.js`, no download needed but
offered), its label ("Yard sign"), and in plain words where it goes now. One
button, "Change where it goes", opens two choices: **My profile page** (only if
they have one) or **A page on monthaus.com** (paste a link; checked with the
same `qr_dest_error()` the admin page uses: https, monthaus.com or a
subdomain). Save writes `dest_type` / `dest_url` and logs the change in
`qr_code_changes` with their user id, exactly as the admin page does. Nothing
else: no create, pause, resume, delete, rename, no scan statistics beyond
"scanned N times". Codes shown: `qr_codes.intake_id` = their account; for a
team account (Weber Boxer Group) also the members' personal codes, since a code
belongs to a sign, not to billing (assumed; confirm with Nikki). A paused code
is shown as paused with "ask Nikki to turn it back on", not editable.

**Hot Sheet areas.** Edits only their own `hs_subscribers` row (found by the
session's person, not by an id in the form): Listings and/or Rentals, daily or
weekly, and which boards (Aspen, Vail, Telluride, elevateMLS, ...) via the
existing `markets` column. `send_hot_sheet.php` then honours `markets` (today it
ignores it). By-town choice is possible later (`hs_listing_state.city`). Turning
the Hot Sheet on for the first time stays Nikki's checklist item ("Hot Sheets:
Subscribe"); the portal adjusts an existing subscription. Note the send cron is
paused until Vail is in the feed (CLAUDE.md > Cron).

---

## 5. Creative on the portal (decision 2, core, not optional)

- Migration adds an uploaded image to campaign creatives and a proof image to
  collateral orders (file name, original name, uploaded at), stored in a new
  `CREATIVES_DIR` outside the web root beside `RECEIPTS_DIR` (two levels up from
  `inc/`, same comment).
- Upload on `agent.php`'s Advertising and Collateral tabs; `mk_store_receipt()`
  already does this job for receipts and takes a field name.
- `portal/asset.php` streams by id only, after checking the item belongs to the
  session's account; `basename()`, MIME allowlist, `nosniff`,
  `Cache-Control: private, no-store`. `receipt.php` stays admin-only.
- Existing `file_url` links stay as "Open original"; nothing to backfill, but the
  portal looks best once current creative has images uploaded (Nikki's time).
- The box has 1 GB RAM: cap uploads (~8 MB), JPEG/PNG/GIF/PDF only. A thumbnail
  per upload is worth it for card pages on phones, but generate it once at upload
  (GD, small) and never on page view.

---

## 6. Marketing menu and requests (decision 4)

**Content first.** Nikki and Claude compile, before building:
- every collateral item agents can order (business cards, postcards, brochures,
  yard and open house signs, listing brochures, ...), each with real example images,
  options (designs, sizes), indicative cost and lead time;
- the digital ad opportunities: outlets (Aspen Times, Aspen Daily News, Vail Daily,
  social, Google, ...), placements and sizes, indicative rates, deadlines, and
  example ads.

**Tables** (one migration): `marketing_products`, `marketing_product_designs`
(example images), `marketing_ad_placements` (platform slugs match `agent.php`'s
`$platform_labels`), `marketing_requests` (account, who asked, type, product /
design / placement, listing, preferred date, notes, status, and what it became:
`accepted_order_id` / `accepted_campaign_id`).

**Flow.** Agent picks from the menu (pictures) or asks a general question; the
request row is written FIRST, then emailed to Nikki (from a verified system address
with the agent as Reply-To, so replying reaches them); a failed email is shown as
"sent, but the email did not go out", never a silent success. Nikki works a
`requests.php` inbox and turns a request into a real order or placement, typing the
money herself. **Nothing an agent submits reaches `cost`, `budget`, `unit_rate` or
`marketing_campaign_months`.** Indicative prices are labelled as estimates.

---

## 7. Phases

0. **Close the gap** (small, no migration): `require_role('admin')` on
   `roster.php` (checked 2026-10-01: it already refuses role `agent` from the
   database, but a BLANK role passes); re-run the guard audit
   (`grep -n "require_role\|require_login" *.php`). Agents who sign in land on
   `/portal/` instead of the admin roster's 403.
1. **Identity** (small, one migration): `users.intake_id`, picker in `users.php`,
   `require_agent_scope()` (database read per request), the portal shell
   (header, nav, home) and the QR page, which needs no other migration. Pilot
   accounts: allowlist + role `agent` + `intake_id`.
2. **Creative uploads** (medium, one migration): section 5. Done before the portal
   pages so the cards have pictures from day one.
3. **The read-only portal** (largest): Home, Spend, Campaigns, Orders, with the
   design principles; reviewed with Nikki on real screens; pilot.
4. **Hot Sheet choices** (small): `portal/hotsheet.php` and `markets` honoured by
   the sender.
5. **Menu and requests** (medium to large, after the content is compiled):
   section 6.
6. Later, one at a time as asked for: proof approval (Approve / Request changes,
   timestamped), onboarding checklist view, brand/asset downloads, listing
   marketing snapshot, publication deadlines, notifications.

Each phase can ship on its own. Good candidate for the Fable model: it crosses
identity, money and permissions on many pages.

---

## 8. Testing

- **`tests/render_portal.php`** (the important one): fixtures with two accounts A
  and B, each with distinctive orders, placements, months and figures; render every
  portal page as A and assert NONE of B's figures, names, order numbers or file ids
  appear. Plus a Weber Boxer case: logins for Jonathan, Scott and Sara all render
  the team's data and nothing from their personal rows. Uses the strict stub
  `mysqli` from `tests/render_order_split.php` (unfixtured SQL throws), so an
  unscoped query fails the test instead of passing quietly.
- **`tests/test_portal_scope.php`**: `require_agent_scope()` refuses no session,
  no link, archived account, not allowlisted; returns the id for a valid one;
  admin preview refused for non-admins.
- **No billing state leaks**: assert no portal page contains invoiced/paid/billed
  wording or reads `marketing_billing_months`.
- Existing suites still pass (`test_campaign_billing.php`,
  `test_invoice_split.php`, the render harnesses).

---

## 9. Open questions

1. ~~Undecided lines~~: "Being finalised" (decision 8).
2. **Pilot:** which two or three agents first? (Jonathan / Weber Boxer Group
   first, by preview and by sign-in.)
3. ~~Receipts~~: yes (decision 9).
4. Should archived or offboarded agents be refused at sign-in? (`is_active` on both
   `users` and `marketing_intakes` already answers it once decided.)

---

## Not in this project

- **Custom Hot Sheets with filters: separate, upcoming project.** Agents build
  their own Hot Sheets with filters (area, price, type, beds, status changes)
  across **ALL listings**, not just Mont Haus's. Today the Hot Sheet covers only
  Mont Haus listings (`api/listings.php?scope=mh`, ~36 rows in
  `hs_listing_state`); the public site holds every board's listings
  (`ap_listings`, ~18,000), so this needs a market-wide listings source for the
  marketing tool, saved searches per agent, and a check of each MLS board's rules
  on emailing other brokers' listings. Plan it on its own.
- Assistants viewing an agent's portal.
- Marketing allowances (spent vs remaining).
- Ad performance numbers (impressions, clicks): outlets report by PDF on their own
  schedule; at most a per-month results note and uploaded report later.
