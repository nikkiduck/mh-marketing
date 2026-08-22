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
