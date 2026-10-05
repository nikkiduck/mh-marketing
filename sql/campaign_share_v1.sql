-- STATUS: run
--
-- campaign_share_v1.sql — one advertising placement shared among several
-- agents (2026-10-05).
--
-- "Share" on a placement (agent.php, action share_campaign) scales the
-- placement to its agent's percentage and writes a copy for each other agent
-- at theirs. split_group ties the copies together so each card can name and
-- link the others; split_pct records how the placement was first divided.
-- Both are for display only: every copy bills from its own budget, rate and
-- month rows, exactly as an unshared placement does, and after the split the
-- copies are edited independently. The Share button appears once this has run.
--
-- Run once, as the master user. Check after:
--   SHOW COLUMNS FROM marketing_campaigns LIKE 'split_%';   -- 2 rows

ALTER TABLE marketing_campaigns
  ADD COLUMN split_group INT UNSIGNED NULL,
  ADD COLUMN split_pct   DECIMAL(5,2) NULL,
  ADD INDEX idx_split_group (split_group);
