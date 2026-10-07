-- ============================================================================
-- hot_sheets_v4_areas.sql — Hot Sheets by area (Phase 1, 2026-10-07)
-- STATUS: run
--
-- Run ONCE in TablePlus as the master user, after hot_sheets_v1/v2/v3.
-- Statements are in the order they must run. Nothing here is destructive.
--
-- The two Mont Haus emails (Listings, Rentals) become one email PER AREA, the
-- six Communities areas the website publishes in api/boards.php (`areas`,
-- each with a `key`): roaring-fork-valley, vail-valley, summit-county,
-- gunnison-valley, southwest-colorado, front-range. Nikki, 2026-10-07: "no
-- reason why our CO Springs team would want to get Aspen listings".
--
--   hs_listing_state.area_key   stamped by the site's feed (listing_area():
--                               the town's area, else the board's); filled by
--                               the next hourly sync after the deploy. Until
--                               then hs_data.php derives it from the city.
--   hs_manual_listings.area_key pocket listings and buyer reps: chosen on
--                               pipeline_review.php when promoted (defaulted
--                               from the MLS match). The six rows that exist
--                               today have no city and no board, so they are
--                               set below where the address makes it obvious
--                               and left for Nikki to set on the review page
--                               where it does not.
--   hs_subscribers.areas        JSON list of area keys ("[\"vail-valley\"]").
--                               NULL or [] = no areas chosen = nothing sent,
--                               shown as a warning on subscribers.php. Filled
--                               below for every subscriber who is a roster
--                               agent, from their FUB service areas, else the
--                               Service area text on their profile, else
--                               their boards (the same rule
--                               mk_hs_default_areas() applies from now on).
--   hs_subscribers.frequency    daily, or twice_weekly (Monday + Thursday,
--                               Mountain time). `weekly` stays in the enum
--                               only so the hub import can still write it;
--                               every weekly row becomes twice_weekly here.
--   hs_sends.area_key           which area's email a send row was.
--
-- The old receives_listings / receives_rentals flags stay in the table but
-- nothing reads them any more: rentals are a section of the area email.
-- ============================================================================

ALTER TABLE hs_listing_state
    ADD COLUMN area_key VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'Hot Sheet area (website Communities area key), from the feed' AFTER subdivision,
    ADD COLUMN area     VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Its name, as the feed sent it' AFTER area_key,
    ADD KEY idx_hs_state_area (area_key);

ALTER TABLE hs_manual_listings
    ADD COLUMN area_key VARCHAR(60) NULL COMMENT 'Hot Sheet area; NULL = not chosen yet, appears in no email' AFTER market;

ALTER TABLE hs_subscribers
    MODIFY COLUMN frequency ENUM('daily','twice_weekly','weekly') NOT NULL DEFAULT 'twice_weekly' COMMENT 'twice_weekly = Monday + Thursday; weekly is legacy (Mondays)',
    ADD COLUMN areas TEXT NULL COMMENT 'JSON list of Hot Sheet area keys; NULL/[] = none chosen, nothing sent' AFTER markets;

ALTER TABLE hs_sends
    MODIFY COLUMN kind ENUM('listings','rentals','area') NOT NULL,
    ADD COLUMN area_key VARCHAR(60) NOT NULL DEFAULT '' AFTER kind,
    ADD KEY idx_hs_sends_area (send_date, email, area_key);

-- Nikki chose daily or twice a week; nobody is weekly any more.
UPDATE hs_subscribers SET frequency = 'twice_weekly' WHERE frequency = 'weekly';

