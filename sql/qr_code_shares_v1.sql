-- ============================================================================
-- qr_code_shares_v1.sql — one QR code on more than one agent's portal
-- STATUS: run (Nikki, 2026-10-26)
--
-- Nikki, 2026-10-26: qr.monthaus.com/kc-open is printed on Kimberlee Coates's
-- open house signs, but her son Jackson Horn works the open houses with her
-- and is usually the one who needs to repoint it. He is not on her account
-- and they are not a team row, so his portal could not see it.
--
-- A share puts a code on another agent's portal WITHOUT moving it: the code
-- stays Kimberlee's (qr_codes.intake_id, the owner's profile page, the admin
-- page's grouping), and Jackson can see it and change where it goes exactly
-- as she can. Both see the same row, so there is one destination, never two.
-- Set from the admin QR Codes page ("Also on the portal of" in a code's
-- Change form); the portal cannot share a code.
--
-- Run ONCE in TablePlus as the master user. qr_codes.php, portal/qr.php,
-- portal/index.php and agent.php all guard on this table existing
-- (qr_shares_ready() in inc/qr.php), so the pages work before and after.
--
-- Verify afterwards:
--   SHOW TABLES LIKE 'qr\_code\_shares';                       -- one row
--   SELECT q.code, mi.agent_name FROM qr_code_shares s
--     JOIN qr_codes q ON q.id = s.qr_id
--     JOIN marketing_intakes mi ON mi.id = s.intake_id;        -- kc-open, Jackson Horn
-- ============================================================================

CREATE TABLE IF NOT EXISTS qr_code_shares (
    qr_id      INT UNSIGNED NOT NULL COMMENT 'qr_codes.id',
    intake_id  INT UNSIGNED NOT NULL COMMENT 'marketing_intakes.id of the OTHER agent whose portal also shows the code',
    added_by   INT UNSIGNED NULL     COMMENT 'users.id',
    added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (qr_id, intake_id),
    KEY idx_qrs_intake (intake_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The case it was built for: kc-open (Kimberlee Coates) on Jackson Horn's
-- portal too. Looked up by code and by name so no id is guessed; inserts
-- nothing if either is not found (check the SELECT in the header afterwards).
INSERT INTO qr_code_shares (qr_id, intake_id)
SELECT q.id, mi.id
  FROM qr_codes q
  JOIN marketing_intakes mi ON mi.agent_name = 'Jackson Horn' AND mi.entity_type = 'agent'
 WHERE q.code = 'kc-open';

-- And the record of it, as the admin page would have written it.
INSERT INTO qr_code_changes (qr_id, what, old_value, new_value, changed_by)
SELECT q.id, 'shared', NULL, 'Jackson Horn', NULL
  FROM qr_codes q WHERE q.code = 'kc-open';
