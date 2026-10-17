-- STATUS: not yet run
--
-- elevation_plans_v1.sql — the Elevation Planner's saved plans on the agent
-- portal (portal/elevation.php, 2026-10-17). One plan = the Planner (part
-- one, the income goal worked back to conversations) AND Lead Sources (part
-- two, the activities that start those conversations), stored as the two JSON
-- state objects the page edits (inc/elevation.php cleans them before they
-- are written).
--
-- Rows are per PERSON (intake_id is the agent's own account, never a team:
-- a plan is personal), two kinds:
--   working  the plan being edited; one row per person, rewritten as they
--            type (the page saves a moment after each change)
--   saved    a named snapshot taken with "Save this plan"; as many as they
--            like; each can be opened again, renamed, printed or deleted
--
-- Until this has run the page keeps entries in the visitor's browser only
-- and says so (mk_table_exists guard), so it is safe to deploy first.
--
-- CREATE TABLE IF NOT EXISTS is standard MySQL (the rule is only about ALTER).
-- Run once, as the master user. Check after:
--   SHOW COLUMNS FROM elevation_plans;   -- 8 rows

CREATE TABLE IF NOT EXISTS elevation_plans (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  intake_id   INT UNSIGNED NOT NULL COMMENT 'marketing_intakes.id: the agent it belongs to (a person, never a team)',
  kind        ENUM('working','saved') NOT NULL DEFAULT 'saved',
  name        VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'the snapshot name; blank on the working row',
  plan        JSON NOT NULL COMMENT 'part one state: goal, expenses, tax, split, weeks, price, rate, apptRate, agreeRate, closeRate, q1-q4, markets, n1-n3',
  leads       JSON NOT NULL COMMENT 'part two state: need, budget, weeks, channels[], tools[]',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_elevation_intake (intake_id, kind, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
