# Microsoft Sign-In — Setup

Everything on the Mont Haus side is built and tested. What remains is the
one-time Microsoft registration, three values copied into a config file, and
one SQL migration.

Budget about 30 minutes for Part 1, then 10 for the rest.

---

## Before you start

Two things to confirm:

1. **Mont Haus is on Microsoft 365, not Google Workspace.** If everyone signs
   into Outlook with their `@monthaus.com` address, you are on Microsoft.
2. **You have Global Administrator or Application Developer** in the Entra
   tenant. Without one of those you cannot create the app registration — the
   "New registration" button will be greyed out, and whoever holds the role
   needs to do Part 1 for you.

---

## Part 1 — Register the app in Microsoft

Go to **[entra.microsoft.com](https://entra.microsoft.com)** → **App
registrations** → **+ New registration**.

**Name:** `Mont Haus Marketing`

**Supported account types:** *Accounts in this organizational directory only
(Single tenant)*.

> This is the setting that keeps outsiders out. Any other choice lets personal
> Microsoft accounts reach the sign-in page. The site rejects them anyway — it
> checks the tenant id on every token — but there is no reason to let them get
> that far.

**Redirect URI:** choose platform **Web**, then enter exactly:

```
https://marketing.monthaus.com/oauth_callback.php
```

Character for character. `http` instead of `https`, a trailing slash, or a
capital letter in the wrong place all produce the same unhelpful Microsoft
error later.

Click **Register**.

### Copy two values from the Overview page

| Label on screen | Goes into `sso_config.php` as |
|---|---|
| Application (client) ID | `SSO_CLIENT_ID` |
| Directory (tenant) ID | `SSO_TENANT_ID` |

### Create the client secret

**Certificates & secrets** → **Client secrets** → **+ New client secret**.

Description: `marketing.monthaus.com`. Expiry: 24 months is the longest
Microsoft offers.

When the page reloads you will see **Value** and **Secret ID**. You want
**Value** — the longer string. Copy it now; leaving the page hides it forever
and you have to start a new secret.

> **Write the expiry date on your calendar with a reminder a month before.**
> The day the secret lapses, Microsoft sign-in stops for everyone, with no
> warning and no obvious error. The break-glass password login below is how
> you get back in when that happens.

### Grant the permissions

**API permissions** should already list `User.Read`. Add `openid`, `profile`
and `email` if they are not there:

**+ Add a permission** → **Microsoft Graph** → **Delegated permissions** →
tick `openid`, `profile`, `email` → **Add permissions**.

Then click **Grant admin consent for Mont Haus**. None of these needs consent
technically, but granting it means nobody sees a "this app wants to..." prompt
on their first sign-in.

### Add the sign-out URL (only if you turn on federated logout)

**Authentication** → **Front-channel logout URL**:
`https://marketing.monthaus.com/login.php`

Skip this unless you set `SSO_FEDERATED_LOGOUT` to `true`.

---

## Part 2 — Run the database migration

Run `sql/sso_schema.sql` against `dbmarketing_monthaus` — in TablePlus, or
from the instance:

```bash
mysql -h <endpoint> -u mhadmin -p --ssl dbmarketing_monthaus < sql/sso_schema.sql
```

It adds two columns to `users`, adds a unique index, and makes `password`
nullable so an account can exist without one. Nothing is dropped and no
existing row changes.

Run it **before** creating `inc/sso_config.php` — sign-in queries the new columns
and will fail confusingly if they are not there yet.

The file ends with commented verification queries. Run the first one; you
should get three rows back.

---

## Part 3 — Configure and test

On the server, at the document root of the subdomain:

```
cp deploy/sso_config.sample.php inc/sso_config.php
```

Edit `inc/sso_config.php` and paste in the tenant id, client id and secret value.
Leave `SSO_AUTO_REDIRECT` as `false` for now.

Check the file is not reachable: open
`https://marketing.monthaus.com/inc/sso_config.php` in a browser. You should get
**403 Forbidden**. If you see a blank page instead, `.htaccess` did not upload
— fix that before going further, because a blank page means PHP executed the
file rather than Apache denying it.

### Test with your own account first

Go to `https://marketing.monthaus.com/oauth_start.php` directly.

You should land on a Microsoft sign-in page, then come back to the agent
roster signed in as yourself. The header should show your name.

Then check the link was recorded:

```sql
SELECT email, entra_object_id, sso_linked_at FROM users WHERE email = 'nikki.boxer@monthaus.com';
```

`entra_object_id` should now hold a GUID. That is the immutable Microsoft
account id — from here on, matching happens on that rather than on your email,
so a mailbox rename will not break your sign-in.

### If it does not work

| What you see | What it means |
|---|---|
| `AADSTS50011: redirect URI does not match` | The URI in `inc/sso_config.php` and the one on the app registration differ. Compare them character by character. |
| `AADSTS7000215: invalid client secret` | You pasted the Secret **ID** instead of the Secret **Value**. Create a new secret and take the Value. |
| "That sign-in link has expired. Please start again." | The session cookie did not survive the round trip. Confirm the site is HTTPS end to end. |
| "There is no Mont Haus portal account for..." | Working as designed. The address has no `users` row. Create one first. |
| Blank white page | PHP fatal. Check `/var/log/apache2/marketing_error.log`. |

Real diagnostics land in the PHP error log, not on screen — the browser only
ever gets a short message, on purpose.

---

## Part 4 — Roll out

Once your own sign-in works:

1. Have two or three agents sign in at
   `https://marketing.monthaus.com/login.php` and click the Microsoft button.
   Each one links on first use.
2. When everyone has linked, set `SSO_AUTO_REDIRECT` to `true`. The login page
   then sends people straight through to Microsoft — they see a flicker rather
   than a login screen, because their browser already holds a Microsoft
   session from Outlook.

`SSO_PASSWORD_LOGIN_ROLES` is already set to `super_admin`, so from the moment
you create the config, agents and admins can only sign in with Microsoft. If
one of them types a correct password they are told to use the button instead.

**Never remove the last super_admin password.** It is the only way back in if
the secret expires or Entra is unreachable. Confirm one exists:

```sql
SELECT email FROM users WHERE role = 'super_admin' AND password IS NOT NULL AND password <> '';
```

---

## What the site actually checks

Worth knowing, because it is what makes the sign-in trustworthy rather than
merely convenient. On every sign-in, `inc/sso.php` requires all of the following
before it will create a session:

- The `state` matches what this browser session generated — a callback that
  arrives without it is refused.
- The authorization code is exchanged **server to server** over TLS. The
  client secret never touches the browser.
- The identity token's **RS256 signature verifies** against the public key
  Microsoft publishes for the tenant, matched by key id. The algorithm is
  pinned in code, so the classic `alg: none` and RS256-to-HS256 substitution
  forgeries are rejected outright.
- Issuer, audience, **tenant id**, expiry, not-before and nonce all match.
  The tenant check is what stops a token minted in some other Microsoft
  directory from being accepted here.
- The resulting account must already exist in `users`. A Microsoft sign-in
  **never** creates one.

All of this is covered by an offline test suite — 28 assertions, including a
dozen forged tokens that each must be rejected. See `TESTING.md`.

Signing keys rotate at Microsoft periodically; the site notices an unfamiliar
key id and re-fetches automatically, so rotation is not something you need to
watch.
