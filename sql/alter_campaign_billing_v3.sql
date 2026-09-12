-- ============================================================================
--  alter_campaign_billing_v3.sql — a standing monthly buy
--
--  STATUS: run
--
--  ▸ Runs AFTER alter_campaign_billing_v2.sql. If v2 has not been applied yet,
--    apply it first — this file only widens an enum v2 creates.
--
--    Check with:  SHOW COLUMNS FROM marketing_campaigns LIKE 'billing_mode';
--      · no such column                    → run v2 first, then this
--      · enum without 'monthly_flat'       → run this
--      · enum already has 'monthly_flat'   → already applied, do nothing
--
--  ── Why ────────────────────────────────────────────────────────────────────
--
--  v2 made every month of a monthly placement something you type, because an
--  amount that copies itself forward is a figure nobody agreed to. That is
--  right for a buy that moves around month to month.
--
--  It is wrong for a standing one. "$1,000 a month on the Aspen Daily News
--  leaderboard, no end date" is a single decision that has already been made,
--  and retyping 1000 every month is busywork that will eventually be forgotten
--  — which produces exactly the unbilled month v2 was protecting against.
--
--  So the two are separate modes rather than one mode with a guess:
--
--    monthly       you enter each month's amount. A month you have not entered
--                  bills nothing and is flagged. For buys that vary.
--    monthly_flat  `budget` is the amount, every month, from the start date
--                  onward. For a standing buy. A per-month row still overrides
--                  any single month, and setting an end date stops it.
--
--  Neither one ever bills a month that has not started yet.
-- ============================================================================


-- 1 ── Append the new mode.
--
--     APPENDED, not inserted in the middle. MySQL can add a value to the END of
--     an ENUM in place; inserting one earlier forces a full table copy, and the
--     order here carries no meaning worth paying for that.
--
--     No IF NOT EXISTS on ALTER TABLE — unsupported on MySQL.
ALTER TABLE marketing_campaigns
  MODIFY billing_mode ENUM('one_time','monthly','per_unit','monthly_flat')
         NOT NULL DEFAULT 'one_time';


-- ── Verify ──────────────────────────────────────────────────────────────────
--
--   SHOW COLUMNS FROM marketing_campaigns LIKE 'billing_mode';
--   -- enum('one_time','monthly','per_unit','monthly_flat'), NOT NULL,
--   -- default one_time
--
--   SELECT billing_mode, COUNT(*) FROM marketing_campaigns GROUP BY billing_mode;
--   -- unchanged from before this ran: widening an enum moves no row
--
-- If any row comes back with an empty billing_mode, an invalid assignment
-- stored '' rather than erroring — the same failure that blanked users.role.
-- Fix the row; do not ignore it.


-- ── Setting up a standing buy ───────────────────────────────────────────────
--
--   UPDATE marketing_campaigns
--      SET billing_mode = 'monthly_flat',
--          budget       = 1000.00,
--          start_date   = '2026-07-01',
--          end_date     = NULL          -- open-ended: runs until stopped
--    WHERE id = ?;
--
-- Every month from July onward bills $1,000, and a new month starts billing on
-- its first day — never before. To stop it, set end_date; to change one month,
-- enter that month in the grid; to change the rate from here on, edit budget
-- and enter the earlier months you want held at the old figure.
