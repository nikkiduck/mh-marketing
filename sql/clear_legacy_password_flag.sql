-- ============================================================================
--  clear_legacy_password_flag.sql
--  Retires the last of the old registration flow's forced-password-change flags.
--
--  Run once in phpMyAdmin against the portal database. DONE
--
--  WHY
--  ---
--  Accounts onboarded under the retired registration system were created with
--  must_change_password = 1 and a temp password they were meant to claim via
--  set_password.php. Agents who never claimed it still carry the flag.
--
--  On a Microsoft sign-in, sso_establish_session() used to copy that flag into
--  the session, oauth_callback.php redirected to change_password.php, and
--  require_login() pinned them there. change_password.php asks for the CURRENT
--  password and checks it with password_verify() -- a password these agents
--  never knew -- so the gate had no exit. Jean-Michel Drai hit this.
--
--  inc/sso.php no longer copies the flag into an SSO session, so the lockout is
--  already fixed in code. This script clears the stale data behind it so the
--  flag stops firing on the password sign-in path too.
-- ============================================================================

-- ── 1. Look before you leap: who still carries the flag? ────────────────────
--
-- SELECT id, first_name, last_name, email, role, is_active,
--        last_login,
--        CASE WHEN entra_object_id IS NULL THEN 'password only' ELSE 'linked' END AS sso
--   FROM users
--  WHERE must_change_password = 1
--  ORDER BY last_login IS NULL DESC, email;
--
-- Expect the agents onboarded 2026-05-26 who never signed in (last_login NULL).
-- If a row appears that you deliberately flagged recently, exclude its id below.


-- ── 2. The fix ──────────────────────────────────────────────────────────────
--     Scoped to accounts that never completed a first sign-in, which is
--     exactly the legacy-onboarding population. An admin-set flag on an
--     active, logged-in account is left alone.

UPDATE users
   SET must_change_password = 0
 WHERE must_change_password = 1
   AND last_login IS NULL;


-- ── 3. Verify: should return zero rows ──────────────────────────────────────
--
-- SELECT id, email, last_login FROM users
--  WHERE must_change_password = 1 AND last_login IS NULL;


-- ── Narrower alternative: unblock Jean-Michel only ──────────────────────────
--  Use this instead of step 2 if you would rather clear one account now and
--  handle the rest as each agent onboards.
--
-- UPDATE users SET must_change_password = 0
--  WHERE email = 'jm.drai@monthaus.com';
