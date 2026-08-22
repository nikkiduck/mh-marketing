-- ============================================================================
--  sso_schema.sql — Microsoft Entra ID sign-in support
--  Run once in phpMyAdmin, against the shared portal database, BEFORE
--  uploading sso_config.php.
--
--  STATUS: already run (2026-08-22) — entra_object_id + sso_linked_at added, password made nullable
--
--  No IF NOT EXISTS on ALTER TABLE — MySQL on SiteGround rejects it.
--  Re-running this errors with "Duplicate column name"; that is expected
--  and harmless, not a bug to chase.
-- ============================================================================

-- 1 ── Link a portal account to its Microsoft identity.
--      entra_object_id is the `oid` claim: immutable, and unlike email it
--      survives a name change or a mailbox rename.
ALTER TABLE users
  ADD COLUMN entra_object_id VARCHAR(64) NULL,
  ADD COLUMN sso_linked_at   DATETIME    NULL;

-- 2 ── One Microsoft account maps to at most one portal user.
--      NULLs are not compared by a MySQL unique index, so every account
--      that has not signed in with Microsoft yet is unaffected.
CREATE UNIQUE INDEX uq_users_entra_oid ON users (entra_object_id);

-- 3 ── An SSO-only account has no password at all.
--      users.password is currently NOT NULL; allow NULL so accounts can be
--      created without one. login.php already treats a NULL/empty hash as
--      "cannot sign in with a password".
ALTER TABLE users MODIFY password VARCHAR(255) NULL;


-- ── Verification: run after the three statements above ──────────────────────
--
-- SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
--   FROM INFORMATION_SCHEMA.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE()
--    AND TABLE_NAME   = 'users'
--    AND COLUMN_NAME IN ('entra_object_id', 'sso_linked_at', 'password');
--
-- Expect three rows: entra_object_id varchar(64) YES,
--                    sso_linked_at   datetime    YES,
--                    password        varchar(255) YES.


-- ── Who has linked so far ───────────────────────────────────────────────────
--
-- SELECT email, role, is_active,
--        CASE WHEN entra_object_id IS NULL THEN 'password only' ELSE 'linked' END AS sso,
--        sso_linked_at, last_login
--   FROM users
--  ORDER BY sso_linked_at IS NULL, last_login DESC;


-- ── Break-glass check: never drop the last super_admin password ─────────────
--
-- SELECT email FROM users
--  WHERE role = 'super_admin' AND password IS NOT NULL AND password <> '';
--
-- This must return at least one row for as long as SSO_PASSWORD_LOGIN_ROLES
-- includes super_admin. It is the way back in if the Entra client secret
-- expires or Microsoft is unreachable.
