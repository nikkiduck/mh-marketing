-- agent_fub_areas_seed_20261028.sql: default Service areas for the 15 approved
-- agents who had ticked nothing (Nikki, 2026-10-28: "most won't go in and do it
-- themselves"). The ticks (marketing_intakes.fub_areas, the website's Communities
-- town keys) are the one record of where an agent works: the website's community
-- pages list the agent under "Agents Who Know" from them, and lead routing reads
-- them for agents in the FUB rotation. Nobody in the rotation is touched: each
-- UPDATE applies only while the agent's ticks are still empty.
--
-- Where each default comes from: the agent's free-text Service area on the
-- profile. "Aspen and the Roaring Fork Valley" = every Roaring Fork Valley town;
-- "Colorado Springs" / "Denver Metro" = that one town (Front Range is one area
-- with Denver and Colorado Springs in it, so the whole area would be wrong);
-- a blank text = the towns of the agent's board (Lynn Emmert: Vail). Nikki
-- adjusts individuals on the agent's profile afterwards.
--
-- Run once as master in TablePlus. The website picks the ticks up on its next
-- hourly roster sync (:30), or at once with "Sync to Website".
-- STATUS: proposed 2026-10-28, not yet run.

-- Grant Langham: Greater Denver
UPDATE marketing_intakes SET fub_areas = '["greater-denver"]' WHERE slug = 'grant-langham' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Timothy Chladek: Greater Denver
UPDATE marketing_intakes SET fub_areas = '["greater-denver"]' WHERE slug = 'timothy-chladek' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Scott Weber: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'scott-weber' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Emma Casson: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'emma-k-casson' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Lynn Emmert: all of Vail Valley (board vail)
UPDATE marketing_intakes SET fub_areas = '["avon","beaver-creek","eagle","edwards","gypsum","minturn","vail"]' WHERE slug = 'lynn-emmert' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Allison Byford: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'allison-byford' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Trent Jones: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'trent-jones' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Sara Perkowski: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'sara-perkowski' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Bryan Peterson: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'bryan-peterson' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Laura Pietrzak: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'laura-pietrzak' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Corey Schaefer: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'corey-schaefer' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Britton Skusa: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'britton-skusa' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Minette Stapleton: all of Roaring Fork Valley, Aspen
UPDATE marketing_intakes SET fub_areas = '["aspen","basalt","carbondale","glenwood-springs","old-snowmass","snowmass-village","woody-creek"]' WHERE slug = 'minette-stapleton' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Megan Walz: Colorado Springs
UPDATE marketing_intakes SET fub_areas = '["colorado-springs"]' WHERE slug = 'megan-walz' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
-- Noah Walz: Colorado Springs
UPDATE marketing_intakes SET fub_areas = '["colorado-springs"]' WHERE slug = 'noah-walz' AND (fub_areas IS NULL OR fub_areas IN ('', '[]'));
