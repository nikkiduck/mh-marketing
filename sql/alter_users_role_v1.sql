-- ============================================================================
--  alter_users_role_v1.sql — allow the super_admin role to actually exist
--
--  STATUS: already run (2026-08-22) — role enum extended to include super_admin
--
--  users.role was enum('agent','admin'). auth.php has always ranked three
--  roles — agent(0) < admin(1) < super_admin(2) — and ships is_super_admin(),
--  but the column could never store the third one. That is why the hub has no
--  super_admin: not an oversight in the data, an impossibility in the schema.
--
--  Assigning an invalid enum value does not always error. Outside strict mode
--  MySQL stores the empty string instead, and an empty role means
--  $hierarchy[''] misses, $user_level becomes -1, and that user is below every
--  threshold — locked out of every page in the app. That is the likely origin
--  of the blank role on nikki.boxer@monthaus.com.
--
--  Run against dbmarketing_monthaus. One statement at a time; check after each.
-- ============================================================================

-- 1 ── Make the third role storable.
--      No IF NOT EXISTS on ALTER TABLE — unsupported on MySQL.
ALTER TABLE users
  MODIFY role ENUM('agent','admin','super_admin') NOT NULL DEFAULT 'agent';


-- 2 ── Find anything the invalid assignment blanked, BEFORE repairing.
--      Expect this to list only accounts you edited by hand.
SELECT id, email, role, is_active
  FROM users
 WHERE role = '' OR role IS NULL
 ORDER BY email;

-- The hub export had exactly two admins and fifteen agents:
--   admin : nikki.boxer@monthaus.com, bonnie.scott@monthaus.com
--   agent : everyone else
-- Restore any blank row to the value it should have, one at a time. Do not
-- bulk-assign a default — that would silently promote or demote people.


-- 3 ── The break-glass owner account.
UPDATE users
   SET role = 'super_admin'
 WHERE email = 'nikki.boxer@monthaus.com';


-- ── Verify ─────────────────────────────────────────────────────────────────
--
-- The enum now carries three values:
--   SHOW COLUMNS FROM users LIKE 'role';
--
-- No blanks left, and the roles distribute sensibly:
--   SELECT role, COUNT(*) FROM users GROUP BY role ORDER BY role;
--   -- expect: agent 15, admin 1, super_admin 1
--
-- At least one super_admin can still sign in with a password. This is the
-- account that gets you back in when the Entra client secret expires:
--   SELECT email FROM users
--    WHERE role = 'super_admin' AND password IS NOT NULL AND password <> '';
--
-- Sign out and back in afterwards — the role is copied into the session at
-- login, so an open session keeps whatever it had.
