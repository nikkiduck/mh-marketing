-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- alter_campaign_who_pays.sql
--
-- Adds the same Who Pays fields to advertising placements that
-- alter_collateral_receipts.sql added to collateral orders.
--
-- The reconciliation total for a placement is `budget`, not `cost`.
--
-- Run once in phpMyAdmin.
--
-- No IF NOT EXISTS on ALTER TABLE — unsupported here. Re-running errors
-- with "Duplicate column name", which is harmless.
-- ============================================================

ALTER TABLE marketing_campaigns
  ADD COLUMN paid_by            ENUM('broker','mont_haus','split') NULL,
  ADD COLUMN paid_broker_amount DECIMAL(10,2) NULL,
  ADD COLUMN paid_mh_amount     DECIMAL(10,2) NULL;


-- ── Verify ──────────────────────────────────────────────────────────────
-- SHOW COLUMNS FROM marketing_campaigns LIKE 'paid%';
