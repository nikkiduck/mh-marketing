-- STATUS: proposed (2026-11-06)
--
-- ============================================================
-- collateral_accounting_v1.sql
--
-- "Send to Accounting" on a collateral order (agent.php, Collateral tab;
-- Nikki, 2026-11-06): the button emails the order's details and its receipt
-- to ACCOUNTING_EMAIL (inc/config.php). These two columns record that it was
-- sent, so the card can show "Sent to Accounting 11/06/2026" and the button
-- can ask before sending the same order twice.
--
-- Until this has been run, agent.php hides the button (mk_column_exists).
--
-- Run once in TablePlus. No IF NOT EXISTS on ALTER TABLE here; re-running
-- errors with "Duplicate column name", which means it was already applied.
-- ============================================================

ALTER TABLE marketing_collateral_orders
  ADD COLUMN accounting_sent_at DATETIME     NULL AFTER paid_mh_amount,
  ADD COLUMN accounting_sent_by VARCHAR(150) NULL AFTER accounting_sent_at;

-- Check:
-- SHOW COLUMNS FROM marketing_collateral_orders LIKE 'accounting_%';   -- 2 rows
