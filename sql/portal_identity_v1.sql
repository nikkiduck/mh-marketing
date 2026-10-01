-- STATUS: not yet run
--
-- portal_identity_v1.sql — which marketing account a login sees in the agent
-- portal (docs/AGENT_PORTAL_PLAN.md, section 2). 2026-10-01.
--
-- users.intake_id → marketing_intakes.id. NULL = no portal (admins, anyone not
-- linked yet). Set in users.php only. Several logins may share one account:
-- Jonathan Boxer, Scott Weber and Sara Perkowski all point at Weber Boxer Group.
-- The portal reads this column on every request (inc/portal.php), so a change
-- takes effect immediately, without signing out.
--
-- Run as the master user in TablePlus. Pages check for the column first
-- (mk_column_exists), so they can be uploaded before or after this runs.
--
-- Verify afterwards:
--   SHOW COLUMNS FROM users LIKE 'intake_id';

ALTER TABLE users
    ADD COLUMN intake_id INT UNSIGNED NULL
        COMMENT 'marketing_intakes.id: the account this login sees in the agent portal (users.php)',
    ADD INDEX idx_users_intake (intake_id);
