-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- Roster Consolidation — Data Population
-- Schema columns are already applied. Run these UPDATE
-- statements to wire everything together.
-- ============================================================

-- ── Step 5: Link mh_brokers → office_roster by email ─────────

UPDATE `mh_brokers` mb
JOIN `office_roster` r
  ON LOWER(r.email COLLATE utf8mb4_unicode_ci) = LOWER(mb.email COLLATE utf8mb4_unicode_ci)
SET mb.roster_id = r.id
WHERE mb.roster_id IS NULL;

-- ── Step 6a: Link marketing_intakes → office_roster via mh_broker_id ──

UPDATE `marketing_intakes` mi
JOIN `mh_brokers` mb ON mb.id = mi.mh_broker_id
SET mi.roster_id = mb.roster_id
WHERE mi.roster_id IS NULL AND mb.roster_id IS NOT NULL;

-- ── Step 6b: Fallback — match marketing_intakes by mh_email ──

UPDATE `marketing_intakes` mi
JOIN `office_roster` r
  ON LOWER(r.email COLLATE utf8mb4_unicode_ci) = LOWER(mi.mh_email COLLATE utf8mb4_unicode_ci)
SET mi.roster_id = r.id
WHERE mi.roster_id IS NULL;

-- ── Step 7a: Link listing_brokers → office_roster by exact name ──

UPDATE `listing_brokers` lb
JOIN `office_roster` r
  ON LOWER(r.name COLLATE utf8mb4_unicode_ci) = LOWER(lb.full_name COLLATE utf8mb4_unicode_ci)
SET lb.roster_id = r.id
WHERE lb.roster_id IS NULL;

-- ── Step 7b: Partial name match (e.g. "Scott Weber" → "Scott James Weber") ──

UPDATE `listing_brokers` lb
JOIN `office_roster` r
  ON LOWER(r.name COLLATE utf8mb4_unicode_ci)
     LIKE CONCAT('%', LOWER(lb.full_name COLLATE utf8mb4_unicode_ci), '%')
SET lb.roster_id = r.id
WHERE lb.roster_id IS NULL;

-- ── Step 8: Copy markets from mh_brokers → office_roster ─────

UPDATE `office_roster` r
JOIN `mh_brokers` mb ON mb.roster_id = r.id
SET r.markets = mb.markets
WHERE r.markets IS NULL AND mb.markets IS NOT NULL;

-- ── Step 9: Copy Spark keys from marketing_intakes → office_roster ──
-- (marketing_intakes.mls_id_aspen stores the 26-char Spark key)

UPDATE `office_roster` r
JOIN `marketing_intakes` mi ON mi.roster_id = r.id
SET r.agent_key = mi.mls_id_aspen
WHERE r.agent_key IS NULL AND mi.mls_id_aspen IS NOT NULL;

UPDATE `office_roster` r
JOIN `marketing_intakes` mi ON mi.roster_id = r.id
SET r.vail_agent_key = mi.mls_id_vail
WHERE r.vail_agent_key IS NULL AND mi.mls_id_vail IS NOT NULL;

-- ── Verification — run these after to confirm results ─────────

-- mh_brokers: how many linked?
-- SELECT COUNT(*) linked, (SELECT COUNT(*) FROM mh_brokers) total FROM mh_brokers WHERE roster_id IS NOT NULL;

-- Any mh_brokers NOT linked (need manual fix)?
-- SELECT id, full_name, email FROM mh_brokers WHERE roster_id IS NULL ORDER BY full_name;

-- marketing_intakes: how many linked?
-- SELECT id, agent_name, mh_email, roster_id FROM marketing_intakes ORDER BY agent_name;

-- listing_brokers: which full_names are still unlinked (external agents are fine)?
-- SELECT DISTINCT full_name FROM listing_brokers WHERE roster_id IS NULL ORDER BY full_name;
