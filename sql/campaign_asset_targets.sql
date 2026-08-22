-- STATUS: superseded — do NOT run against dbmarketing_monthaus.
--
-- This migration was applied on the hub before the 2026-08-21 export that
-- bootstrap.sql was built from, so its effect is already present in this
-- database. Running it now errors with "Duplicate column name" — expected,
-- not a bug. Kept for history.
--
-- ============================================================
-- campaign_asset_targets.sql
--
-- Gives each ad/creative its own target URL, so one advertising
-- placement can carry several ads pointing at different pages.
--
-- Run once in phpMyAdmin.
-- ============================================================


-- ── Step 1: per-ad target URL ───────────────────────────────────────────
-- No IF NOT EXISTS on ALTER TABLE.

ALTER TABLE marketing_campaign_assets
  ADD COLUMN target_url VARCHAR(500) NULL AFTER file_url;


-- ── Step 2: fold the old single ad_file_url into the assets table ───────
-- Placements created before this change stored one ad directly on the
-- campaign row. This moves those into marketing_campaign_assets so every
-- ad lives in one place, then clears the old column.
--
-- Both statements are safe to run together; skip them if you'd rather
-- leave the legacy values where they are (the page renders either way).

INSERT INTO marketing_campaign_assets (campaign_id, label, file_url, target_url, file_type)
SELECT c.id, 'Ad file', c.ad_file_url, NULL, 'file'
  FROM marketing_campaigns c
 WHERE c.ad_file_url IS NOT NULL
   AND c.ad_file_url <> '';

UPDATE marketing_campaigns
   SET ad_file_url = NULL
 WHERE ad_file_url IS NOT NULL
   AND ad_file_url <> '';


-- ── Verify ──────────────────────────────────────────────────────────────

-- SELECT c.id, c.platform, c.name,
--        COUNT(a.id) AS ad_count,
--        SUM(a.target_url IS NOT NULL) AS ads_with_own_target
--   FROM marketing_campaigns c
--   LEFT JOIN marketing_campaign_assets a ON a.campaign_id = c.id
--  GROUP BY c.id
--  ORDER BY c.id;
