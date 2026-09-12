-- STATUS: run
--
-- ============================================================
-- vendor_invoices_v2_fulfilment.sql
--
-- Adds fulfilment to marketing_vendor_invoices: status, tracking number and
-- tracking URL, held ONCE for the whole invoice.
--
-- WHY A SECOND FILE. These three columns were briefly written into the
-- CREATE TABLE in vendor_invoices_v1.sql while that migration was believed to
-- be unrun. It had in fact already been applied, and because v1 uses
-- CREATE TABLE IF NOT EXISTS, re-running the edited file did nothing at all
-- and reported no error — the table already existed, so the whole statement
-- was skipped. The page then failed on save with:
--
--     Unknown column 'status' in 'field list'
--
-- v1 has been restored to the table as it was actually created. Everything
-- after it is an ALTER, which is the only form that can be applied to a table
-- that already exists.
--
-- WHAT IT IS FOR. One invoice is one shipment: the vendor produces it together
-- and ships it in one box, so a status and tracking number per agent line would
-- be the same value typed several times and free to disagree with itself.
-- order_split.php copies these onto EVERY collateral order it writes, so the
-- Collateral tab and the agent's own view still show tracking per order — the
-- value is entered once and fans out.
--
-- Run once in TablePlus.
--
-- No IF NOT EXISTS on ALTER TABLE — unsupported here. Re-running errors with
-- "Duplicate column name", which is harmless and means it was already applied.
-- ============================================================

ALTER TABLE marketing_vendor_invoices
  -- Same vocabulary as marketing_collateral_orders.status, deliberately: two
  -- surfaces that write one concept must not offer two sets of words.
  --
  -- Defaults to 'ordered', not 'pending'. An invoice being entered on the split
  -- page is one that has been placed; defaulting to pending would mark every
  -- order on it as not yet ordered. os_blank_row() in order_split.php makes the
  -- same choice — change one, change the other.
  ADD COLUMN status          VARCHAR(30)  NOT NULL DEFAULT 'ordered' AFTER house_label,
  ADD COLUMN tracking_number VARCHAR(255) NULL     AFTER status,
  ADD COLUMN tracking_url    TEXT         NULL     AFTER tracking_number;


-- ── Verify ──────────────────────────────────────────────────────────────
-- Three rows means this ran. Zero means it did not — and note that re-running
-- vendor_invoices_v1.sql will NOT fix that, for the reason at the top.
--
-- SHOW COLUMNS FROM marketing_vendor_invoices
--   WHERE Field IN ('status','tracking_number','tracking_url');

-- Invoices saved before this ran carry status='ordered' and no tracking, which
-- is the correct reading of them: they were placed, and nothing was recorded.
-- Nothing needs backfilling.
