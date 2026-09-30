-- ============================================================================
-- hot_sheets_v3_pipeline.sql — Paperless Pipeline events + review queue
-- STATUS: not yet run
--
-- See docs/HOT_SHEETS_PLAN.md. Run ONCE in TablePlus as the master user, after
-- hot_sheets_v2.sql. Same shape as the hub's pipeline_schema.sql, with:
--   · hub_id columns so cron/import_hot_sheets.php can mirror the hub's queue
--     (re-runnable) until Zapier is pointed here at go-live;
--   · the MLS match recorded as market + listing key (hs_listing_state has no
--     integer id), not the hub's listings.id;
--   · manual_listing_id pointing at hs_manual_listings.
-- ============================================================================

-- Raw webhook bodies, stored verbatim. api/pipeline_webhook.php parses nothing,
-- so a parser bug never costs an event; parse_status drives re-processing.
CREATE TABLE IF NOT EXISTS hs_pipeline_events (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hub_id        INT          NULL COMMENT 'pipeline_events.id on monthausint.com',
    payload       LONGTEXT     NOT NULL,
    headers       TEXT         NULL,
    source_ip     VARCHAR(45)  NULL,
    parse_status  ENUM('unparsed','parsed','error','ignored') NOT NULL DEFAULT 'unparsed',
    parse_notes   TEXT         NULL,
    parsed_at     DATETIME     NULL,
    received_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hs_pe_hub (hub_id),
    KEY idx_hs_pe_status (parse_status),
    KEY idx_hs_pe_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One staging row per Paperless transaction. Nothing here reaches an email
-- until an admin promotes it in pipeline_review.php. Carries no buyer/seller
-- names, commission, escrow or title data, as on the hub.
CREATE TABLE IF NOT EXISTS hs_pipeline_transactions (
    id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id             INT UNSIGNED  NULL COMMENT 'Latest hs_pipeline_events row for this transaction',
    pipeline_txn_id      VARCHAR(64)   NOT NULL COMMENT 'Paperless Transaction ID, stable across status changes',
    address              VARCHAR(255)  NULL COMMENT 'Paperless Transaction Name',
    postal_code          VARCHAR(20)   NULL,
    property_type        VARCHAR(100)  NULL,
    mls_number           VARCHAR(40)   NULL COMMENT 'As supplied by Paperless',
    side                 ENUM('Buying','Listing','Both','Unknown') NOT NULL DEFAULT 'Unknown',
    status_category      VARCHAR(50)   NULL COMMENT 'Machine value, e.g. 30-pending; logic keys on this',
    status_label         VARCHAR(100)  NULL,
    status_changed_at    DATETIME      NULL,
    acceptance_date      DATE          NULL,
    closing_date         DATE          NULL,
    sale_price           DECIMAL(14,2) NULL COMMENT 'Never published before closing',
    agent_names          VARCHAR(500)  NULL COMMENT 'Resolved against marketing_intakes',
    unmatched_agents     VARCHAR(500)  NULL,
    suggested_entry_type ENUM('buyer_rep','pocket_listing') NULL,
    mls_match_market     VARCHAR(20)   NULL COMMENT 'Address matched a Mont Haus MLS listing (advisory)',
    mls_match_key        VARCHAR(64)   NULL,
    mls_match_number     VARCHAR(40)   NULL,
    match_confidence     ENUM('none','fuzzy','exact') NOT NULL DEFAULT 'none',
    review_status        ENUM('pending','promoted','rejected') NOT NULL DEFAULT 'pending',
    manual_listing_id    INT UNSIGNED  NULL COMMENT 'hs_manual_listings.id once promoted',
    reviewed_by          INT UNSIGNED  NULL,
    reviewed_at          DATETIME      NULL,
    hub_id               INT           NULL COMMENT 'pipeline_transactions.id on monthausint.com',
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hs_pt_txn (pipeline_txn_id),
    KEY idx_hs_pt_review (review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
