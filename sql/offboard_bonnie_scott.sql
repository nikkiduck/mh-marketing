-- ============================================================================
--  offboard_bonnie_scott.sql — revoke access for a departed employee
--
--  STATUS: run
--
--  bonnie.scott@monthaus.com (user id 11) has left Mont Haus. She came across
--  in bootstrap.sql as one of the two `admin` accounts.
--
--  This DEACTIVATES rather than deletes. She authored nothing — 0 rows in
--  marketing_intakes.created_by, 0 in marketing_notes.created_by — so a hard
--  delete would orphan no data, and that option is at the bottom. But keeping
--  the row preserves last_login and created_at, which is the record of who had
--  access and when they last used it. That is worth more than a tidy table if
--  anyone ever asks.
--
--  Functionally this is removal: after step 1 she cannot sign in by any path.
--    login.php        checks is_active and refuses  ("account is inactive")
--    oauth_callback   checks is_active and refuses  (same, before any session)
--
--  Run against dbmarketing_monthaus.
-- ============================================================================

-- 1 ── Revoke everything, in one statement so there is no window where the
--      account is half-disabled.
UPDATE users
   SET is_active       = 0,      -- blocks both sign-in paths
       password        = NULL,   -- no password login (column is nullable since sso_schema.sql)
       entra_object_id = NULL,   -- unlink Microsoft; a future account cannot inherit the link
       sso_linked_at   = NULL,
       role            = 'agent' -- drop admin, so a later bulk reactivation cannot restore it
 WHERE email = 'bonnie.scott@monthaus.com';


-- 2 ── Verify. Expect is_active 0, password NULL, entra_object_id NULL, role agent.
SELECT id, email, role, is_active, sso_linked_at,
       CASE WHEN password IS NULL OR password = '' THEN 'none' ELSE 'SET' END AS has_password,
       last_login
  FROM users
 WHERE email = 'bonnie.scott@monthaus.com';


-- 3 ── Confirm the remaining admin picture is what you expect.
SELECT email, role, is_active
  FROM users
 WHERE role IN ('admin','super_admin')
 ORDER BY role DESC, email;
-- Expect only nikki.boxer (super_admin) plus whoever you have promoted since.


-- ── Also do this, and it matters more than the rows above ───────────────────
--
-- Disable or delete her account in Microsoft Entra. While her Microsoft
-- account is live she can still authenticate to Microsoft — this database
-- only stops her at the door afterwards. Offboarding in Entra is what
-- actually revokes access to everything, including Exchange.
--
--
-- ── If you would rather delete the row outright ─────────────────────────────
--
-- Safe here: no foreign key points at users, and she authored no intakes or
-- notes. Irreversible, and it takes last_login with it.
--
--   DELETE FROM users WHERE email = 'bonnie.scott@monthaus.com';
--
-- Check nothing references her first if this is run any later than today:
--   SELECT COUNT(*) FROM marketing_intakes WHERE created_by = 11;
--   SELECT COUNT(*) FROM marketing_notes   WHERE created_by = 11;
