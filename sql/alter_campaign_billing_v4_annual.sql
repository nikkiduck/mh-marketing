-- ============================================================================
--  alter_campaign_billing_v4_annual.sql — a yearly charge (membership dues)
--
--  STATUS: run
--
--  ▸ Runs AFTER alter_campaign_billing_v3.sql (which it only widens).
--
--    Check with:  SHOW COLUMNS FROM marketing_campaigns LIKE 'billing_mode';
--      · enum without 'annual'   → run this
--      · enum already has it     → already applied, do nothing
--
--  ── Why ────────────────────────────────────────────────────────────────────
--
--  Membership dues (2026-10-14, Nikki: "have that dropdown have an Annually
--  option") are one amount a year, from the day the agent joined, until the
--  renewal date. `one_time` books the first year and nothing after; `monthly`
--  would bill twelve times. So:
--
--    annual   `budget` is the amount, charged in the month of the start date
--             and again in that month every year, up to and including the
--             end date (Renews). No end date means it keeps renewing until one
--             is set. A per-month row still overrides a single year. Never a
--             year that has not started.
--
--  agent.php offers the option only once this has run (mk_enum_has); until
--  then a posted 'annual' is stored as one_time rather than failing silently.
-- ============================================================================

-- APPENDED to the end of the ENUM (in place; inserting mid-list copies the
-- table). No IF NOT EXISTS on ALTER TABLE — unsupported on MySQL.
ALTER TABLE marketing_campaigns
  MODIFY billing_mode ENUM('one_time','monthly','per_unit','monthly_flat','annual')
         NOT NULL DEFAULT 'one_time';

-- ── Verify ──────────────────────────────────────────────────────────────────
--   SHOW COLUMNS FROM marketing_campaigns LIKE 'billing_mode';
--   -- enum('one_time','monthly','per_unit','monthly_flat','annual')
--   SELECT billing_mode, COUNT(*) FROM marketing_campaigns GROUP BY billing_mode;
--   -- unchanged: widening an enum moves no row
