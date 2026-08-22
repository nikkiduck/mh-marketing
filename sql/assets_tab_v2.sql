-- STATUS: already run (2026-08-22) — marketing_asset_links created
-- ============================================================
-- assets_tab_v2.sql
-- Assets tab rework: file links, photoshoot fields, one-off links,
-- plus cleanup of the old onboarding sub-tasks.
--
-- Run each step once, in order, in phpMyAdmin.
-- STATUS: not yet run
-- ============================================================


-- ── Step 1: new columns on marketing_intakes ────────────────────────────
-- NOTE: no IF NOT EXISTS — unsupported on ALTER TABLE here.
-- If a column already exists you'll get "Duplicate column name"; skip that line.

ALTER TABLE marketing_intakes
  ADD COLUMN qr_code_url             VARCHAR(500) NULL,
  ADD COLUMN email_signature_url     VARCHAR(500) NULL,
  ADD COLUMN photoshoot_not_needed   TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN photoshoot_photographer VARCHAR(255) NULL,
  ADD COLUMN photoshoot_proofs_date  DATE         NULL,
  ADD COLUMN photoshoot_finals_date  DATE         NULL;


-- ── Step 2: table for one-off labeled links (Other Docs) ────────────────
-- IF NOT EXISTS is fine on CREATE TABLE.

CREATE TABLE IF NOT EXISTS marketing_asset_links (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  intake_id  INT          NOT NULL,
  label      VARCHAR(255) NOT NULL,
  url        VARCHAR(500) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_intake (intake_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── Step 3: drop the old onboarding sub-tasks ───────────────────────────
-- The checklist is now a flat list of 9 items. Existing agents still have
-- the seeded sub-task rows; this removes them. Their file links move to
-- the Assets tab (see Step 4 before running this).

DELETE FROM marketing_tasks
 WHERE category = 'onboarding'
   AND parent_id IS NOT NULL;


-- ── Step 4: OPTIONAL — carry existing sub-task file links over ──────────
-- Run this BEFORE Step 3 if any agents already have a signature or QR
-- SVG link saved on a sub-task. Otherwise skip.

-- UPDATE marketing_intakes mi
--   JOIN marketing_tasks mt
--     ON mt.intake_id = mi.id
--    AND mt.category  = 'onboarding'
--    AND mt.title     = 'Signature file'
--    SET mi.email_signature_url = mt.file_url
--  WHERE mt.file_url IS NOT NULL AND mt.file_url <> '';

-- UPDATE marketing_intakes mi
--   JOIN marketing_tasks mt
--     ON mt.intake_id = mi.id
--    AND mt.category  = 'onboarding'
--    AND mt.title     = 'SVG file'
--    SET mi.qr_code_url = mt.file_url
--  WHERE mt.file_url IS NOT NULL AND mt.file_url <> '';


-- ── Step 5: OPTIONAL — retire now-unused headshot checkbox columns ──────
-- The Assets tab no longer reads these. Leaving them costs nothing;
-- run this only if you want the table tidy.

-- ALTER TABLE marketing_intakes
--   DROP COLUMN check_headshot_scheduled,
--   DROP COLUMN check_headshot_proofs,
--   DROP COLUMN check_headshot_final,
--   DROP COLUMN headshot_proofs_url,
--   DROP COLUMN headshot_final_url;
