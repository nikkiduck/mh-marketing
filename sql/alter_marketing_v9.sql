-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- Marketing Management Schema — Migration v9
-- Per-order tracking for collateral (multiple orders per type)
-- ============================================================

CREATE TABLE IF NOT EXISTS `marketing_collateral_orders` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `intake_id`       INT UNSIGNED  NOT NULL,
  `type`            VARCHAR(50)   NOT NULL COMMENT 'business_cards | yard_signs | oh_signs | postcards | brochures | other',
  `label`           VARCHAR(100)  NULL     COMMENT 'Optional label e.g. "Reorder #2"',
  `vendor`          VARCHAR(255)  NULL,
  `vendor_url`      TEXT          NULL     COMMENT 'Link to vendor order page',
  `order_number`    VARCHAR(100)  NULL,
  `qty`             VARCHAR(50)   NULL,
  `cost`            DECIMAL(10,2) NULL,
  `status`          VARCHAR(30)   NOT NULL DEFAULT 'pending'
                                  COMMENT 'pending | ordered | in_production | shipped | delivered',
  `ordered_at`      DATE          NULL,
  `tracking_number` VARCHAR(255)  NULL,
  `tracking_url`    TEXT          NULL,
  `delivered_at`    DATE          NULL,
  `file_url`        TEXT          NULL     COMMENT 'Dropbox/Drive link to final print-ready or delivered files',
  `notes`           TEXT          NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coll_intake` (`intake_id`),
  CONSTRAINT `fk_coll_intake` FOREIGN KEY (`intake_id`)
    REFERENCES `marketing_intakes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
