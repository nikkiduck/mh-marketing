-- ============================================================================
-- agent_roster_v2_teams.sql — teams as marketing entities
-- STATUS: not yet run
--
-- Run ONCE in TablePlus as the master user, after agent_roster_v1.sql.
-- Not idempotent (a second run: "Duplicate column name"; expected, harmless).
--
-- Why (Nikki, 2026-09-22): Weber Boxer Group advertises and is billed as ONE
-- entity, but on the website and in the MLS its people are individuals:
-- Jonathan Boxer, Scott Weber, Sara Perkowski. So a team is a marketing_intakes
-- row with entity_type = 'team':
--   · it keeps its campaigns, collateral, tasks and billing, as today;
--   · it is never in the website feed, never matched to a person by email or
--     name, never credited on a listing (mk_team_sql() in inc/agent_roster.php);
--   · its members are ordinary agent rows with their own profiles, listed in
--     team_members. The team's MLS ID (WebBoxGrp) is an alias identity on each
--     member, so team listings credit every member by name.
--
-- After running this, split the existing row with cron/split_team.php (see
-- docs/AGENT_ROSTER_PLAN.md, "Teams").
-- ============================================================================

ALTER TABLE marketing_intakes
    ADD COLUMN entity_type ENUM('agent','team') NOT NULL DEFAULT 'agent'
        COMMENT 'team = marketing/billing entity only; its people are separate agent rows'
        AFTER agent_name;

CREATE TABLE IF NOT EXISTS team_members (
    team_id    INT UNSIGNED NOT NULL COMMENT 'marketing_intakes.id, entity_type = team',
    member_id  INT UNSIGNED NOT NULL COMMENT 'marketing_intakes.id, entity_type = agent',
    sort_order INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (team_id, member_id),
    KEY idx_tm_member (member_id),
    CONSTRAINT fk_tm_team   FOREIGN KEY (team_id)   REFERENCES marketing_intakes (id) ON DELETE CASCADE,
    CONSTRAINT fk_tm_member FOREIGN KEY (member_id) REFERENCES marketing_intakes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
