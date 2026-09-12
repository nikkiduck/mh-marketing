-- STATUS: run (2026-08-24)
--
-- Fulfilment columns (status, tracking_number, tracking_url) are NOT here. They
-- were briefly added to the CREATE TABLE below, which was a mistake: this file
-- uses CREATE TABLE IF NOT EXISTS, so once the table exists the whole statement
-- is a no-op and re-running the edited file adds nothing while appearing to
-- succeed. That produced "Unknown column 'status' in 'field list'" on save.
-- They ship as an ALTER in vendor_invoices_v2_fulfilment.sql instead.
--
-- The rule that failure teaches: a CREATE TABLE migration may only be edited
-- while it is genuinely unrun anywhere. Once it has been applied, every later
-- change is a new ALTER file, however small.
--
-- ============================================================
-- vendor_invoices_v1.sql
--
-- One vendor invoice, several agents.
--
-- The problem: a vendor bills the discount, rush fee, shipping and sales tax
-- ONCE at the bottom of the invoice, however many agents' signs are on it.
-- Entering only each agent's line-item total under-states every one of them.
-- On Oakley IND-650292 that gap is $1,278.64 on $2,368.40 of merchandise —
-- every $1.00 of sticker price actually cost $1.54.
--
-- What this adds:
--
--   marketing_vendor_invoices          the invoice itself, entered once
--   marketing_collateral_orders
--       .vendor_invoice_id             which invoice this order came off
--       .merch_amount                  this order's line-item total, BEFORE
--                                      its share of the order-level charges
--
-- `cost` keeps its existing meaning and stays the one number the rest of the
-- app reads — order_split.php writes the LANDED cost into it. That is the whole
-- reason this migration is small: mh_agent_financials(), billing.php, the
-- Financials tab and the roster balance badge all need no change at all,
-- because they already read the right field.
--
-- `merch_amount` is not a duplicate of `cost`. It is the input the allocation
-- was computed from, kept so an invoice can be reopened and re-split after a
-- correction. Without it, reopening would have to work backwards out of the
-- landed figure, which is not recoverable once the residual penny has moved.
--
-- Run once in TablePlus, in this order.
--
-- No IF NOT EXISTS on ALTER TABLE — that is MariaDB-only syntax and MySQL 8
-- throws on it. Re-running errors with "Duplicate column name", which is
-- expected and harmless, not a bug to chase.
-- ============================================================


-- ── 1. The invoice ──────────────────────────────────────────────────────────
--
-- IF NOT EXISTS is fine here — that IS standard MySQL on CREATE TABLE.
--
-- The five charge columns are stored exactly as the invoice prints them, and
-- `discount` is stored POSITIVE, the way the vendor shows it. order_split.php
-- subtracts it. Storing it negative would mean two conventions in the codebase
-- for the same idea and one of them eventually getting it backwards — the same
-- class of mistake bm_age() hit with DateTime::diff().
--
-- grand_total is NOT derived. It is what the card was actually charged, and it
-- is the authority: if the parts do not add up to it, the page refuses to save
-- rather than absorbing the difference into somebody's cost.

CREATE TABLE IF NOT EXISTS marketing_vendor_invoices (
  id                  INT UNSIGNED   NOT NULL AUTO_INCREMENT,

  vendor              VARCHAR(255)   NULL,
  order_number        VARCHAR(100)   NULL,
  vendor_url          TEXT           NULL,
  ordered_at          DATE           NULL,

  -- As printed on the invoice. discount is positive; it gets subtracted.
  merch_subtotal      DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  discount            DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  rush                DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  shipping            DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  tax                 DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  other               DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  grand_total         DECIMAL(10,2)  NOT NULL DEFAULT 0.00,

  -- Merchandise on this invoice that belongs to no agent — generic office
  -- inventory, like the 20 unbranded Open House signs on IND-650292.
  --
  -- Only the MERCHANDISE figure is kept, deliberately. Nothing is written to
  -- any agent for it and it creates no collateral order. It is stored because
  -- without it the invoice cannot be reopened and reconciled: the agent rows
  -- alone would not add up to merch_subtotal, and the page would have to either
  -- refuse to load its own saved data or silently inflate everyone's share.
  --
  -- It also has to be in the allocation pool rather than excluded from it.
  -- Dropping house merchandise out would make the agents absorb the freight and
  -- tax on signs that were never theirs — on IND-650292 that would have charged
  -- two agents 74% more than they owe.
  house_merch         DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  house_label         VARCHAR(255)   NULL,

  -- Same convention as marketing_collateral_orders: stored filename only, file
  -- lives outside the docroot in RECEIPTS_DIR and is streamed by receipt.php
  -- behind the admin session. Invoices carry vendor and cost detail and must
  -- not be fetchable by guessing a URL.
  receipt_file        VARCHAR(255)   NULL,
  receipt_orig_name   VARCHAR(255)   NULL,
  receipt_uploaded_at DATETIME       NULL,

  notes               TEXT           NULL,
  created_by          INT UNSIGNED   NULL,
  created_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_vi_ordered_at (ordered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ── 2. Link collateral orders back to it ────────────────────────────────────
--
-- Deliberately no foreign key, matching alter_collateral_billed_with.sql. A FK
-- would either block deleting an invoice or null the link silently; the page
-- checks whether the invoice still exists and says so, which is visible rather
-- than silent.

ALTER TABLE marketing_collateral_orders
  ADD COLUMN vendor_invoice_id INT UNSIGNED  NULL AFTER billed_with_order_id,
  ADD COLUMN merch_amount      DECIMAL(10,2) NULL AFTER vendor_invoice_id;

CREATE INDEX idx_coll_vendor_invoice
    ON marketing_collateral_orders (vendor_invoice_id);


-- ── Verify ──────────────────────────────────────────────────────────────────
--
-- SHOW COLUMNS FROM marketing_collateral_orders LIKE 'vendor_invoice_id';
-- SHOW COLUMNS FROM marketing_collateral_orders LIKE 'merch_amount';
-- SHOW TABLES LIKE 'marketing_vendor_invoices';
--
-- After splitting a real invoice, this is the reconciliation — the landed costs
-- of every agent row plus the house merchandise's own share must come to the
-- grand total. The agent side of it:
--
--   SELECT v.id, v.vendor, v.order_number, v.grand_total,
--          v.house_merch,
--          SUM(o.merch_amount) AS agent_merch,
--          SUM(o.cost)         AS agent_landed
--     FROM marketing_vendor_invoices v
--     LEFT JOIN marketing_collateral_orders o ON o.vendor_invoice_id = v.id
--    GROUP BY v.id;
--
-- agent_merch + house_merch must equal merch_subtotal. If it does not, an
-- order was edited or deleted on the Collateral tab after the split — reopen
-- the invoice in order_split.php, which reports exactly that.


-- ── Note on billed_with_order_id ────────────────────────────────────────────
--
-- The two mechanisms do NOT combine, and must not be used on the same order.
--
--   billed_with_order_id  one order carries the whole invoice amount, the
--                         others contribute $0. It de-duplicates a combined
--                         purchase inside ONE agent's record.
--
--   vendor_invoice_id     every order carries its own true landed share. It
--                         divides one invoice ACROSS agents.
--
-- An order with both set would be counted as zero by mh_agent_financials()
-- while still holding an allocated cost — the cost would silently vanish from
-- Financials. order_split.php therefore clears billed_with_order_id on every
-- row it writes, and refuses to adopt an order that has one set.
