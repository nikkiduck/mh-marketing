-- STATUS: not yet run
--
-- campaign_membership_v1.sql — memberships on the Advertising tab (2026-10-14)
--
-- A membership (Ridgway Chamber of Commerce, joined on behalf of an agent)
-- is a marketing tool for networking, not an ad, but it carries the same
-- money: an amount, who pays (Mont Haus, the agent or a split), a share
-- among several agents, a receipt, and a term with a renewal date. So it is
-- a placement with a third `medium`. The form hides the platform, UTM and
-- creative fields for it and relabels the rest (Organization, Dues, Member
-- since, Renews). agent.php offers the Membership option only once this
-- has run (mk_enum_has in inc/schema.php); the portal reads the value as
-- it is, so nothing there needs the migration.
--
-- Run once, as the master user. Check after:
--   SHOW COLUMNS FROM marketing_campaigns LIKE 'medium';
--   -- Type: enum('digital','print','membership')

ALTER TABLE marketing_campaigns
  MODIFY COLUMN medium ENUM('digital','print','membership') NOT NULL DEFAULT 'digital';
