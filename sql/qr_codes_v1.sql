-- ============================================================================
-- qr_codes_v1.sql — dynamic QR codes served from qr.monthaus.com
-- STATUS: not yet run
--
-- Run ONCE in TablePlus as the master user. Once run, change these tables only
-- with a NEW ALTER file (see CLAUDE.md, "Never edit a CREATE TABLE migration
-- that has already run").
--
-- qr_codes.php (the admin page) shows a notice naming this file and does
-- nothing until it has been applied. qr.php (the redirect) sends every scan to
-- QR_FALLBACK_URL until then, so a code printed early is never a dead end.
--
-- Verify afterwards:
--   SHOW TABLES LIKE 'qr\_%';      -- three rows
-- ============================================================================

-- One row per printed code. `code` is the path after qr.monthaus.com/ and is
-- what the printed square encodes, so it NEVER changes once created: the page
-- offers no way to rename or delete a code, only to repoint or pause it.
CREATE TABLE IF NOT EXISTS qr_codes (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    code          VARCHAR(40)   NOT NULL COMMENT 'qr.monthaus.com/<code>. Lowercase a-z 0-9 and hyphens. Immutable',
    intake_id     INT UNSIGNED  NULL     COMMENT 'marketing_intakes.id of the broker (or team); NULL = office code',
    label         VARCHAR(120)  NOT NULL DEFAULT '' COMMENT 'What it is printed on: Yard sign, Open house, ...',
    dest_type     ENUM('profile','url') NOT NULL DEFAULT 'profile'
                  COMMENT 'profile = the broker''s page on PUBLIC_SITE_URL, worked out at scan time; url = dest_url',
    dest_url      VARCHAR(1000) NULL     COMMENT 'Used when dest_type = url. https, monthaus.com or a subdomain',
    is_active     TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = paused: scans go to QR_FALLBACK_URL',
    scan_count    INT UNSIGNED  NOT NULL DEFAULT 0,
    last_scan_at  DATETIME      NULL     COMMENT 'UTC',
    created_by    INT UNSIGNED  NULL     COMMENT 'users.id',
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qr_code (code),
    KEY idx_qr_intake (intake_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Every change to a code, so "where did the Smuggler sign point in June?" has
-- an answer.
CREATE TABLE IF NOT EXISTS qr_code_changes (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    qr_id       INT UNSIGNED  NOT NULL,
    what        VARCHAR(20)   NOT NULL COMMENT 'created | destination | label | broker | paused | resumed',
    old_value   VARCHAR(1000) NULL,
    new_value   VARCHAR(1000) NULL,
    changed_by  INT UNSIGNED  NULL COMMENT 'users.id',
    changed_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_qrc_qr (qr_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Scans per code per Mountain-time day. A count only: no IP, no user agent.
CREATE TABLE IF NOT EXISTS qr_scans_daily (
    qr_id      INT UNSIGNED NOT NULL,
    scan_date  DATE         NOT NULL COMMENT 'America/Denver date',
    scans      INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (qr_id, scan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
