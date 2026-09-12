# Agent Portal: build plan

**Status:** proposal, not yet started. Nothing here has been built.
**Written:** 2026-09-08
**Scope:** giving Mont Haus agents a signed-in view of their own marketing, and a
way to request work, inside `marketing.monthaus.com`.

Decisions already made (Nikki, 2026-09-08):

- Agents see **all three columns** on their financials: Agent / Mont Haus / Undecided.
- Creative images are **uploaded to the server**, following the `receipt.php` pattern.
- A request is **recorded and emailed**; marketing converts it into a real order or
  placement by hand. Nothing an agent submits touches the money tables.

---

## 1. Where the site actually stands today

Read before planning anything, because two of these are load-bearing and one is a
hole that has to be closed before a single agent signs in.

### There is no link between a user account and an agent record

`users` has `id, first_name, last_name, email, role, mls_id, agent_key, entra_object_id`.
`marketing_intakes` has `agent_name, mh_email, roster_id`. **Nothing joins them.**

Every page in the app takes the agent from the URL (`agent.php?id=42`) and trusts
an admin to have typed the right number. A portal cannot do that. The whole
portal rests on answering "which intake row is the person signed in right now?"
and there is currently no way to answer it.

### Every page is admin-gated, and one is not

```
index.php:13        require_role('admin')
agent.php:12        require_role('admin')
billing.php:20      require_role('admin')
intake.php:14       require_role('admin')
order_split.php:82  require_role('admin')
receipt.php:24      require_role('admin')
users.php:36        require_role('super_admin')
roster.php:8        require_login()          ← no role check
```

`roster.php` is the hole. It renders the full office roster including Spark agent
keys and both MLS member IDs, and it asks only that you be signed in. Today that
is harmless: `ACCESS_ALLOWLIST` is three admins, and no agent has an account. The
moment an agent gets one, that page is open to them. **Fixing this is step one of
phase 0 and is not optional.**

The good news: `require_role()` already carries `agent` at level 0 in its
hierarchy, so the guard needs no change, only a call added.

### The money is already one function, and it already takes an intake id

```php
mh_agent_financials(mysqli $conn, int $id): array
```

Returns `months`, `grand`, `coll_rows`, `camp_overrides`, split three ways per
line. `agent.php` renders it for one agent; `billing.php` runs it across
everybody. The agent's own financial page is close to free: same function, same
intake id, a read-only renderer. That is the single biggest reason this project
is a few weeks rather than a few months.

The one thing it does not return is invoiced/paid state. That lives in
`marketing_billing_months` (`billed_at`, `paid_at`, `billed_amount`) and
`billing.php` reads it separately. The portal needs the same read.

### Creatives are links, not images

`marketing_campaign_assets.file_url` is a Dropbox or Drive share URL, and
`marketing_collateral_orders.file_url` is the same. Neither renders as an image.
Showing agents the actual creative means storing actual files, which is phase 3.

### Everything else worth knowing

- `mailer.php` sends via SendGrid and defaults the From address to the session
  user's email. The comment says `@wbaspen.com` is covered by SendGrid domain
  auth. **Confirm `monthaus.com` is authorized before relying on portal email**,
  or the request notifications will silently fail to deliver.
- `index.php` closes its connection before rendering. Irrelevant to the portal,
  but do not copy that page as a template without noticing.
- Migrations are hand-run in TablePlus, non-idempotent, no `IF NOT EXISTS` on
  `ALTER TABLE`, and the `STATUS:` header line is the only record of what has
  been applied.

---

## 2. The identity link: `users.intake_id`

This is the decision everything else hangs off, so it gets its own section.

**Recommendation: an explicit `users.intake_id` column, set by hand in `users.php`.**

Not email matching. The SSO design already learned this lesson and wrote it down:
`sso_resolve_user()` matches on email exactly once, stamps `entra_object_id`, and
from then on the address can change and the link holds. Matching an agent to
their intake on `users.email = marketing_intakes.mh_email` at every page load
would reintroduce the fragility that design removed. Agents change names, get
their MH address rebuilt, and appear in `mh_email` with a typo. A row id does not.

