-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- alter_collateral_billed_with.sql
--
-- Combined orders: yard signs and open-house signs bought on one
-- Oakley invoice were being entered as two orders, each carrying the
-- full cost, so Financials counted the spend twice.
--
-- One order now holds the real invoice amount; the others point at it
-- and contribute $0 to the totals.
--
-- Run once in phpMyAdmin.
--
-- No IF NOT EXISTS on ALTER TABLE — unsupported here. Re-running errors
-- with "Duplicate column name", which is harmless.
-- ============================================================

ALTER TABLE marketing_collateral_orders
  ADD COLUMN billed_with_order_id INT UNSIGNED NULL AFTER cost;

-- Deliberately no foreign key. If the order carrying the cost is deleted,
-- a FK would either block the delete or null the link silently. The page
-- checks whether the target still exists and shows an "orphaned" warning
-- instead, which is visible rather than silent.
CREATE INDEX idx_coll_billed_with ON marketing_collateral_orders (billed_with_order_id);


-- ── Verify ──────────────────────────────────────────────────────────────
-- SHOW COLUMNS FROM marketing_collateral_orders LIKE 'billed_with_order_id';

-- Find combined orders after you've set them up:
-- SELECT o.id, o.type, o.label, o.cost, o.billed_with_order_id,
--        p.type AS billed_with_type, p.label AS billed_with_label, p.cost AS parent_cost
--   FROM marketing_collateral_orders o
--   LEFT JOIN marketing_collateral_orders p ON p.id = o.billed_with_order_id
--  WHERE o.billed_with_order_id IS NOT NULL;
