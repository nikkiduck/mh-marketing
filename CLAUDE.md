# marketing.monthaus.com — Project Conventions

The Mont Haus marketing tool, standalone on its own subdomain.
PHP 8 / MySQL 8 on an AWS Lightsail instance (Debian, LAMP blueprint).

**Site + marketing are one system (2026-10-02).** Start sessions in the parent
folder `/Users/nikkiduck/Mont Haus Master`; its CLAUDE.md has the shared rules
and the new-board checklist for both projects. Board, agent and feed changes
are made in both repos in the same piece of work. Planned single board
registry: site.monthaus.com docs/HANDOFF-board-registry.md.

Replicated from `monthausint.com/marketing`, and fully independent of it.
Its own code, its own database, its own cron. Nothing is shared with the hub
(the Spark MLS API credentials were the last shared thing; removed here
2026-10-19) — so a fix made here does not reach monthausint.com, and a fix
made there does not reach here.

## Broker page links (2026-10-13)

The public site's broker pages live at `/{slug}` (monthaus.com/bryan-cournoyer).
`qr_profile_url()` (inc/qr.php: QR destinations, the portal's "View your page"
link) and the profile page's "View public profile" build that form. The site
301s the old `broker.php?s=` form, so QR codes printed before this keep working.

## How to work on this project

**Check, don't speculate.** Read the actual file before making any claim about
what it contains or does. Don't offer theories about causes that could be
settled by reading code or running a query.

When something breaks right after a change, **suspect that change first** — not
the server, the host, or the platform. A parse error produces no output when
CLI `display_errors` is off, so "nothing happened" often means "the file is
broken," not "the cron didn't fire."

Server state and the live database are READ-ONLY from here (`ssh
mh-marketing`, and the `claude_ro` MySQL user, see "Claude can read the live
database"). Look before guessing; when the answer needs a write or a query
you cannot run, say so plainly and supply the single command or query that
resolves it, rather than presenting a guess as a likely cause.

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

**Client secret lifetime:** set for 24 months (Nikki, 2026-10-01; exact creation
date not recorded). Note the expiry date here when it is next checked in the
Azure portal, and put a reminder on the calendar a month before.

### Break-glass access

`SSO_PASSWORD_LOGIN_ROLES` in `inc/sso_config.php` is `super_admin`. At least one
super_admin must always keep a working password:

```sql
SELECT email FROM users WHERE role='super_admin' AND password IS NOT NULL AND password <> '';
```

If the Entra client secret expires — it does, silently, on its expiry date —
that password is the only way back in.

## Accounts and roles — users.php

Reached from the roster page's header buttons ("Users", super admins only,
2026-10-08); before that it answered only by URL.

The only surface that creates a `users` row or writes `users.role`.
`require_role('super_admin')`, and reachable from the gear beside your name in
the header.

**Accounts are created without a password.** A new user gets a name, an email
and a role; `sso_resolve_user()` matches that address at their first Microsoft
sign-in and stamps `entra_object_id`, after which the address can change and the
link holds. There is no temp password to send, leak, or forget to rotate. The
one password that must exist — break-glass — is not created here: the page warns
when no active super_admin has one and otherwise never touches the column.

**No migration.** Every column it uses already exists: `role` got its third
value in `alter_users_role_v1.sql` and `password` became nullable in
`sso_schema.sql`, both already run.

### The role is never taken from the post

It is checked against a whitelist before any write. This is not routine input
hygiene — it is the specific failure `alter_users_role_v1.sql` documents.
Assigning a value the enum does not carry stores the **empty string** outside
strict mode, `$hierarchy['']` misses, `$user_level` becomes `-1`, and that
person is below every threshold: locked out of every page in the app, with
nothing in any log. It is the known origin of the blank role on
nikki.boxer@monthaus.com.

Same reason the page checks `information_schema` for the enum's contents and
**removes** `super_admin` from the forms when the column cannot store it, rather
than offering it and letting the write mangle itself. An account already holding
a role the enum has lost still shows it, so nobody is silently demoted by a
stale schema — but it cannot be saved, because writing it back would blank it.

### The three lockout guards

All server-side, all asserted by tripping them in `tests/render_users.php`. The
disabled controls in the markup are a courtesy; the redirect is the guard.

- **You cannot change your own role.** The role is copied into the session at
  sign-in, so demoting yourself leaves a session that still works until it ends
  and an account that cannot get back in afterwards. Another super_admin can do
  it.
- **You cannot deactivate yourself.**
- **The last *active* super_admin can be neither demoted nor deactivated.**
  Counted with a query at the moment of the write, not from the rendered list —
  the page in the browser could be minutes stale, and this is the one mistake
  with no way back except the database.

Deleting a user is deliberately not offered. Deactivating is the offboard:
`marketing_billing_months.billed_by`, `paid_by_user` and every other reference
still resolve to a name, and Billing's tabs 2 and 3 exist precisely so a former
person's history stays visible.

### The allowlist is shown, not managed

`ACCESS_ALLOWLIST` in `inc/config.php` is a **second gate**, enforced in
`require_login()` and independent of `users.role`. Somebody added here signs in
perfectly and then gets a 403 until their address is on that line — a confusing
failure, so the page renders an Access column and names the file and constant.

It is not editable from the page on purpose. Making it so would mean either a
migration moving the gate into the database — a real change to how access works,
worth a decision of its own — or a web page with write access to its own config.
While the list is short and temporary, saying plainly who is on it is worth more
than a checkbox.

### The gear is in the user block, not the nav run

`inc/_nav.php` renders it inside `.mh-user`, icon-only, `is_super_admin()` only.
The 820px breakpoint was measured against the labelled `.mh-nav-link` anchors
and a fifth would need re-measuring first — see the `order_split.php` note below
for the same reasoning. A 26px icon beside the avatar does not move where the
bar runs out of room, and it grows exactly one person's header.

**Print advertising lives on the Advertising tab (2026-10-05).** A print ad
is a placement (publication, run, recurring cost, creative, who pays), not a
collateral order (vendor, order number, tracking), so it is a row in
`marketing_campaigns` with `medium = 'print'` and an `ad_size`
(sql/campaign_medium_v1.sql; `$has_medium` guards everything until it has
run). The form's Medium select swaps the fields: print shows Ad size, hides
the UTM builder and relabels Target URL as an optional QR / landing URL.
Cards carry a Print / Digital chip and the list has an All / Digital / Print
filter. medium and ad_size are written by a follow-up UPDATE after the
INSERT (add and duplicate), deliberately not added to the hand-counted
bind strings. Billing modes, Financials, Billing and the portal are
unchanged. The tab names stay Collateral and Advertising (a rename to
Printed Marketing / Digital Advertising was tried and reverted the same day).

**Sharing a placement among agents (2026-10-05).** "Share" on a placement
(`share_campaign` in agent.php; sql/campaign_share_v1.sql) divides one ad
among several agents. Every agent (or team; never staff) has their own
identical placement (outlet, run, billing mode, creatives) carrying their own
amount, linked by `split_group`. The form is LINES, "Share with agent(s)": a
fixed first line for this page's agent, then an agent dropdown and a dollar
amount per line, "Add agent", "Split equally", and the full amount they must
add up to, to the cent (Nikki: dollars, not percentages; lines, not a wall of
checkboxes). Amounts are of the base figure: budget, or the day rate for
per-day. No percentage mode: a placement with no figure gets its full amount
typed into the form.

- **Every copy shows the whole arrangement** (Nikki, 2026-10-05): the card
  line "Shared placement: $3,000.00 in total" with each agent and amount
  (others linked to their copy), and "share of $3,000.00" beside the budget.
- **Share can be reopened from ANY copy** and its lines are everyone in the
  group. Saving sets each copy to its agent's amount (its other figures
  follow, new ÷ old), makes a copy from this page's placement for a new
  agent, DELETES the copy of an agent left off the lines (the browser asks
  first), and clears `split_group` when one agent is left. The full amount
  itself may be changed there.
- First share: this placement is scaled to its agent's amount and the others
  get copies; entered months and who-pays amounts are divided by largest
  remainder, so the parts sum to the original exactly.
- It divides the WHOLE run. A change partway through a run is done by ending
  the placement and starting a new one (Duplicate); the form says so on
  recurring placements.
- Editing a card directly (budget, a month) still changes only that copy.
  That is deliberate (Nikki: "keep it local to the agent").
- The form posts `share_seen` (each copy id and figure); if a copy changed
  since the page loaded the save is refused.

