-- STATUS: run
--
-- hot_sheets_changeover_cleanup.sql — undo the feed changeover's false Hot
-- Sheet changes (2026-10-04). Run once, as the master user.
--
-- When Elevate and Vail went live in Anyprop (2026-10-01) the listing feed
-- delivered 16 homes that had been on the market for weeks or months, and
-- the sync logged each as new_listing; two more (311 S Aspen St 2 on 9/28,
-- 131 Dillon Lane on 10/2) were likewise already listed before we first saw
-- them. Each was checked against the board's own on-market date on the site.
-- The one genuine new listing in the window, 633 W Main Street (listed
-- 2026-09-28), is kept. The sync now reads the on-market date from the feed
-- and carries such listings in silently, so this cannot recur for CREN or
-- REColorado.
--
-- 405 Meadow Court SOLD on 2026-09-30 ($2,650,000) but was logged as
-- "removed": the sale arrived through the site's 15-minute delta, which does
-- not stamp final_status_checked_at, so the listing left the feed before the
-- sync saw it close. The feed now keeps recently modified Mont Haus listings
-- for 30 days; the next sync (:40) re-reads it and logs the status change and
-- the sale itself, so only the wrong "removed" row goes here.
--
-- Check first (expect 18 rows + 1 row):
-- SELECT market, listing_key, change_type, detected_at FROM hs_listing_changes
--  WHERE change_type = 'new_listing' AND listing_key <> 'agsmls_194709';
-- SELECT * FROM hs_listing_changes WHERE change_type = 'removed' AND listing_key = 'agsmls_194266';

DELETE FROM hs_listing_changes
 WHERE change_type = 'new_listing'
   AND listing_key IN ('agsmls_194669', 'ppmls_2669587', 'ppmls_2761177', 'ppmls_3185344', 'ppmls_3387263',
                       'ppmls_5667650', 'ppmls_7058717', 'ppmls_8568516', 'ppmls_6979058',
                       'vail_1014076', 'vail_1014092', 'vail_1014100', 'vail_1014591', 'vail_1014785',
                       'vail_1014903', 'vail_1014904', 'vail_1015173', 'ppmls_2251122');

DELETE FROM hs_listing_changes WHERE change_type = 'removed' AND listing_key = 'agsmls_194266';

-- After: SELECT change_type, COUNT(*) FROM hs_listing_changes GROUP BY change_type;
-- expect new_listing 1, price 2, status 1 (the Meadow Court sale appears after the next :40 sync).
