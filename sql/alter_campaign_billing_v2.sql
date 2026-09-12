-- ============================================================================
--  alter_campaign_billing_v2.sql — recurring ad placements, billed month by month
--
--  STATUS: run
--
--  ▸ This file was revised after an earlier version was delivered on the same
--    day and before either was applied. Check first — one query:
--
--        SHOW COLUMNS FROM marketing_campaign_months;
--
--    · Error 1146, table does not exist  → nothing has run. Run this file.
--    · Table exists and has an `effective_forward` column → the earlier version
--      ran. Stop and say so; that column and `skipped` need dropping rather
--      than this file re-running.
--    · Table exists without that column  → this file already ran. Do nothing.
--
--  ── What this is for ───────────────────────────────────────────────────────
--
--  Financials used to bucket every placement into a single month (start_date)
--  and put the whole budget there. end_date was stored and shown but never
--  affected a figure. Real situations that broke:
--
--   1. Wednesday marquee, $125/day, August. Four Wednesdays — but the outlet
--      missed one, so the agent owes $375, not $500.
--   2. Saturday marquee, $125/day, August. Five Saturdays that month: $625.
--      Same placement, same rate, different total, purely from the calendar.
--   3. $400 of impressions for the month. Starting on the 15th does not make
--      it $200 — the full buy is delivered and the full $400 is owed.
--   4. In September that agent goes to $600. In October, who knows: agents
--      budget a month at a time and rarely commit three months ahead.
--
--  ── The principle ──────────────────────────────────────────────────────────
--
--  A recurring placement does NOT carry an amount that repeats itself forward.
--  Each month is entered. A month nobody has entered bills NOTHING, and says
--  so on the Financials tab — so an unbilled month is visible, and no agent is
--  ever charged a figure that was inferred rather than typed.
--
--  That is why there is no "monthly budget" driving anything: `budget` on a
--  monthly placement is only a convenience default that pre-fills the grid.
--
--  ── The three billing modes ────────────────────────────────────────────────
--
--    one_time   whole `budget` in the month of start_date. Unchanged, and
--               still the default, so no historical total moves.
--    monthly    one row per month in marketing_campaign_months carries that
--               month's amount. No row, or a NULL amount, bills nothing. (3, 4)
--    per_unit   `unit_rate` × the matching days in the month. Here the count
--               IS derivable, so the calendar supplies it and a row only
--               overrides it when the outlet ran short. (1, 2)
--
--  Run against dbmarketing_monthaus.
-- ============================================================================


-- 1 ── How a placement bills, and the per-day rate.
--      No IF NOT EXISTS on ALTER TABLE — unsupported on MySQL.
ALTER TABLE marketing_campaigns
  ADD COLUMN billing_mode ENUM('one_time','monthly','per_unit')
             NOT NULL DEFAULT 'one_time' AFTER budget,
  ADD COLUMN unit_rate    DECIMAL(10,2) DEFAULT NULL
             COMMENT 'per_unit: charge per matching day' AFTER billing_mode,
  ADD COLUMN unit_weekday TINYINT DEFAULT NULL
             COMMENT 'per_unit: 0=Sun..6=Sat, counted from the calendar. NULL = type the quantity each month' AFTER unit_rate,
  ADD COLUMN unit_label   VARCHAR(40) DEFAULT NULL
             COMMENT 'per_unit: what one unit is called — day, insertion, spot' AFTER unit_weekday;


-- 2 ── One row per month of a recurring placement.
--
--     For `monthly` this is where the money lives. `amount` is that month's
--     charge and nothing else supplies it:
--
--       no row, or amount IS NULL   nothing billed, and Financials shows the
--                                   month with "amount not set"
--       amount = 0.00               billed as zero on purpose — a paused month
--                                   that has been decided, not forgotten
--       amount = 600.00             that month is $600, and no other month
--                                   moves because of it
--
--     For `per_unit` the month bills unit_rate × days-on-the-calendar with no
--     row at all. A row overrides the count (`units`) or that month's rate.
--
--     `paid_by` and the split amounts are per-month too, and NULL inherits
--     from the placement — an agent can pick up one month of a buy Mont Haus
--     otherwise covers.
CREATE TABLE IF NOT EXISTS marketing_campaign_months (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id`        INT UNSIGNED NOT NULL,
  `ym`                 CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `amount`             DECIMAL(10,2) DEFAULT NULL COMMENT 'monthly: this month''s charge. NULL = not set, bills nothing',
  `units`              DECIMAL(6,2)  DEFAULT NULL COMMENT 'per_unit: NULL = count the weekday from the calendar',
  `unit_rate`          DECIMAL(10,2) DEFAULT NULL COMMENT 'per_unit: NULL = use campaigns.unit_rate',
  `paid_by`            ENUM('broker','mont_haus','split') DEFAULT NULL COMMENT 'NULL = inherit from the placement',
  `paid_broker_amount` DECIMAL(10,2) DEFAULT NULL,
  `paid_mh_amount`     DECIMAL(10,2) DEFAULT NULL,
  `note`               VARCHAR(255) DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campaign_ym` (`campaign_id`,`ym`),
  CONSTRAINT `fk_campmonth_campaign`
    FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ── Verify ──────────────────────────────────────────────────────────────────
--
--   SHOW COLUMNS FROM marketing_campaigns LIKE 'billing_mode';
--   -- enum('one_time','monthly','per_unit'), NOT NULL, default one_time
--
--   SELECT billing_mode, COUNT(*) FROM marketing_campaigns GROUP BY billing_mode;
--   -- every existing row should still be one_time
--
--   SELECT COUNT(*) FROM marketing_campaign_months;   -- 0
--
-- If billing_mode comes back with an empty value anywhere, an invalid enum
-- assignment stored '' rather than erroring — the same failure that blanked
-- users.role. Fix the row, do not ignore it.


-- ── For reference: what the Advertising tab writes ──────────────────────────
--
-- Everything below is done from the Month-by-month grid on the placement.
-- These are here as the record of the shape, and for bulk edits.
--
--   UPDATE marketing_campaigns
--      SET billing_mode='monthly', start_date='2026-08-01', end_date=NULL
--    WHERE id = ?;
--
--   INSERT INTO marketing_campaign_months (campaign_id, ym, amount) VALUES
--     (?, '2026-08', 400.00),
--     (?, '2026-09', 600.00);
--   -- October is deliberately absent: it has not been decided yet, so it
--   -- bills nothing and shows as not set until somebody enters it.
--
-- A per-day placement — the calendar does the counting:
--
--   UPDATE marketing_campaigns
--      SET billing_mode='per_unit', unit_rate=125.00, unit_weekday=3,
--          unit_label='Wednesday', start_date='2026-08-01', end_date='2026-08-31'
--    WHERE id = ?;
--   -- unit_weekday: 0 Sun, 1 Mon, 2 Tue, 3 Wed, 4 Thu, 5 Fri, 6 Sat
--   -- August 2026 has four Wednesdays and five Saturdays, so the same rate on
--   -- the same month gives $500 or $625 depending on the day. That is correct.
--
--   INSERT INTO marketing_campaign_months (campaign_id, ym, units, note)
--   VALUES (?, '2026-08', 3, 'outlet missed one week');
