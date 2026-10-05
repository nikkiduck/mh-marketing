-- STATUS: run
--
-- campaign_receipt_v1.sql — a receipt (the vendor's invoice) on an
-- advertising placement (2026-10-05).
--
-- Same three columns marketing_collateral_orders has. The file itself is
-- stored outside the web root (RECEIPTS_DIR) and served by
-- receipt.php?campaign_id=. A shared placement's receipt is copied to every
-- agent's copy, one physical file each. The "Add Receipt" button on a
-- placement appears once this has run; until then nothing changes.
--
-- Run once, as the master user. Check after:
--   SHOW COLUMNS FROM marketing_campaigns LIKE 'receipt%';   -- 3 rows

ALTER TABLE marketing_campaigns
  ADD COLUMN receipt_file        VARCHAR(255) NULL,
  ADD COLUMN receipt_orig_name   VARCHAR(255) NULL,
  ADD COLUMN receipt_uploaded_at DATETIME     NULL;