**A placement's receipt (2026-10-05, sql/campaign_receipt_v1.sql).** One
receipt (the vendor's invoice) per placement: `receipt_file`,
`receipt_orig_name`, `receipt_uploaded_at` on `marketing_campaigns`, the same
three columns and the same storage as collateral (RECEIPTS_DIR, outside the
web root), served by `receipt.php?campaign_id=`. On the card: a Receipt row
(Open / Download / Remove) and an "Add Receipt" / "Replace Receipt" button
with its own upload form. A shared placement is one invoice, so (Nikki: it
"should carry over to shared agents"):

- uploading on ANY copy puts the receipt on every agent's copy;
- sharing a placement that has a receipt, or adding an agent later, gives the
  new copy the receipt too;
- every copy has its OWN physical file, as with collateral, so Remove is
  local to that agent's copy and breaks nobody else's link;
- deleting a placement, or removing an agent from a share, deletes that
  copy's file.

Admin only for now: placement receipts are not in Financials or the agent
portal (a shared ad's invoice shows the full amount, every agent's part).
The receipt file helpers (`mk_store_receipt`, `mk_delete_receipt_file`,
`mk_copy_receipt_file`) live in `inc/receipts.php`; the first two moved there
unchanged from inside agent.php's POST block so every handler can use them.

`inc/campaign_share.php` holds the arithmetic and
`tests/test_campaign_share.php` (52 assertions) tests the real functions.
`split_pct` is each copy's percentage of the whole, for the record only. Same
principle as order_split.php: each agent's own record carries their own
figure, so inc/financials.php is untouched. This is separate from Who Pays,
which splits one agent's placement between that agent and Mont Haus.

**Memberships live on the Advertising tab too (2026-10-14).** A chamber of
commerce, association or club Mont Haus joins on an agent's behalf (Nikki:
the Ridgway Chamber of Commerce for Sierrah Smith) is a marketing tool for
networking, not an ad, but it carries the same money: dues, who pays (Mont
Haus, the agent, or a split), a share among several agents, a receipt, and a
term with a renewal date. So it is a row in `marketing_campaigns` with
`medium = 'membership'` (sql/campaign_membership_v1.sql widens the enum;
`mk_enum_has()` in inc/schema.php guards it, so until it has run the form
offers Digital and Print only) and `platform = 'membership'` (the fixed
word; it is in `$platform_labels` for the Financials tab but never in the
outlet select). The form's Type select (first row now: Digital ad, Print ad,
Membership) swaps the fields through four classes, `.js-digital-only`,
`.js-print-only`, `.js-membership-only` and `.js-ad-only` (digital or
print): a membership hides the outlet, the UTM builder, the creatives and
"Already sent to media", and relabels Name, Budget, Run Start and Run End as
Organization, Dues, Member since and Renews; Target URL becomes the
organization's website. `mk_medium_value()` reduces every posted or copied
medium to one the column accepts, and the add and update handlers set the
platform and blank the UTMs for a membership. On the card: the organization
leads with a green Membership chip, Dues / Member since / Renews, a renewal
chip from 60 days out (amber "Renews in 23 days", red "Renewal was due
…"; `mk_membership_renewal()`), no creatives section, no Mark Sent.
Duplicate is the renewal (same dues and who-pays, dates blank). Share and
Receipt work unchanged. The list filter gains Memberships. The Financials
tab, the Billing modal and the portal's Spend page say "Membership" through
`mk_fin_kind_label()` (inc/financials.php, which now reads `medium` with
the placement); the item's `kind` stays 'Advertising' underneath, so links,
`portal_item_spend()` and the share arithmetic are untouched. The agent
portal shows them on their own page, portal/memberships.php (see Agent
portal), and leaves them off Advertising.

## Advertising: how a placement reaches Financials

`marketing_campaigns.billing_mode` decides how a placement is charged:

- **`one_time`** — the whole `budget` lands in the month of `start_date`.
  The original behaviour, and the default, so no historical figure moved when
  the other two were added.
- **`monthly_flat`** — a **standing buy**. `budget` is the amount, every month,
  from the start month onward, with no end date meaning it keeps going until
  one is set. A per-month row overrides any single month.
- **`monthly`** — a buy that **varies**. One line per month, each carrying the
  amount entered for that month in `marketing_campaign_months`; a month nobody
  entered bills nothing. Never prorated: a month of impressions that starts on
  the 15th still owes the whole month.
- **`per_unit`** — `unit_rate` × the matching days in that month. A Wednesday
  marquee at $125/day is $500 where there are four Wednesdays and $625 where
  there are five. That is not a rounding artefact; it is what the outlet
  invoices.
- **`annual`** (alter_campaign_billing_v4_annual.sql, 2026-10-14, Nikki:
  "Annually" for membership dues) — `budget` is the amount, once a year: in
  the month of `start_date` and in that month every year after, up to and
  including `end_date` (Renews); no end date keeps renewing through the
  current year. `mk_campaign_years()` lists the anniversaries, compared by
  DAY (10/14/2026 to 10/13/2027 is one year; the first version compared
  months and said two; Financials passes `started_only`, the card does not); `mk_camp_resolve()` treats it
  like `monthly_flat`, so a `marketing_campaign_months` row can still
  override one year, though the card shows no month grid for it. The item
  carries `recurring` so the Financials tab labels it ("yearly", by mode).
  agent.php offers the option once `mk_enum_has()` sees the value; a posted
  'annual' before that is stored as one_time. Shares say "a year".

### The rule that governs all of this

**No figure is ever repeated forward unless somebody said to repeat it.**

That single rule produces the two monthly modes, and they are two modes rather
than one mode with a heuristic because guessing which one a placement is would
be guessing about money:

- **`monthly`** — the buy varies. Every month is entered, and a month nobody
  has entered bills **nothing** and says so. Agents budget a month at a time;
  an amount that quietly copies itself into October is a figure nobody agreed
  to, and it would go out on an invoice. So the blank is deliberately loud: an
  amber row in the grid, a "N months not set" chip on the placement, and an
  "amount not set — nothing billed" line in Financials linking back to the grid.
  `budget` here is **only the grid's placeholder** — `mk_camp_resolve()` must
  never fall back to it, and the test suite's **THE FALLBACK GUARD** section
  exists solely to fail if somebody reintroduces that.
- **`monthly_flat`** — the buy is standing. "$1,000 a month, no end date" is a
  decision already made, once, when the placement was set up. Repeating it is
  honouring an instruction, not inventing one, so `budget` genuinely bills
  every month. Making Nikki retype 1000 each month would be busywork that gets
  forgotten — producing exactly the unbilled month `monthly` protects against.

The one way `monthly_flat` can silently bill nothing is a standing buy with no
standing amount. That resolves to not-set and is flagged like any other blank.

`per_unit` is different again, and principled: the quantity is *derivable*. The
calendar supplies the day count and a stored row only overrides a month the
outlet ran short. Nothing is being guessed there either.

### Blank, zero, and no line at all

Three distinct outcomes that must stay distinct:

| State | Bills | Shows as |
|---|---|---|
| no row, or `amount` NULL | nothing | **not set** — amber, needs a decision |
| `amount` = 0.00 | nothing | **no charge** — decided, grey |
| per-day month the run never touches | nothing | *no line at all* |

Typing `0` is how you record a month that is deliberately not charged. That is
why `0.00` and NULL cannot be collapsed, and why a row carrying only a note
still counts as not set — `mk_camp_resolve()` returns `set`, `zeroed` and
`edited` separately for exactly this.

### Month expansion

`mk_campaign_months()` expands the run for all three recurring modes:

- whole calendar months, from the month of `start_date` to the month of
  `end_date`. A run of Aug 15 – Sep 2 is two months, not one and a bit.
- **no `end_date` means open-ended**: it runs through the *current* month and
  grows by one each month.
- capped at 60 months, so a mistyped year gives a wrong number rather than
  thousands of rows.

**`$started_only` — Financials always passes `true`; the grid passes `false`.**

A month that has not begun has not been delivered and cannot be owed. Showing
September in August makes that month's totals wrong and the running totals
above them worse. So Financials shows nothing beyond the current month, *even
when an end date commits the run further out*, and a placement booked to start
next March produces no line at all until March.

The grid passes `false` on purpose, so a committed run stays visible there and
a future month can be entered ahead of time. It simply will not reach
Financials until it arrives. Note the two calls therefore return different
lists for the same placement — that is the design, not a bug to reconcile.

`mk_weekday_days()` counts occurrences for `per_unit`, clipped to the run — a
placement starting on the 20th did not run on the 6th. Note this clipping is
the *opposite* of `monthly`, and deliberately so: a per-day buy is priced by
the day, a monthly buy is not.

### Entering the figures

`mk_month_grid()` renders the only place per-month figures are entered: a
column of months with an amount (or days × rate) box on each, one Save.
Financials is **read-only** for advertising — it links back here. One surface,
so there is nothing to keep in step, and no way to change a month without
seeing the rest of its run.

An earlier build had a one-month-at-a-time popover in Financials. It was wrong
for the way the work happens: Nikki sits down with an invoice and types a
column of numbers.

Two constraints on that grid:

- It is a **sibling** of the campaign edit form, never a child. Nested `<form>`
  elements are invalid HTML, the browser drops the inner one, and the grid
  would post nothing at all.
- The monthly amount input takes the placement's budget as a **placeholder,
  never a value**. A pre-filled figure is one nobody typed, and it would save
  on the next submit as though somebody had.

Run totals are the **sum of the entered months** — not an amount times a month
count, which is no longer even expressible.

### Advertising tab layout

Three rules that came from the page getting unusable rather than from taste:

- **No URL is ever printed as text, and none goes in a `title` attribute.**
  Six creatives each carrying a 150-character tracking URL twice over buried
  the buttons that actually do the work, and the hover tooltip covered the rest
  of the card. Open and Copy do everything the text did. A creative is one row:
  name, size, Open/Copy for the file, Open/Copy for its landing page, Edit,
  remove.
- **The Target URL field is paste-first.** Most placements reuse a URL from an
  email or a previous campaign. The UTM builder is collapsed behind "Build with
  UTMs" and writes into the field **only** when "Use this" is clicked. It used
  to write on every keystroke, which is fine against a hidden input and
  destructive against a visible one somebody has just pasted into.
- **Duplicate carries the ad, not the flight.** The same buy re-run in a new
  month is the common case (`RoS August` → `RoS September`, half the spend), so
  Duplicate copies the platform, name + " (Copy)", budget, billing mode, target
  URL, UTMs, who-pays and **every creative with its own file and landing URL** —
  and deliberately drops `start_date`, `end_date`, `status` and `sent`. A copy
  that inherited last month's dates and a Sent flag reads as a live placement
  the moment it appears, and a forgotten one bills twice. Month rows
  (`marketing_campaign_months`) are not copied either — they belong to the
  flight. `mk_campaign_months()` returns `[]` for a null start, so a dateless
  copy is invisible in Financials until the new dates are typed. The redirect
  carries `?edit_camp=N#camp-N`; `edit_camp` opens that one edit form
  server-side, so the dates and the spend are the first thing on screen.

- There must be exactly **one** `name="target_url"` in the add form. When the
  builder's hidden input was left in alongside the new visible one, PHP would
  take the last of the two and the pasted value would vanish with no error.

### If you change any of this

Run the test first — it fails loudly rather than silently:

```bash
php tests/test_campaign_billing.php     # 84 assertions
```

It pulls `mk_campaign_months()`, `mk_weekday_days()` and `mk_camp_resolve()`
out of `agent.php` by regex rather than copying them, so it cannot pass against
a stale copy. The cases are the real situations, pinned to a real calendar:
August 2026 has four Wednesdays and five Saturdays.

For anything touching the rendered panel, rebuild the render harness as well —
a stub `class mysqli` under `php -n` returning crafted campaign and month rows,
then read the Financials table and the grid's input values out of the HTML.
Check the October case specifically: `value=""` with the budget only as
`placeholder`. Reasoning about the arithmetic is not the same as seeing it.

## The agent roster (index.php, rebuilt 2026-09-21)

A table by default, cards as an option (per-browser, localStorage, wrapped in
try/catch). Every row is shaped once in PHP (`$rows`) and both views render
from it with the same data-* attributes the script filters and sorts on.
"Needs attention" chips filter by `data-attn` (onboard, web_pending,
web_incomplete, balance); the Pipeline chip links out. Default order is
first name A-Z (Nikki, 2026-10-02; last name until then), tie on last name.
The search box is also a typeahead (2026-10-02): matching agents of ANY
status drop down under it (prefix matches first, 8 max), Down/Up/Enter opens
the profile, Escape closes; typing alone still filters the list. The
Onboarding tab was removed the same day (the checklist is on the Profile
editor; the Tasks column stays), so the tabs are Active / Inactive /
Archived / All. Onboard / Offboard /
Restore are `inc/agent_lifecycle.php` (mk_onboard, mk_offboard), which report
back what they did as a flash list. Archived intakes come from their own query
(the UNION arms join is_active = 1 only). The rules below about the UNION, the
early close, balances and initials() all still hold. The card notes that
follow describe the old card layout; the optional card view keeps its spirit.

### Teams (2026-09-22)
- `marketing_intakes.entity_type` 'agent' | 'team'; members in `team_members`.
- Any query that means "a person" (email/name matching, feed, listing credit,
  slugs) must append `mk_team_sql($conn[, $alias])` from inc/agent_roster.php.
  It returns '' until the migration has run.
- Team MLS IDs live as `is_alias = 1` identities on the credited members only (not
  every member: WB's is on Jonathan and Scott, not Sara), never on the team.
- Split a mixed team/person row with cron/split_team.php (see docs/AGENT_ROSTER_PLAN.md).

### Roster statuses (2026-09-22)
- Active = with MH in the MLS; Inactive = `departure_detected_at` set (the sync
  never changes web_status, FUB or Hot Sheets for a departure; Nikki offboards
  by hand); Archived = offboarded. 'roster' / 'pending' are legacy, read as Active.
- Nikki can also mark someone Inactive by hand (status 'inactive': in the MLS
  under MH but not an active broker, e.g. the TC) by clicking the Active badge
  on the roster; clicking Inactive makes them Active again. The sync never
  writes status, so it sticks. Only MLS departures get the red dot and chip.
- "Onboarding" is only the marketing checklist (marketing_tasks category
  'onboarding'), never a status.
- Hot Sheet subscriptions are never automatic: ticking the checklist item
  MK_HS_TASK ('Hot Sheets: Subscribe') calls mk_hs_subscribe (agent.php
  toggle_task); un-ticking calls mk_hs_pause.
- Roster change emails: inc/roster_alerts.php, ROSTER_ALERT_EMAILS. Since
  2026-10-04 the email also has a "New board identities" table: an MLS ID
  attached to an EXISTING agent (matched by email or name, found under
  another brokerage, or promoted when the board moves them to Mont Haus),
  so a board going live or a feed gap closing shows up in Nikki's inbox.

### Bulk profiles
- cron/import_profiles.php: import/headshots, import/bios, import/profiles.csv →
  marketing_intakes; photos are written to agent-photos/ and referenced as
  SITE_URL . '/agent-photos/...', which is what api/roster.php counts as hosted.
- Fills blanks only unless --overwrite. --from-mls fills contact fields from mls_*.

### The agent page (2026-09-25)
- Overview is gone: Profile is the default tab and holds Contact, Social,
  Website, Headshots (with the crop), Bio, Board identities, Subscriptions,
  Tasks and the MLS mirror. ?tab=overview redirects to it.
- Hot Sheets: the card links to subscribers.php (frequency, pause, remove);
  only the first Subscribe happens on the agent page.
- subscribers.php is the marketing version of the hub's hot-sheets page.
  Unsubscribed is the person's own choice and is never undone there.
- agent-photos/ must belong to www-data or every photo save fails
  (mk_photo_perm_hint() says so in the error).

### One place for agents (2026-09-23)
- **No per-agent Office (2026-10-05, Nikki).** The Office select on the agent
  page, the roster's office filter and the feed's `offices` (now always [])
  are gone: with agents on several boards it only confused. Service area is
  the one concept: on the site it decides the regional pages and which office
  address a profile shows (first region named; boards when blank). The
  `marketing_intakes.office` column and the roster sync's default-office
  write remain, unused.
- agent.php has a Profile tab: website status/slug/sort/service area/FUB,
  headshot upload (inc/photos.php writes agent-photos/<slug>.jpg and
  -square.jpg, the URLs api/roster.php counts as hosted), the bio (moved from
  Assets), board identities (add/remove) and the Hot Sheet switch, which is the
  same thing as the MK_HS_TASK checklist item and keeps it in step.
- Colour: GD cannot read ICC profiles, so an Adobe RGB or Display P3 photo
  comes out oversaturated (red skin). mk_photo_srgb() converts it when Imagick
  is installed (assets/icc/sRGB.icc is the target profile) and otherwise saves
  it as-is and returns a note, shown on the agent page beside Saved.
- Two crop tools (mkCropTool in agent.php): the profile photo at 4:5, saved
  1200x1500, which is the shape of the website's broker cards, and the square
  at 600x600. The square is cut BEFORE the profile photo is rewritten, since
  both boxes were framed against the photo as it stands.
- The face crop is Cropper.js (CDN, same version as the site admin): pick a
  file or press "Crop from the profile photo", frame it, and the BOX is sent
  (crop_x/y/w/h + crop_src), with mk_crop_photo() doing the cut server-side.
  Do not go back to exporting the canvas: a photo hosted elsewhere taints it,
  toDataURL throws, and the save looks fine but writes nothing (2026-09-23).
  The data-URL path is still accepted as a fallback.
- agent.php also accepts ?slug=, so the site can link here without our row ids.
- site.monthaus.com/admin/agent.php is READ-ONLY: its save/status posts flash a
  notice and change nothing, the form is inside a disabled fieldset, and it
  links to marketing. Editing there used to be silently overwritten by the feed.
- PUBLIC_SITE_URL (inc/config.php) is where "view public profile" points.

### Current Listings on the agent page (2026-10-01)
- Read from `hs_listing_state` (Mont Haus listings in the site's Anyprop feed,
  refreshed by `cron/sync_hot_sheet_listings.php`), matched on the agent's
  `agent_mls_ids` (market + MLS id, alias/team ids included) as list or co-list
  agent; a team's page uses its members' identities. Statuses Coming Soon /
  Active / Under Contract / Pending, `in_feed = 1`; rentals labelled For Rent.
- Replaced the Spark-era sources: the `listings` / `listing_brokers` snapshot
  (frozen 2026-08-21, nothing refreshes it) and a live Spark v1 call that
  failed with HTTP 400 on every view and showed nothing. Those tables are no
  longer read by any page. A board not yet in the feed (Vail) shows nothing.
- The ad URL builder's listing choices are the listings' pages on the new site
  (feed URLs follow the site's address, so they become monthaus.com at
  go-live); its old Lofty `listing-detail/` links are gone.

### Pocket listings (2026-10-14)
- Off-MLS listings cannot come from `hs_listing_state` (the feed never has
  them), so they are entered by hand on the agent page, under Current
  Listings: `pocket_listings` (sql/pocket_listings_v1.sql; `$has_pocket` =
  `mk_table_exists()` guards everything): address, city, price, beds, baths,
  sq ft, for-rent, status (Available / Under Contract / Sold / Withdrawn),
  admin-only notes, and an optional photo stored like a creative
  (`mk_store_creative('photo')`, images only, a PDF is refused) and served by
  `creative.php?pocket=` here and `portal/asset.php?pocket=` on the portal
  (owning account only). Handlers add_pocket / update_pocket / pocket_status /
  delete_pocket in agent.php; the card carries a green "Pocket" chip and a
  status select; edit forms sit under the grid. **Admins only for now**
  (Nikki, 2026-10-14; agents entering their own is a decision for Jonathan).
  The portal's My Profile shows Available and Under Contract ones after the
  MLS listings (`portal_pocket_listings()`, badge "Pocket listing", "Not on
  the MLS or the website.", never the notes). Nothing public reads the
  table: not the roster feed, not Hot Sheets, and not the Templates tools
  (Nikki: no). When the property later lists on an MLS its feed card
  appears on its own; mark the pocket row Sold or Withdrawn by hand (no
  address auto-hide: the two can legitimately overlap for a day).

### Website order and title line breaks (2026-09-29)
- `website_order.php` (admin only, "Website Order" button on the roster) sets
  the public broker grid's order by dragging. It shows approved, active,
  non-team agents four across, in the site's order (`sort_order`, then name).
  The drag is hand-written HTML5, no library, on purpose (a CDN outage once
  cost the agent page its crop tool); arrow buttons do the same for touch.
  Save writes `sort_order` 1..n in one transaction, ignoring ids not currently
  on the site, then offers "Sync to Website" (`mk_site_sync()`). It pushes
  nothing itself. The per-agent `sort_order` box on `agent.php` still exists.
  It is an `mk_team_sql()` caller, so it belongs in the leadership/staff audit.
- The title on `agent.php` is a 2-row textarea: a newline means "break the
  broker card here". Saving normalises `\r\n`, trims around each break and
  collapses blank lines; the profile shows it with `nl2br`. `api/roster.php`
  passes the newline through, and the site's `agent_title_html()`
  (`webroot/_roster.php`) applies the same normalisation and `nl2br`, so the
  public cards break in the same place.

## The agent roster card

`index.php` cards carry: avatar, name (the link to the profile), MLS badges,
then one bottom row — open-tasks badge and balance marker on the left, Archive
floated right.

**Removed over successive passes:** the Profile button and the intake pencil
(the name is the link, and `intake.php` is the superseded form), then the status
chip. Whatever is added back, keep the bottom row to a single line.

**The status chip is redundant, not missing.** `$avatar_bg` already encodes
status by colour — black active, orange pending, grey archived, pale grey
no-intake — so the chip said ACTIVE on nearly every card and repeated what was
already on screen. If a status ever stops being distinguishable by avatar
colour, that mapping is the thing to fix, not the chip to reinstate.

Cards without an intake keep their own `.card-actions-row` with Create Intake
and the roster-record pencil: those are real actions, not decoration.

**The name comes from `COALESCE(mi.agent_name, r.name)`.** The marketing record
wins where there is one. `office_roster.name` is overwritten from Spark at 03:00
nightly, so a name corrected there silently reverts — and `agent.php` has always
shown the intake's name, so the roster and the profile disagreeing about the
same person was the visible symptom.

**Both arms of that UNION must select the same columns in the same order.**
Adding one to either without the other is a fatal query error that takes the
landing page down.

### Colour split on the badges

Open tasks are **blue**; a balance due is **orange**. Tasks are work in
progress, money is the thing to chase. Sharing one colour would have made the
badge that matters indistinguishable from the one that usually doesn't.

The balance badge is a **marker, not a figure**: a small `$` circle, no amount.
The roster is for scanning, and the number belongs on Billing where it can
actually be acted on. The amount stays in the `title`, so hovering answers "how
much" without a click.

It appears only when something is owed, links to `billing.php`, and uses the
same definition of outstanding as that page — every unpaid month's broker
total, via `mh_agent_financials()`. One function, so the badge and the Billing
page cannot quote different numbers.

That does mean a `mh_agent_financials()` pass per agent on the landing page.
There is no cheaper aggregate: a month's charge depends on billing mode, the
calendar and per-month overrides, so it cannot be reduced to a `SUM` over a
column. If the roster ever feels slow, that is where to look first.

### Avatar initials

Derived as first-letter-of-first-word plus first-letter-of-last-word, unless
`marketing_intakes.initials` is set. Deriving is right for a person and wrong
for anything else — "Weber Boxer Group" gives WG — so the override exists to be
typed into the Initials box on the Overview tab. Blank means keep deriving.

`initials()` is defined identically in `index.php` and `agent.php`. They must
stay in step: the roster card and the profile show the same avatar for the same
person, so a divergence is immediately visible and reads as a data bug.

## agent.php closes its connection early too

`agent.php` calls `$conn->close()` (line ~1090) after loading everything and
before rendering. Any database call in the template (`mk_column_exists()`
included) is a fatal that cuts the page off at that point, taking every
script below it (crop tools, Quill, the stepper). It happened on 2026-10-02:
the Leadership card checked a column while rendering and every agent page was
truncated for about 4 minutes. Compute flags like `$has_leadership` above the
close.

## index.php closes its connection early

`index.php` calls `$conn->close()` right after loading the roster, well before
the page renders. **Anything added below that line which touches the database is
a fatal**, and the message is a good one:

```
PHP Fatal error: Uncaught Error: mysqli object is already closed
```

The balance-badge block was written below it and every page load 500'd. Both
the balance queries and `mh_agent_financials()` now sit above the close, with a
comment there saying so.

If a new feature needs data on this page, gather it before the close rather
than moving the close down — the close is early on purpose, so the connection
is not held open through rendering.

### The render harness must fail the same way the server does

That bug shipped because the stub `mysqli::close()` in the test harness was
`return true;` and nothing more, so the harness rendered the broken file
perfectly. Real mysqli throws on **any** use after close.

The stub now sets a flag in `close()` and every `query()`/`prepare()` checks it.
Verified both directions: the old ordering throws in the harness, the fixed one
renders. When adding to that stub, put the method on the right class — `mh_stmt`
also has a `close()`, and the first edit landed there instead of on `mysqli`.

**The general rule:** a stub that is more forgiving than the real thing is worse
than no stub, because it converts a crash into a pass. Make stubs strict.

## Deploying a page before its migration

`inc/schema.php` provides `mk_column_exists()`, and both `index.php` and
`agent.php` use it to guard the `initials` column. This is not defensive
programming for its own sake — both failure modes are **silent**:

- `agent.php` builds one UPDATE from every posted field and does
  `if ($s) { ...execute... }`. An unknown column makes `prepare()` fail, so the
  write is skipped entirely and the page still redirects saying "saved". Every
  edit on that form quietly stops working.
- `index.php` names the column in its roster query. A failed query leaves
  `$agents` empty, so the page renders perfectly with no agents on it.

Neither shows an error to anyone. Guarding costs one `information_schema`
lookup, cached per request.

The guard is scaffolding for the deploy window, not a permanent abstraction —
once a migration has been applied everywhere, the guard around it can be
removed.

## Billing — the collections page

`billing.php` has three tabs, and only the first is a collections view.

| Tab | Question | Scope |
|---|---|---|
| **Due from agent** | What needs doing next | Active agents; settled months older than last month drop off |
| **Monthly breakdown** | What did each month cost, and who absorbed it | Every month, every agent, former included |
| **Agent totals** | Where does each relationship stand overall | All time, every agent, former included |

### Every tab comes from one pass, and the scopes must not be crossed

`mh_agent_financials()` already returns `broker`, `mh`, `unassigned` and the item
list per agent-month. Billing used to read `broker` and discard the rest, so all
three tabs and the breakdown modal came from data the page was already computing:
no new query, no migration.

The trap is scope. Tab 1 filters twice — settled months older than last month are
hidden, and only active agents are listed. Both are right for a to-do list and
wrong for a total:

- Build the all-time figures from tab 1's filtered set and **"covered by Mont Haus
  to date" shrinks every time a month is marked paid**.
- Carry the `is_active = 1` filter into them and **every offboarded agent's spend
  silently vanishes** from the company's own numbers.

So tabs 2 and 3 are computed from `$fin['months']` *before* the tab-1 filters run,
the intake query no longer filters on `is_active`, and each tab decides for itself.
Former agents appear on tabs 2 and 3 with a **Former** tag. The render harness
marks every month paid and asserts the Mont Haus totals do not move.

### Three columns, never two

Agent / Mont Haus / **Undecided**. A line whose Who Pays is unset belongs to
neither party — `mk_fin_split()` deliberately keeps it visible rather than letting
it skew a total. Folding it into either column overstates that column by exactly
that amount and hides work that still needs doing. The column is always rendered,
even at zero.

Mont Haus's share has **no paid state**, and none should be invented for it. It is
absorbed, not invoiced — spend, not a receivable. Only the broker figure has
`billed_at` / `paid_at` in `marketing_billing_months`.

### The tab lives in the URL

`?tab=due|monthly|agents`, validated server-side with a fallback to `due`.
Switching tabs is client-side (all three panels are already rendered) and updates
the URL via `history.replaceState`, so a reload — or the Billed/Paid checkboxes,
which POST and redirect — comes back to the tab you were on. The **Show settled
history** link carries the tab too.

### The amount is the breakdown trigger

Every month row's amount opens a `<dialog>` listing what the month is made of,
with each line's own three-way split. It replaced the "This cycle" chip, which
only ever meant "not old, not billed" — something the absence of the other four
chips already says — while occupying the one part of the row that could carry
something useful. The trigger is on **every** row, not just the ones that used to
show that chip: "what is this made of" is the same question in any state.

The dialog is filled from JSON embedded in the page, not from an endpoint — an
endpoint would re-run `mh_agent_financials()` to return figures the browser is
already holding. Only months that reach tab 1 are included, since that is the only
place the modal opens from.

Style note: `.bl-amt-btn` is `inline-block`, never `width:100%`. Full width puts
the dashed underline across the whole cell, so the rule starts well left of the
number and reads as a stray line rather than an affordance.

### The spreadsheet link

`ADVERTISING_SHEET_URL` in `inc/config.php` points at the SharePoint digital
advertising workbook, linked from the top of the page. Config rather than
hard-coded because SharePoint share URLs carry a token in `?e=` and get
regenerated when a link is re-shared — that should be a config change, not a
code edit.

`billing.php` renders it behind `defined()`, so publishing the page against a
stale `config.php` drops the link rather than fataling. Both directions were
rendered and checked.

### It computes nothing

Every figure comes from `mh_agent_financials()` in `inc/financials.php`, the
same function that renders an agent's Financials tab. That extraction is the
whole reason the page is safe to have: two implementations of "what does this
agent owe" would eventually disagree, and the first sign of it would be a
broker holding an invoice that does not match the screen.

`marketing_billing_months` stores **state only** — `billed_at`, `paid_at`, who
ticked them. It does not store what an agent owes.

### `billed_amount` is the one stored figure, and it is not a duplicate

It is the total frozen at the moment BILLED was ticked: what the invoice
actually says. If the underlying campaigns later change, the two disagreeing is
the useful signal — an invoice is out in the world for the old number. The page
shows both and flags it rather than quietly adopting the new one.

Unticking BILLED clears the snapshot, because a frozen figure attached to a
month nobody has invoiced is worse than none.

Ticking PAID on a month that was never invoiced stamps `billed_at` too. A paid
month that was never billed would be a hole in the record.

### The five states

| State | Condition | Colour |
|---|---|---|
| Paid | `paid_at` set | green — hidden entirely once 2+ months old |
| Invoiced | billed, unpaid, this month or last | yellow |
| Overdue | billed, unpaid, 2+ months old | red |
| Not invoiced | unbilled, 2+ months old | amber |
| This cycle | unbilled, this month or last | grey |

**Red and amber are deliberately different colours.** Red is "they owe us and
it is late" — the broker's move. Amber is "nobody has invoiced this" — ours.
Same urgency, different owner, and the page is only scannable if the two piles
are distinguishable at a glance.

An agent with no visible months is omitted from the page completely.

### bm_age() — plain integers, never DateTime::diff()

The first version used `DateTime::diff()` and got the **sign backwards**: every
past month returned negative, so `$age >= 2` was never true and nothing could
ever turn red or amber. The page looked perfectly normal. It was caught only by
printing the ages for a range of months and comparing them to what they should
be.

`(year * 12 + month)` subtraction has no sign to get wrong. Leave it alone.

## Splitting one invoice across several agents

`order_split.php`. A vendor bills the discount, the rush fee, the shipping and
the sales tax **once**, at the bottom of the invoice, however many agents' signs
are on it. Entering only each agent's line-item total therefore under-states
every one of them, and the order never reconciles to the card statement.

Oakley IND-650292 is the case it was built from: $2,368.40 of merchandise
charged as $3,647.04. Every $1.00 of sticker price actually cost $1.54.

### It changes nothing downstream, and that is the point

`marketing_collateral_orders.cost` is already the one number the rest of the app
reads — `mh_agent_financials()` derives the Financials tab, `billing.php` and the
roster balance badge from it and from nothing else. `order_split.php` writes the
**landed** cost into that field, so every downstream surface is correct with no
edit to any of them.

That is why this is a new page plus one migration rather than a change to the
money. If a future version needs to touch `inc/financials.php`, stop and ask why
— the reason it does not is load-bearing.

### The rule

Every bottom-of-invoice line is allocated pro-rata by each row's share of the
merchandise subtotal. One percentage drives all five adjustments, so there is no
line on which anyone could argue one agent was favoured, and no per-line
judgement to be made differently next time.

`mh_invoice_allocate()` in **`inc/invoice_split.php`** is the whole of it. That
file queries nothing and echoes nothing, so the test suite tests the real
function rather than a copy — unlike `test_campaign_billing.php`, which has to
regex it out of `agent.php`.

Two things in that file worth knowing before changing it:

- **Tax needs no rate, and none is stored.** Merchandise, discount and rush are
  all split by the same share, so whatever combination of them the vendor's
  taxable base is, that base splits by the same share too. Allocating tax by
  share is therefore *exact*, not an approximation. The **THE TAX BASE
  INDEPENDENCE** section of the test proves it against three different candidate
  bases and is what fails if somebody "improves" the tax line into something
  that needs to know which one is right.
- **`grand_total` is the authority, not the sum of the parts.** It is the number
  on the card statement. Rounding five allocations across three rows leaves the
  sum a cent or two out, and that residual is assigned to the largest row so the
  costs always sum to it *exactly*. When the entered charges do not add up,
  `reconciles` comes back false and the page refuses to save — it must never
  absorb a mistyped figure into whoever happens to be biggest.

### The house line

Merchandise belonging to no agent — generic office stock, like the 20 unbranded
Open House signs on IND-650292 — goes on the house line. It is **in** the
allocation pool but is never written to anybody, and only its merchandise figure
is stored, on `marketing_vendor_invoices.house_merch`.

Both halves of that matter:

- Leaving it **out of the pool** would make the agents absorb the freight and tax
  on signs that were never theirs. On IND-650292 that would have charged two
  agents 74% more than they owe.
- **Not storing it** would mean the agent rows no longer add up to
  `merch_subtotal`, so reopening the invoice would either refuse to load its own
  saved data or silently inflate everyone's share.

### `billed_with_order_id` does not combine with this

Two mechanisms, opposite directions, and an order must never carry both:

| Column | What it does |
|---|---|
| `billed_with_order_id` | de-duplicates a combined purchase inside **one** agent's record — the order holding the cost reports it, the linked ones contribute $0 |
| `vendor_invoice_id` | divides one invoice **across** agents — every row carries its own true share |

An order with both set is counted as zero by `mh_agent_financials()` while still
holding an allocated cost, so the money would vanish from Financials with nothing
on screen saying so. `order_split.php` therefore clears `billed_with_order_id` on
every row it writes, and refuses to adopt an order that already has one set.

### What it writes, and what it leaves alone

On an order it owns it writes `type`, `label`, `qty`, `cost`, `merch_amount`,
`vendor`, `vendor_url`, `order_number`, `ordered_at`, `paid_by`, `status`,
`tracking_number`, `tracking_url`, `vendor_invoice_id`.

**Fulfilment is invoice-level, not per line.** `status`, `tracking_number` and
`tracking_url` are entered once in the invoice header and stored on
`marketing_vendor_invoices`. One invoice is one shipment — the vendor makes it
together and sends it in one box — so a status per agent line would be the same
value typed several times and free to disagree with itself. They are then copied
onto **every** collateral order the split writes, so the Collateral tab still
shows tracking per order: entered once, fanned out.

The consequence is deliberate: editing one order's tracking on the Collateral tab
and then re-saving the invoice here overwrites it. If a shipment ever genuinely
arrives in two boxes, that is the assumption to revisit.

Status defaults to **`ordered`**, not `pending`. An invoice being typed here is
one that has been placed; defaulting to `pending` would mark every order on it as
not yet ordered.

It does **not** write `delivered_at`, `file_url` or `notes` on an existing order.
Those are not on this form, so writing them would blank them. `notes` is written
on INSERT only, where there is nothing to lose.

### The lines table sizes with min-width, never width

Nine columns, each with a `min-width` floor, and `table.os-lines` carrying a
`min-width` of **at least the sum of those floors plus 8px-a-side padding**
(currently 1140px).

`width` on a `<th>` is a suggestion the table overrules to make columns fit, and
it takes the space from whichever column holds the *narrowest content* — which is
Qty. A `width:70px` Qty column rendered about 20px wide once two more columns were
added. `min-width` is a floor the table cannot go under, so it overflows into
`.os-table-scroll` instead of crushing a cell.

Leaving the table's own `min-width` stale has the same effect as a bare `width`:
the table compresses to that figure and overrules every column floor together. The
render harness asserts all three — no bare `width=` in the header, nine columns,
and the table floor covering the sum.

Measured, not eyeballed: Qty's input is 116px at every width from 390px to 1440px,
the table scrolls inside its wrapper below 1280px, and the page never scrolls
sideways at any width. Note when using Playwright for this that `newPage()` takes
`viewport`, not `viewportSize` — the wrong key is silently ignored and every
reading comes back at the default 1280px, which looks like a clean result.

Deleting an invoice record **keeps** the collateral orders and their costs — the
orders were really placed, and an agent's history should not disappear because
the invoice record was tidied up. They are unlinked instead.

### Receipts are copied per order, not shared

Each collateral order gets its **own physical copy** of the invoice receipt.
`agent.php`'s `delete_collateral_receipt` physically unlinks the file, so three
orders pointing at one filename would mean clearing the receipt on one agent
silently breaking the link for the other two. A few duplicated PDFs is the better
trade. `receipt.php` also takes `?invoice_id=` for the invoice's own copy.

### The JavaScript is a second implementation, and is labelled one

The live preview at the bottom of `order_split.php` is a transliteration of
`mh_invoice_allocate()`, kept because a server round-trip per keystroke cannot
move the figures as you type. It decides nothing: Save recomputes in PHP and the
page redisplays the server's numbers. Change one, change the other in the same
commit. Note `r2()` there — PHP's `round()` is half-away-from-zero and JS's
`Math.round` is half-up, which differ on negatives, and the discount line is
always negative.

### Before changing any of it

```bash
php tests/test_invoice_split.php     # 70 assertions — the arithmetic
php tests/render_order_split.php     # 29 assertions — the rendered page
```

The render harness builds a sandbox in the temp directory with a **strict** stub
mysqli: `close()` sets a flag every `query()`/`prepare()` checks, `bind_param()`
throws when the type string and the argument count disagree, and an unfixtured
SQL statement **throws** rather than returning no rows. A stub more forgiving
than the real thing converts a crash into a pass — see the `mysqli::close()`
story above. It runs the page under `php -n` so that ext/mysqli is absent and the
stub class is the only one defined.

The entry points are the top of the Collateral tab in `agent.php` and the header
of `billing.php`. Deliberately **not** the top nav: the 820px breakpoint in
`inc/_nav.php` was measured against the links already there, and a fourth would
need re-measuring first.

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

Spark is retired (2026-10-01): `mls_key_debug.php` was deleted, and nothing in
the app calls Spark any more (agent.php's listings and intake.php's member
lookup were the last). The notes above are kept for reading old code.

**Deleted 2026-10-19, before the portal opened to every agent** (the go-live
audit, docs/HANDOFF-portal-go-live.md): `spark_debug.php`,
`cron/sync_mh_brokers.php` and `cron/sync_roster.php`, the `SPARK_ACCESS_TOKEN`
/ `VAIL_SPARK_ACCESS_TOKEN` placeholders (with `VAIL_OFFICE_MLS_ID` and
`ASPEN_OFFICE_ID`, which nothing read either) in deploy/db.sample.php, and the
unused `HUB_URL` in inc/config.php. The live token defines sit in the server's
`inc/db.php` (git-ignored) and go with the same deploy. Why it could not
wait: sync_mh_brokers.php carried both Spark access tokens as trailing
comments on its two `'token' =>` lines, so **the tokens remain in git history**
(every commit up to e8eeac8). Treat them as exposed: the Spark (FBS) account
should be closed with the vendor, which is Nikki's job, not a code change.

The same day the two remaining senders on the hub domain moved off it:
forgot-password.php and the fallback in inc/mailer.php now send as
`noreply@monthaus.com` (was noreply@monthausint.com; hotsheet@ and portal@
already sent through SendGrid from monthaus.com, so the domain is verified).

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
creatives  /var/www/creatives             (CREATIVES_DIR — outside the web root)
cron logs  /var/log/mh-marketing/
services   systemctl (apache2)
```

Nikki deploys over SFTP from Nova; Claude Code deploys with `scp` over `ssh
mh-marketing` the way the parent-folder CLAUDE.md says (md5 drift check
against git HEAD, backup to ~/deploy-backups/<YYYYMMDD>/, `php -l` on the
server) and ONLY after Nikki's OK for that deploy, since every server write
here needs it. Full setup — SSH keys, Nova config, Apache, TLS, cron — is in
`DEPLOY_LIGHTSAIL.md`.

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

### Nothing under assets/ is PHP (2026-10-05)

`assets/` is the admin theme plus our css/js: static files only. The theme
shipped `assets/json/file-upload.php`, a demo upload handler with no sign-in
and no type check, which answered 200 on the live site until it was deleted
(inert only because `assets/json/uploads/` did not exist). `.htaccess`
(`RedirectMatch 404`) and the vhost (`<DirectoryMatch>` + `<FilesMatch>`,
403) now refuse any .php / .phtml / .phar under `assets/`, so a theme update
or a stray upload cannot bring one back. Never put a PHP file there; check
after a theme update with `find assets -iname '*.ph*'`.

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
  `utf8mb4_unicode_ci`, which is why `sync_roster.php` (deleted 2026-10-19)
  carried explicit `COLLATE` casts in its JOINs. Normalised here so the problem cannot recur.

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

admin's crontab, times UTC (read from the server and trimmed 2026-09-30;
the previous version is in `/home/admin/deploy-backups/20260930/crontab.before`). root and
www-data have no crontab; `/etc/cron.d/` holds only the Debian defaults
(certbot, e2scrub, php session cleanup). Logs in `/var/log/mh-marketing/`.

```
40 * * * *  cron/sync_hot_sheet_listings.php  >> hot_sheet_sync.log      hourly (agent pages' listings)
0 13 * * *  cron/send_hot_sheet.php           >> hot_sheet_send.log      07:00 MDT
20 * * * *  cron/sync_anyprop_roster.php      >> anyprop_roster.log      hourly
*/15 * * * * cron/parse_pipeline_events.php   >> pipeline_parse.log      every 15 min
5,35 * * * * cron/check_health.php            >> check_health.log        alerts
```
- `cron/check_health.php` (2026-10-01) emails nikki.boxer@monthaus.com when the
  roster sync or listing sync has not completed in 2.5 h or logged a ✗, the
  parser has not run in 45 min or reported errors, a webhook event sits
  unparsed > 30 min, the board registry needs attention (see "Boards come
  from the site's registry"), or disk > 85 %. A quota error carries Nikki's note (call
  Anyprop if it is a live board). Repeats at most every 12 h, "back to normal"
  when cleared; state `/var/log/mh-marketing/check_health.state.json`.
  `--dry-run` prints instead of sending. The site has the same
  (`check_feeds.php`).
- logrotate installed 2026-10-01: `/etc/logrotate.d/mh-marketing` (weekly, 8
  kept, copytruncate) plus Debian's apache2 rule, which had never run.

(Each line is really `/usr/bin/php /var/www/marketing.monthaus.com/cron/...`
with the log under `/var/log/mh-marketing/`; shortened here.)

- **Spark retired 2026-10-01** (Nikki: the roster must be shown to work on
  Anyprop alone). `sync_mh_brokers.php` and `sync_roster.php` no longer run
  (backup `deploy-backups/20261001/crontab.before-spark-retire`), so
  `office_roster` and `mh_brokers` are frozen as of the 2026-09-30 03:00 run
  and `marketing_intakes.roster_id` is no longer re-linked. Anything that
  still reads those tables (index.php's UNION arm, roster.php) sees that
  snapshot. The scripts were deleted 2026-10-19 (see "Spark MLS API" above);
  the tables stay until nothing reads them.
- Removed 2026-09-30 at Nikki's request: `activate_roster.php` (nightly
  03:45; to be rescheduled when the roster is tidied up) and a leftover
  every-minute `/bin/date` test job. Its `cron_test.log` (1.6 MB) is still
  in `/var/log/mh-marketing/` and can be deleted.
- `send_hot_sheet.php` was paused 2026-10-01 until Vail was in the Anyprop
  feed, and resumed the same day once Vail went live (OSN vbor → vail) and
  its 8 Mont Haus listings reached `hs_listing_state` at 20:40 UTC. Those 8
  were logged as `new_listing` because that was the first time this system
  saw them, not because they were newly listed. It still mails only
  `HOT_SHEET_ALLOWED_RECIPIENTS` (Nikki) until go-live. The listings sync and the
  pipeline parser keep running: they only record, so no history is lost.
- The review alert's "Off-Market" means `suggested_entry_type =
  pocket_listing`, which the parser sets for EVERY listing-side deal; whether
  it is really off-market is what the MLS match on the review page shows.
- `sync_hot_sheet_listings.php` went from daily (12:40) to hourly on
  2026-10-01 because it now also feeds the agent page's Current Listings; it
  sends nothing (backup `crontab.before-listings-hourly`).
- `sync_anyprop_roster.php` and `parse_pipeline_events.php` were added
  2026-09-30 (backup: `crontab.before-anyprop-pipeline` in the same folder).
  Both take a flock and skip a run while the previous one holds it. The
  roster sync's first live run was 2026-10-01 00:27 UTC: 18 Aspen refreshed,
  Noah and Megan Walz attached on elevate, nothing created or departed.
- The rest of `cron/` is one-off tools (`import_*`, `merge_agents`,
  `split_team`, `activate_roster`); see `docs/AGENT_ROSTER_PLAN.md`.
## Stepping between agents (2026-09-29)

`agent.php` carries a chevron either side of a switcher in the breadcrumb row:
previous agent, a dropdown of everyone, "7 of 26", next agent. Alt with an
arrow key does the same (Alt, not a bare arrow, so it cannot fight a text box,
the bio editor or the crop tool).

The set and the order are the roster's Active tab: `is_active = 1`, not
archived, teams excluded, by last name. Somebody archived is not in it, so the
strip is simply absent on their page rather than wrong.

The tab travels with you, and that is the part to be careful with. The tab
buttons switch panels in the page and rewrite the address bar with
`history.replaceState`, so the `tab=` the server baked into those links is
stale the moment Nikki clicks a tab. The JS therefore reads the tab from
`location.search` at click time and builds the URL then. The server-rendered
href stays correct for the first click and for anyone without JS.

## Deploying files (and how a half-deploy hides itself)

File ownership and modes, settled 2026-09-28. `admin` is in the `www-data`
group; directories are `2775`, files `0664`, and `inc/db.php` /
`inc/sso_config.php` are `0660`. Both Apache and Nikki can therefore write
every file, which is what lets her save straight from Nova over SFTP. Do NOT
tell her to `chown www-data:www-data` a file any more: that leaves it
read-only to her and Nova hangs on the failed save rather than reporting it.
The site's `config.php` is owned by `admin`, so it needs group `www-data` and
`0640`; `0660` alone locked Apache out of it and the sync endpoint 500'd.

**The failure mode to know about.** A PHP fatal in the middle of a page, with
`display_errors` off, truncates the response and says nothing: no error on
screen, nothing in the browser console, no closing tags. On 2026-09-28
`agent.php` was uploaded but `inc/photos.php` was not, so `mk_ini_bytes()` was
undefined, output stopped mid-`<script>`, and every line after it (both
`mkCropTool()` calls, the Quill setup) never reached the browser. The crop
buttons rendered and did nothing. It reads exactly like a JavaScript problem
and is not one. When a page's JS "stops working" for no visible reason, check
where the HTML actually ends before touching the JavaScript, and check
`/var/log/apache2/error.log` (this box is Debian 12 with journald, there is
no `/var/log/syslog`).

**Uploading is per file and easy to get partly wrong.** `deploy/manifest.md5`
lists every `.php` under the root, `inc/`, `api/`, `cron/` and `portal/`,
plus `assets/css/{market,mh-theme,portal,social}.css` and
`assets/js/{market-report,mh-qr}.js`. It deliberately omits `inc/db.php`,
`inc/sso_config.php`, `agent-photos/` and `assets/vendor/`, whose server copies
are meant to differ. Regenerate it from the working folder, then on the server:

```
cd /var/www/marketing.monthaus.com && md5sum -c ~/manifest.md5 2>&1 | grep -v ': OK$'
```

Silence means the server matches. `FAILED` means the copies differ, which is
not the same as the server being behind — compare sizes and dates before
overwriting, since a file may have been edited up there.

**No CDNs on the agent page.** Cropper and Quill are served from
`assets/vendor/` (downloaded on the server with curl). The CDN tags remain as a
fallback that only fires when a local copy is missing. `mkCropTool()` binds
nothing without `Cropper`, so a missing library used to leave the crop buttons
silently dead; it now disables the button and says so on the page.

## Migrations are run by hand

Nikki runs all SQL by hand — **TablePlus**, connected over an SSH tunnel
through the instance to the managed database. (There is also a phpMyAdmin on
the box at `/var/www/html/phpmyadmin`, restricted to `Require local`, but it
points at the disabled local MariaDB and is not used.) There is no migration
runner. So:

- Deliver SQL as a `.sql` file in `sql/`.
- Keep statements copy-pasteable and in the order they must run.

### Claude can read the live database (2026-09-30)

`ssh mh-marketing "mysql -e '...'"` connects as `claude_ro`: SELECT and SHOW
VIEW on `dbmarketing_monthaus` only, TLS required, password only in the
server's `/home/admin/.my.cnf` (0600). Use it to verify schema and data
instead of asking Nikki to run read queries. It cannot write, so migrations
are still hers, as the master user in TablePlus. Every server write (files,
Apache, cron) needs her OK first.

On 2026-09-30 it confirmed MySQL 8.4.11, `utf8mb4_0900_ai_ci`, and that
agent_roster v1/v2, hot_sheets v1/v2/v3 and qr_codes_v1 had all been applied
(every table and column present) despite their headers saying not yet run.

### The STATUS line is the source of truth

Every migration carries a `STATUS:` line in its header comment, and it is the
one record of what has actually been applied. The protocol:

- **I write `STATUS: not yet run`.** Nikki changes it to `STATUS: run` once she
  has run it in TablePlus. Older files use the longer
  `STATUS: already run (date) — what it did` form; either reads fine.
- **Read it before assuming anything about the schema.** The live database is
  not visible from here, so this file header is the only evidence available.
  `grep -m1 -i -- '-- *STATUS:' sql/*.sql` lists the lot in one go.
- **Never redeliver a migration marked run.** Writing the file again would
  replace her stamp with `not yet run`, and the next session would tell her to
  run a migration that has already been applied — which, on a non-idempotent
  ALTER, is a confusing error rather than a no-op. If a run migration turns out
  to be wrong, write the next-numbered file that corrects it.
- When a delivered file gets revised before being run, say so plainly and give
  the one query that distinguishes the versions. `alter_campaign_billing_v2.sql`
  was rewritten twice in a day; its header carries the `SHOW COLUMNS FROM
  marketing_campaign_months` check that tells the two apart.

Current state, checked 2026-08-24: everything is run except
`vendor_invoices_v2_fulfilment.sql` — `order_split.php` shows a notice naming it
and does nothing until it has been applied. No other page is affected.

### Never edit a CREATE TABLE migration that has already run

`vendor_invoices_v1.sql` was edited to add `status`, `tracking_number` and
`tracking_url` to the `CREATE TABLE`, on the belief it was still unrun. It had
already been applied. Because the file is a `CREATE TABLE IF NOT EXISTS`, running
the edited version **did nothing and reported success** — the table existed, so
the whole statement was skipped. The page then failed on save with
`Unknown column 'status' in 'field list'`.

`v1` has been restored to the table as it was actually created, and the three
columns ship as `vendor_invoices_v2_fulfilment.sql`, an `ALTER`. The rule: once a
`CREATE TABLE` migration has been applied anywhere, every later change is a new
`ALTER` file, however small. "Not yet run" in this document is a claim about the
hub, and it can be stale.

**The guard must cover every migration the page writes to, not just the first.**
`order_split.php` originally checked only v1's objects, which is why a
half-migrated database rendered the form, accepted an invoice and then threw a
driver error. It now checks each migration separately and names the missing file —
and when v1 is present but v2 is not, it says explicitly that re-running v1 will
not help, because that is the obvious next move and it silently does nothing.

The render harness reproduces that half-migrated state. Doing so needed
param-aware fixtures (`__by_params`): `mk_column_exists()` binds the table and
column as parameters, so every call has identical SQL and matching on query text
alone cannot tell one column check from another.

Current state, checked 2026-08-24: `vendor_invoices_v1.sql` is **run**;
`vendor_invoices_v2_fulfilment.sql` is **not yet run**. Verify with
`SHOW COLUMNS FROM marketing_vendor_invoices WHERE Field IN ('status','tracking_number','tracking_url');`
— three rows means v2 has been applied.

`offboard_bonnie_scott.sql` now carries `STATUS: run`; the note here used to say
it was deferred. The file header is the source of truth, so this summary was the
stale one.
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

### The 169px gap under the header

`<body class="layout-extended">` brings a rule the pages cannot beat:

```css
.layout-extended .pc-container, .layout-extended .pc-sidebar { top: 169px }
```

That 169px is 74px of header plus ~95px reserved for a `.pc-tab-wrapper`
secondary nav bar. **None of our pages have that bar**, so it was 95px of dead
white space on every one of them.

Each page already had `.pc-container { top: 0 }` and it never did anything —
one class against two loses on specificity regardless of source order. The page
looked like it was setting the value; the browser was ignoring it.

The override now lives in **`inc/_nav.php`**, which is a departure from that
partial's "user-menu styles only" rule and deliberate: every page that includes
it had the bug, so a page-by-page fix would leave the next new page to inherit
it. It matches the framework's own selector and lands later in the document.

Two things not to do:

- Don't remove `layout-extended` from `<body>`. It also supplies
  `--pc-header-background` (the dark header) and `.pc-header { left: 0 }`.
- Don't try to fix it with a single-class selector in a page's `<style>`. That
  is exactly what was already there and losing.

Verified by measurement, not by eye: headless Chromium against the real
stylesheet, reading `getComputedStyle`. Computed `top` was 169px before and 0px
after, and the page heading moved from y=263 to y=94. A gap this size is easy to
mistake for padding somewhere — find the winning rule rather than adding
negative margin on top of it.

The same rule then carries `padding-top: 20px` for actual breathing room.
**Keep the two separate.** `margin-top` clears the fixed header and must equal
its height; `padding-top` is the gap you see. Merging them into one number loses
which half is structural.

That padding is also what stops margin collapsing. `index.php`'s `.wrap` asks
for `margin: 32px auto`, and with nothing between it and `.pc-container` the
32px collapsed straight out through the container and did nothing — which is
why that page sat flush against the nav while others looked fine. Remove the
padding and it goes flush again.

The site header is `inc/_nav.php`, included by `index.php`, `agent.php`,
`intake.php` and `billing.php`. Its links are Marketing and Billing; the Hub
link was removed at Nikki's request, and `HUB_URL` was removed from
`inc/config.php` on 2026-10-19 (nothing had read it since). It relies on `.mh-header-inner`, `.mh-logo`, `.mh-nav`,
`.mh-nav-link` and `.mh-nav-divider`, which those pages define in their own
inline `<style>` blocks; only the user-menu rules live in the partial. Set
`$nav_extra` before including to add a trailing crumb.

`roster.php` is the exception — it is a bare Tabler page with its own navbar
and does not use `_nav.php`. Including it there would render unstyled, because
the two pages load different CSS frameworks.

### Mobile

The pages were built wide and used on a phone, which is a different problem
from being "responsive": nothing was broken at desktop width, so every fix here
is additive and lives inside a `max-width` media query. **Desktop was verified
pixel-identical before and after** — headless Chromium at 1400px, screenshots of
the roster, Billing and the agent page diffed against the same pages rendered
from the previous revision. Keep it that way: if a change to one of these
surfaces cannot be made inside a media query, it is a change to both.

Breakpoints, and why each one is where it is — measured from where the content
actually runs out of room, not from a device table:

| Where | Breakpoint | What runs out |
|---|---|---|
| `inc/_nav.php` | 820px | Marketing, Billing, the user chip, Sign out, plus a crumb |
| `agent.php` tabs | 860px | seven tabs with icons and badges |
| `agent.php` cards | 700px | Collateral / Advertising card internals |
| `index.php` toolbar | 620px | search plus four sort buttons |
| `billing.php` rows | 640px | month, amount, chip and two checkboxes |

Four things worth knowing before touching any of it:

- **Media queries carry no extra specificity.** A mobile block placed *above* a
  base rule loses to it. `agent.php`'s mobile block therefore sits at the very
  end of its `<style>`, after every rule it overrides. This was not a
  hypothetical: the block was first written next to the other media queries
  around the listings grid, and `.order-details { display:grid }` silently lost
  to the `display:flex` defined ninety lines further down. The page looked
  almost right, which is the worst way for it to fail.

- **An inline `style` beats a media query outright.** The Advertising tab's
  title row had its flex rules on the element, so no `max-width` block could
  restack it. Those rules moved into `.adv-head` in the stylesheet, unchanged.
  Any row that might need to stack on a phone must not carry inline layout.

- **The mobile controls are never a second copy of the markup.** The nav panel
  contains the same `<a>` elements as the bar; the agent tab menu is cloned
  from the `.tab-btn` elements at runtime and forwards each click to the real
  button; the roster's sort `<select>` and the sort buttons share one state and
  one `applyAll()`. So a tab or a nav link added in one place appears in both,
  and there is still exactly one implementation of "switch tab" and "sort".
  `.sort-row` is `display:contents` at desktop for the same reason — it wraps
  the controls for the mobile rules without changing the desktop flex layout.

- **`bl-tag.current` — "This cycle" — is hidden on mobile, and only that tag.**
  It is what every uninvoiced current month says, so it carries no information
  and was the widest thing in the row. Paid / Invoiced / Overdue / Not invoiced
  all stay: each is something to act on. The whole `.bl-chips` wrapper is
  hidden rather than the tag alone, so the empty grid row and its gap go with
  it — safe because `current` means "not yet invoiced" and `.bl-drift` only
  renders on a month that *has* been invoiced. The two can never co-occur; if
  that ever stops being true, this rule has to become narrower.

Verify by measurement, as with the 169px gap. The harness used here extracts
each page's `<style>` block, renders representative markup at 390px, 320px and
1400px, and asserts computed geometry — that the amount is right-aligned to its
row, that wrapped button rows share one left edge, that nothing exceeds the
viewport width. Reasoning about flex-wrap is not the same as seeing where it
wrapped.

### Phone pass, 2026-10-15 (portal and admin pages)

Every portal page and the Users, Billing and Split-an-Invoice admin pages
were walked at 375px from rendered snapshots (`PORTAL_SNAPSHOTS=<dir> php
tests/render_portal.php`, served locally with python's http.server beside a
copy of `assets/`). The portal was already phone first; two fixes went in:
the Menu button's three bars are a grid (as a flex row they sat side by
side, three dashes), and a `.pt-subnav` row that runs past the edge fades
out on the right under 720px so it reads as scrollable. The Instagram tool's
option rows (`.sp-look`) wrap and each pill keeps one line ("M mark" and
"Upper right" broke mid-word). The Users and Split-an-Invoice tables scroll
inside their wrapper on a phone (min-width), which is the intended
behaviour. Later that day, signed in: the roster (index.php) and the agent
page (agent.php) were walked live. Roster under 620px: cards are the phone
view (the script forces them; the toggle is hidden), the seven header
buttons are a two-column block with New Agent on top (minmax columns,
plain case, so they shrink), attention chips are full-width rows, the tab
row fades on the right, a card's board list wraps. Agent page under 700px:
the page laid out at 490px on a 375px phone (the layout viewport grows to
fit nowrap content, which is what to look for when a page "zooms out" on
a phone: `innerWidth` above the device width); the header, the Website
status row, form rows and the FUB town labels now wrap, the towns sit in
two columns. All seven tabs measure 375px. Not walked: the live Instagram
and Flyer tools (their form markup was checked against the real
stylesheets instead).

**Running the stub harnesses on the Mac (2026-10-15).** Homebrew's PHP
compiles mysqli in, so the stub `class mysqli` cannot load there. A scratch
copy of `tests/ portal/ inc/` with the stub class renamed (`class mysqli` to
`class mh_mysqli`, `new mysqli(` likewise, every `mysqli $conn` type hint to
`mh_mysqli $conn`, the `php -n -m` check removed, and the two MYSQLI_*
constants guarded with `defined()`) runs all four harnesses locally with the
same results. Only the scratch copy is patched; the repo's harnesses stay as
they are for the servers.

## The one agent roster (Anyprop, feed to monthaus.com)

`docs/AGENT_ROSTER_PLAN.md` is the plan and the runbook. The short version:

- `marketing_intakes` is the agent table for everything, the public website
  included (`sql/agent_roster_v1.sql`). `web_status` (website) is separate from
  `status` (onboarding). `status = 'roster'` is a row the Anyprop sync created
  for an agent marketing has not onboarded: drawn as a no-intake card, and
  "Create Intake" promotes that same row rather than inserting another.
- `agent_mls_ids` holds one row per board identity, `UNIQUE (market,
  mls_agent_id, intake_id)`: a team ID is an `is_alias` row on each member.
  `marketing_agent_mls_ids` (one per board) is superseded but still read by
  `agent.php` until the Website card lands.
- `mls_*` columns are written only by `cron/sync_anyprop_roster.php`; it never
  writes a curated field. The feed falls back to `mls_*` where curated is blank.
- **Boards come from the site's registry (2026-10-02): `inc/boards.php`.**
  The site's `anyprop_boards.php` (`MH_BOARDS`) is the one list; the roster
  sync fetches `api/boards.php` there (Bearer AGENT_SYNC_TOKEN; address =
  boards.php beside SITE_AGENT_SYNC_URL unless SITE_BOARDS_URL is set) at the
  start of every hourly run and caches it in `/var/log/mh-marketing/boards.json`
  (written by the cron as admin, read by Apache). `mk_market_slug()`,
  `mk_board_known()`, `mk_board_label()` / `mk_board_labels()`,
  `mk_market_office()`, `mk_board_towns()` (= `hs_city_market_map()`) and
  `mk_board_names()` (import spellings) all read that cache; there is no
  board map to edit in this repo. `MK_BOARDS_FALLBACK` is the 2026-10-02
  copy, used only until the cache is first written. A member on a board the
  registry does not know is SKIPPED with a ⚠ (never stored under a raw OSN
  again, which is what vbor and summit did), the agent page's identity form
  accepts only registry slugs, and check_health.php emails when the roster
  log carries a ⚠, the cache is > 3 h old, or agent_mls_ids holds a market
  that is not a registry slug. A new board is therefore one entry on the
  site; this portal follows within the hour.
- Boards in Anyprop today: agsmls (aspen), ppmls (elevate), tridemls
  (telluride), vbor (vail), summit (altitude), ccbr_idx (recolorado, live
  2026-10-05), cren (live 2026-10-05). REcolorado's member ids are the plain
  numbers (55061958); its Mont Haus office is `MHAUS`. For three hours that
  day Anyprop served the WRONG feed (the IRES data share, OSN `recolorado`),
  whose ids carried an `IRE` prefix: seven such identities were attached and
  are removed by sql/agent_mls_ids_recolorado_ire_cleanup.sql. An `IRE…` id
  is never right. The sync searches every board for a "Mont Haus" office each run and adds
  `ANYPROP_MH_OFFICE_IDS` on top, so a newly live board needs no config.
  CREN (approved and live 2026-10-05): Anyprop has a Mont Haus office there,
  OfficeMlsId 4330, with three members (13985 Jonathan Boxer, 13986 Jackson
  Horn, 13679 Sierrah Smith), all attached and refreshed hourly. CREN's
  Member records carry NO name, email or status, only the id and office, so
  a new CREN agent cannot be matched by email or name: attach the id by hand
  on the profile and the sync keeps it fresh.
  Anyprop's Member/Office `$filter` whitelist is in its docs; `$select` is
  rejected, and MemberLastName is not filterable (use MemberEmail).
  Before the registry, Vail's and Altitude's identities were first stored
  under the raw OSN (vbor, summit) and renamed by
  sql/agent_mls_ids_vbor_cleanup.sql / agent_mls_ids_summit_cleanup.sql (both
  run); that cannot happen any more.
- **Boards the feed will carry** (Nikki, 2026-10-01): Aspen, elevateMLS
  (PPMLS), Telluride, Vail, Altitude, REColorado and CREN (both 2026-10-05): all seven live.
- **New brokers arrive before their MLS identity, on every board, always.**
  Nikki adds a new agent for marketing onboarding as soon as they join, often
  before their license moves to Mont Haus in that MLS (any board, also after
  the full feed is live). Until then the board shows their old brokerage
  (e.g. Sierrah Smith, CREN, Oct 2026). That is expected, not an error: the sync matches them by email or name once a
  board lists them under Mont Haus, and an identity typed in by hand that
  Anyprop has never returned (last_seen_at NULL) is never used to mark them
  departed.
- **Board record still under another brokerage (2026-10-04):** step 4b of the
  roster sync looks every live agent up by email (mh, alt, mls) on all
  boards. A record under a non-Mont Haus office is stored as an identity with
  `member_status = 'Other brokerage'` (Sierrah Smith, Telluride, Mountain
  Rose Realty; Jonathan Boxer, Vail, Christie's): shown on the agent page with
  that pill, NOT sent to the website (api/roster.php skips it) and not counted
  as a board on the roster. Step 4 sets it Active the run after the board
  moves them into a Mont Haus office. The pass only inserts and heartbeats
  its own rows; it never touches an Active or Inactive identity. An agent
  with NO record on a board (Jonathan on elevateMLS) cannot be captured.
- `last_seen_at IS NULL` on an identity means Anyprop has never returned it.
  The sync never deactivates anyone over such a row. Keep that guard.
- `api/roster.php` is public (no `auth.php`), guarded by `ROSTER_FEED_TOKEN`
  in the `Authorization` header only. It publishes only photos this portal
  hosts; a Dropbox link is not an image.
- Shared helpers (slugs, bio cleaning, phone format) live in
  `inc/agent_roster.php`. `mk_clean_bio()` is what reaches the public website.
- "Sync to Website" on the roster (2026-09-28, `inc/site_sync.php`) does not
  push anything. It POSTs to the site's `webroot/api/sync_agents.php` with
  `AGENT_SYNC_TOKEN`, and the SITE pulls `api/roster.php` as it always has,
  so there is one direction of travel and the nightly cron is unchanged. That
  endpoint runs the site's own `sync_agents_from_marketing.php` by including it
  with `MH_SYNC_EMBED` defined, which is why that script's CLI guard checks for
  the constant. The flash shows the tally, anything that went wrong, and the
  full log; a run with no `✓` tally is reported as failed whatever the HTTP
  code said.

## Hot Sheets (in progress)

**One email per area since 2026-10-07 (Phase 1; Nikki: "no reason why our
CO Springs team would want to get Aspen listings").** The Listings and
Rentals emails are gone. The areas are the website's Communities areas
(the same list FUB routing and the site's Communities menu use), published
by the site's api/boards.php with a `key` each and cached by inc/boards.php
(`mk_area_names()`): roaring-fork-valley, vail-valley, summit-county,
gunnison-valley, southwest-colorado, front-range. Front Range is one area
(Denver + Colorado Springs) because the list is; to split it, edit the
site's `_communities.php` and everything follows. Each area email: area
name, jump menu (MLS Listings | Pocket Listings | Buyer's Rep | Rentals,
only sections with content), Latest Updates (sale changes AND new rentals
since Monday, newest first), MLS Listings, Pocket Listings, Buyer
Representation, Rentals. **An area with nothing in it is not sent at all**
(`hs_area_has_content()`); subscribers.php and the portal say so.
- A listing's area comes stamped from the site's feed (`area_key`, `area`:
  `listing_area()` there, the town's Communities area else the board's),
  stored on `hs_listing_state` by the sync; `hs_listing_area()` falls back to
  the city through the board's towns when a row has no stamp. A pocket
  listing or buyer rep has `hs_manual_listings.area_key`, chosen on
  pipeline_review.php when promoted (pre-selected from the MLS match) and
  changeable under Recently reviewed; one with no area is in no email and
  the preview page lists it.
- `hs_subscribers.areas` is a JSON list of area keys (NULL/[] = nothing
  sent, flagged on subscribers.php); `frequency` is `daily` or
  `twice_weekly` (Monday + Thursday, Mountain; `weekly` is legacy, Mondays).
  `hs_sends.area_key` dedupes per area per day. All in
  sql/hot_sheets_v4_areas.sql, which also starts every roster subscriber
  off with areas from their profile and links the hub-imported rows to their
  agent. `mk_hs_default_areas()`: FUB service-area towns, else the Service
  area text, else their boards (`area` per board in the registry).
- Where it is set: the Subscriptions card on agent.php (frequency + area
  boxes, action `hs_prefs`), subscribers.php (per row, and the add form),
  and the agent's own portal/hotsheets.php (by their sign-in address, so
  Weber Boxer Group's three logins each have their own; saving creates the
  row, and a self-unsubscribed person may start again there). The
  "Hot Sheets: Subscribe" checklist item follows via `mk_hs_sync_task()`.
- **Paperless statuses (2026-10-07).** The Zap fired only on Pending and
  Closed, so 263 Klitowya Trail fell through unseen; Nikki cleared the
  trigger's status filter and it now fires on every status. The parser
  (cron/parse_pipeline_events.php) therefore: IGNORES a new listing-side
  deal that is on the MLS (Paperless MLS number or an exact address match
  with the site's feed; the IDX feed already shows it; parse_status
  `ignored`), and marks a PROMOTED deal whose new status is not publishable
  (fell through, expired, withdrawn, cancelled) `Fell Through` on
  hs_manual_listings with a status change row, so Latest Updates says FELL
  THROUGH until the window passes and the entry never returns to a section;
  the transaction stays `promoted`, nothing goes back to review. Needs
  sql/hot_sheets_v5_fell_through.sql (the enum value); until it has run the
  deal is queued as before. A buyer-side deal on an MLS listing is still
  queued: that is a buyer rep.
- Preview: hot_sheet_preview.php lists every area with counts and whether
  it would be sent today; `?area=key` renders that email. Send:
  `send_hot_sheet.php --to=ADDR [--area=key]`. check_health.php now watches
  hot_sheet_send.log too. Phase 2 (custom Hot Sheets with filters across
  all listings) is a separate project; the per-area tagging is what it
  will filter on.

`docs/HOT_SHEETS_PLAN.md` (plan + runbook). Built so far: `hs_listing_state` /
`hs_listing_changes` (`sql/hot_sheets_v1.sql`) filled by
`cron/sync_hot_sheet_listings.php`, which logs EVERY transition (except a
listing first seen whose feed `on_market_date` is older than 7 days: that is
a board going live, carried in silently since 2026-10-04, after Vail +
Elevate announced 16 old listings as new;
sql/hot_sheets_changeover_cleanup.sql removed those rows); never add back
the hub's "skip if an un-notified row of this type exists" rule, it is how
sales went missing. Emails: `cron/send_hot_sheet.php` + `inc/hs_*.php`.
`HOT_SHEET_ALLOWED_RECIPIENTS` (config.php, checked in `hs_send_email()`)
keeps the new system to Nikki until go-live; do not move that check out of
the send function. Latest Updates is a computed Monday window, not a
notified_at flag. Paperless Pipeline: `api/pipeline_webhook.php` (header
token only; Zapier posts to BOTH the hub and here since 2026-09-30, hub step
first) → `cron/parse_pipeline_events.php` → `pipeline_review.php`;
`pl_map_status()` returning null means "never publish", keep it that way.
Until go-live the hub's review queue is mirrored here by the import. Hot Sheets moves here from the hub. Listings come
from site.monthaus.com's `api/listings.php` (the site is the only Anyprop
listings consumer); change tracking, subscribers, emails and Paperless Pipeline
live here. The hub's copy keeps running on Spark until the new site is live.

## Anyprop feed lookup (anyprop_debug.php, 2026-10-02)

Admin page ("Anyprop Feed" button on the roster): search Anyprop's Member or
Office resource by name / email / MLS id / office id, per board or all, and
see the summary table plus the raw JSON. Reads only, same credentials as the
roster sync, no cache. Built when Jonathan Boxer's Vail and Telluride
identities were missing: both Anyprop and the old Spark feed showed him at
Christie's on VBOR (last changed 2026-05-06), and Anyprop's Telluride feed
has no Mont Haus office at all (docs/vail-member-check-2026-10-02.md), so
the fix is at the boards, not in the feed. Name search is `contains` on
MemberFullName in several casings because Anyprop cannot filter on last
name and `contains` is case-sensitive.

`spark_debug.php` (deleted 2026-10-19 with the rest of Spark) was the same
tool against a board's own Spark (FBS) RESO feed, to compare with Anyprop: tokens from inc/db.php (Aspen, Vail) or one
pasted into the form (POSTed, kept in the PHP session, "Forget token"
drops it; never in a URL or on disk). Spark honours only `eq`: startswith()
and contains() return 200 with an empty list, so member searches are exact
and an office NAME search pages through the whole Office list and matches
in PHP (`*` lists every office).

## Dynamic QR codes (qr.monthaus.com, 2026-09-28)

Printed QR codes encode `https://qr.monthaus.com/<code>`; where each one opens
is changed in `qr_codes.php` without reprinting. Same instance, same docroot,
same database as this site.

- `qr.php` (docroot) is the redirect. `deploy/qr.monthaus.com.conf` rewrites
  EVERY request on that host to it with `[END]`, so nothing else in the docroot
  is reachable through qr.monthaus.com. On marketing.monthaus.com it just
  sends you to `qr_codes.php`.
- Always **302 + `Cache-Control: no-store`, never 301.** A cached 301 would
  stop a code from ever being repointed for anyone who scanned it once.
- **Never an error page.** Unknown, paused, database down, fatal: all go to
  `QR_FALLBACK_URL`. `inc/db.php` die()s on a failed connect, so qr.php
  registers a shutdown function that turns any unplanned exit into that
  redirect. Keep it.
- Destinations must pass `qr_dest_error()` (https, `QR_ALLOWED_DOMAIN` or a
  subdomain), and qr.php re-checks the stored URL at scan time, so it can
  never become an open redirect.
- `dest_type = 'profile'` is resolved at scan time to
  `PUBLIC_SITE_URL/broker.php?s=<slug>`, so those codes move to monthaus.com
  by themselves when PUBLIC_SITE_URL changes at go-live.
- Codes are **immutable, and never deleted once scanned** (they are in
  print). Since 2026-09-30 a code with `scan_count = 0` shows Delete (row,
  its changes and daily counts go; the name becomes free); the DELETE
  re-checks `scan_count = 0`, and the Test link counts as a scan. Pause sends
  scans to the fallback; repoint and resume to reuse. Every change is logged in
  `qr_code_changes`; scans are counted in `qr_codes.scan_count` and per
  Mountain-time day in `qr_scans_daily` (no IP or user agent stored).
- Scans are sent on with `utm_source=qr&utm_medium=print&utm_campaign=<code>`
  unless the destination has its own `utm_source` (`QR_ADD_UTM`).
- QR images are drawn in the browser (qrcode-generator 1.4.4 from cdnjs),
  error correction Q, 4-module quiet zone. SVG for print, 2400px PNG.
- **Centre marks.** `assets/js/mh-qr.js` draws every code (the page no
  longer has its own drawing code). **Triangle only since 2026-10-01**
  (Nikki: the printed signs use it, so the admin page and the agent portal
  show and download the triangle; the Triangle/M/None chooser was removed; a
  code over 34 characters is drawn plain). The mark applies to previews and downloads; nothing is stored with the
  code, because the encoded address is identical either way. With a mark:
  error correction H instead of Q, modules under the mark and a 0.6-module
  white outline are left out, mark at 34% (triangle) / 25% (M) of the code's
  width, outlines copied from Final Logos. Without one the pattern is exactly
  what the page drew before. Versions 7+ (codes over 34 characters) have an
  alignment pattern at the centre, so they are drawn without a mark and the
  download asks first. Every download is decoded on the page with jsQR
  (`MHQR.verify()`) and refused if it does not read back exactly; a marked
  code is never saved when the checker failed to load. `tests/qr_marks.html`
  (serve the project root over http) decodes every mark x code length with
  jsQR and ZXing, sharp and blurred; run it after changing mh-qr.js.
  Squares across (quiet zone excluded) by code length, measured 2026-09-30:
  <=8 chars 29 plain / 33 marked; 9-10: 33 / 33; 11-21: 33 / 37; 22-34:
  37 / 41. So the form recommends codes of 10 characters or fewer, and each
  code shows its count.
- Migration: `sql/qr_codes_v1.sql`. Constants: `QR_*` in `inc/config.php`
  (defaults also in `inc/qr.php`). Never change `QR_BASE_URL` once printed.
- `marketing_intakes.coll_oh_qr_code` / `coll_oh_qr_url` (open house signs in
  the old intake form) predate this and are not connected to it.

### Server setup (one time)

1. DNS (AWS DNS zone for monthaus.com): A record `qr` -> the same static IP
   as marketing.monthaus.com (35.161.76.244 on 2026-09-28).
2. Upload with Nova, then on the instance:

```bash
sudo mkdir -p /var/www/letsencrypt
sudo cp /var/www/marketing.monthaus.com/deploy/qr.monthaus.com.conf /etc/apache2/sites-available/qr.monthaus.com.conf
sudo a2ensite qr.monthaus.com
sudo /usr/sbin/apache2ctl configtest && sudo systemctl reload apache2
sudo certbot certonly --webroot -w /var/www/letsencrypt -d qr.monthaus.com
sudo systemctl reload apache2
sudo certbot renew --dry-run
```

The :443 block is inside `<IfFile>` on the certificate, so the file loads
before the cert exists (serving only the ACME challenge on :80) and HTTPS
comes up on the reload after certbot. Webroot, not `--apache`: the Apache
plugin rewrites vhost files, which is how 000-default.conf once hijacked this
site's hostname.

3. Check: `curl -sI https://qr.monthaus.com/anything` must be a 302 to
   https://monthaus.com, and `curl -s -o /dev/null -w "%{http_code}\n"
   https://qr.monthaus.com/inc/db.php` must also be a 302 (never the file).

## Leadership and staff (2026-10-01)

Spec: `docs/HANDOFF-leadership-and-staff.md` (Phases 1 to 3 here; Phase 4 on
the site). Migration `sql/leadership_v1.sql`.
- `entity_type` 'staff' = a person on the Leadership page who is NOT an agent
  (Nikki Boxer, Kellee Anderson). **`mk_agents_only_sql()`** (renamed from
  mk_team_sql, which is now an alias) appends `entity_type = 'agent'` to every
  agent query, so staff (and teams) never reach the roster feed's `agents`,
  MLS matching, Hot Sheets, Pipeline crediting, the agent stepper or Website
  Order. Separately excluded: the roster (`index.php`, `$no_staff`), Billing,
  the invoice-split picker; QR codes treat staff like teams (no profile page);
  `users.php` never offers staff as a portal account; the portal refuses them.
- `agent.php` for staff: Profile tab only (Contact, Headshots, QR codes,
  Leadership); no onboarding checklist is seeded; `add_identity` is refused.
- `leadership.php` (admin, "Leadership & Staff" on the roster): drag-order the
  Leadership page (leadership_sort 10, 20, ...), add/remove people, add a
  staff member (goes straight to their page for a headshot), staff list.
  Agents show on the site's Leadership only while web_status is approved.
- Feed: `api/roster.php` adds a top-level `leadership` array (key, type,
  agent_key = slug for agents / null for staff, name, title, email, phone
  E.164, portal-hosted headshots, sort). `agents` is unchanged and staff-free.
  No `leadership` key before the migration = "old feed" to the site.

## Agent portal (portal/, 2026-10-01)

Plan and status: `docs/AGENT_PORTAL_PLAN.md`. Built so far: Phases 0, 1, 2
(creative uploads, 2026-10-06) and the Advertising / Print orders pages.
- `inc/portal.php` `portal_context()` is the ONLY way a portal page learns
  whose data to show: `users.intake_id` read from the database on every
  request (not the session), refused with a 403 that names the gate (no link,
  inactive, archived, staff, migration missing). Never take an account id from
  the request; an item id from the request (a QR code) is used only after
  checking it belongs to `ctx['ids']` (account + a team's members).
- Admins preview any account with `?preview=<intake id>` (banner shown;
  `portal_url()` keeps it on links). An agent's `?preview=` is ignored.
- `users.php` sets the account: **a login links to a PERSON, never a team
  row (Nikki, 2026-10-08)**; the picker leaves teams out and portal_context()
  refuses a login pointed at one (403 naming the team). A team's marketing
  is shown on each member's own portal as its own section: `ctx['teams']`
  (from `team_members`) and `ctx['scopes']` (the person first, then each
  team) drive home, advertising, orders and spend; each page loops the
  scopes and labels a team's section with its name, "The team paid" in
  place of "You paid", and a detail page carries the team's name. **A team
  is marketing, nothing more (Nikki, 2026-10-14):** on a member's Home the
  team's cards follow their own under a heading of their own, "WB Marketing"
  (2026-10-17; they used to sit inside My Marketing), and every team
  heading carries the team's initials from its roster row (`scope['short']`:
  "WB Marketing Spend", "WB Advertising", "WB Collateral"; the name when the
  row has no initials). Every
  member sees the team's full spend (Nikki). asset.php and receipt.php
  check `intake_id IN (scopes)`; QR lists the person's and their teams'
  codes. Hot Sheets stay personal (by sign-in address). Spend's month view
  is per scope (`?m=&team=`; a team id that is not theirs falls back to
  their own). An admin previewing a team row (roster eye icon) sees the
  team's own section alone. **Admins have both** (Nikki, 2026-10-08): an
  admin whose login is linked to their own account opens `/portal/` for
  their portal (a banner links back to the admin pages) and `_nav.php`
  shows a "My portal" icon beside the Users cog for them. `index.php` sends
  role `agent` to `/portal/`. `roster.php` now calls
  `require_role('admin')` (a blank role used to pass its own check).
- `portal/qr.php`: agents change their codes' destination only (profile page
  or a monthaus.com page via `qr_dest_error()`), logged in `qr_code_changes`.
- `portal/spend.php`: month by month from `mh_agent_financials()` (same
  function as Billing), account only (Weber Boxer Group = the team row).
  "You paid" / "Mont Haus paid" / "Being finalised" (undecided who-pays).
  NEVER invoiced/billed/paid status or `marketing_billing_months`; the test
  asserts those words never appear. `portal/receipt.php` streams a collateral
  receipt by order id after checking the order's intake_id is the account.
- Questions and requests go to `PORTAL_MARKETING_EMAIL` (marketing@monthaus.com,
  Nikki 2026-10-01: the team inbox, not a person, and no phone number). Sent by
  `portal_send_mail()` (SendGrid API, from portal@monthaus.com, Reply-To the
  agent), NOT `hs_send_email()`, whose Hot Sheet allowlist would block it.
- QR Code Request (`portal/qr.php?request=1`): saved FIRST in
  `marketing_requests` (sql/marketing_requests_v1.sql), then emailed; the email
  outcome is stored on the row, and a failed send tells the agent to email
  marketing@ directly. Shown as "Request ›" on home when they have no codes,
  "Review + Edit ›" when they do, plus "Need another QR code?" on the list.
  The destination is the exact URL pasted from the browser, held to
  `qr_dest_error()` (https, monthaus.com) like any QR destination.
- **Creative on the portal (2026-10-06, sql/creatives_v1.sql).** Nikki
  (2026-09-08): creative is UPLOADED, never a Dropbox link. An image or PDF
  on each advertising creative (`marketing_campaign_assets.image_*`) and a
  proof on each collateral order (`marketing_collateral_orders.proof_*`),
  uploaded on agent.php's Add/Edit Creative and order forms, stored in
  `CREATIVES_DIR` (/var/www/creatives, outside the web root, beside
  receipts) by `inc/creatives.php` (`mk_store_creative`: JPG/PNG/GIF/PDF,
  16 MB since 2026-10-09, was 8; a 720px JPEG thumbnail made ONCE at upload
  with GD, never on page view; a PDF's first page via Imagick, else Ghostscript
  directly, since Debian's ImageMagick policy refuses PDF; else a PDF tile).
  `cron/import_dropbox_creatives.php` (2026-10-09) fetched the Dropbox-linked
  creatives and proofs onto the portal through the same store function
  (`mk_store_creative_from_path()`); `--rethumb` makes missing thumbnails;
  folder links cannot be fetched and are uploaded by hand.
  Served by `creative.php` (admin, by id) and `portal/asset.php` (the owning
  account only; a JOIN on `c.intake_id`), each with `?thumb=1` / `?dl=1`.
  Shared or duplicated placements copy the files per copy
  (`mk_own_asset_files`), like receipts; deleting a creative, order or
  placement unlinks its files. **A creative added, changed or removed on
  one copy of a shared placement follows to the other copies**
  (`mk_mirror_asset_add/update/delete`, called by agent.php's three creative
  handlers; siblings matched by label + link + target, NULL-safe; Nikki,
  2026-10-09, after the shared Vail magazine page showed on one portal
  only). `import_dropbox_creatives.php --mirror` repairs copies that missed
  one. Nothing uploaded = no placeholder picture on the portal, the line
  "Contact marketing@monthaus.com to see the creative." instead
  (`portal_no_creative_html()`). The old `file_url` links stay as "Open
  original". `$has_creatives` / `$has_proofs` are computed above agent.php's
  early close; every handler guards with `mk_column_exists()`.
- **Memberships (2026-10-14).** `portal/memberships.php`, under My
  Marketing: one card per membership (a `marketing_campaigns` row with
  `medium = 'membership'`, entered on the admin Advertising tab): the
  organization, its state in plain words (`portal_membership_state()`:
  Active member / Renews soon, 60 days out / Renewal due, the date passed
  and nobody marked it ended / Starts … / Ended / Being set up), "Member
  since … · Renews …" (`portal_membership_term()`), a "Visit {host}" button
  to the organization's website (the placement's target_url), the shared or
  team note, and the spend line from the same `mh_agent_financials()` lines
  by `ref_id`. No `?id=` detail: the card says everything there is. Ended
  memberships sort last. Never dues as a figure, notes, the receipt or
  billing words. `portal/advertising.php` and the home ad count leave these
  rows out (`portal_is_membership()`); Home gets a fourth card per scope
  ("You are a member of Ridgway Chamber of Commerce, renewing soon.",
  ended ones not counted). On Spend the line is the organization's name
  with the kind "Membership".
- `portal/advertising.php` and `portal/orders.php`: cards (picture, title,
  plain-word state, when, spend from the same `mh_agent_financials()` lines
  by `ref_id`), `?id=` opens one; an id from another account is a 404 with
  nothing in it (the detail is picked from the already-scoped list). Shown:
  outlet, name, print/digital + ad size, dates, creatives, cost. NEVER
  budget, notes, UTMs, vendor, order number, receipts or billing words.
  Nav: Home, Internal Hot Sheets, Advertising, Collateral, Spend, QR
  codes, Brand & templates. **Brand & templates (2026-10-10):**
  `portal/brand.php` links the brand guidelines (`/brand-guidelines/#downloads`,
  the logo ZIP and SVGs to download) and the business card and yard sign
  template galleries, which moved from monthausint.com/assets/marketing to
  `brand-guidelines/business-cards/` and `brand-guidelines/yard-signs/`
  (static HTML + PNGs, no sign-in, like the guidelines). The whole
  `brand-guidelines/` folder is in git since that day (it was published
  from Nova before; local and server matched). monthausint.com's
  openhousesigns.html was not moved (not asked for). Hot Sheets come first in the nav and the home cards and are
  called "Internal" (Nikki, 2026-10-07) because a Client Hot Sheets
  section (Phase 2) will sit beside them; portal/hotsheets.php is their
  own areas and frequency (see "Hot Sheets" above). **In an admin preview
  every page shows the ACCOUNT's subscription** (`mk_hs_subscription()` by
  intake id), never the admin's own row by sign-in address: until
  2026-10-08 the home card read the admin's row, so Bryan's preview said
  "You get ..." every area (Nikki's own six). The roster list
  (index.php) has an eye icon per agent that opens their portal as an
  admin preview; the checklist count, Profile meter and Subs marks left
  the list the same day (the agent page has them).
- **Menu and My Profile (Nikki, 2026-10-12).** The nav is main tabs with
  sub-menus (`portal_nav()` in inc/portal.php, one place): Home · My
  Profile · My Marketing (Spend, Advertising, Collateral, Memberships) · My Tools
  (Elevation Planner, Market Report since 2026-10-18, Social Templates = portal/social.php, QR Codes, Brand
  Tools). Desktop: dropdowns; phones (< 720px): a Menu button opens a
  stacked list with every group's pages under its name; a page in a group
  also gets a row of sub-tabs under the header. Home mirrors it (a My
  Profile card, then My Marketing and My Tools sections). `portal/profile.php`
  is My Profile: name, email, title, headshot, MLS identities
  (agent_mls_ids), cell phone and current listings (`portal_listings()`,
  the Hot Sheet feed copy matched like the Instagram tool, drawn like the
  admin page's listing cards, last on the page) read-only; the bio (Quill
  from assets/vendor, the admin page's safe tags), social handles (an @
  before the box; a pasted address is parsed to the handle by
  `portal_handle()`, stored bare like agent.php) and the Hot Sheet
  subscription editable; a row of anchors under the header; and a contact
  form (marketing_requests kind `contact`, emailed via portal_send_mail).
  **Brand Tools inside the portal (Nikki, 2026-10-18):** portal/brand.php's
  three cards open portal pages with the portal header and menu:
  `brand_guidelines.php` lifts the PUBLIC page's <main> (brand-guidelines/
  index.html stays the one copy, still served to vendors) through
  inc/brand_embed.php, which makes its asset paths absolute, takes its own
  nav anchors as the jump row, and scopes its stylesheet under `.bg-embed`
  (its `:root` redefines --ink/--muted/--line, the portal's own variable
  names; html/body rules dropped). `business_cards.php` and `yard_signs.php`
  draw the galleries' pictures in portal cards with `portal_lightbox()`.
  portal_header() now draws a page's anchor row under a group's sub-tabs
  too (`.pt-subnav-wrap.jump`).
  **The short bio is gone (Nikki, 2026-10-12):** `bio_short` is no longer
  read or written anywhere (the column stays). 2026-10-13 (Nikki): the
  contact form is the FOOTER of every portal page (portal_footer() reads the
  page's $ctx; posts to portal/contact.php, which returns to the page it
  came from); My Profile also edits Service areas (one per line in the box,
  stored joined with "; " as the admin page types them); the anchors row is
  drawn by portal_header()'s fourth argument, in the band above the title;
  phones show with periods (`portal_phone()`); the nav says "Templates" for
  portal/social.php ("More templates coming soon." in italics above its
  title; removed for go-live 2026-10-19, see Nav under Listing Flyer); the admin editor and the portal editor show a space between
  paragraphs (Quill's p + p). Later that day: an admin preview shows the
  footer form with its fields disabled (hidden, it looked unrendered); My
  Profile links "View your page on monthaus.com" (qr_profile_url(), shown
  only when web_status is approved, else "not published yet"); the social
  section is "Social + Digital" and the last field "Personal Website"; a
  preview never falls back to the admin's own email. **Wanted next (Nikki,
  2026-10-13, a SITE session):** friendlier agent URLs, monthaus.com/
  bryan-cournoyer in place of broker.php?s=bryan-cournoyer, with the old
  form redirected; qr_profile_url() here is the one place to follow it. **Every change an agent makes is emailed to
  marketing@ with who, what, before, after and when (Mountain time) and
  left as a note on their agent page** (`portal_change_alert()`,
  marketing_notes). The website picks the fields up from the roster feed
  within the hour. The Hot Sheets form lives on the profile
  (inc/portal_hotsheets.php renders it); portal/hotsheets.php only takes
  the POST and sends a GET to the profile. `portal/elevation.php` is the
  Elevation Planner (its own section below, 2026-10-17).
- Pilot access = ACCESS_ALLOWLIST entry + role `agent` (or admin) +
  `users.intake_id`. Pilot (Nikki, 2026-10-06): Weber Boxer Group
  (Jonathan -> 30, Scott -> 21, Sara -> 23; the team row 8 is their
  section), Bryan Cournoyer -> 5, Jackson Horn -> 6. Admins (Jonathan,
  Jean-Michel) keep role admin and link to their own account.
- Tests: `tests/render_portal.php` (124 assertions: isolation between
  accounts on every page and on asset.php, someone else's code or id refused
  on GET and POST, preview rules, refusals, the creatives query asked for
  the account's campaign ids only). Proven to catch a leak by breaking the
  ownership check.

### Instagram Posts (portal/social.php, 2026-10-11, reworked and opened 2026-10-12)

Instagram-ready PNGs (1080 x 1440) of an agent's own listings: template
(Listing General and Open House, keys photo and photo_openhouse: the photo fills the post, the words
sit over a fade at the bottom, two lines; the split family, Featured
Listing and Open House with a text block below the photo, is kept in
social_templates() but `offered => false` since 2026-10-12, Nikki) and
look (dark, light), listing (a dropdown), photo (the listing's from
the site, or the agent's own upload) framed by drag + zoom, words (a
Headline and Text 1 to 4, each in a style: Small Caps Gold, Large Serif,
Light, Italic Serif, Plain; a blank line takes no room), a label on the
photo (solid gold pill; ready-made, none, or custom), the Mont Haus logo
top-right on a soft top fade (2026-10-13; no drop shadow, Nikki), so the
words have the full width; a very long headline wraps, balanced. No agent headshot or contact details
(Nikki, 2026-10-12). Three templates offered since 2026-10-15: Listing
General and Open House (posts, 1080 x 1440) and Story (1080 x 1920; the
size follows the template, never a switch; a Story Open House exists with
`offered => false`: one Story, its lines free text, Nikki). A
story keeps a photo-only safe area of 190 px at the top and 170 at the
bottom (`social_template_format()`, `social_format_height()`,
`social_format_insets()` in social_render.php), places its logo by `story_logo`: upper right (the badge then moves to
upper left, like a post, and the date line sits under it at the left),
lower left above the rule (default), or none and a WATERMARK lower right, the agent's pick
(`social_marks()`, posts too since later that day): the ghosted triangle
(default; triangle_mark-dark.svg on light, a tight canvas, because
triangle_mark-black.svg has air around the shape and floated) bleeding off the edge (970 px wide, its
base on the bottom edge, right corner at x = 1300; assets/images/triangle_mark-white.svg on dark, the black mark on light, at
low opacity), the M mark flush to the corner (745 px, never cut off), or
none; and puts the label pill upper right. **Pick Template since later on
2026-10-15 (Nikki):** a Size switch (Post / Story) and a Template dropdown
(Listing / Open House / Other) in place of cards; the JS composes the type
key (photo, photo_openhouse, photo_other, story, story_openhouse,
story_other; `social_template_kind()`). Other = no listing: the agent's
uploads (social_photos.php?listing=__none), the Mont Haus STOCK LIBRARY
(assets/stock/<name>.jpg at 2048 px + thumbs/ at 400, captions in the
tracked assets/stock/stock.json, images server-only and git-ignored,
built 2026-10-15 from Nikki's "Mont Haus/Mtn Photos" folder, 40 photos,
Colorado and abroad; `social_stock()`, a tile's caption is its hover
tooltip; a new photo = a 2048 jpg + a 400 thumb + a caption line; BOTH
pickers group the library by region, 2026-10-19: `social_stock_region()`
in social_render.php reads the place from the start of the file name
(aspen-, vail-, steamboat-, ...) into nine groups in
`social_stock_region_order()`, mountain markets first, then Front Range,
Colorado, Beyond Colorado; `social_stock_grouped()` returns the library
in that order; a new place prefix goes in the map or lands under Beyond
Colorado) plus the Mont Haus
mountains placeholder (assets/images/social-placeholder.jpg, chosen until
an upload is), headline "Alpine Roots. Global Vision." and Text 1 "Mont
Haus International Realty" to start, no label by default. Open House: the date and time line is free text
(`oh_when`, "Today 11am-2pm"), built from the pickers until the agent
types in it; on a story it sits under the badge, upper right, 34 px bold in the text
colour (`.whentop`; no text shadow and no shade behind it, Nikki: a hard
photo means another photo or the words elsewhere). The page is "Instagram Templates" and the Templates landing
card "Instagram Post/Story"; git tag `templates-v1-20261015` is the
version before this change, kept at Nikki's request; the export window follows
the template. Live preview, Download PNG (no Share button: tried and
removed 2026-10-12). Plan and
mechanics: `docs/HANDOFF-social-posts.md`. The rules that must hold:
- **One render path.** `social_render()` (inc/social_render.php, standalone)
  makes the HTML; the preview iframe shows it and headless Chromium
  (`/usr/bin/chromium`, installed 2026-10-11) screenshots it for the PNG.
  Never draw the post in JavaScript. The framing box on the page applies
  the same zoom/focus CSS as a control only. `tests/render_social_sample.php`
  renders sample data (four looks) without a database.
- Everything posted is checked in `social_post_from_request()`
  (inc/social.php): the listing must be one of the account's
  (`social_listings()`: hs_listing_state by the account's and its teams'
  agent_mls_ids identities or name, live statuses), the photo one the site
  listed for it (session-cached by `social_photos()`) or an upload under
  THIS account's folder (`upload:<hex>`), else its primary; labels from
  `social_labels()` or 28 custom chars; line styles from
  `social_line_styles()`; lengths capped.
- Uploads: CREATIVES_DIR/social/users/<users.id>/uploads/<hex>.jpg (2000 px)
  + -thumb.jpg (400), EXIF rotation applied, JPG/PNG/WEBP up to 20 MB
  (`social_store_upload()`). **Keyed by the signed-in PERSON since
  2026-10-18**, not the portal account: keyed by account, an admin
  previewing an agent uploaded into that agent's folder and the agent saw
  the admin's photos as "Yours" (Nikki's two test photos on Jonathan's
  portal; moved to users/2/). Served only to that person by
  portal/social_asset.php; the photo grid shows "Listing photos", or the
  stock library under one heading per region (the `group` each stock photo
  carries in social_photos.php), first and the person's own under "Your photos" last, beside
  the upload box, each own tile with an x that removes it
  (portal/social_delete.php, `social_delete_upload()`, owner only; kept
  exports untouched); the EXPORT reads the file from disk
  (`social_render($p, null, true)` writes file://), because Chromium has no
  portal session.
- Photos come from the site: `api/listings.php?scope=photos` and img.php
  (PUBLIC_SITE_URL). Fonts are self-hosted in assets/fonts/mh (variable
  woff2); the preview loads them by URL, the EXPORT embeds them as data
  URIs (`social_render($p, null, true)`), because a file:// page fetching
  fonts over https hits cross-origin rules and fell back to Liberation on
  the real download path (2026-10-12).
- Export runs as www-data under the system temp dir, one at a time (flock;
  the box has under 1 GB), 60 s cap; a copy of every PNG is kept in
  CREATIVES_DIR/social/<intake id>/. Failures log `social_export:` lines.
- `.sp-form [hidden] { display: none !important }` in social.css: portal.css
  gives the fields a display value that beat the hidden attribute, so the
  Open House date showed on every template (found 2026-10-12).
- **Open to every portal login since 2026-10-12** (Nikki, after her review):
  nav entry "Instagram Posts" in `portal_header()`, a home card on
  portal/index.php, `SOCIAL_BETA_EMAILS` (inc/config.php) empty. Setting the
  constant hides it again (plain 404 from `social_gate()`; remove the nav
  entry and card too). Nikki's own login has no linked account, so she opens
  it as `/portal/social.php?preview=30`. No Instagram posting, ever (Nikki):
  the agent uploads the PNG.

### Listing Flyer (portal/flyer.php, 2026-10-13)

A printed Letter-size PDF of one of the agent's listings, after the
InDesign display template in "Listings Display Flyer" (Nikki's Documents):
headline, address, price, a beds / baths / sq ft box, optional notes, up to
six photos in the order picked (hero, then two across, then three; fewer
photos = fewer rows, the grid stretches), footer with the logo, name,
phone, monthaus.com, email and a QR code. Under the figures box an optional
coloured BANNER (`flyer_banners()`: Just Listed, Price Improvement,
Pending, Under Contract, Long Term Rental, Short Term Rental, Open House,
Sold, each with a preset colour from `flyer_banner_colors()`; or the
agent's own words in one of those colours; `flyer_default_banner()` picks
Pending / Under Contract / Long Term Rental from the listing). The headline
starts as a phrase from the opening of the public remarks
(`social_headline_from_remarks()`: first sentence minus lead-ins, cut at
the last clause break before 52 characters; the site's photos scope
carries `remarks` since 2026-10-13, kept in the session beside the photo
list) and shrinks to stay on one line; the address and price are large
(`$fit()` in flyer_render.php sizes both to the column). **Nav (Nikki,
2026-10-13, regrouped 2026-10-15):** My Tools > Templates is
`portal/templates.php`, a landing in two groups, Instagram Templates
(portal/social.php) and Print Templates (portal/flyer.php: the flyer is
not an Instagram thing, so the tool-to-tool switch is gone); each tool
page links back with `.tp-back`; both pass 'social' as the active key.
**The three "coming soon" lines were removed for go-live (2026-10-19;
Nikki has not decided whether they come back).** They were: `<p
class="pt-soon">More templates coming soon.</p>` directly above the
`.pt-h1` in portal/templates.php and portal/social.php (the `.pt-soon`
style is still in portal.css, flyer.php and market_post.php use it), and
"More coming soon." at the end of the Templates card's `.pt-card-sub` on
portal/index.php. Each comes back in one edit.
- `inc/flyer_render.php` (standalone layout at 612 x 792 pt, Jost) and
  `inc/flyer.php` (rules, QR, PDF export) sit on the Instagram tool's data
  layer: `social_listings()` (now with `url`), `social_photos()`,
  uploads, `social_gate()`, the same endpoints for photos and uploads.
  ONE render path, as for the posts: preview iframe and PDF from the same
  HTML; `flyer_render($p, null, true)` embeds fonts and reads uploads from
  disk.
- PDF: Chromium `--print-to-pdf` (page size from `@page`), same temp dir
  and lock as the Instagram export (`flyer_export_pdf()`); a copy of each
  PDF is kept beside the PNGs in CREATIVES_DIR/social/<intake id>/.
  Photos: the hero at the site's 2048 rendition (img.php allows 2048 since
  2026-10-13), the rest at 1600; about 220 dpi on the hero.
- QR: `qrencode` (Debian package, installed 2026-10-13 with Nikki's OK) as
  inline SVG, level H, the triangle mark over the centre
  (assets/images/triangle_mark-black.svg). Choices: the listing's page on
  the site (hs_listing_state.url, default), each of the account's active
  dynamic codes (qr_codes → qr_public_url()), or none.
- Everything posted is checked in `flyer_post_from_request()`: the
  account's listing, photos from the site's list for it or the account's
  uploads (six at most), the QR choice from `flyer_qr_options()`, lengths.

### Elevation Planner (portal/elevation.php, 2026-10-17)

The broker business-planning tool, built into the portal from the standalone
pages JB had made as Claude artifacts (`/Users/nikkiduck/Mont Haus
Master/elevation-plan-project`, whose CLAUDE.md holds the formulas and the
expected figures: the example plan gives $902,041 commission, 10 closings,
350 conversations, 7.3 a week; the example lead sources 324 conversations,
$12,096, $37 each). Nikki, 2026-10-17: both tools in ONE page, one saved
plan holds both, agent only (plus admin preview), Jost throughout with
Cormorant on the headings only, a Letter PDF with no headshot or contact
block (a personal tool). The website's static demo at /elevation-plan/ was
retired the same day and 301s here.
- Three tabs: Planner (part one: a take-home goal worked back to
  commission, closings, sales volume and weekly conversations, quarters,
  focus markets, three prompts), Lead Sources (part two: a typical month of
  outreach per source, software, pace against part one's target, which the
  "Use the target from my Planner" button copies across) and Saved Plans.
  Every number and every summary comes from `assets/js/elevation.js`
  (`MHElevation.calc`, `.compute`, `.planTarget`, the plain-text and the
  drawn summaries, `renderDocument`); `assets/js/elevation-page.js` drives
  the page; `assets/css/elevation.css` (scoped `.ev-*`, portal tokens) and
  `assets/css/elevation-print.css` (the sheet). Keep `calc` and `compute`
  as they are: the figures above are the test.
- Saving: `elevation_plans` (sql/elevation_plans_v1.sql), per PERSON
  (`elevation_owner()` = the account id, never a team), two kinds: ONE
  `working` row rewritten 0.9 s after each change (POST
  portal/elevation_save.php action=working; `navigator.sendBeacon` on the
  way out), and `saved` snapshots from "Save this plan" (a `<dialog>` asks
  the name; default "Elevation Plan, Oct 17, 2026"), which Saved Plans
  opens (two-click, it replaces the working plan), prints, renames or
  deletes. `inc/elevation.php` cleans everything posted
  (`elevation_clean_plan()` / `elevation_clean_leads()`: shape, lengths,
  counts, numbers from 0 up) before it is stored, and every read binds the
  owner, so anyone else's id is simply not found. The browser's
  localStorage (`mh-elevation-v1`) mirrors the state: it is what the page
  uses before the migration has run (`elevation_ready()` false: the page
  says so and the endpoint refuses), when a save fails, and on a first
  visit it is moved into the account. An admin preview can look but not
  save (the endpoint answers 403; the page says so).
- Print and PDF: `elevation_render()` in inc/elevation.php is ONE render
  path, a self-contained Letter document (header with the horizontal logo,
  the plan's name, the agent and the date; part one, then part two; the
  figures drawn by `MHElevation.renderDocument()` from the row's two JSON
  states). portal/elevation_print.php shows it in the browser with Print
  and Download PDF; portal/elevation_pdf.php prints it with the flyer's
  Chromium call (`flyer_export_pdf()`, fonts and logo inlined as data URIs
  because Chromium opens a file with no session) and downloads
  `Elevation-Plan-{slug}-{date}.pdf`; `?id=working` or a saved id. No
  forced page break between the parts (it left a near-empty page when the
  prompts ran over); headings keep their first block.
- Tests: `tests/render_portal.php` (the plans fixtures: Jackson's working
  and saved rows, Kim's, and the save, rename, delete, print and PDF
  refusals), run on a server like the other harnesses.

### Market Report and Market Snapshot posts (2026-10-18)

The Monthly Market Report of the Colorado markets Mont Haus serves (JB
owns it) and the Market Snapshot social cards, built into the portal from
Nikki's Claude artifacts (`/Users/nikkiduck/Mont Haus Master/mont-haus-market-report`,
whose CLAUDE.md holds the sources, the exact credit lines, the hard rules
and the monthly refresh procedure). Plan and mechanics:
`docs/HANDOFF-market-report.md`. Nikki, 2026-10-18: on both the admin and
the agent portals, under Tools for agents, in the portal's look, the cards
a feature of the Instagram template area "dressed up a little".
- **Where:** My Tools > Market Report (`portal/market.php`) and My Tools >
  Templates > Instagram Templates > Market Snapshot (`portal/market_post.php`)
  for agents; `market_report.php` (a button on the Agent Roster header) and
  `market_snapshot.php` for admins whose login has no linked account
  (Nikki). Both sides draw the same bodies (`inc/market_report_body.php`,
  `inc/market_post_body.php`). A home card and a Templates card link them.
- **The card's type since 2026-10-19 is Nikki's InDesign pass** (her
  market-snapshot-story.pdf, sizes read from the PDF): eyebrow 41, title
  105, month line 50 regular, median label 36, median 162, change 46 in
  the text colour with no arrow, rows 100 / 38 gold small caps / 46,
  hairlines between the rows only, a near-flat 90 % shade over the photo,
  the story starting 150 px lower than the post. The same card exported as
  editable IDML lives in Documents > Weber Boxer Group > Mont Haus > Market
  Snapshot InDesign (a hand-built package; Adobe's PDF-to-INDD tools fail).
- **The data is files, not tables:** `inc/market_data/<YYYY-MM>/` with
  `meta.json`, `boards.json`, `county.json`, `county_trend.json`,
  `towns.json`, `front_range.json` (the artifact's files, `_sep` dropped
  from three names, field names untouched: the JavaScript and the card
  model read them by name; **Routt County is its own boards.json row,
  market `routt`, split from the artifact's `altitude` row and renamed in
  county.json and county_trend.json**: Nikki, 2026-10-18, Summit and
  Steamboat apart as on the site; the tab names follow the site's
  Communities). The newest folder is the report; `?m=YYYY-MM`
  an older one (a Month picker appears with two or more). **A monthly
  refresh is a new folder, deployed and committed; no SQL.** September 2026
  is on file (exported 2026-10-18).
- `inc/market_data.php` (loading, the words for months and numbers,
  `market_card_model()`) and `inc/market_card_render.php`
  (`market_card_render()`, the card as HTML) run without a database;
  `inc/market.php` adds the portal parts: `market_gate()` (any portal
  login, or an admin without an account; the endpoints hold no account
  data), the checked options, the stock backdrop, `market_card_html()`.
- **The report** (`assets/js/market-report.js`, `assets/css/market.css`,
  scoped `.mr-*` with its own tokens so it sits under either header) is
  the artifact's page: tabs per market (the hash, `#aspen`), the overview
  table, the metro panels, county tiles, 24-month charts and the Aspen
  town table. The charts are plain SVG (no d3, no CDN). The month words
  come from the data (`market_boot()`), never hard-coded.
- **The cards:** ONE render path, as for the Instagram posts: the preview
  iframe and the PNG (`portal/market_render.php`, `portal/market_export.php`,
  through `social_export_png()` at 1080 x 1350 or 1080 x 1920) come from
  the same HTML; never draw the card in JavaScript. The look is the
  Instagram templates' (Cormorant on the title and figures, Jost labels,
  gold rule and small caps, dark ink or cream light, the ghosted triangle
  or M mark, the vertical-stack logo upper right or lower left) and an
  optional stock photo under a shade (`social_stock()`, the tiles under a
  heading per region since 2026-10-19, None first; the place's
  suggested photo is preselected when it is on the server), or one of the
  person's OWN photos (Nikki, 2026-10-19: the same uploads as the Instagram
  tool, under "Your photos" with the same drop box and x; the endpoints
  are social_upload.php and social_delete.php unchanged; a backdrop of
  `upload:<id>` is accepted by `market_card_options_from_request()` only
  when the portal context owns the file, so market_render.php and
  market_export.php now pass `$ctx` through, and the export hands Chromium
  the file:// path; the admin-side market_snapshot.php has no account and
  shows neither). The first
  version had three layouts (hero, metro, list of two or three
  counties). **Redrawn the same evening (Nikki): one PLACE per card**,
  every county, every Aspen Glenwood town and Denver Metro (21 cards,
  `market_card_defs()`, a Location dropdown grouped by board; the county
  is the title with the site's Communities towns under it, Routt County
  over Steamboat Springs; the background photo is picked from thumbnails
  with the location under each), the median large
  and three rows under it (closed sales, days on market, homes for sale;
  months of supply stays in the report: Nikki, "keep it simple"), the
  words big enough for a phone; no second-type line and NO
  source block on the card (Nikki: removed entirely; the credit stays in
  the report and in the model's unused `credit`); the photo brighter and
  the watermark fainter. The artifact's other rules hold: a thin place
  (under 10 sales in the month) shows year to date, changes are
  recomputed with one decimal and a trailing .0 dropped, no em dashes.
  Every card was measured inside the post's height budget on 2026-10-18
  (the budget is in market_card_render.php's header; re-measure after a
  size change). **2026-10-19 (Nikki):** the title is the ONE location name
  (the town names beneath it are gone; `place` stays on the defs, undrawn),
  the month-and-type line is 50 px (was 40), all 84 re-measured. Price per
  sq ft is not in CAR's reports, so the 21 CAR cards take it from our own
  feed (the site's market_stats API publishes county rows since 2026-10-19;
  `market_feed_counties()`, `market_feed_place_for_card()`,
  `market_feed_ppsf_stat()` in inc/market_feed.php; the board with the most
  sales in the county this year); the feed towns had it already. 17 more stock photos (57 now; the Mtn Photos
  folder's Glenwood Springs shot is Editorial Use Only and stays out), with
  suggested backdrops for El Paso and Teller and closer ones for several
  places.
- `tests/render_market_sample.php` renders every card, the report and the
  builder from the data files alone into `tests/market-preview/`
  (git-ignored); serve the project folder to look at them. It prints the
  card figures to check against the artifact's CLAUDE.md, section 10
  (they matched on 2026-10-18). Walked at 1200 px and 375 px that day. Not
  covered by `tests/render_portal.php`.
- Kept on purpose: a market page's headline names the county with the
  most single-family sales that month (Garfield for Aspen), as the
  artifact did; its CLAUDE.md flags it for JB to decide.
- **Town figures from our own feed (late 2026-10-18):** the site's
  `api/market_stats.php` (closings from its ap_sales table, every board,
  since 2025-01-01) is fetched per month by `market_feed()` (inc/market.php)
  and cached in `CREATIVES_DIR/market/`; `inc/market_feed.php` (standalone)
  resolves the Communities towns against it, every town, NO minimum (Nikki:
  Ridgway with two sales is still worth seeing), the board with the most
  sales for the town this year. Each market page ends with a "Town by
  town, from the MLS feed" table, and the towns join the snapshot cards'
  Location list (keys `feed-<town>`; the Roaring Fork towns keep their CAR
  cards). **The agent picks the three rows under the median** (evening:
  `market_stat_catalog()`, eight figures; a location offers what its source
  publishes, defaults closed sales, avg days on market, homes for sale or
  price per sq ft until a month-end inventory snapshot exists, the first
  being 2026-11-01), the type is nothing under 30 px and there is NO logo on
  the card (Nikki: the watermark carries the brand). Caveats shown with the
  figures: IDX data runs under a board's own totals; CREN, elevateMLS and
  REcolorado days on market are on-market to closing. Details:
  docs/HANDOFF-market-report.md.

### Render harnesses: run them on a server
The stub-mysqli harnesses (`tests/render_*.php`) run pages under `php -n`
so the stub is the only `mysqli`. Homebrew's PHP 8.5 on Nikki's Mac compiles
mysqli in, so they fail there with "Cannot redeclare class mysqli". Run them on
the marketing server: copy the repo to /tmp, `php tests/render_users.php`,
delete the copy. `render_users.php` is also intermittently flaky (a different
single assertion fails now and then, before and after 2026-10-01): a race
between rewriting scenario.json and the next request; rerun before chasing it.

## Upcoming projects (not started)

- **Retire the frozen Spark-era roster views.** `office_roster` / `mh_brokers`
  stopped updating 2026-10-01 (Spark syncs retired). Still reading them:
  `index.php`'s first UNION arm and Onboard action, `roster.php` (whole page,
  incl. an UPDATE), and `sync_anyprop_roster.php` (read-only, to link a new
  agent to an old row). Plan: roster rows from `marketing_intakes` only, then
  drop `roster.php` and the tables (the dead crons went 2026-10-19). `intake.php` no longer
  writes office_roster or calls Spark (2026-10-01).

- **Agent portal**: `docs/AGENT_PORTAL_PLAN.md` (revised 2026-10-01 with Nikki's
  decisions: dummy-proof cards, creative shown on the portal, spend without
  invoiced/paid, a marketing menu, Weber Boxer Group as one account).
- **Client Hot Sheets (Phase 2, proposed 2026-10-07):** an agent sends a
  client only the listings matching filters that work like the website
  search, with an unsubscribe link. The site runs it (matcher, sender,
  unsubscribe, API); this portal is the agent's form. Plan:
  site.monthaus.com/docs/HANDOFF-client-hot-sheets.md. Supersedes the
  "Custom Hot Sheets" note below.
- **Custom Hot Sheets with filters**: agents' own Hot Sheets searching ALL
  listings, not just Mont Haus's. Deliberately separate from the portal and
  big: needs a market-wide listings source, saved searches, and each board's
  rules on emailing other brokers' listings. See the end of the portal plan.
- Leadership page + staff: `docs/HANDOFF-leadership-and-staff.md`.

## Marketing tool structure

- `index.php` — agent roster. `office_roster` UNION ALL intake-only agents so newly
  added agents appear before they exist in the roster.
- `agent.php` — the primary editing surface. All adding/editing happens here and in its
  tabs: Overview | Tasks | Collateral | Advertising | Assets & Docs | Notes.
- `intake.php` — legacy form, largely superseded by `agent.php`.
- `order_split.php` — one vendor invoice, several agents. Writes the landed cost into
  each agent's collateral order. See "Splitting one invoice across several agents".
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

Currently: nikki.boxer, jonathan.boxer, jm.drai, mary.lappe, and the portal
pilot (2026-10-06): scott.weber, sara.perkowski, bryan.cournoyer, jackson.horn.

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
import/  bulk-profile inputs (bios, headshots, profiles.csv)
```

Two consequences worth remembering:

- Pages include with `__DIR__ . '/inc/x.php'`; files inside `inc/` include
  their siblings bare, as `__DIR__ . '/x.php'`.
- `RECEIPTS_DIR` in `inc/config.php` is `__DIR__ . '/../../receipts/'` —
  **two** levels up, because config.php sits one deeper than the docroot.
  One level would put receipt uploads inside the web root.

If you add a new include-only file, put it in `inc/`. If you add a page, it
goes at the docroot and is reachable — check whether it should be.

## Follow Up Boss on the agent profile (2026-10-06)

**2026-10-16 (Nikki): the card shows only the standing pill (Active / Invited
/ Not a FUB user / Unknown), the "In the FUB lead rotation" box and the
service-area boxes.** The explanations (which email FUB was matched on, when
the website last checked, what opting in does, how the town list works) are
gone from the page; this section is where that knowledge lives now.

The public site is the only system that talks to Follow Up Boss (its key,
its sync, its lead push). This portal holds no FUB key and no FUB ids. The
"Follow Up Boss" card on the Profile tab (agent.php, action `update_fub`) has:

- **In the FUB lead rotation** (`marketing_intakes.in_fub`), moved out of the
  Website form. Opted out = inquiries on their listings reach them by email
  and FUB never sees them.
- **FUB account**, read-only, from the site's `api/fub_users.php` through
  `inc/fub.php` (live, 6 s; falls back to `/var/log/mh-marketing/fub_users.json`,
  which cron/sync_anyprop_roster.php refreshes hourly, since Apache cannot
  write that folder): Active / Invited (not accepted) / Not a FUB user, with
  the email the site matched and when it last checked. The site matches FUB
  users to agents by the profile email, then the MLS email; an agent is only
  usable for leads when ticked AND Active. FUB bills per user, so only agents
  who work website leads are added there (Nikki).
- **Service areas** (`marketing_intakes.fub_areas`, JSON list of town keys,
  sql/agent_fub_areas.sql): the website's Communities list, which the site
  publishes as `areas` in the same api/boards.php this portal already caches
  (inc/boards.php: `mk_areas()`, `mk_area_keys()`; MK_AREAS_FALLBACK only
  until the first fetch). An area header ticks all its towns. The roster feed
  (api/roster.php) sends `fub_areas` once the column exists; the site gives a
  lead on another brokerage's listing to an opted-in, Active agent who ticked
  its town, and a town the list does not name to agents with the whole area
  ticked. The free-text Service area field stays for the public regional
  pages and office address; it no longer routes leads. Edit the town list on
  the site (its `_communities.php`), never here.
