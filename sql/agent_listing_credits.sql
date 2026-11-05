-- agent_listing_credits.sql (2026-11-05, run once as master in TablePlus)
--
-- Listing credits: a sold listing the board credits to someone else, which an
-- agent should still show on their website page. The case that started it:
-- Whit Ward's two RE/MAX team sales (CREN 831368 and 834978, July 2026) carry
-- James Brunswick's MLS id on every side and nothing of Whit's, so no feed rule
-- can find them. One row per MLS number, entered by an admin on the agent's
-- profile (agent.php > Listing credits). The roster feed (api/roster.php)
-- sends them as `listing_credits`; the site stores them, pulls the listings
-- from Anyprop in its nightly history run and shows them on the agent's page.
--
-- side: which side of the deal the agent was on, for the "Represented Buyer"
-- label on the website (the record itself names someone else, so the site
-- cannot tell). mls_number is stored as the site stores it (REcolorado without
-- MLS Grid's REC prefix).

CREATE TABLE IF NOT EXISTS agent_listing_credits (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    intake_id   INT UNSIGNED NOT NULL,
    market      VARCHAR(20)  NOT NULL COMMENT 'board slug from the site registry: cren, aspen, ...',
    mls_number  VARCHAR(32)  NOT NULL COMMENT 'ListingId as the site stores it',
    side        ENUM('listing', 'buyer') NOT NULL DEFAULT 'listing',
    note        VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'why, e.g. "RE/MAX team deal with James Brunswick"',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credit (intake_id, market, mls_number),
    KEY idx_alc_intake (intake_id),
    CONSTRAINT fk_alc_intake FOREIGN KEY (intake_id) REFERENCES marketing_intakes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
