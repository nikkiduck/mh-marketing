-- STATUS: not yet run
--
-- agent_mls_ids_vbor_cleanup.sql — remove the duplicate Vail identities (2026-10-01)
--
-- Run AFTER inc/agent_roster.php with the vbor → vail alias is deployed (so
-- the next roster sync does not re-create them). Run as the master user.
--
-- Vail went live in Anyprop as OSN 'vbor' before mk_market_slug() knew it, and
-- the 19:20 UTC roster sync attached the five Vail agents as market 'vbor'.
-- The same five ids already exist as market 'vail' (entered earlier, never
-- seen by Anyprop), so the 'vbor' rows are pure duplicates. After this, the
-- next hourly roster sync stamps last_seen_at on the 'vail' rows, and the
-- public site's agent sync (hourly at :30) replaces its copies.
--
-- Check first (expect 5 rows, each with a matching 'vail' twin):
--   SELECT a.intake_id, a.mls_agent_id,
--          EXISTS (SELECT 1 FROM agent_mls_ids v WHERE v.market = 'vail'
--                   AND v.intake_id = a.intake_id AND v.mls_agent_id = a.mls_agent_id) AS has_vail_twin
--     FROM agent_mls_ids a WHERE a.market = 'vbor';

DELETE a FROM agent_mls_ids a
  JOIN agent_mls_ids v ON v.market = 'vail' AND v.intake_id = a.intake_id AND v.mls_agent_id = a.mls_agent_id
 WHERE a.market = 'vbor';

-- Should return nothing:
SELECT * FROM agent_mls_ids WHERE market = 'vbor';
