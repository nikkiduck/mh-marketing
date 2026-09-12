-- ============================================================================
--  alter_billing_status_v1.sql — has this month been invoiced, and has it been paid
--
--  STATUS: run
--
--  Runs after alter_campaign_billing_v2/v3. Independent of them, but the page
--  it serves needs their figures to be right.
--
--  ── What this stores, and what it deliberately does not ────────────────────
--
--  It stores STATE, not money. What an agent owes for a month is computed from
--  their collateral orders and placements every time the page loads — see
--  `mh_agent_financials()` in inc/financials.php. Storing that total here as
--  well would give two answers to one question, and they would drift the first
--  time somebody corrected a campaign.
--
--  The single exception is `billed_amount`, and it is not a duplicate — it is
--  the figure that actually went out on the invoice, frozen at the moment the
--  BILLED box was ticked. If the live total later moves, the two disagreeing
--  is the useful signal: an invoice is out in the world for the old number.
--  The page shows both and flags it rather than quietly adopting the new one.
--
--  Run against dbmarketing_monthaus.
-- ============================================================================


-- 1 ── One row per agent per month, created the first time either box is ticked.
--
--     No row means "not billed, not paid" — the normal state of a fresh month,
--     and the reason nothing needs seeding.
CREATE TABLE IF NOT EXISTS marketing_billing_months (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `intake_id`      INT UNSIGNED NOT NULL,
  `ym`             CHAR(7) NOT NULL COMMENT 'YYYY-MM',

  `billed_at`      DATETIME DEFAULT NULL COMMENT 'NULL = not invoiced yet',
  `billed_amount`  DECIMAL(10,2) DEFAULT NULL COMMENT 'the total as it stood when BILLED was ticked — what the invoice says',
  `billed_by`      INT UNSIGNED DEFAULT NULL COMMENT 'users.id, soft reference',

  `paid_at`        DATETIME DEFAULT NULL COMMENT 'NULL = outstanding',
  `paid_by_user`   INT UNSIGNED DEFAULT NULL COMMENT 'users.id, soft reference — NOT the who-pays enum',

  `note`           VARCHAR(255) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_intake_ym` (`intake_id`,`ym`),
  KEY `idx_ym` (`ym`),
  CONSTRAINT `fk_billmonth_intake`
    FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ── Verify ──────────────────────────────────────────────────────────────────
--
--   SHOW COLUMNS FROM marketing_billing_months;
--   SELECT COUNT(*) FROM marketing_billing_months;   -- 0, nothing ticked yet
--
-- The foreign key points at marketing_intakes, not office_roster: billing
-- follows the marketing record, and an agent with no intake row has nothing
-- to bill.
--
--   SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND TABLE_NAME = 'marketing_billing_months'
--      AND CONSTRAINT_TYPE = 'FOREIGN KEY';   -- 1


-- ── Reading it by hand ──────────────────────────────────────────────────────
--
-- Everything outstanding, oldest first:
--
--   SELECT i.agent_name, b.ym, b.billed_at, b.billed_amount
--     FROM marketing_billing_months b
--     JOIN marketing_intakes i ON i.id = b.intake_id
--    WHERE b.billed_at IS NOT NULL AND b.paid_at IS NULL
--    ORDER BY b.ym ASC, i.agent_name;
--
-- Un-tick a month that was marked in error — clearing the timestamp is what
-- un-ticks it, and clearing billed_at should clear the snapshot with it:
--
--   UPDATE marketing_billing_months
--      SET billed_at = NULL, billed_amount = NULL, billed_by = NULL
--    WHERE intake_id = ? AND ym = ?;
