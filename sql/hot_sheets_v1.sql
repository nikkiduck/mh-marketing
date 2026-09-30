-- ============================================================================
-- hot_sheets_v1.sql — Hot Sheets listing state + change log
-- STATUS: not yet run
--
-- See docs/HOT_SHEETS_PLAN.md. Run ONCE in TablePlus as the master user.
-- CREATE TABLE IF NOT EXISTS is fine (standard MySQL). Once run, any change to
-- these tables is a NEW ALTER file, never an edit here: re-running an edited
-- CREATE TABLE IF NOT EXISTS silently does nothing (see vendor_invoices_v1).
--
-- Listings arrive from site.monthaus.com's api/listings.php?scope=mh
-- (cron/sync_hot_sheet_listings.php). This portal keeps its own copy of what it
-- last saw and logs every difference, so the emails never depend on how the
-- site keeps history. Subscribers, manual listings and Pipeline tables come in
-- hot_sheets_v2.sql with the email port.
-- ============================================================================

-- What the last sync saw, one row per listing ever seen.
CREATE TABLE IF NOT EXISTS hs_listing_state (
    market              VARCHAR(20)   NOT NULL COMMENT 'aspen, vail, cren, recolorado, elevate, altitude',
    listing_key         VARCHAR(64)   NOT NULL COMMENT 'RESO ListingKey (unique per board only)',
    mls_id              VARCHAR(32)   NOT NULL DEFAULT '' COMMENT 'Human-facing MLS #',
    status              VARCHAR(32)   NOT NULL DEFAULT '',
    is_rental           TINYINT(1)    NOT NULL DEFAULT 0,
    property_type       VARCHAR(64)   NOT NULL DEFAULT '',
    address             VARCHAR(255)  NOT NULL DEFAULT '',
    city                VARCHAR(100)  NOT NULL DEFAULT '',
    subdivision         VARCHAR(150)  NOT NULL DEFAULT '',
    list_price          DECIMAL(14,2) NULL,
    close_price         DECIMAL(14,2) NULL,
    close_date          DATE          NULL,
    beds                DECIMAL(4,1)  NULL,
    baths               DECIMAL(4,1)  NULL,
    sqft                INT           NULL,
    primary_photo       VARCHAR(512)  NOT NULL DEFAULT '',
    url                 VARCHAR(512)  NOT NULL DEFAULT '' COMMENT 'Public listing page',
    list_agent_mls_id   VARCHAR(32)   NOT NULL DEFAULT '',
    list_agent_name     VARCHAR(255)  NOT NULL DEFAULT '',
    colist_agent_mls_id VARCHAR(32)   NOT NULL DEFAULT '',
    colist_agent_name   VARCHAR(255)  NOT NULL DEFAULT '',
    list_office_name    VARCHAR(255)  NOT NULL DEFAULT '',
    in_feed             TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = no longer returned by the feed',
    first_seen_at       DATETIME      NOT NULL,
    last_seen_at        DATETIME      NOT NULL,
    PRIMARY KEY (market, listing_key),
    KEY idx_hs_state_feed (in_feed, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per transition. Unlike the hub's listing_changes there is NO "skip if
-- an un-notified row of this type exists" rule: that rule is how
-- Active -> Pending -> Closed inside one week lost its Closed row. The email
-- decides what to show from the full history.
CREATE TABLE IF NOT EXISTS hs_listing_changes (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    market       VARCHAR(20)   NOT NULL,
    listing_key  VARCHAR(64)   NOT NULL,
    change_type  ENUM('new_listing','status','price','closed','removed') NOT NULL,
    old_value    VARCHAR(64)   NULL,
    new_value    VARCHAR(64)   NULL,
    detected_at  DATETIME      NOT NULL,
    PRIMARY KEY (id),
    KEY idx_hs_changes_listing (market, listing_key, detected_at),
    KEY idx_hs_changes_when (detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
