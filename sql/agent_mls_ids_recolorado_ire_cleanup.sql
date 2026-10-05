-- STATUS: not run
--
-- agent_mls_ids_recolorado_ire_cleanup.sql — REcolorado identities from the
-- wrong feed (2026-10-05)
--
-- Anyprop first switched on the wrong REcolorado feed (the IRES data share),
-- whose member ids carry an IRE prefix. The 16:20 UTC roster sync attached
-- seven of them. At 19:30 UTC Anyprop replaced that feed with the correct one
-- (OSN ccbr_idx), where the ids are the plain REcolorado numbers (55061958),
-- and stopped serving the old one, so nothing will ever refresh or remove
-- these rows and no listing carries their ids any more.
--
-- Run as the master user, any time: the correct ids are attached by the
-- hourly roster sync on its own once the site's registry maps ccbr_idx to
-- recolorado (they do not depend on this file).
--
-- Check first (expect 7 rows: Allison Decent, Jean-Michel Drai, Jonathan
-- Boxer, Megan Walz, Nikki Boxer, Noah Walz, Scott Weber):
--   SELECT a.id, mi.agent_name, a.mls_agent_id, a.agent_key FROM agent_mls_ids a
--     JOIN marketing_intakes mi ON mi.id = a.intake_id
--    WHERE a.market = 'recolorado' AND a.agent_key LIKE 'iresds\_mlsgrid\_%';

DELETE FROM agent_mls_ids WHERE market = 'recolorado' AND agent_key LIKE 'iresds\_mlsgrid\_%';

-- After, no row should come back:
--   SELECT * FROM agent_mls_ids WHERE market = 'recolorado' AND mls_agent_id LIKE 'IRE%';
