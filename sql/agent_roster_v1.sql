-- ============================================================================
-- agent_roster_v1.sql — marketing_intakes becomes the one agent roster
-- STATUS: run (verified against the live schema 2026-09-30: every table and column present)
--
-- See docs/AGENT_ROSTER_PLAN.md. Adds the public-website fields and a
-- per-board MLS identity table, so this portal can own every agent fact and
-- feed monthaus.com (api/roster.php), with the roster itself refreshed from
-- Anyprop (cron/sync_anyprop_roster.php).
--
-- Run ONCE, by hand, in TablePlus, as the master user (mh_app has no DDL).
-- Not idempotent: a second run fails with "Duplicate column name". That is
-- expected and harmless. No IF NOT EXISTS on any ALTER (MySQL, not MariaDB).
--
-- Verify afterwards:
--   SHOW COLUMNS FROM marketing_intakes LIKE 'web_status';     -- 1 row
--   SELECT market, COUNT(*) FROM agent_mls_ids GROUP BY market;
-- ============================================================================

-- ── 1. Website + MLS-mirror fields on the roster ────────────────────────────
--
-- web_status is NOT the existing `status` column. `status` (pending | active |
-- archived, and now `roster`) is marketing onboarding; web_status is whether
-- the agent appears on monthaus.com. They change for different reasons.
--
-- `status = 'roster'` needs no ALTER: the column is VARCHAR(20). It marks a row
-- the MLS sync created for an agent marketing has not onboarded. index.php
-- draws it as the pale-grey no-intake card.
--
-- mls_* are written ONLY by the Anyprop sync. Curated fields (agent_name,
-- mh_email, cell_phone, bio_text, social_*) are never written by it; the feed
-- falls back to mls_* where a curated field is blank.

ALTER TABLE marketing_intakes
    ADD COLUMN slug                  VARCHAR(60)  NULL COMMENT 'DNS-safe public key; headshot filenames; feed key',
    ADD COLUMN web_status            ENUM('pending','approved','inactive') NOT NULL DEFAULT 'pending'
                                     COMMENT 'Shown on monthaus.com? Independent of status',
    ADD COLUMN web_approved_at       DATETIME     NULL,
    ADD COLUMN sort_order            INT          NOT NULL DEFAULT 0 COMMENT 'Lower = earlier on the website',
    ADD COLUMN in_fub                TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Follow Up Boss lead rotation',
    ADD COLUMN office                VARCHAR(40)  NULL COMMENT 'aspen | vail | colorado-springs | other',
    ADD COLUMN service_area          VARCHAR(255) NULL COMMENT 'Shown on the website when office = other',
    ADD COLUMN headshot_face_url     VARCHAR(512) NULL COMMENT '400x400 face crop',
    ADD COLUMN mls_full_name         VARCHAR(150) NULL COMMENT 'Sync-written',
    ADD COLUMN mls_email             VARCHAR(255) NULL COMMENT 'Sync-written',
    ADD COLUMN mls_phone             VARCHAR(50)  NULL COMMENT 'Sync-written',
    ADD COLUMN mls_synced_at         DATETIME     NULL COMMENT 'Last Anyprop refresh of mls_*',
    ADD COLUMN departure_detected_at DATETIME     NULL COMMENT 'Set when no active MLS identity remains',
    ADD UNIQUE KEY uq_mi_slug (slug),
    ADD KEY idx_mi_web_status (web_status);

-- ── 2. One row per board identity ───────────────────────────────────────────
--
-- UNIQUE (market, mls_agent_id, intake_id), deliberately NOT (market,
-- mls_agent_id): a team ID such as Weber Boxer Group credits several agents,
-- one row each, is_alias = 1. And NOT (intake_id, market) either, which is the
-- constraint marketing_agent_mls_ids carries: an agent can hold a personal ID
-- and a team alias on the same board.
--
-- last_seen_at NULL means "never returned by Anyprop" (seeded below from the
-- Spark era). The sync never deactivates an agent over an identity it has not
-- once seen, so a seeded ID in an unexpected format cannot take anyone down.

CREATE TABLE IF NOT EXISTS agent_mls_ids (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    intake_id     INT UNSIGNED NOT NULL,
    market        VARCHAR(20)  NOT NULL COMMENT 'aspen, vail, cren, recolorado, ...',
    mls_agent_id  VARCHAR(32)  NOT NULL COMMENT 'MemberMlsId',
    agent_key     VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'MemberKey',
    member_status VARCHAR(20)  NOT NULL DEFAULT 'Active',
    is_alias      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Team ID credited to this agent',
    last_seen_at  DATETIME     NULL COMMENT 'Last sync that returned it; NULL = never',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_identity (market, mls_agent_id, intake_id),
    KEY idx_ami_intake (intake_id),
    CONSTRAINT fk_ami_intake FOREIGN KEY (intake_id) REFERENCES marketing_intakes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ── 3. Seed identities we already know ──────────────────────────────────────
--
-- Short member IDs only. A 20+ character value is a Spark account key, which
-- Anyprop will never return as a MemberMlsId (intake.php used to write Spark
-- keys into the mls_id columns). INSERT IGNORE skips anything already seeded.

INSERT IGNORE INTO agent_mls_ids (intake_id, market, mls_agent_id)
SELECT m.intake_id, LOWER(TRIM(m.board)), TRIM(m.mls_id)
  FROM marketing_agent_mls_ids m
 WHERE m.mls_id IS NOT NULL AND TRIM(m.mls_id) <> '' AND CHAR_LENGTH(TRIM(m.mls_id)) < 20;

INSERT IGNORE INTO agent_mls_ids (intake_id, market, mls_agent_id)
SELECT mi.id, 'aspen', TRIM(r.mls_id_aspen)
  FROM marketing_intakes mi JOIN office_roster r ON r.id = mi.roster_id
 WHERE r.mls_id_aspen IS NOT NULL AND TRIM(r.mls_id_aspen) <> '' AND CHAR_LENGTH(TRIM(r.mls_id_aspen)) < 20;

INSERT IGNORE INTO agent_mls_ids (intake_id, market, mls_agent_id)
SELECT mi.id, 'vail', TRIM(r.mls_id_vail)
  FROM marketing_intakes mi JOIN office_roster r ON r.id = mi.roster_id
 WHERE r.mls_id_vail IS NOT NULL AND TRIM(r.mls_id_vail) <> '' AND CHAR_LENGTH(TRIM(r.mls_id_vail)) < 20;

-- Slugs are assigned in PHP by the first run of cron/sync_anyprop_roster.php
-- (or cron/import_site_agents.php), which can resolve collisions.
