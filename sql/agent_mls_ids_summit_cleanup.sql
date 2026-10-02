-- STATUS: not yet run
--
-- agent_mls_ids_summit_cleanup.sql — Altitude identities stored as 'summit' (2026-10-02)
--
-- Run AFTER inc/agent_roster.php with the summit → altitude alias is deployed
-- (so the next roster sync does not re-create them). Run as the master user.
--
-- Altitude REALTORS went live in Anyprop as OSN 'summit' before
-- mk_market_slug() knew it; the 17:20 UTC roster sync attached Jonathan Boxer
-- and Allison Decent as market 'summit'. The public site stores the board as
-- 'altitude', so these never matched their Altitude listings there or on the
-- Hot Sheets. Unlike Vail there are no 'altitude' twins, so they are renamed;
-- the DELETE only matters if a sync with the alias ran first and made twins.
--
-- Check first (expect 2 rows):
--   SELECT * FROM agent_mls_ids WHERE market IN ('summit','altitude');

DELETE a FROM agent_mls_ids a
  JOIN agent_mls_ids t ON t.market = 'altitude' AND t.intake_id = a.intake_id AND t.mls_agent_id = a.mls_agent_id
 WHERE a.market = 'summit';

UPDATE agent_mls_ids SET market = 'altitude' WHERE market = 'summit';

-- Should show 2 'altitude' rows and no 'summit':
SELECT market, intake_id, mls_agent_id FROM agent_mls_ids WHERE market IN ('summit','altitude');
