-- ============================================================================
-- hot_sheets_v5_fell_through.sql — "Fell Through" on pocket listings and buyer reps
-- STATUS: not yet run
--
-- Run ONCE in TablePlus as the master user, after hot_sheets_v4_areas.sql.
--
-- Nikki, 2026-10-07: when a promoted deal (263 Klitowya Trail, buyer rep,
-- Pending) falls through in Paperless, the Hot Sheet should SAY so in Latest
-- Updates, not have the entry quietly vanish. The Zap now fires on every
-- Paperless status (it used to fire only on Pending and Closed), and the
-- parser applies a fell-through / expired / withdrawn / cancelled status to a
-- promoted entry by itself: status 'Fell Through' plus a status change row,
-- so the card shows FELL THROUGH in Latest Updates until the window passes
-- and never appears in the sections again. Until this has run the parser
-- cannot store that status and leaves the deal in the review queue instead.
-- ============================================================================

ALTER TABLE hs_manual_listings
    MODIFY COLUMN status ENUM('Active','Pending','Closed','Fell Through') NOT NULL DEFAULT 'Active'
        COMMENT 'Fell Through = Paperless reported fell through / expired / withdrawn / cancelled after promotion';

-- After: SHOW COLUMNS FROM hs_manual_listings LIKE 'status';
