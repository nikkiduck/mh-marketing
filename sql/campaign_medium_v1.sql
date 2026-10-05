-- STATUS: run
--
-- campaign_medium_v1.sql — print advertising on the Advertising tab (2026-10-05)
--
-- A print ad is a placement like a digital one (publication, run, recurring
-- cost, creative, who pays), so it lives in marketing_campaigns rather than
-- in collateral orders. `medium` says which; every existing placement stays
-- digital. `ad_size` is free text for print ("Full page", "1/4 page").
-- agent.php checks for the column (mk_column_exists) and shows the Medium
-- field, the Print / Digital chips and the filter only once this has run.
-- Billing, Financials and the portal are unaffected: same table, same amounts.
--
-- Run once, as the master user. Check after:
--   SHOW COLUMNS FROM marketing_campaigns LIKE 'medium';   -- 1 row

ALTER TABLE marketing_campaigns
  ADD COLUMN medium  ENUM('digital','print') NOT NULL DEFAULT 'digital' AFTER platform,
  ADD COLUMN ad_size VARCHAR(80) NULL AFTER medium;
