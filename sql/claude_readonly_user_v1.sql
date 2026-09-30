-- STATUS: run (verified 2026-09-30: claude_ro connects over TLS with SELECT, SHOW VIEW only)
--
-- claude_readonly_user_v1.sql — a SELECT-only login for Claude Code (2026-09-30)
--
-- Run as the MASTER user in TablePlus (mh_app has no rights to create users).
-- Replace PASTE_PASSWORD with the value generated on the server (see below)
-- in the one place it appears. Do not save the password into this file.
--
-- Claude runs queries on the instance as `ssh mh-marketing "mysql -e '...'"`;
-- the password lives only in /home/admin/.my.cnf on the server (mode 0600).
-- Migrations still run as the master user, by hand, in TablePlus.
--
-- Password: generate it on the server, so it is never typed anywhere else:
--   openssl rand -hex 24
--
-- Verify afterwards:
--   SHOW GRANTS FOR 'claude_ro'@'%';
-- should list USAGE (with REQUIRE SSL on the user) and SELECT, SHOW VIEW on
-- dbmarketing_monthaus.* and nothing else.

CREATE USER 'claude_ro'@'%' IDENTIFIED BY 'PASTE_PASSWORD' REQUIRE SSL;
GRANT SELECT, SHOW VIEW ON dbmarketing_monthaus.* TO 'claude_ro'@'%';