```sql
ALTER TABLE users ADD COLUMN intake_id INT UNSIGNED NULL
  COMMENT 'FK -> marketing_intakes.id. The agent record this login speaks for.';
ALTER TABLE users ADD INDEX idx_users_intake (intake_id);
```

Rules that come with it:

- **Nullable, and null means "not an agent."** Admins have no intake row and
  should not get one. A null `intake_id` on a user whose role is `agent` is a
  misconfiguration, and the portal must say so plainly rather than showing an
  empty page. That failure mode is exactly the blank-role lockout in
  `alter_users_role_v1.sql`: silent, invisible in logs, and diagnosed only by
  someone reading the column.
- **Set in `users.php` and nowhere else.** That page is already the only surface
  that writes `users.role`; the same argument applies. Add a select listing
  active intakes, showing which are already claimed.
- **One intake, one login.** Enforce it in the page, not with a unique index,
  because a team assistant sharing a principal's view is a plausible future ask
  and a unique index would need dropping to allow it. Warn on a duplicate,
  refuse to save it.
- **Copied into the session at sign-in.** `login.php` and
  `sso_establish_session()` both populate eight keys today and CLAUDE.md is
  explicit that a ninth must be added to both. `intake_id` is that ninth key.

**Where the session copy bites you:** the role is already copied at sign-in,
which is why `users.php` refuses to let you change your own role. `intake_id` has
the same property. Re-pointing an agent's login at a different intake will not
take effect until they sign in again. That is fine, but the portal should read
`intake_id` from the session and re-verify it against the database on every
request that shows money, so a revoked link cannot outlive the session.

---

## 3. The security model

One invariant, stated once, testable:

> **A portal page never takes an agent identifier from the request.**
> Not from `$_GET`, not from `$_POST`, not from a hidden field. The intake id
> comes from the session, is re-verified against `users`, and every query is
> scoped by it.

Everything else follows from that.

### `require_agent_scope()`

A new function in `inc/auth.php`, next to `require_role()`, following its rules
exactly: it fails with 403 and a short page, and it never redirects. CLAUDE.md
explains why in detail; the summary is that a redirect from a guard can point at
a page that runs the same guard, and the result is `ERR_TOO_MANY_REDIRECTS` with
nothing in any log.

```php
function require_agent_scope(mysqli $conn): int   // returns the intake id, or exits 403
```

It calls `require_login()`, reads `$_SESSION['intake_id']`, re-reads it from
`users` (guarding against a link revoked mid-session), confirms the intake row
exists and `is_active = 1`, and returns the id. On any failure it prints the same
kind of self-diagnosing 403 the role guard prints: the signed-in email, and what
was missing. That page turns "the portal is broken" into "your login is not
linked to an agent record yet" without anyone opening a database.

Admins get the same function with a documented override so they can preview an
agent's portal: an `admin_preview` query parameter honoured **only** when
`is_elevated_admin()` is true, which stamps a visible banner on the page. Worth
building on day one, because otherwise every support question means asking the
agent for a screenshot.

### Separate pages, not role branches inside `agent.php`

`agent.php` is 4,457 lines with edit affordances on essentially every row.
Branching it on role would mean auditing every one of them, and one missed `if`
leaks another agent's cost basis or an internal note. A separate `portal/`
directory has a much smaller surface, and its correctness is one property that a
test can assert.

```
portal/index.php      home / dashboard
portal/marketing.php  their profile, assets, listings (read-only)
portal/orders.php     collateral orders, status, tracking, proofs
portal/ads.php        placements, spend, creatives
portal/financials.php month by month, three columns
portal/request.php    the ordering form
portal/asset.php      streams a file after an ownership check
inc/portal.php        shared portal helpers and renderers
```

