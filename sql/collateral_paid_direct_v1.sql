-- STATUS: proposed (2026-11-16)
--
-- ============================================================
-- collateral_paid_direct_v1.sql
--
-- "Paid directly by agent" on a collateral order (agent.php, Collateral tab;
-- Nikki, 2026-11-16): an agent who pays for their own collateral on their own
-- card has already paid the vendor, so Mont Haus must not bill them for it
-- again. The tick sits under Who Pays and applies to the agent's share (the
-- whole order when Who Pays is Broker, the Broker amount of a Split).
--
-- With it set, inc/financials.php reports that share as `direct` instead of
-- `broker`: it never reaches Due from agent on billing.php, the roster's
-- balance marker or an invoiced total, and it still shows as the agent's own
-- spend on their Financials tab and on the portal's Spend page.
--
-- Until this has been run, agent.php does not show the tick and nothing
-- reads the column (mk_column_exists), so the code can be deployed first.
--
-- Run once in TablePlus. No IF NOT EXISTS on ALTER TABLE here; re-running
-- errors with "Duplicate column name", which means it was already applied.
-- ============================================================

ALTER TABLE marketing_collateral_orders
  ADD COLUMN paid_direct TINYINT(1) NOT NULL DEFAULT 0 AFTER paid_mh_amount;

-- Check:
-- SHOW COLUMNS FROM marketing_collateral_orders LIKE 'paid_direct';   -- 1 row
-- SELECT COUNT(*) FROM marketing_collateral_orders WHERE paid_direct = 1;   -- 0 until an order is ticked
