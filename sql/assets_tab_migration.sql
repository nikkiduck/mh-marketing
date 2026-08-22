-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- assets_tab_migration.sql
-- Adds columns needed by the Assets & Docs tab in agent.php
-- ============================================================

ALTER TABLE marketing_intakes
  ADD COLUMN bio_url            VARCHAR(500) NULL AFTER bio_short,
  ADD COLUMN headshot_notes     TEXT         NULL AFTER headshot_final_url,
  ADD COLUMN assets_cv_url      VARCHAR(500) NULL AFTER headshot_notes,
  ADD COLUMN assets_other_notes TEXT         NULL AFTER assets_cv_url;