`.htaccess` denies `inc|sql|tests|cron|vendor|deploy|docs`. `portal/` is not in
that list and holds pages, which is consistent with the docroot rule ("the
docroot holds only pages"). Pages inside it include as `__DIR__ . '/../inc/x.php'`.

### Files

`receipt.php` requires `admin`, so agents cannot see their own receipts through
it. Do not relax that check. Add `portal/asset.php`, which takes an order id or a
creative id (never a path, never a filename), resolves it through the session's
intake id, and streams the file with the same hardening `receipt.php` already
uses: `basename()` on the stored name, a MIME allowlist, `X-Content-Type-Options:
nosniff`, `Cache-Control: private, no-store`.

Two pages doing the same thing is deliberate here. They answer different
questions ("is this person an admin" vs "does this person own this row") and
folding them together would mean one function holding both, which is how the
wrong branch eventually runs.

### Nav

Reuse `inc/_nav.php` with a `$nav_mode = 'portal'` that swaps the link set.
CLAUDE.md is explicit that the `layout-extended` 169px override lives in that
partial precisely so a new page cannot inherit the bug, and that the 820px
breakpoint was measured against the links actually present. A separate portal nav
partial would duplicate both and drift. One partial, two link sets, and the
breakpoint gets re-measured because the portal's link set is different.

---

## 4. Phases

Ordered so that each one is shippable and the risky parts come after the cheap
wins have proved the model.

### Phase 0: close the holes (before any agent has an account)

1. `roster.php` gets `require_role('admin')`.
2. Audit every page for the same omission. `grep -n "require_role\|require_login" *.php`
   is the whole audit and takes a minute.
3. Decide `ACCESS_ALLOWLIST` policy. It is independent of role by design, so a
   pilot agent needs adding there **and** an `agent` role **and** an `intake_id`.
   Three things that must agree. Worth a one-line note on the 403 page so the
   confusion diagnoses itself.

No migration. Half a day.

### Phase 1: identity

- `sql/portal_identity_v1.sql`: `users.intake_id` + index.
- `users.php`: intake picker on the create and edit forms, showing claimed rows.
- `login.php` and `sso_establish_session()`: the ninth session key, both paths.
- `inc/auth.php`: `require_agent_scope()`.
- `portal/index.php`: a page that says "Hello, here is your name and your intake
  id." Nothing else. It exists to prove the link works end to end.

One migration. Two to three days.

### Phase 2: the read-only portal

This is the bulk of the value and touches no new money code.

- **`portal/index.php`** Their name, headshot, at-a-glance: open orders, current
  balance, active placements, anything waiting on them.
- **`portal/marketing.php`** Their profile as marketing holds it (bio, headshot,
  signature, QR, MLS IDs, socials), their active listings, all read-only, with a
  "Request a change" button next to each block that opens a request rather than a
  form field.
- **`portal/orders.php`** Collateral orders from `marketing_collateral_orders`.
  Status chip, order date, vendor, quantity, cost, tracking number and link.
  Everything except the receipt and the internal notes.
- **`portal/ads.php`** Placements from `marketing_campaigns`, with per-month spend
  from `mk_camp_resolve()`, run dates, status, target URL. Creatives appear here
  as links in phase 2 and as images in phase 3.
- **`portal/financials.php`** Month by month, three columns, from
  `mh_agent_financials()` plus the invoiced/paid state out of
  `marketing_billing_months`.

Reuse the renderers rather than rewriting them. The strongest option is to lift
the Financials tab's table out of `agent.php` into `inc/portal.php` (or a shared
`inc/fin_render.php`) as a function taking a read-only flag, and have both pages
call it. Same argument as `inc/financials.php` itself: two implementations of the
same table will eventually disagree, and the first sign is an agent quoting a
number that is not on Nikki's screen.

**On the three columns.** Agents seeing what Mont Haus absorbed is the point, and
it is a real recruiting and retention argument. Two things to get right:

- The "Undecided" column must stay visible, exactly as `mk_fin_split()` keeps it
  visible on the admin side. An agent seeing a line in neither column will ask
  about it, which is the correct outcome; folding it into either column would
  either overstate what they owe or quietly promise them a subsidy.
- Mont Haus's share has no paid state and none should be invented. It is absorbed
  spend, not a receivable. The portal must not render a Paid checkbox against it.

No migration. One to two weeks.

### Phase 3: creatives and proofs as images

The receipts pattern generalizes cleanly.

- `sql/portal_creatives_v1.sql`
  - `marketing_campaign_assets`: `image_file`, `image_orig_name`, `image_uploaded_at`
  - `marketing_collateral_orders`: `proof_file`, `proof_orig_name`, `proof_uploaded_at`
