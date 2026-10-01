# marketing.monthaus.com

The Mont Haus marketing tool, running as a standalone site on its own
subdomain. Replicated from `monthausint.com/marketing`, with the hub
dependencies folded in and Microsoft Entra ID sign-in replacing the shared
password login.

PHP 8 on an AWS Lightsail instance (Debian, LAMP blueprint) with a Lightsail
managed MySQL 8.4 database. No build step.

---

## What changed from the hub version

The app used to live at `monthausint.com/marketing/` and lean on the hub for
authentication, database access and the asset framework. It now stands alone:

| Then | Now |
|---|---|
| `/marketing/index.php` | `/index.php` — the app is the whole site |
| `require '../auth.php'` | `require './auth.php'` — its own copy |
| `require '../hot-sheets/db.php'` (shim) | `require './inc/db.php'` |
| `/assets/` shared with three other apps | its own `assets/` tree |
| Email + password login on the hub | Microsoft sign-in, break-glass password for super_admin |
| SiteGround shared hosting | its own Lightsail instance |
| the hub's shared MySQL database | its own managed database, `dbmarketing_monthaus` |
| Receipts at `../../receipts/` | `RECEIPTS_DIR`, still one level above the docroot |
| Header markup copied into each page | one `_nav.php` partial |

It has its **own database**. 13 of the hub's 33 tables were carved out into
`sql/bootstrap.sql` and imported once; the rest — `mls_listings`, hot sheets,
the email broadcaster, pipeline events — belong to b2b and hot-sheets and are
not here.

The tables that look shared are not really hub-owned data: `office_roster`,
`mh_brokers`, `listings` and `listing_brokers` are all caches of the Spark MLS
API, and `cron/` rebuilds them here from that same API. `users` is the one
genuine fork — 17 accounts came across, and from now on an account created on
the hub does not appear here. With Entra as the identity source that table is
an authorization list rather than a credential store, which is what makes the
split tolerable.

---

## Layout

```
/                    the docroot — every file here is a page, i.e. a URL
├── index.php        agent roster — the landing page
├── agent.php        the primary editing surface
│                    tabs: Overview | Tasks | Collateral | Advertising | Assets & Docs | Notes
├── intake.php       legacy intake form, largely superseded by agent.php
├── roster.php       office_roster admin — MLS key linking
├── receipt.php      streams a collateral receipt after an auth check
│
├── login.php  logout.php  oauth_start.php  oauth_callback.php
├── change_password.php  forgot-password.php  reset-password.php  set_password.php
│
├── inc/             include-only PHP — never a URL
│   ├── auth.php     session bootstrap + require_login() / require_role()
│   ├── db.php       DB credentials and API keys — NOT in git, created on the server
│   ├── config.php   SITE_URL, HUB_URL, RECEIPTS_DIR
│   ├── sso.php      OIDC engine: PKCE, JWKS, token verification
│   ├── sso_config.php  tenant/client/secret — NOT in git, created on the server
│   ├── mailer.php   PHPMailer wrapper for password resets
│   └── _nav.php  _footer.php  _onboarding.php
│
├── assets/          css, fonts, images, js, marketing templates, pdfs
├── vendor/          PHPMailer
├── sql/             bootstrap.sql + migrations, run by hand
├── cron/            sync_roster.php, sync_mh_brokers.php — Spark API pulls
├── tests/           test_sso.php — offline sign-in test suite
├── deploy/          vhost, db.sample.php, sso_config.sample.php, composer.json
└── docs/            DEPLOY_LIGHTSAIL.md, ENTRA_SETUP.md, TESTING.md
```

The docroot deliberately holds **only pages**. Everything under `inc/`, `sql/`,
`tests/`, `cron/`, `vendor/`, `deploy/` and `docs/` is denied by both
`.htaccess` and the vhost — `inc/` in particular holds `db.php` and
`sso_config.php`, which carry the database password and the Entra client
secret.


`receipts/` sits **outside** the web root, at `/var/www/receipts/`. Note that
`RECEIPTS_DIR` in `inc/config.php` is therefore `__DIR__ . '/../../receipts/'` —
two levels up, because config.php lives in `inc/`. One level would land it
inside the docroot, which is the thing the constant exists to prevent.
`agent.php` creates it
on first upload. Receipts carry vendor and cost detail, which is why they are
never web-reachable — `receipt.php` streams them after checking the session.

---

## Deploying

Full walkthrough — SSH keys, Nova publishing, Apache, TLS, MySQL, cron — is in
**DEPLOY_LIGHTSAIL.md**. The short version:

1. Create the managed database, then load `sql/bootstrap.sql`,
   `sql/assets_tab_v2.sql` and `sql/sso_schema.sql`, in that order.
2. Publish everything except `db.php` and `sso_config.php` (both gitignored).
3. Create those two **in `inc/`** on the server, from `deploy/db.sample.php`
   and `deploy/sso_config.sample.php`. Keep the `sql_mode` line in `db.php` — without it
   `index.php` and `roster.php` return a 500. See DEPLOY_LIGHTSAIL.md.
4. Confirm `.htaccess` uploaded — hidden files are easy to miss over SFTP, and
   Apache needs `AllowOverride All` to honour it. Open `/inc/db.php` in a browser:
   you want **403**, not a blank page.
5. Install the two cron jobs from `cron/`.

There is no composer step. `vendor/` holds PHPMailer and is committed as-is;
the sign-in code has no third-party dependencies at all.

---

## Sign-in

Microsoft Entra ID, OpenID Connect authorization-code flow with PKCE. Full
setup and troubleshooting is in **ENTRA_SETUP.md**; the security properties
are summarised at the end of that file and the reasoning lives in `sso.php`.

Three things worth knowing up front:

- **Accounts are never created by signing in.** A Microsoft account with no
  matching `users` row is refused with a clear message. Create the portal
  account first.
- **First sign-in links on email, everything after that on the Entra `oid`.**
  So renaming someone's mailbox does not break their access.
- **super_admin keeps a password.** That is deliberate — it is the way back in
  the day the client secret expires. Do not remove the last one.

`auth.php`, `require_login()` and `require_role()` are untouched by any of
this. Both sign-in paths populate the identical session keys, so the app has
no idea which one was used.

---

## Related

- `CLAUDE.md` — conventions and the gotchas this codebase has actually hit
- `docs/DEPLOY_LIGHTSAIL.md` — server, database and publishing setup
- `docs/ENTRA_SETUP.md` — the Microsoft side, step by step
- `docs/TESTING.md` — how to re-run the sign-in test suite
- `sql/` — bootstrap plus migrations, newest last
