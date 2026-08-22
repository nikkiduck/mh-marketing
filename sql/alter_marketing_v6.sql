-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- Marketing Management Schema — Migration v6
-- Adds agent status (pending / active / archived).
-- Run once in phpMyAdmin. No prior columns to check — schema
-- confirmed from DB export before writing.
-- ============================================================

-- Adds a status column alongside the existing is_active flag.
-- status drives the roster UI going forward:
--   pending  = recruit being prepped, not yet on MLS as Mont Haus
--   active   = official agent
--   archived = left the company (hidden from roster by default)
--
-- Existing rows default to 'active' to match their current is_active = 1.

ALTER TABLE marketing_intakes
  ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active'
                       COMMENT 'pending | active | archived'
  AFTER `is_active`;