- A new `CREATIVES_DIR` in `inc/config.php`, outside the web root, alongside
  `RECEIPTS_DIR`. Note that constant is `__DIR__ . '/../../receipts/'`, **two**
  levels up because `config.php` lives in `inc/`. The new one is the same shape
  and gets the same comment.
- Upload UI on `agent.php`'s Advertising and Collateral tabs. `mk_store_receipt()`
  at agent.php:452 already does exactly this job for receipts and takes a field
  name argument, so it is likely reusable as-is.
- `portal/asset.php` streams them. `receipt.php` grows an admin path for the same
  files.

The existing `file_url` column stays and keeps working. An uploaded image is an
addition, not a replacement, so no historical placement changes and nothing has to
be backfilled. Where both exist, show the image and keep the link as "Open
original."

**Constraints worth setting now.** The box has 1 GB of RAM and it is shared by
Apache, PHP and nothing else, because MySQL is managed and off-box. Cap uploads at
something like 8 MB, accept only JPEG, PNG, PDF and GIF, and generate no
thumbnails on the server. Serve the original and let CSS size it. Image
processing on that instance is where OOM kills start.

One migration. About a week.

### Phase 4: requests and ordering

The part with the most design in it, so it gets its own section below.

### Phase 5: everything in section 6

Picked off individually once the portal has real users and you know which of them
they actually ask for.

---

## 5. The ordering system

### Two tables of catalog, one table of requests

**Catalog: what can be ordered.**

```
marketing_products          business cards, postcards, brochures, yard signs,
                            open house signs, listing brochure, ...
                            (slug, name, blurb, active, sort_order,
                             indicative_cost, lead_time_days)

marketing_product_designs   the pickable designs under a product
                            (product_id, name, preview_file, active, sort_order)

marketing_ad_placements     outlet + placement + size + rate
                            (platform, name, size_label, rate, rate_unit,
                             billing_mode_hint, active, notes)
```

`marketing_ad_placements.platform` uses the same slugs as `$platform_labels` in
`agent.php:1047` (`vail_daily`, `aspen_daily`, `aspen_times`, `social_meta`,
`social_instagram`, `google`, `other`). Same vocabulary on both sides of the
handoff, so accepting a request into a placement does not need a translation
table.

`billing_mode_hint` is a hint and nothing more. It pre-selects the mode when
marketing accepts the request, and does not bill anything. The rule that governs
the whole advertising module still holds: no figure is ever repeated forward
unless somebody said to repeat it, and an agent asking for a placement is not
somebody saying that.

`marketing_product_designs.preview_file` is a real uploaded image, served through
`portal/asset.php` like everything else. Agents pick from pictures, not from a
dropdown of names.

**Requests: what was asked for.**

```
marketing_requests
  id, intake_id, requested_by (users.id), request_type,
  status              new | reviewing | accepted | declined | cancelled
  listing_id          nullable -> listings.id
  product_id          nullable
  design_id           nullable
  placement_id        nullable
  needs_mailing_list  nullable tinyint   (1 = MH sources it)
  target_areas        text
  preferred_date      date               (send-by, or run start)
  quantity            varchar
  notes               text
  extra_json          text               type-specific overflow
  created_at, reviewed_at, reviewed_by,
  accepted_order_id, accepted_campaign_id
```

The typed columns cover the fields that recur across request types; `extra_json`
holds the ones that do not. That is a compromise and worth naming as one: this
codebase's convention is explicit typed columns (`marketing_intakes` has well
over a hundred), and JSON in a column is not queryable in the same way. The
argument for the hybrid is that request types will multiply and each new one
should not need a migration. If a field in `extra_json` starts mattering to a
report, promote it to a column then.

`accepted_order_id` and `accepted_campaign_id` are the audit trail. Once
marketing accepts a request, the row it became is recorded on the request, so
"what did we do about this" is answerable a year later without reading email.

### The flow

1. Agent picks a request type on `portal/request.php`.
2. Type-specific form. Direct mail asks: which listing, which design, do you have
   a mailing list or should Mont Haus source one, what areas, preferred send-by
   date. Digital asks: which outlet, which placement, which listing, preferred
   start date.
