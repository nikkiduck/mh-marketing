# Testing

All offline — no network, no live database, no Microsoft tenant.

```bash
php tests/test_sso.php               # sign-in
php tests/test_campaign_billing.php  # advertising money
php tests/test_invoice_split.php     # one invoice across several agents
php tests/render_billing.php         # the collections page
php tests/render_order_split.php     # the invoice-split page
php tests/render_users.php           # accounts and roles
```

Exit code 0 means every assertion passed; the last line is a summary.

---

# Testing the sign-in code

`tests/test_sso.php` exercises `sso.php` offline — no Microsoft tenant, no
network, no database. It mints its own RSA key, publishes it the way Entra
publishes its JWKS, signs tokens with it, and checks the verifier's verdict on
each one.

Run it after any change to `sso.php`:

```
php tests/test_sso.php
```

Exit code 0 means every assertion passed. The last line is a summary.

It needs PHP 8 with ext/openssl — the same requirements as the site. It writes
one temp file (the seeded JWKS cache) and deletes it on the way out.

## What it covers

**The JWK-to-PEM conversion** — that the DER SubjectPublicKeyInfo built from
the modulus and exponent is byte-for-byte the key OpenSSL would have produced.
This is the fiddliest code in the file and the most worth pinning down.

**A valid token** is accepted and its claims survive intact.

**Thirteen tokens that must be rejected**, each standing in for a real attack
or a real failure mode:

| Case | What it would mean if accepted |
|---|---|
| tampered payload | anyone could edit claims in a real token |
| `alg: none` | signature checking skippable by asking nicely |
| `alg: HS256` signed with the public key | the classic RS256→HS256 confusion |
| signed by a different key | any key would do |
| unknown `kid` | key substitution |
| wrong `aud` | a token for another app accepted here |
| wrong `tid` | **a token from any other Microsoft tenant accepted here** |
| wrong `iss` | an impostor issuer |
| expired | replay of an old token |
| `nbf` in the future | clock games |
| different nonce | replay across sign-in attempts |
| no `oid` | an account we cannot pin an identity to |
| not a JWT | garbage handled cleanly, not fatally |

**Audience as an array** — Entra sometimes sends `aud` as a list; our client id
appearing in it must count as a match.

**The open-redirect guard** on `return_to` — absolute URLs, protocol-relative
URLs and bare relative paths are all rewritten to `/index.php`; only a rooted
path on this site survives.

**PKCE and the authorize URL** — that the challenge really is
`base64url(sha256(verifier))`, that state and nonce are 32 random bytes each,
that the state in the URL matches the session, and that `response_mode` is
`query` rather than `form_post`.

## What it does not cover

The token exchange itself (`sso_exchange_code`) talks to Microsoft over the
network and is not exercised here. Nor is `sso_resolve_user()`, which needs a
database. Both are covered by the live test in **ENTRA_SETUP.md** Part 3 —
sign in as yourself and confirm `entra_object_id` gets populated.

Note also that the suite includes `sso.php` with the `auth.php` require
stripped out, so it can run without a session or the rest of the app. If you
move code between those two files, check the regex at the top of the test
still matches.


---

# Testing the advertising money

`tests/test_campaign_billing.php` exercises the three billing modes and the
per-month rules. 64 assertions, no database.

```bash
php tests/test_campaign_billing.php
```

It **extracts** `mk_campaign_months()`, `mk_weekday_days()` and
`mk_camp_resolve()` from `agent.php` by regex and evals them, rather than
keeping its own copy. So it always tests the shipped logic, and it aborts with
a clear message if a function stops being findable — each must stay a
top-level function whose closing brace is in column 0.

## The section that matters most

**THE FALLBACK GUARD.** Four assertions proving that a monthly placement with a
`budget` set still bills **nothing** in a month nobody entered, and that
August's figure does not reach December. If those start failing, somebody has
reintroduced an amount that carries forward — the exact bug this design exists
to prevent, and one that is invisible in the UI until it reaches an invoice.

## What else it covers

**The calendar itself.** August 2026 has four Wednesdays and five Saturdays,
February 2026 has four Sundays, and a run clipped to Aug 28–31 contains no
Wednesday at all. Every per-day figure rests on this.

**The real situations, by name:**

| Case | Expected |
|---|---|
| Wednesday marquee $125/day, outlet ran 3 of 4 | $375, flagged corrected |
| Saturday marquee $125/day, five Saturdays | $625, counted from the calendar |
| $400/month impressions starting on the 15th | $400 — never prorated |
| $600 entered for September | Sep $600, Aug still $400, **Oct not set** |

