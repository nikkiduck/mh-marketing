-- STATUS: run
--
-- creatives_v1.sql — creative shown on the agent portal (2026-10-06).
-- docs/AGENT_PORTAL_PLAN.md, section 5 (Nikki, 2026-09-08: creative is
-- UPLOADED to the server, never a Dropbox or Drive link, so the portal can
-- show it as a picture).
--
-- An uploaded image (or PDF) on each advertising creative, and a proof on
-- each collateral order. The file itself lives outside the web root
-- (CREATIVES_DIR, /var/www/creatives beside /var/www/receipts) and is served
-- by creative.php (admin) and portal/asset.php (the owning agent only). The
-- *_thumb column holds a small JPEG made once at upload time, so phone cards
-- never resize an original on page view.
--
-- The old file_url columns stay: they are the "Open original" link.
-- Guarded with mk_column_exists() in agent.php and the portal, so the pages
-- work before and after this runs. Run once, as the master user. Check:
--   SHOW COLUMNS FROM marketing_campaign_assets   LIKE 'image_%';   -- 4 rows
--   SHOW COLUMNS FROM marketing_collateral_orders LIKE 'proof_%';   -- 4 rows
--
-- The server also needs the folder (Nikki's OK, then as admin):
--   sudo mkdir -p /var/www/creatives
--   sudo chown admin:www-data /var/www/creatives && sudo chmod 2775 /var/www/creatives

ALTER TABLE marketing_campaign_assets
  ADD COLUMN image_file        VARCHAR(255) NULL AFTER file_type,
  ADD COLUMN image_orig_name   VARCHAR(255) NULL AFTER image_file,
  ADD COLUMN image_thumb       VARCHAR(255) NULL AFTER image_orig_name,
  ADD COLUMN image_uploaded_at DATETIME     NULL AFTER image_thumb;

ALTER TABLE marketing_collateral_orders
  ADD COLUMN proof_file        VARCHAR(255) NULL AFTER file_url,
  ADD COLUMN proof_orig_name   VARCHAR(255) NULL AFTER proof_file,
  ADD COLUMN proof_thumb       VARCHAR(255) NULL AFTER proof_orig_name,
  ADD COLUMN proof_uploaded_at DATETIME     NULL AFTER proof_thumb;
