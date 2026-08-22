-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- Marketing Management Schema — Migration v7
-- Adds parent_id (sub-tasks) and file_url (headshot/design links)
-- to marketing_tasks.
-- Run in phpMyAdmin — two separate statements.
-- ============================================================

ALTER TABLE marketing_tasks
  ADD COLUMN `parent_id` INT UNSIGNED NULL DEFAULT NULL
                          COMMENT 'Parent task ID — NULL = top-level task'
  AFTER `intake_id`;

ALTER TABLE marketing_tasks
  ADD COLUMN `file_url` TEXT NULL DEFAULT NULL
                         COMMENT 'Optional URL for headshot image, design file, Dropbox link, etc.'
  AFTER `notes`;