3. Submit writes one `marketing_requests` row with `status = 'new'` and emails
   `marketing@monthaus.com` with everything on the form and a deep link back to
   the request in admin.
4. Agent sees the confirmation: "the marketing team will reach out to review
   options," and the request appears on their portal home with its status.
5. Nikki works a new `requests.php` inbox. Accepting opens a prefilled create
   form for a collateral order or a placement. **Marketing types the money.**
   Nothing an agent submits ever reaches `cost`, `budget`, `unit_rate` or
   `marketing_campaign_months`.

That last line is the whole reason for the holding table. `order_split.php`
already refuses to save an invoice whose parts do not reconcile rather than
absorbing a mistyped figure, and the campaign module refuses to repeat an amount
nobody entered. Letting an agent's form write into either would undo both.

### Email

`send_email()` defaults From to the session user, so the portal must pass an
explicit From. Send **as the agent, reply-to the agent, to marketing@**, so
hitting reply in the inbox reaches the person who asked. That needs the agent's
`@monthaus.com` address to be sendable through SendGrid, which is the domain
question above. If it is not, fall back to sending from a verified system address
with the agent in Reply-To.

Also: the row is written before the email is attempted, and a failed send is
logged and shown to the agent as "submitted, but the notification did not go
out." An email that silently fails while the agent sees a success page is the
worst possible outcome, and `send_email()` returning false is easy to ignore.

### Phase 4 sizing

Two migrations (catalog, requests), the portal form, the admin inbox, the accept
conversion, and the email. Two to three weeks, and the catalog needs content
typed into it before it is useful, which is Nikki's time rather than build time.

---

## 6. What else the portal could do

Ordered by value over effort. The first four are close to free because the data
already exists.

**Order status and tracking (free).** `marketing_collateral_orders` already
stores `status`, `tracking_number`, `tracking_url`, `delivered_at`. Rendering
them removes an entire category of "where are my cards" emails, and it costs
nothing beyond phase 2.

**Their onboarding checklist (nearly free).** `marketing_tasks` with
`category = 'onboarding'` already holds the flat nine-item list per agent.
Showing a new agent what is done and what marketing is waiting on **from them**
is the single most useful thing a brand-new agent could see in week one.

**Brand and asset library (cheap).** Two halves. Their own assets are already in
`marketing_intakes`: `headshot_url`, `email_signature_url`, `qr_code_url`,
`bio_text`, `bio_short`, plus whatever is in `marketing_asset_links`. Shared
assets (logos, letterhead, templates, brand guidelines, presentation shells) need
one small table. Both are downloads, no editing, which fits the "agents cannot
edit their info" rule exactly.

**Proof approval queue (high value, moderate effort).** Right now a proof goes
out by email and someone chases it. A queue of "these are waiting on your
approval," with Approve or Request changes and a timestamped record, replaces
that chase and creates the paper trail for "you approved this spelling." Builds
naturally on `marketing_tasks` with an approval state and a file, or on a small
`marketing_proofs` table hanging off orders and campaigns. Recommend the latter,
because a proof belongs to an order, not to a checklist.

**Listing marketing snapshot (moderate).** For each of their active listings, one
card showing what has been done: brochure ordered, postcard mailed on this date,
running in Aspen Times through this date, signs delivered. It is a join across
`listings`, `marketing_campaigns` and `marketing_collateral_orders`, and it is the
view an agent actually wants when a seller asks "what are you doing for my
house." That is also the view that sells the portal internally.

**Publication deadlines (small, high leverage).** A tiny
`marketing_deadlines` table of outlet cutoffs, surfaced on the request form when
an agent picks a send-by or start date. It prevents the most common back-and-forth
in the whole process, which is an agent asking for a Thursday run on Wednesday.

**Rate card (free once the catalog exists).** The `marketing_products` and
`marketing_ad_placements` tables are a price list. Publishing indicative costs on
the request form lets agents self-select, which cuts the "what does a postcard
cost" thread before it starts. Label them indicative, not quotes.

**Statement export (small).** A CSV or PDF of one month's lines for an agent's own
bookkeeping. Nothing new is computed; it is `mh_agent_financials()` output in a
different wrapper.

