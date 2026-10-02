-- STATUS: run (columns verified 2026-10-02)
--
-- leadership_v1.sql — the public site's Leadership page managed here, and
-- non-agent staff in the roster (docs/HANDOFF-leadership-and-staff.md). 2026-10-01.
--
--   entity_type gains 'staff': a person who is not an agent (Nikki Boxer,
--     Kellee Anderson). Reuses agent_name, agent_title, mh_email, cell_phone,
--     the headshots, is_active and slug. Never an agent anywhere:
--     mk_agents_only_sql() (inc/agent_roster.php) keeps staff out of every
--     agent query, the roster feed's `agents`, MLS matching, Hot Sheets,
--     Pipeline, billing and lead rotation.
--   leadership_show / leadership_sort: who appears on Leadership, and in what
--     order (separate from sort_order, the Our Agents order). Managed on
--     leadership.php; published as the feed's top-level `leadership` array.
--
-- Run as the master user in TablePlus. Pages check for the columns first, so
-- they can be uploaded before this runs. MySQL 8: no IF NOT EXISTS on ALTER.
--
-- Verify afterwards:
--   SHOW COLUMNS FROM marketing_intakes WHERE Field IN ('entity_type','leadership_show','leadership_sort');

ALTER TABLE marketing_intakes
    MODIFY COLUMN entity_type ENUM('agent','team','staff') NOT NULL DEFAULT 'agent',
    ADD COLUMN leadership_show TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order,
    ADD COLUMN leadership_sort INT NOT NULL DEFAULT 0 AFTER leadership_show;
