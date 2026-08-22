-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- alter_collateral_receipts.sql
--
-- Adds to marketing_collateral_orders:
--   • receipt upload (stored filename, not a URL)
--   • who pays — Broker / Mont Haus / Split, with the split amounts
--
-- Run once in phpMyAdmin.
--
-- No IF NOT EXISTS on ALTER TABLE — unsupported here. Re-running errors
-- with "Duplicate column name", which is harmless.
-- ============================================================

ALTER TABLE marketing_collateral_orders
  -- Filename only. Files live outside the doc root and are served through
  -- receipt.php, which checks the admin session first — receipts carry cost
  -- data and should not be fetchable by URL guess.
  ADD COLUMN receipt_file        VARCHAR(255) NULL AFTER file_url,
  ADD COLUMN receipt_orig_name   VARCHAR(255) NULL AFTER receipt_file,
  ADD COLUMN receipt_uploaded_at DATETIME     NULL AFTER receipt_orig_name,

  -- Who pays. NULL means not yet decided.
  ADD COLUMN paid_by             ENUM('broker','mont_haus','split') NULL AFTER receipt_uploaded_at,
  ADD COLUMN paid_broker_amount  DECIMAL(10,2) NULL AFTER paid_by,
  ADD COLUMN paid_mh_amount      DECIMAL(10,2) NULL AFTER paid_broker_amount;


-- ── Verify ──────────────────────────────────────────────────────────────
-- SHOW COLUMNS FROM marketing_collateral_orders;
