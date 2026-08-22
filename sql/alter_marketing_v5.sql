-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- Marketing Management Schema — Migration v5
-- Run once on SiteGround via phpMyAdmin or CLI.
-- Safe to run on an existing marketing_intakes table.
-- ============================================================


-- NOTE: marketing_intakes already has mh_broker_id, is_active, archived_at,
-- and headshot_url — no ALTER needed. The five tables below are all new.

-- ── 1. marketing_agent_mls_ids ───────────────────────────────────────────────
--
-- One row per MLS board per agent. Aspen and Vail spark_key values are
-- auto-looked up; all other boards are filled in manually.
-- board examples: 'aspen', 'vail', 'denver', 'summit', 'montrose'

CREATE TABLE IF NOT EXISTS `marketing_agent_mls_ids` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `intake_id`   INT UNSIGNED NOT NULL,
  `board`       VARCHAR(50)  NOT NULL  COMMENT 'e.g. aspen, vail, denver',
  `mls_id`      VARCHAR(50)  NULL      COMMENT 'Short MLS member/agent ID',
  `spark_key`   VARCHAR(26)  NULL      COMMENT '26-char Spark key (auto for aspen/vail)',
  `verified_at` DATETIME     NULL      COMMENT 'When auto-lookup last confirmed this key',
  `created_at`  DATETIME     NOT NULL  DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_board` (`intake_id`, `board`),
  CONSTRAINT `fk_mls_ids_intake`
    FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 3. marketing_tasks ───────────────────────────────────────────────────────
--
-- Custom per-agent to-do list. Standard onboarding tasks are inserted via
-- PHP when a new profile is created (see intake.php). Additional tasks are
-- added manually at any time.
--
-- category  : 'onboarding' | 'collateral' | 'digital' | 'photo' | 'other'
-- priority  : 'normal' | 'high'
-- status    : 'open' | 'done'
-- sort_order: lower = shown first within the same status group

CREATE TABLE IF NOT EXISTS `marketing_tasks` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `intake_id`    INT UNSIGNED NOT NULL,
  `title`        VARCHAR(255) NOT NULL,
  `category`     VARCHAR(30)  NOT NULL DEFAULT 'other',
  `priority`     VARCHAR(10)  NOT NULL DEFAULT 'normal',
  `status`       VARCHAR(10)  NOT NULL DEFAULT 'open',
  `due_date`     DATE         NULL,
  `notes`        TEXT         NULL,
  `sort_order`   INT          NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_intake` (`intake_id`),
  KEY `idx_tasks_status` (`intake_id`, `status`),
  CONSTRAINT `fk_tasks_intake`
    FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 4. marketing_campaigns ───────────────────────────────────────────────────
--
-- One row per digital ad campaign. Replaces the flat digital_ads_* columns
-- on marketing_intakes (those columns are kept for backwards compat but new
-- campaigns go here).
--
-- platform : 'vail_daily' | 'aspen_daily' | 'aspen_times' | 'social_meta'
--            | 'social_instagram' | 'google' | 'other'
-- status   : 'planned' | 'active' | 'ended'

CREATE TABLE IF NOT EXISTS `marketing_campaigns` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `intake_id`    INT UNSIGNED NOT NULL,
  `platform`     VARCHAR(50)  NOT NULL,
  `name`         VARCHAR(255) NULL      COMMENT 'Optional campaign label',
  `budget`       DECIMAL(10,2) NULL,
  `start_date`   DATE         NULL,
  `end_date`     DATE         NULL,
  `status`       VARCHAR(20)  NOT NULL  DEFAULT 'planned',
  `notes`        TEXT         NULL,
  `created_at`   DATETIME     NOT NULL  DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_campaigns_intake` (`intake_id`),
  CONSTRAINT `fk_campaigns_intake`
    FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 5. marketing_campaign_assets ─────────────────────────────────────────────
--
-- Creative files attached to a campaign. Displayed as a lightbox gallery on
-- the agent profile page.
--
-- file_type : 'image' | 'pdf' | 'video' | 'link'

CREATE TABLE IF NOT EXISTS `marketing_campaign_assets` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NOT NULL,
  `label`       VARCHAR(255) NULL      COMMENT 'e.g. "728x90 banner", "Facebook post"',
  `file_url`    TEXT         NOT NULL,
  `file_type`   VARCHAR(20)  NOT NULL  DEFAULT 'image',
  `uploaded_at` DATETIME     NOT NULL  DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_assets_campaign` (`campaign_id`),
  CONSTRAINT `fk_assets_campaign`
    FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── 6. marketing_notes ───────────────────────────────────────────────────────
--
-- Dated admin-only notes per agent. Newest first. Full-text indexed for
-- search.

CREATE TABLE IF NOT EXISTS `marketing_notes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `intake_id`  INT UNSIGNED NOT NULL,
  `content`    TEXT     NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT      NULL     COMMENT 'users.id — who wrote the note',
  PRIMARY KEY (`id`),
  KEY  `idx_notes_intake` (`intake_id`),
  FULLTEXT KEY `ft_notes_content` (`content`),
  CONSTRAINT `fk_notes_intake`
    FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