**Notifications (small each).** Order shipped, month invoiced, request accepted or
declined, proof waiting. Each is a `send_email()` call at an existing state
change. Add them one at a time as the state changes get built, not as a batch at
the end.

**Marketing allowance tracking (needs a decision, not just a build).** If Mont
Haus gives agents an annual or monthly marketing allowance, showing spent and
remaining against it is the most motivating number on the whole portal. It needs
a column (`marketing_intakes.annual_allowance` or similar) and, more importantly,
a policy decision about what counts against it. Worth asking before building.

**The one thing to be honest about: ad performance.** Agents will ask for
impressions and clicks. The schema has nowhere to put them, and the outlets report
by PDF and spreadsheet on their own schedule. The realistic version is a
per-month "results" note and an uploaded report file on a placement, shown in the
portal. Anything more is an integration project per outlet and should not be
promised in this phase.

---

## 7. Testing

The repo's convention is that a test extracts the real function rather than
copying it, and that a stub is stricter than the real thing rather than more
forgiving. Both apply here.

**`tests/render_portal.php` is the important one.** Build a fixture set with two
agents, A and B, each carrying orders, placements, months and distinctive
figures. Render every portal page as A. Assert that **none** of B's figures,
names, order numbers or file ids appear anywhere in the HTML. That is the whole
security model as one assertion, and it fails loudly the first time somebody
writes a query without a `WHERE intake_id = ?`.

Use the strict stub `mysqli` from `tests/render_order_split.php`: `close()` sets a
flag every `query()`/`prepare()` checks, `bind_param()` throws when the type
string and argument count disagree, and an unfixtured statement throws rather than
returning no rows. That last property is what makes the isolation test meaningful.
A forgiving stub would return nothing for an unscoped query and the test would
pass on a page that leaks in production.

**`tests/test_portal_scope.php`.** Unit-level assertions on
`require_agent_scope()`: no session gives 403, a session with no `intake_id` gives
403, an `intake_id` pointing at an archived or missing intake gives 403, a valid
one returns the id, and the admin preview path is refused for a non-admin.

**Existing suites must still pass.** Phase 3 adds columns to
`marketing_campaign_assets` and `marketing_collateral_orders`, both of which
`test_campaign_billing.php` (64 assertions) and `test_invoice_split.php` (70
assertions) touch. Run them.

---

## 8. Risks and open questions

**SendGrid domain authorization for monthaus.com.** `mailer.php`'s comment says
`@wbaspen.com` is covered. If `monthaus.com` is not, every portal notification
fails at send. Check this before phase 4, not during it.

**Three gates that must agree.** A pilot agent needs an `ACCESS_ALLOWLIST` entry,
a role of `agent`, and an `intake_id`. Missing any one produces a different
failure, and two of them are 403s that look similar. The 403 pages should each say
which gate stopped the person.

**The blank role.** `alter_users_role_v1.sql` documents it and `users.php` guards
it: a role value the enum cannot store becomes the empty string, which is below
every threshold, locking that person out of everything with nothing in any log. It
is the known origin of the blank role on `nikki.boxer@monthaus.com`. Adding agent
accounts multiplies the chances of hitting it. Check the column first whenever an
agent reports being unable to get in.

**Disk and memory.** 1 GB of RAM, and phase 3 puts files on it. Cap upload size,
allowlist MIME types, do no image processing on the box, and keep an eye on the
receipts and creatives directories.

**Read-only is a claim that has to stay true.** Every portal page starts read-only.
Phase 4 adds exactly one write path (a request row) and phase 5 may add a second
(a proof approval). Each write path is a place where the scope invariant has to be
re-checked, because a POST carries an id and the temptation to trust it is
stronger than on a GET.

**Open questions for Nikki:**

1. Is there a marketing allowance per agent, and what counts against it?
2. Should an agent see their receipts (`receipt_file`), or only the cost?
3. Team accounts: does an assistant ever need to see a principal's portal?
4. Should the portal show archived or offboarded agents anything, or refuse at
   sign-in? `is_active` on both `users` and `marketing_intakes` already answers
   this if you pick an answer.
5. Which two or three agents are the pilot?
