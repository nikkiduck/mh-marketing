-- ============================================================================
--  alter_intake_initials_v1.sql — let the avatar initials be set by hand
--
--  STATUS: run
--
--  The avatar derives initials from the agent's name: first letter of the first
--  word, first letter of the last word. That is right for a person and wrong
--  for anything else. "Weber Boxer Group" gives WG. "Weber Boxer Group |
--  Jonathan Boxer Scott Weber" gave WW.
--
--  Rather than guess harder — team names have no rule a computer can infer —
--  this stores an override. NULL means keep deriving it, which is the case for
--  every ordinary agent and why nothing needs seeding.
--
--  Run against dbmarketing_monthaus.
-- ============================================================================

-- No IF NOT EXISTS on ALTER TABLE — unsupported on MySQL.
ALTER TABLE marketing_intakes
  ADD COLUMN initials VARCHAR(4) DEFAULT NULL
             COMMENT 'avatar override; NULL = derive from agent_name'
  AFTER agent_name;


-- ── Verify ──────────────────────────────────────────────────────────────────
--
--   SHOW COLUMNS FROM marketing_intakes LIKE 'initials';
--   -- varchar(4), nullable, default NULL
--
--   SELECT COUNT(*) FROM marketing_intakes WHERE initials IS NOT NULL;   -- 0
--
-- Set from the Overview tab, or by hand:
--
--   UPDATE marketing_intakes SET initials = 'WB' WHERE id = 8;
--
-- Four characters rather than two so an unusual case is not blocked by the
-- column. The avatar is sized for two and will look cramped beyond three —
-- that is a judgement for whoever types it, not a limit worth enforcing here.