**The two monthly modes.** `monthly_flat` bills its standing `budget` every
month with nothing entered, and a per-month row still overrides one month;
`monthly` bills nothing until a month is entered. A standing buy with no
standing amount is the one case where `monthly_flat` resolves to not-set.

**Months that have not started.** Financials stops at the current month even
when an end date commits the run further out; the grid does not. A placement
starting next month produces an empty list for Financials and one row for the
grid. These assertions are anchored to `date('Y-m')` rather than a fixed month,
so they keep meaning the same thing next year.

**Blank versus zero.** An entered `0` bills nothing and reads as a decision
(`zeroed`); no row at all bills nothing and reads as outstanding (`set` false);
a row carrying only a note is still not set. These three cannot be collapsed —
one is a paused month, one is a month waiting on somebody.

**Per-day edge cases:** no weekday configured charges $0 and says so; a month
the run does not touch produces no line rather than a $0 one; a one-month rate
correction leaves the months either side alone; an overridden payer does not
inherit the placement's split amounts.

## What it does not cover

The rendered HTML. For that, build the render harness described at the end of
the advertising section in **CLAUDE.md** — a stub `class mysqli` under `php -n`
returning crafted rows — and read both the Financials table and the grid's
input attributes out of the output. The one to check by eye is a month with no
figure: `value=""` and the placement's budget appearing only as `placeholder`.
A `value` there would mean the next Save silently commits a number nobody
typed, and no arithmetic test can see that.


---

# Testing accounts and roles

```bash
php tests/render_users.php
```

58 assertions. `users.php` has no arithmetic in it, so unlike the billing
suites there is nothing to prove in isolation. What it *does* have is a set of
guards that only matter on the day somebody trips them — the last super admin,
your own role, a role the enum cannot store — and each one fails either
silently or catastrophically if it stops working.

So this harness does not stop at the markup. It starts a real PHP server over
the sandbox under `php -n` and **POSTs to the page**, asserting on the redirect
each guard actually produced. A guard tested by reading the source is a guard
you believe in; a guard tested by tripping it is one that works.

## The guards it trips

| Guard | Why it exists |
|---|---|
| CSRF token mismatch | nothing is written |
| Invented role (`root`) | an enum cannot store it: outside strict mode MySQL writes `''`, `$hierarchy['']` misses, and that account is below every threshold — locked out of the whole app with nothing in any log |
| `super_admin` while the enum lacks it | same failure, reached by deploying ahead of `alter_users_role_v1.sql` |
| Changing your own role | the role is copied into the session at sign-in, so demoting yourself leaves a session that still works and an account that cannot get back in |
| Demoting the last active super admin | there would be nobody left who can promote anyone |
| Deactivating the last active super admin | same, by the other route |
| Deactivating yourself | you would be locked out immediately |
| Editing a deleted account | fails with a message rather than writing an orphan |
| Duplicate or malformed email | refused before the insert |

Each of those asserts on `k=err` **and** on the specific message, so a guard
that starts firing for the wrong reason fails here rather than passing quietly.

## What else it covers

- **The stub is strict**, on the same terms as `render_order_split.php`:
  `mysqli::close()` sets a flag every `query()`/`prepare()` checks and throws
  after it; an unfixtured statement throws rather than returning no rows;
  `bind_param()` throws on a type/argument miscount; every column comes back a
  string. `users.php` closes its connection before rendering, so anything added
  below that line fails here the way it would on the server.
- **Both migration guards.** With `users.role` still `enum('agent','admin')`
  the option disappears from every form and a banner names the migration; with
  `entra_object_id` missing the page still lists everyone, because an unguarded
  query would render an empty account list with nothing on screen saying why.
- **The allowlist column** appears and disappears with `ACCESS_ALLOWLIST`, and
  the edit row's `colspan` follows the column count in both states.
- **The break-glass warning** is quiet while an active super admin has a
  password and loud when none does.
- **Post/redirect/get** — every write ends in a 302, so a refresh cannot repeat
  a role change.
- **The table sizes with `min-width`, never `width`** — the floors are summed
  out of the stylesheet and compared to the table's own floor.

## What it does not cover

The real database. Every enum, unique index and `NOT NULL` is a stub here, so a
schema that disagrees with the page is not something this can find — that is
what the migration guards are for. It also does not cover `require_role()`
itself, which `test_sso.php` and the guard's own 403 handle.
