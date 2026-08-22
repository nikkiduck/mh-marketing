-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- Migration v3 (revised): agent_title only — other columns already added
ALTER TABLE marketing_intakes
  ADD COLUMN `agent_title` VARCHAR(100) NULL DEFAULT NULL AFTER `agent_name`;
