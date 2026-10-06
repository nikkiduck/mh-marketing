-- agent_fub_areas.sql (2026-10-06): the "Service areas" ticked in the Follow Up
-- Boss section of the agent profile, as a JSON list of the website's Communities
-- town keys ("greater-denver", "old-snowmass"; the list itself comes from the
-- site's api/boards.php, cached by inc/boards.php). The roster feed sends it to
-- the site, which gives a lead on another firm's listing to an agent who ticked
-- its town. Run once as master in TablePlus.
ALTER TABLE marketing_intakes
    ADD COLUMN fub_areas TEXT NULL COMMENT 'JSON list of website community keys: FUB lead routing on other firms listings' AFTER in_fub;
