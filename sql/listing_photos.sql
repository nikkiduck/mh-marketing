-- listing_photos.sql (2026-11-05, run once as master in TablePlus)
--
-- Listing photos: one photo an admin uploads for a listing the feed has no
-- photos for, so the website's cards, agent-page rows and listing hero show
-- it instead of the grey placeholder. The case that started it: CREN stops
-- delivering media on closed listings about a year after they close (sampled
-- in Anyprop 2026-11-05: June 2025 closings 0 of 6 with photos, February 2026
-- onward all), so Sierrah Smith's 2025 sales (CREN 825649, 825778) lost
-- theirs. Uploaded on the agent's profile (agent.php > Listing photos), filed
-- as agent-photos/listing-{market}-{mls}.jpg, sent by api/roster.php as a
-- top-level `listing_photos` list; the site downloads the file and uses it
-- ONLY when the feed sends no photos (the board's record always wins).
-- intake_id = whose profile it was uploaded from, for the card's list; the
-- photo belongs to the listing, so it shows on every agent credited with it.

CREATE TABLE IF NOT EXISTS listing_photos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    intake_id   INT UNSIGNED NOT NULL,
    market      VARCHAR(20)  NOT NULL COMMENT 'board slug from the site registry',
    mls_number  VARCHAR(32)  NOT NULL COMMENT 'ListingId as the site stores it',
    url         VARCHAR(255) NOT NULL COMMENT 'SITE_URL/agent-photos/listing-{market}-{mls}.jpg?v=',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_listing_photo (market, mls_number),
    KEY idx_lp_intake (intake_id),
    CONSTRAINT fk_lp_intake FOREIGN KEY (intake_id) REFERENCES marketing_intakes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