-- Link the subscribers the hub import left unlinked to their roster agent
-- (matched on the profile email, 2026-10-07; only where exactly one active
-- agent row matched). Lets the agent page and the portal find them.
UPDATE hs_subscribers SET intake_id = 16 WHERE id = 2  AND intake_id IS NULL;   -- Jean-Michel Drai
UPDATE hs_subscribers SET intake_id = 36 WHERE id = 3  AND intake_id IS NULL;   -- Nikki Boxer (agent row)
UPDATE hs_subscribers SET intake_id = 19 WHERE id = 5  AND intake_id IS NULL;   -- Allison Byford
UPDATE hs_subscribers SET intake_id = 4  WHERE id = 6  AND intake_id IS NULL;   -- Allison Decent
UPDATE hs_subscribers SET intake_id = 10 WHERE id = 7  AND intake_id IS NULL;   -- Britton Skusa
UPDATE hs_subscribers SET intake_id = 5  WHERE id = 8  AND intake_id IS NULL;   -- Bryan Cournoyer
UPDATE hs_subscribers SET intake_id = 18 WHERE id = 9  AND intake_id IS NULL;   -- Bryan Peterson
UPDATE hs_subscribers SET intake_id = 17 WHERE id = 10 AND intake_id IS NULL;   -- Corey Schaefer
UPDATE hs_subscribers SET intake_id = 32 WHERE id = 11 AND intake_id IS NULL;   -- Grant Langham
UPDATE hs_subscribers SET intake_id = 6  WHERE id = 12 AND intake_id IS NULL;   -- Jackson Horn
UPDATE hs_subscribers SET intake_id = 11 WHERE id = 13 AND intake_id IS NULL;   -- Jay Friedstein
UPDATE hs_subscribers SET intake_id = 7  WHERE id = 14 AND intake_id IS NULL;   -- Kimberlee Coates
UPDATE hs_subscribers SET intake_id = 20 WHERE id = 15 AND intake_id IS NULL;   -- Laura Pietrzak
UPDATE hs_subscribers SET intake_id = 3  WHERE id = 16 AND intake_id IS NULL;   -- Minette Stapleton
UPDATE hs_subscribers SET intake_id = 2  WHERE id = 19 AND intake_id IS NULL;   -- Steve Harriage
UPDATE hs_subscribers SET intake_id = 9  WHERE id = 22 AND intake_id IS NULL;   -- Trent Jones

-- Starting areas, from the roster on 2026-10-07 (FUB service areas, else the
-- profile's Service area text, else boards). Adjust on subscribers.php.
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 1;    -- Jonathan Boxer (FUB towns: Roaring Fork Valley)
UPDATE hs_subscribers SET areas = '["vail-valley"]'                               WHERE id = 2;    -- Jean-Michel Drai: "Vail and the Vail Valley"
UPDATE hs_subscribers SET areas = '["front-range","gunnison-valley","roaring-fork-valley","southwest-colorado","summit-county","vail-valley"]'
                                                                                  WHERE id = 3;    -- Nikki Boxer: every area (the test recipient)
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 5;    -- Allison Byford
UPDATE hs_subscribers SET areas = '["summit-county","vail-valley"]'               WHERE id = 6;    -- Allison Decent: "Vail and the Vail Valley; Summit and Lake County"
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 7;    -- Britton Skusa
UPDATE hs_subscribers SET areas = '["roaring-fork-valley","vail-valley"]'         WHERE id = 8;    -- Bryan Cournoyer: "Vail, Aspen and the Roaring Fork Valley"
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 9;    -- Bryan Peterson
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 10;   -- Corey Schaefer
UPDATE hs_subscribers SET areas = '["front-range"]'                               WHERE id = 11;   -- Grant Langham: "Denver Metro"
UPDATE hs_subscribers SET areas = '["roaring-fork-valley","southwest-colorado"]'  WHERE id = 12;   -- Jackson Horn: "Montrose and the Western Slope; Aspen and the Roaring Fork Valley"
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 13;   -- Jay Friedstein
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 14;   -- Kimberlee Coates
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 15;   -- Laura Pietrzak
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 16;   -- Minette Stapleton
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 17;   -- Sara Perkowski
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 18;   -- Scott Weber
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 19;   -- Steve Harriage
UPDATE hs_subscribers SET areas = '["roaring-fork-valley"]'                       WHERE id = 22;   -- Trent Jones
-- ids 4, 20, 21, 23, 24 are addresses not on the roster: no areas until someone chooses them on subscribers.php.

-- Pocket listings and buyer reps on the sheet today (no city or board stored).
-- Set where the street makes the valley obvious; the rest wait for the Area
-- field on pipeline_review.php (Recently reviewed > Area).
UPDATE hs_manual_listings SET area_key = 'vail-valley' WHERE id = 2 AND address = '1182 Beard Creek Road';          -- Edwards (Cordillera)
UPDATE hs_manual_listings SET area_key = 'vail-valley' WHERE id = 3 AND address = '1000 Homestead Drive, Unit #13'; -- Edwards (Homestead)
-- ids 4 (TDR), 5 (1264 Crystal Bluffs Loop), 6 (70 W Eldorado Circle), 7 (263 Klitowya Trail): set on the review page.

-- After: SELECT id, email, frequency, areas FROM hs_subscribers ORDER BY id;
--        SELECT id, address, area_key FROM hs_manual_listings WHERE is_active = 1;
--        SHOW COLUMNS FROM hs_sends;
