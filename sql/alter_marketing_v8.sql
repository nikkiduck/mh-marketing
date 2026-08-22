-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- Marketing Management Schema — Migration v8
-- Run in phpMyAdmin — two ALTER TABLE statements.
-- ============================================================

-- marketing_intakes: alternate email + forwarding flag + short bio
ALTER TABLE marketing_intakes
  ADD COLUMN `alt_email`       VARCHAR(255) NULL DEFAULT NULL
                                COMMENT 'Agent alternate / personal email'
    AFTER `mh_email`,
  ADD COLUMN `email_forwarded` TINYINT(1)   NOT NULL DEFAULT 0
                                COMMENT '1 = MH email forwards to alt email'
    AFTER `alt_email`,
  ADD COLUMN `bio_short`       TEXT         NULL DEFAULT NULL
                                COMMENT 'Short / social-media bio'
    AFTER `bio_text`;

-- marketing_campaigns: target URL, UTM params, ad file link, sent flag
ALTER TABLE marketing_campaigns
  ADD COLUMN `target_url`   TEXT         NULL DEFAULT NULL
                             COMMENT 'Constructed landing page URL'
    AFTER `notes`,
  ADD COLUMN `utm_source`   VARCHAR(100) NULL DEFAULT NULL  AFTER `target_url`,
  ADD COLUMN `utm_medium`   VARCHAR(100) NULL DEFAULT NULL  AFTER `utm_source`,
  ADD COLUMN `utm_campaign` VARCHAR(100) NULL DEFAULT NULL  AFTER `utm_medium`,
  ADD COLUMN `utm_content`  VARCHAR(100) NULL DEFAULT NULL  AFTER `utm_campaign`,
  ADD COLUMN `ad_file_url`  TEXT         NULL DEFAULT NULL
                             COMMENT 'Dropbox / Drive link to ad creative files'
    AFTER `utm_content`,
  ADD COLUMN `sent`         TINYINT(1)   NOT NULL DEFAULT 0
                             COMMENT '1 = media company notified'
    AFTER `ad_file_url`;

-- Rename existing onboarding tasks to match new seed names
UPDATE marketing_tasks SET title = 'MH Website Updated' WHERE title = 'Website Updated'  AND category = 'onboarding';
