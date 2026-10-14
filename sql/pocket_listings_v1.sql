-- STATUS: run
--
-- pocket_listings_v1.sql — pocket (off-MLS) listings on the agent page and
-- the agent portal (Nikki, 2026-10-14). NEVER the public site.
--
-- Current Listings on both pages come from hs_listing_state, which the hourly
-- cron fills from the site's MLS feed, so a listing that is not on an MLS can
-- never reach them. These are entered by hand on the admin agent page
-- (admins only for now; agents entering their own is a decision for
-- Jonathan), shown after the MLS listings with a "Pocket" badge, and read by
-- nothing public: not the roster feed, not Hot Sheets, not the Templates
-- tools (Nikki: no). The photo is optional and lives in CREATIVES_DIR like
-- a creative (inc/creatives.php), served by creative.php?pocket= (admin) and
-- portal/asset.php?pocket= (the owning account only).
--
-- CREATE TABLE IF NOT EXISTS is standard MySQL (the rule is only about ALTER).
-- Run once, as the master user. Check after:
--   SHOW COLUMNS FROM pocket_listings;   -- 17 rows

CREATE TABLE IF NOT EXISTS pocket_listings (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  intake_id         INT UNSIGNED NOT NULL COMMENT 'marketing_intakes.id: the agent (or team) it belongs to',
  address           VARCHAR(255) NOT NULL,
  city              VARCHAR(100) NOT NULL DEFAULT '',
  list_price        DECIMAL(14,2) NULL,
  beds              DECIMAL(4,1) NULL,
  baths             DECIMAL(4,1) NULL,
  sqft              INT NULL,
  is_rental         TINYINT(1) NOT NULL DEFAULT 0,
  status            ENUM('available','under_contract','sold','withdrawn') NOT NULL DEFAULT 'available',
  notes             TEXT NULL COMMENT 'admin notes; shown on the agent page only, never on the portal',
  photo_file        VARCHAR(255) NULL COMMENT 'stored name in CREATIVES_DIR',
  photo_orig_name   VARCHAR(255) NULL,
  photo_thumb       VARCHAR(255) NULL,
  photo_uploaded_at DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pocket_intake (intake_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
