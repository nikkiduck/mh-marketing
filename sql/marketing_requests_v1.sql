-- STATUS: not yet run
--
-- marketing_requests_v1.sql — requests agents send from the portal (2026-10-01).
-- First use: "QR Code Request" on portal/qr.php. The marketing menu and other
-- requests (docs/AGENT_PORTAL_PLAN.md, section 6) will add columns with a new
-- ALTER file when they are built.
--
-- A request is written here FIRST, then emailed to marketing@monthaus.com, so a
-- failed email never loses it (email_status / email_error record what happened).
-- Agents never write money: nothing here reaches cost, budget or any billing
-- table. Run as the master user in TablePlus. The portal checks for the table
-- and, until it exists, still emails the request.

CREATE TABLE IF NOT EXISTS marketing_requests (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    intake_id     INT UNSIGNED NOT NULL COMMENT 'portal account (marketing_intakes.id)',
    user_id       INT UNSIGNED NULL     COMMENT 'users.id of who sent it',
    kind          VARCHAR(30)  NOT NULL COMMENT 'qr_code (more as the portal grows)',
    title         VARCHAR(200) NOT NULL COMMENT 'project name',
    details       TEXT         NULL,
    destination   VARCHAR(1000) NULL    COMMENT 'qr_code: where it should take people',
    status        ENUM('new','in_progress','done','declined') NOT NULL DEFAULT 'new',
    email_status  VARCHAR(20)  NULL     COMMENT 'sent | failed',
    email_error   VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mr_intake (intake_id, created_at),
    KEY idx_mr_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
