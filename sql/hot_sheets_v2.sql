-- ============================================================================
-- hot_sheets_v2.sql — subscribers, send log, manual listings (pocket + buyer rep)
-- STATUS: run (verified against the live schema 2026-09-30: every table and column present)
--
-- See docs/HOT_SHEETS_PLAN.md. Run ONCE in TablePlus as the master user, after
-- hot_sheets_v1.sql. Once run, change these tables only with a NEW ALTER file.
-- ============================================================================

-- People who receive the Hot Sheet emails. For now only addresses on
-- HOT_SHEET_ALLOWED_RECIPIENTS (inc/config.php) are ever sent to, whatever
-- this table says. Later: a roster agent gets a row by default (intake_id set).
CREATE TABLE IF NOT EXISTS hs_subscribers (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email              VARCHAR(255) NOT NULL,
    intake_id          INT UNSIGNED NULL COMMENT 'marketing_intakes.id when the subscriber is a roster agent',
    receives_listings  TINYINT(1)   NOT NULL DEFAULT 1,
    receives_rentals   TINYINT(1)   NOT NULL DEFAULT 1,
    frequency          ENUM('daily','weekly') NOT NULL DEFAULT 'daily' COMMENT 'weekly = Mondays',
    markets            VARCHAR(255) NULL COMMENT 'NULL = every market. Comma list for the later per-market emails',
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    unsubscribe_token  CHAR(32)     NOT NULL COMMENT 'Random; the only key unsubscribe.php accepts',
    unsubscribed_at    DATETIME     NULL,
    hub_subscriber_id  INT          NULL COMMENT 'subscribers.id on monthausint.com, set by the import',
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hs_sub_email (email),
    UNIQUE KEY uq_hs_sub_token (unsubscribe_token),
    KEY idx_hs_sub_intake (intake_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per email attempt, including ones the allowlist stopped. Also what
-- keeps a cron that fires twice in a day from sending twice.
CREATE TABLE IF NOT EXISTS hs_sends (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscriber_id INT UNSIGNED NULL,
    email         VARCHAR(255) NOT NULL,
    kind          ENUM('listings','rentals') NOT NULL,
    send_date     DATE         NOT NULL COMMENT 'Mountain-time date of the send',
    outcome       ENUM('sent','failed','blocked','test') NOT NULL,
    detail        VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hs_sends_day (send_date, email, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Pocket listings and buyer representation. Same shape as the hub's
-- manual_listings, with the board widened past Aspen/Vail and hub_id kept so
-- the import can be re-run to refresh while Pipeline still lives on the hub.
CREATE TABLE IF NOT EXISTS hs_manual_listings (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    hub_id            INT           NULL COMMENT 'manual_listings.id on monthausint.com',
    entry_type        ENUM('buyer_rep','pocket_listing') NOT NULL,
    mls_number        VARCHAR(40)   NULL,
    market            VARCHAR(20)   NULL,
    address           VARCHAR(255)  NOT NULL DEFAULT '',
    city              VARCHAR(100)  NULL,
    state_abbr        VARCHAR(10)   NULL DEFAULT 'CO',
    postal_code       VARCHAR(20)   NULL,
    price             DECIMAL(14,2) NULL,
    close_price       DECIMAL(14,2) NULL,
    status            ENUM('Active','Pending','Closed') NOT NULL DEFAULT 'Active',
    primary_photo_url VARCHAR(512)  NULL,
    listing_url       VARCHAR(512)  NULL,
    agent_names       VARCHAR(500)  NULL COMMENT 'Comma-separated, as on the hub',
    notes             TEXT          NULL,
    is_active         TINYINT(1)    NOT NULL DEFAULT 1,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hs_ml_hub (hub_id),
    KEY idx_hs_ml_status (is_active, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS hs_manual_listing_changes (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    hub_id            INT           NULL COMMENT 'manual_listing_changes.id on monthausint.com',
    manual_listing_id INT UNSIGNED  NOT NULL,
    change_type       ENUM('new_listing','status','price') NOT NULL,
    old_value         VARCHAR(255)  NULL,
    new_value         VARCHAR(255)  NOT NULL,
    detected_at       DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hs_mlc_hub (hub_id),
    KEY idx_hs_mlc_when (detected_at),
    CONSTRAINT fk_hs_mlc_listing FOREIGN KEY (manual_listing_id)
        REFERENCES hs_manual_listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
