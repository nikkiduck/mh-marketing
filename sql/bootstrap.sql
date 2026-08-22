-- STATUS: already run (2026-08-22) — 13 tables + data imported into dbmarketing_monthaus
-- ============================================================================
--  marketing.monthaus.com — database bootstrap
--
--  Generated from the hub export by build_bootstrap.py.
--  13 tables, carved out of the hub's 33. Collation normalised
--  to utf8mb4_0900_ai_ci throughout.
--
--  INSERTs are emitted in small batches, not one statement per table --
--  see INSERT_BATCH_ROWS in build_bootstrap.py for why that matters.
--
--  Run this ONCE against a fresh, empty database:
--      mysql -u root -p marketing_monthaus < bootstrap.sql
--
--  Then run, in this order:
--      sql/assets_tab_v2.sql     creates marketing_asset_links
--      sql/sso_schema.sql        adds the Entra columns to users
--
--  Requires MySQL 8.0 or newer. NOT MariaDB — utf8mb4_0900_ai_ci
--  does not exist there and every CREATE TABLE below would fail.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- Data is inserted before the foreign keys are added, so ordering within
-- each section does not matter.
SET FOREIGN_KEY_CHECKS = 0;


-- ── Schema ────────────────────────────────────────────────────────────

--
-- users
--
CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `role` enum('agent','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'agent',
  `mls_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `mls_short_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `agent_key` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `team_agent_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `vail_agent_key` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `team_vail_agent_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `last_seen_announcement_id` int UNSIGNED NOT NULL DEFAULT '0',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- password_resets
--
CREATE TABLE `password_resets` (
  `id` int UNSIGNED NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT '0',
  `type` enum('reset','invite') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'reset',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- office_roster
--
CREATE TABLE `office_roster` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `office` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `markets` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'e.g. Aspen, Vail',
  `title` varchar(150) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Agent title / designation',
  `mls_id_aspen` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Short Aspen MLS member ID (numeric)',
  `mls_id_vail` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Short Vail MLS member ID (numeric)',
  `agent_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `team_agent_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `vail_agent_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `team_vail_agent_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '0',
  `last_synced_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- mh_brokers
--
CREATE TABLE `mh_brokers` (
  `id` int NOT NULL,
  `roster_id` int UNSIGNED DEFAULT NULL COMMENT 'FK → office_roster.id',
  `agent_key` varchar(40) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `mobile_phone` varchar(30) DEFAULT NULL,
  `markets` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- listings
--
CREATE TABLE `listings` (
  `id` int NOT NULL,
  `listing_key` varchar(40) NOT NULL,
  `mls_number` varchar(40) NOT NULL DEFAULT '',
  `listing_type` enum('Sale','Rental') NOT NULL DEFAULT 'Sale',
  `market` enum('Vail','Aspen') NOT NULL,
  `address` varchar(255) NOT NULL DEFAULT '',
  `city` varchar(100) DEFAULT NULL,
  `state_abbr` varchar(10) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `lofty_id` varchar(64) DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `close_price` decimal(12,2) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT '',
  `listing_broker_name` varchar(150) DEFAULT NULL,
  `colist_broker_name` varchar(150) DEFAULT NULL,
  `primary_photo_url` varchar(500) DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- listing_brokers
--
CREATE TABLE `listing_brokers` (
  `id` int NOT NULL,
  `listing_id` int NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `roster_id` int UNSIGNED DEFAULT NULL COMMENT 'FK → office_roster.id',
  `sort_order` tinyint UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- marketing_intakes
--
CREATE TABLE `marketing_intakes` (
  `id` int UNSIGNED NOT NULL,
  `agent_name` varchar(150) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `agent_title` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `team_name` varchar(150) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `mls_id_aspen` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `mls_id_vail` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `intake_date` date NOT NULL,
  `cell_phone` varchar(30) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `mh_email` varchar(150) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `alt_email` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Agent alternate / personal email',
  `email_forwarded` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = MH email forwards to alt email',
  `website_url` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `social_instagram` varchar(150) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `social_facebook` varchar(150) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `social_linkedin` varchar(150) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `social_tiktok` varchar(150) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `social_other` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `has_listings` tinyint(1) NOT NULL DEFAULT '0',
  `listing_details` text COLLATE utf8mb4_0900_ai_ci,
  `check_email_setup` tinyint(1) NOT NULL DEFAULT '0',
  `check_email_signature` tinyint(1) NOT NULL DEFAULT '0',
  `email_sig_dropbox_url` varchar(500) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `email_sig_client` varchar(20) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `check_mls_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `check_b2b_system` tinyint(1) NOT NULL DEFAULT '0',
  `check_marketing_overview` tinyint(1) NOT NULL DEFAULT '0',
  `check_social_overview` tinyint(1) NOT NULL DEFAULT '0',
  `check_bio_received` tinyint(1) NOT NULL DEFAULT '0',
  `check_website_updated` tinyint(1) NOT NULL DEFAULT '0',
  `check_social_welcome_post` tinyint(1) NOT NULL DEFAULT '0',
  `social_welcome_date` date DEFAULT NULL,
  `check_b2b_announcement` tinyint(1) NOT NULL DEFAULT '0',
  `b2b_announcement_date` date DEFAULT NULL,
  `check_headshot_scheduled` tinyint(1) NOT NULL DEFAULT '0',
  `headshot_photoshoot_date` date DEFAULT NULL,
  `check_headshot_proofs` tinyint(1) NOT NULL DEFAULT '0',
  `headshot_proofs_url` varchar(500) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `check_headshot_final` tinyint(1) NOT NULL DEFAULT '0',
  `headshot_final_url` varchar(500) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `headshot_notes` text COLLATE utf8mb4_0900_ai_ci,
  `assets_cv_url` varchar(500) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `assets_other_notes` text COLLATE utf8mb4_0900_ai_ci,
  `bio_text` mediumtext COLLATE utf8mb4_0900_ai_ci,
  `bio_short` text COLLATE utf8mb4_0900_ai_ci COMMENT 'Short / social-media bio',
  `bio_url` varchar(500) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_business_cards` tinyint(1) NOT NULL DEFAULT '0',
  `coll_bc_qty` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_bc_front` varchar(20) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_bc_back` varchar(20) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_bc_chk_design` tinyint(1) NOT NULL DEFAULT '0',
  `coll_bc_chk_proof` tinyint(1) NOT NULL DEFAULT '0',
  `coll_bc_chk_accounting` tinyint(1) NOT NULL DEFAULT '0',
  `coll_bc_chk_ordered` tinyint(1) NOT NULL DEFAULT '0',
  `coll_bc_chk_delivered` tinyint(1) NOT NULL DEFAULT '0',
  `coll_postcards` tinyint(1) NOT NULL DEFAULT '0',
  `coll_pc_listing` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_pc_target_area` text COLLATE utf8mb4_0900_ai_ci,
  `coll_pc_design_needed` tinyint(1) DEFAULT NULL,
  `coll_pc_chk_design` tinyint(1) NOT NULL DEFAULT '0',
  `coll_pc_chk_proof` tinyint(1) NOT NULL DEFAULT '0',
  `coll_pc_chk_accounting` tinyint(1) NOT NULL DEFAULT '0',
  `coll_pc_chk_ordered` tinyint(1) NOT NULL DEFAULT '0',
  `coll_pc_chk_delivered` tinyint(1) NOT NULL DEFAULT '0',
  `coll_brochures` tinyint(1) NOT NULL DEFAULT '0',
  `coll_br_listing` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_br_chk_design` tinyint(1) NOT NULL DEFAULT '0',
  `coll_br_chk_proof` tinyint(1) NOT NULL DEFAULT '0',
  `coll_br_chk_created` tinyint(1) NOT NULL DEFAULT '0',
  `coll_yard_signs` tinyint(1) NOT NULL DEFAULT '0',
  `coll_ys_qty` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_ys_design` varchar(10) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_ys_info` text COLLATE utf8mb4_0900_ai_ci,
  `coll_oh_signs` tinyint(1) NOT NULL DEFAULT '0',
  `coll_oh_qty` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_oh_qr_code` tinyint(1) DEFAULT NULL,
  `coll_oh_qr_url` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coll_oh_info` text COLLATE utf8mb4_0900_ai_ci,
  `coll_other` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `digital_ads_interest` tinyint(1) NOT NULL DEFAULT '0',
  `digital_ads_vail_daily` tinyint(1) NOT NULL DEFAULT '0',
  `digital_ads_aspen_daily` tinyint(1) NOT NULL DEFAULT '0',
  `digital_ads_aspen_times` tinyint(1) NOT NULL DEFAULT '0',
  `digital_ads_spend` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `digital_ads_duration` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `digital_ads_use_mh` tinyint(1) DEFAULT NULL,
  `digital_ads_content_type` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `digital_ads_property_addr` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `other_marketing` text COLLATE utf8mb4_0900_ai_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `mh_broker_id` int DEFAULT NULL COMMENT 'FK → mh_brokers.id (optional)',
  `roster_id` int UNSIGNED DEFAULT NULL COMMENT 'FK → office_roster.id',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = active, 0 = archived',
  `status` varchar(20) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'active' COMMENT 'pending | active | archived',
  `archived_at` datetime DEFAULT NULL COMMENT 'Set when is_active flips to 0',
  `headshot_url` text COLLATE utf8mb4_0900_ai_ci COMMENT 'Displayable headshot image URL'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- marketing_campaigns
--
CREATE TABLE `marketing_campaigns` (
  `id` int UNSIGNED NOT NULL,
  `intake_id` int UNSIGNED NOT NULL,
  `platform` varchar(50) NOT NULL,
  `name` varchar(255) DEFAULT NULL COMMENT 'Optional campaign label',
  `budget` decimal(10,2) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'planned',
  `notes` text,
  `target_url` text COMMENT 'Constructed landing page URL',
  `utm_source` varchar(100) DEFAULT NULL,
  `utm_medium` varchar(100) DEFAULT NULL,
  `utm_campaign` varchar(100) DEFAULT NULL,
  `utm_content` varchar(100) DEFAULT NULL,
  `ad_file_url` text COMMENT 'Dropbox / Drive link to ad creative files',
  `sent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = media company notified',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_by` enum('broker','mont_haus','split') DEFAULT NULL,
  `paid_broker_amount` decimal(10,2) DEFAULT NULL,
  `paid_mh_amount` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- marketing_campaign_assets
--
CREATE TABLE `marketing_campaign_assets` (
  `id` int UNSIGNED NOT NULL,
  `campaign_id` int UNSIGNED NOT NULL,
  `label` varchar(255) DEFAULT NULL COMMENT 'e.g. "728x90 banner", "Facebook post"',
  `file_url` text NOT NULL,
  `target_url` varchar(500) DEFAULT NULL,
  `file_type` varchar(20) NOT NULL DEFAULT 'image',
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- marketing_tasks
--
CREATE TABLE `marketing_tasks` (
  `id` int UNSIGNED NOT NULL,
  `intake_id` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED DEFAULT NULL COMMENT 'Parent task ID — NULL = top-level task',
  `title` varchar(255) NOT NULL,
  `category` varchar(30) NOT NULL DEFAULT 'other',
  `priority` varchar(10) NOT NULL DEFAULT 'normal',
  `status` varchar(10) NOT NULL DEFAULT 'open',
  `due_date` date DEFAULT NULL,
  `notes` text,
  `file_url` text COMMENT 'Optional URL for headshot image, design file, Dropbox link, etc.',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- marketing_collateral_orders
--
CREATE TABLE `marketing_collateral_orders` (
  `id` int UNSIGNED NOT NULL,
  `intake_id` int UNSIGNED NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'business_cards | yard_signs | oh_signs | postcards | brochures | other',
  `label` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Optional label e.g. "Reorder #2"',
  `vendor` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `vendor_url` text COLLATE utf8mb4_0900_ai_ci COMMENT 'Link to vendor order page',
  `order_number` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `qty` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `billed_with_order_id` int UNSIGNED DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'pending' COMMENT 'pending | ordered | in_production | shipped | delivered',
  `ordered_at` date DEFAULT NULL,
  `tracking_number` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `tracking_url` text COLLATE utf8mb4_0900_ai_ci,
  `delivered_at` date DEFAULT NULL,
  `file_url` text COLLATE utf8mb4_0900_ai_ci COMMENT 'Dropbox/Drive link to final print-ready or delivered files',
  `receipt_file` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `receipt_orig_name` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `receipt_uploaded_at` datetime DEFAULT NULL,
  `paid_by` enum('broker','mont_haus','split') COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `paid_broker_amount` decimal(10,2) DEFAULT NULL,
  `paid_mh_amount` decimal(10,2) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_0900_ai_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- marketing_notes
--
CREATE TABLE `marketing_notes` (
  `id` int UNSIGNED NOT NULL,
  `intake_id` int UNSIGNED NOT NULL,
  `content` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL COMMENT 'users.id — who wrote the note'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- marketing_agent_mls_ids
--
CREATE TABLE `marketing_agent_mls_ids` (
  `id` int UNSIGNED NOT NULL,
  `intake_id` int UNSIGNED NOT NULL,
  `board` varchar(50) NOT NULL COMMENT 'e.g. aspen, vail, denver',
  `mls_id` varchar(50) DEFAULT NULL COMMENT 'Short MLS member/agent ID',
  `spark_key` varchar(26) DEFAULT NULL COMMENT '26-char Spark key (auto for aspen/vail)',
  `verified_at` datetime DEFAULT NULL COMMENT 'When auto-lookup last confirmed this key',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ── Data ──────────────────────────────────────────────────────────────

--
-- users
--
INSERT INTO `users` (`id`, `first_name`, `last_name`, `email`, `password`, `role`, `mls_id`, `mls_short_id`, `agent_key`, `team_agent_key`, `vail_agent_key`, `team_vail_agent_key`, `is_active`, `must_change_password`, `last_seen_announcement_id`, `last_login`, `created_at`) VALUES
(2, 'Nikki', 'Boxer', 'nikki.boxer@monthaus.com', '$2y$10$C6irdoQyjUDc33idIulC3uLC44/eQCXTgWkLjpqiWamMykbYrAovG', 'admin', 'AG-ADMIN-001', 'ADMIN1', NULL, NULL, NULL, NULL, 1, 0, 1, '2026-08-19 20:22:29', '2026-03-30 02:49:16'),
(7, 'Jonathan', 'Boxer', 'jonathan.boxer@monthaus.com', '$2y$10$F7oj8.u607AFwgUXOj/EL.q14lGa4bBcwDGDmrpR.7JluTNC0fI/W', 'agent', '1513', NULL, '20090111180226595967000000', '20260507191700890078000000', '20230525171222492207000000', NULL, 1, 0, 1, '2026-07-23 13:26:49', '2026-04-03 16:31:54'),
(9, 'Sara', 'Perkowski', 'sara.perkowski@monthaus.com', '$2y$10$FhCAhlSdtcnNW1Xhsl2RPejvbMRGgD0yBBlOXNxzGZUOOWaxrcPJy', 'agent', '', NULL, '20210625162700985460000000', '20260507191700890078000000', NULL, NULL, 1, 0, 0, '2026-06-27 16:10:24', '2026-05-15 23:54:03'),
(11, 'Bonnie', 'Scott', 'bonnie.scott@monthaus.com', '$2y$10$/rzPZEIhuhqPygkSjDQ0nubC0TL7PBStFW.EKioOd1Vagt6rSBxcW', 'admin', 'bonnie.scott@monthaus.com', NULL, NULL, NULL, NULL, NULL, 1, 0, 0, '2026-05-21 13:40:14', '2026-05-15 23:57:31'),
(12, 'Allison', 'Byford', 'allison.byford@monthaus.com', '$2y$10$OtfACPtEiTiDEzyGDcLcAO.y1/aEZRBBCqlhA48LIxbEiBlQQn0tu', 'agent', 'allison.byford@monthaus.com', NULL, '20090111180410882341000000', NULL, '', NULL, 1, 1, 0, NULL, '2026-05-26 22:35:23'),
(13, 'Allison', 'Decent', 'allison.decent@monthaus.com', '$2y$10$sJClkjIqncIzXS6t25HZvuGZPBPROl/IAZQii0PuD/wpVTSi0ADFm', 'agent', 'allison.decent@monthaus.com', NULL, '', NULL, '20200416143434709803000000', NULL, 1, 0, 0, '2026-07-03 15:09:27', '2026-05-26 22:37:03'),
(14, 'Britton', 'Skusa', 'britton.skusa@monthaus.com', '$2y$10$m6ulZ4NBk.k9Q4CoIll6suS4gElda27SMiORn5BKQ5KImt/zNOeAG', 'agent', 'britton.skusa@monthaus.com', NULL, '20230316203007066283000000', NULL, '', NULL, 1, 0, 0, '2026-05-28 02:07:29', '2026-05-26 22:38:50'),
(15, 'Bryan', 'Cournoyer', 'bryan.cournoyer@monthaus.com', '$2y$10$/1NTTZ7ZdH.h0CWJ1gzdLu0aGRYGWJZuv23lt19.B/LOCJCugggF2', 'agent', 'bryan.cournoyer@monthaus.com', NULL, '20151208170337935211000000', NULL, '20230530155610497775000000', NULL, 1, 0, 0, '2026-06-24 18:11:37', '2026-05-26 22:43:43'),
(16, 'Bryan', 'Peterson', 'bryan.peterson@monthaus.com', '$2y$10$C8rd3TYDZFcJ9DWgKUeUNuAeo3GWMWfN48mGVKg82E5z1jDRjJsom', 'agent', 'bryan.peterson@monthaus.com', NULL, '20090111180210438929000000', NULL, NULL, NULL, 1, 1, 0, NULL, '2026-05-26 22:44:09'),
(17, 'Corey', 'Schaefer', 'corey.schaefer@monthaus.com', '$2y$10$vsZ/vjJ.3sYjW6luDhfKBuED9VETEuG./3WPmrlV422.9FQZVPpjG', 'agent', 'corey.schaefer@monthaus.com', NULL, '20240624170127629763000000', NULL, NULL, NULL, 1, 1, 0, NULL, '2026-05-26 22:44:40'),
(18, 'Jay', 'Friedstein', 'jay.friedstein@monthaus.com', '$2y$10$gl3RXca/m6qGe4.RPuSS0erocHIP2uNUlY6aTaLfPNZS9b3Wk/pNm', 'agent', 'jay.friedstein@monthaus.com', NULL, '20210810222455629807000000', NULL, NULL, NULL, 1, 0, 0, '2026-05-27 21:08:57', '2026-05-26 22:45:12'),
(19, 'Jean-Michel', 'Drai', 'jm.drai@monthaus.com', '$2y$10$pRJL0MAiKDexCvVwHDMUiuyuOJsczmLgXn3jKcHSSTkGdGhQ.uzqC', 'agent', 'jm.drai@monthaus.com', NULL, '20260410153034681782000000', NULL, '20220505163915419348000000', NULL, 1, 1, 0, NULL, '2026-05-26 22:45:55'),
(20, 'Laura', 'Pietrzak', 'laura.pietrzak@monthaus.com', '$2y$10$Ehz9D.HAWtR4xO.FCo3pN.Eovj.glWy.DL6uaZWtFd4JE5t2zfDWK', 'agent', 'laura.pietrzak@monthaus.com', NULL, '20090111180418563420000000', NULL, NULL, NULL, 1, 1, 0, NULL, '2026-05-26 22:46:24'),
(21, 'Scott', 'Weber', 'scott.weber@monthaus.com', '$2y$10$NpuAbKKiwz6.7s8cXDT0U.IixcFjUpR54rgl.TjZ7uqWRDvc1c4gq', 'agent', 'scott.weber@monthaus.com', NULL, '20151013173815765491000000', '20260507191700890078000000', NULL, NULL, 1, 1, 0, NULL, '2026-05-26 22:46:49'),
(22, 'Timothy', 'Chladeck', 'timothy.chladeck@monthaus.com', '$2y$10$SB0etAa89EiHTOaT2rmlWuvio5kt.ZNGn91xw.HL4ovTm1rj8hatm', 'agent', 'timothy.chladeck@monthaus.com', NULL, NULL, NULL, NULL, NULL, 1, 1, 0, NULL, '2026-05-26 22:51:15'),
(23, 'Tommy', 'Tollesson', 'tommy.tollesson@monthaus.com', '$2y$10$mYGtr9i2jxVyI2GHocLS3ugqtluOgSyQSg5kyPxjmF/oR5cm5kM8G', 'agent', 'tommy.tollesson@monthaus.com', NULL, '20170315202929263386000000', NULL, NULL, NULL, 0, 1, 0, NULL, '2026-05-26 22:51:55'),
(24, 'Trent', 'Jones', 'trent.jones@monthaus.com', '$2y$10$320nilCZTU9VppJbNn4TLuhP46ub7J2DevVIP/GpvtG2wOSaYFzhS', 'agent', 'trent.jones@monthaus.com', NULL, '20201023192139426473000000', NULL, NULL, NULL, 1, 0, 0, '2026-06-22 18:22:10', '2026-05-26 22:52:11');

--
-- office_roster
--
INSERT INTO `office_roster` (`id`, `name`, `phone`, `email`, `office`, `markets`, `title`, `mls_id_aspen`, `mls_id_vail`, `agent_key`, `team_agent_key`, `vail_agent_key`, `team_vail_agent_key`, `active`, `last_synced_at`, `created_at`, `updated_at`) VALUES
(127, 'Allison Decent', '970-445-8144', 'allison.decent@monthaus.com', 'Mont Haus International Realty', 'Vail', NULL, NULL, NULL, NULL, NULL, '20200416143434709803000000', NULL, 1, '2026-08-21 03:00:01', '2026-05-20 01:09:42', '2026-08-21 03:00:02'),
(149, 'Allison Byford', '970-948-1525', 'allison.byford@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20090111180410882341000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(150, 'Bonnie Scott', '970-292-1800', 'bonnie.scott@monthaus.com', 'Mont Haus International Realty', 'Aspen', '', '', '', '20260522194949558735000000', NULL, '', NULL, 0, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(151, 'Britton Skusa', '970-390-2437', 'britton.skusa@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20230316203007066283000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(152, 'Bryan Cournoyer', '970-274-1497', 'bryan.cournoyer@monthaus.com', 'Mont Haus International Realty', 'Aspen, Vail', NULL, NULL, NULL, '20151208170337935211000000', NULL, '20230530155610497775000000', NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(153, 'Bryan Peterson', '970-948-0859', 'Bryan.Peterson@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20090111180210438929000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(154, 'Corey Schaefer', '970-544-5800', 'corey.schaefer@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20240624170127629763000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(155, 'Jay Friedstein', '303-898-4889', 'Jay.Friedstein@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20210810222455629807000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(156, 'Jean-Michel Drai', '970-292-1800', 'jm.drai@monthaus.com', 'Mont Haus International Realty', 'Aspen, Vail', NULL, NULL, NULL, '20260410153034681782000000', NULL, '20220505163915419348000000', NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(157, 'Jonathan Boxer', '970-948-4802', 'jonathan.boxer@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20090111180226595967000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(158, 'Laura Pietrzak', '970-948-5484', 'laura.pietrzak@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20090111180418563420000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(159, 'Megan Walz', '970-840-1514', 'tc@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20260506195858615095000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(160, 'Sara Perkowski', '262-510-6622', 'Sara.Perkowski@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20210625162700985460000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(161, 'Scott James Weber', '970-948-2766', 'Scott.Weber@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20151013173815765491000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(162, 'Tommy Tollesson', '970-948-1203', '', 'Mont Haus International Realty', 'Aspen', '', '', '', '20170315202929263386000000', NULL, '', NULL, 0, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(163, 'Trent Jones', '970-306-5855', 'Trent.Jones@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20201023192139426473000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(164, 'Weber Boxer Group | Jonathan Boxer Scott Weber', '970-948-4802', 'Jonathan.Boxer@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20260507191700890078000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-08 03:00:01', '2026-08-21 03:00:01'),
(170, 'Jackson Horn', '970-948-6130', 'jackson.horn@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20111121191609012769000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-14 03:00:02', '2026-08-21 03:00:01'),
(175, 'Kimberlee Coates', '970-948-5310', 'kim.coates@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20090111180215784869000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-18 03:00:02', '2026-08-21 03:00:01'),
(182, 'Minette Stapleton', '970-379-1850', 'minette.stapleton@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20090111180408600404000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-24 03:00:02', '2026-08-21 03:00:01'),
(184, 'Steve Harriage', '970-355-4646', 'steve.harriage@monthaus.com', 'Mont Haus International Realty', 'Aspen', NULL, NULL, NULL, '20130712145759765974000000', NULL, NULL, NULL, 1, '2026-08-21 03:00:01', '2026-07-25 03:00:02', '2026-08-21 03:00:01'),
(196, 'Olivia Roemer', '970-840-1514', 'olivia@avetransactions.com', 'Mont Haus International Realty', 'Vail', NULL, NULL, NULL, NULL, NULL, '20260514192140308637000000', NULL, 1, '2026-08-21 03:00:01', '2026-08-04 16:40:31', '2026-08-21 03:00:02'),
(200, 'Lynn Emmert', '970-390-2084', 'lynn.emmert@monthaus.com', 'Mont Haus International Realty', 'Vail', NULL, NULL, NULL, NULL, NULL, '20200416143516552273000000', NULL, 1, '2026-08-21 03:00:01', '2026-08-08 03:00:02', '2026-08-21 03:00:02'),
(202, 'Emma K Casson', '970-948-4155', 'emma@emmainaspen.com', 'Mont Haus International Realty', 'Aspen', 'Broker Associate', '', '', '20090111180204477889000000', NULL, '', NULL, 1, '2026-08-21 03:00:01', '2026-08-09 20:32:10', '2026-08-21 03:00:01');

--
-- mh_brokers
--
INSERT INTO `mh_brokers` (`id`, `roster_id`, `agent_key`, `full_name`, `email`, `mobile_phone`, `markets`, `is_active`, `last_synced_at`, `created_at`) VALUES
(37, 149, NULL, 'Allison Byford', 'allison.byford@monthaus.com', '970-948-1525', 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(38, 127, NULL, 'Allison Decent', 'allison.decent@monthaus.com', NULL, 'Vail', 1, NULL, '2026-07-08 03:15:02'),
(39, 150, NULL, 'Bonnie Scott', 'bonnie.scott@monthaus.com', NULL, 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(40, 151, NULL, 'Britton Skusa', 'britton.skusa@monthaus.com', '970-390-2437', 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(41, 152, NULL, 'Bryan Cournoyer', 'bryan.cournoyer@monthaus.com', '970-274-1497', 'Aspen, Vail', 1, NULL, '2026-07-08 03:15:02'),
(42, 153, NULL, 'Bryan Peterson', 'Bryan.Peterson@monthaus.com', '970-948-0859', 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(43, 154, NULL, 'Corey Schaefer', 'corey.schaefer@monthaus.com', NULL, 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(44, 155, NULL, 'Jay Friedstein', 'Jay.Friedstein@monthaus.com', NULL, 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(45, 156, NULL, 'Jean-Michel Drai', 'jm.drai@monthaus.com', NULL, 'Aspen, Vail', 1, NULL, '2026-07-08 03:15:02'),
(46, 157, NULL, 'Jonathan Boxer', 'jonathan.boxer@monthaus.com', '970-948-4802', 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(47, 158, NULL, 'Laura Pietrzak', 'laura.pietrzak@monthaus.com', '970-948-5484', 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(48, 159, NULL, 'Megan Walz', 'tc@monthaus.com', NULL, 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(49, 196, NULL, 'Olivia Roemer', 'olivia@avetransactions.com', '970-840-1514', 'Vail', 1, NULL, '2026-07-08 03:15:02'),
(50, 160, NULL, 'Sara Perkowski', 'Sara.Perkowski@monthaus.com', NULL, 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(51, 161, NULL, 'Scott James Weber', 'Scott.Weber@monthaus.com', '970-948-2766', 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(52, NULL, NULL, 'Tommy Tollesson', NULL, '970-948-1203', 'Aspen', 0, NULL, '2026-07-08 03:15:02'),
(53, 163, NULL, 'Trent Jones', 'Trent.Jones@monthaus.com', '970-306-5855', 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(54, 157, NULL, 'Weber Boxer Group | Jonathan Boxer Scott Weber', 'Jonathan.Boxer@monthaus.com', NULL, 'Aspen', 1, NULL, '2026-07-08 03:15:02'),
(60, 170, NULL, 'Jackson Horn', 'jackson.horn@monthaus.com', NULL, 'Aspen', 1, NULL, '2026-07-14 03:15:01'),
(65, 175, NULL, 'Kimberlee Coates', 'kim.coates@monthaus.com', '970-948-5310', 'Aspen', 1, NULL, '2026-07-18 03:15:02'),
(72, 182, NULL, 'Minette Stapleton', 'minette.stapleton@monthaus.com', '970-379-1850', 'Aspen', 1, NULL, '2026-07-24 03:15:02'),
(74, 184, NULL, 'Steve Harriage', 'steve.harriage@monthaus.com', '970-355-4646', 'Aspen', 1, NULL, '2026-07-25 03:15:02'),
(89, 200, NULL, 'Lynn Emmert', 'lynn.emmert@monthaus.com', '970-390-2084', 'Vail', 1, NULL, '2026-08-08 03:15:01'),
(100, 202, NULL, 'Emma K Casson', 'emma@emmainaspen.com', '970-948-4155', 'Aspen', 1, NULL, '2026-08-18 03:15:02');

--
-- listings
--
INSERT INTO `listings` (`id`, `listing_key`, `mls_number`, `listing_type`, `market`, `address`, `city`, `state_abbr`, `postal_code`, `lofty_id`, `price`, `close_price`, `status`, `listing_broker_name`, `colist_broker_name`, `primary_photo_url`, `last_synced_at`, `created_at`, `updated_at`) VALUES
(1, '20260518162147397160000000', '193399', 'Sale', 'Aspen', '970 Powder Lane', 'Aspen', 'CO', '81611', '1184442251', 62000000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260628140605131850000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:02', '2026-08-21 15:50:02'),
(2, '20260513213439463809000000', '192836', 'Sale', 'Aspen', '294 Draw Drive', 'Aspen', 'CO', '81611', '1182288364', 39500000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513213442779343000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:02', '2026-08-21 15:50:02'),
(3, '20260513214247806249000000', '192835', 'Sale', 'Aspen', '82 Northway Drive', 'Aspen', 'CO', '81611', '1182288347', 35000000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513214248635548000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:03', '2026-08-21 15:50:02'),
(4, '20260513214822239594000000', '193534', 'Sale', 'Aspen', '96 Mcskimming Road', 'Aspen', 'CO', '81611', '1184892718', 32000000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260627150620307038000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:03', '2026-08-21 15:50:02'),
(5, '20260513185848076172000000', '192864', 'Sale', 'Aspen', '40 Mountain Laurel Lane', 'Aspen', 'CO', '81611', '1182404601', 10900000.00, 9875000.00, 'Closed', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513193058936667000000-o.jpg', '2026-07-17 03:56:43', '2026-07-06 14:53:03', '2026-07-17 03:56:44'),
(6, '20260511175700727622000000', '192793', 'Sale', 'Aspen', '36 Pinnacle Point', 'Edwards', 'CO', '81632', '1182115079', 5895000.00, 5500000.00, 'Closed', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260511181936543323000000-o.jpg', '2026-07-17 03:56:43', '2026-07-06 14:53:03', '2026-07-17 03:56:44'),
(7, '20260602232741172090000000', '193273', 'Sale', 'Aspen', '400 Wood Road E-2209', 'Snowmass Village', 'CO', '81615', '1184011838', 3950000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260603003057042310000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:03', '2026-08-21 15:50:02'),
(8, '20260513214704465156000000', '193366', 'Sale', 'Aspen', '189 Light Hill Road', 'Snowmass', 'CO', '81654', '1184339800', 3495000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513214705340685000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:03', '2026-08-21 15:50:02'),
(9, '20260518165436716062000000', '192876', 'Sale', 'Aspen', '360 Wood Road Unit 103', 'Snowmass Village', 'CO', '81615', '1182460290', 2500000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260518165438020709000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:04', '2026-08-21 15:50:02'),
(10, '20260513213332482949000000', '192838', 'Rental', 'Aspen', '294 Draw Drive', 'Aspen', 'CO', '81611', '1182293837', 200000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513213335472043000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:04', '2026-08-21 15:50:02'),
(11, '20260513215000647115000000', '192900', 'Rental', 'Aspen', '75 Mclain Court', 'Aspen', 'CO', '81611', '1182526004', 195000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260519172515995014000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:04', '2026-08-21 15:50:02'),
(12, '20260513213241940733000000', '192829', 'Rental', 'Aspen', '1190 Riverside Drive', 'Aspen', 'CO', '81611', '1182253700', 100000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513213242574008000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:04', '2026-08-21 15:50:02'),
(13, '20260513214353472433000000', '192830', 'Rental', 'Aspen', '706 E Hyman Avenue', 'Aspen', 'CO', '81611', '1182255449', 85000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513214354143529000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:05', '2026-08-21 15:50:02'),
(14, '20260513213949103286000000', '192834', 'Rental', 'Aspen', '800 S Monarch Street Unit 9', 'Aspen', 'CO', '81611', '1182278234', 45000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513213949981499000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:05', '2026-08-21 15:50:02'),
(15, '20260514163940711604000000', '192998', 'Rental', 'Aspen', '731 S Mill Street Unit 2d', 'Aspen', 'CO', '81611', '1182909980', 40000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260514163941767747000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:05', '2026-08-21 15:50:02'),
(16, '20260513213107675946000000', '192840', 'Rental', 'Aspen', '625 S West End Street Unit 12', 'Aspen', 'CO', '81611', '1182294989', 25000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513213108645344000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:05', '2026-08-21 15:50:02'),
(17, '20260513214054558077000000', '192825', 'Rental', 'Aspen', '926 Waters Avenue 203', 'Aspen', 'CO', '81611', '1182219871', 22500.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513224420970039000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:05', '2026-08-21 15:50:02'),
(18, '20260611201701264482000000', '193260', 'Rental', 'Aspen', '100 E Dean Street Unit 2b', 'Aspen', 'CO', '81611', '1183921605', 20000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260611211607746867000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:05', '2026-08-21 15:50:02'),
(19, '20260513214045850830000000', '192827', 'Rental', 'Aspen', '1039 E Cooper Avenue 19b', 'Aspen', 'CO', '81611', '1182252116', 15000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260513214046574413000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:05', '2026-08-21 15:50:02'),
(20, '20241105211230626552000000', '186050', 'Rental', 'Aspen', '311 S Aspen Street 2', 'Aspen', 'CO', '81611', '1153685338', 6500.00, NULL, 'Withdrawn', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20241106232352450187000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:05', '2026-08-21 15:50:03'),
(21, '20260514181440765876000000', '1014132', 'Sale', 'Vail', '40 Mountain Laurel Lane', 'Aspen', 'CO', '81611', '1182405177', 10900000.00, 9875000.00, 'Closed', NULL, NULL, 'https://cdn.resize.sparkplatform.com/vbr/640x480/true/20260514184153280433000000-o.jpg', '2026-07-17 03:56:43', '2026-07-06 14:53:17', '2026-07-17 03:56:58'),
(22, '20260617214920108836000000', '1014591', 'Sale', 'Vail', '1100 N Frontage Road W # 1513', 'Vail', 'CO', '81657', '1185155299', 1495000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/vbr/640x480/true/20260803020953276692000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:17', '2026-08-21 15:50:22'),
(23, '20260427191832935297000000', '1014100', 'Sale', 'Vail', '1061 W Beaver Creek Boulevard H304', 'Avon', 'CO', '81620', '1182191961', 645000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/vbr/640x480/true/20260505162526652544000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:17', '2026-08-21 15:50:22'),
(24, '20260512185326993494000000', '1014092', 'Sale', 'Vail', '13 Sawmill Circle', 'Eagle', 'CO', '81631', '1182135454', 375000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/vbr/640x480/true/20260512200158033395000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:17', '2026-08-21 15:50:22'),
(25, '20260509211104798678000000', '1014076', 'Sale', 'Vail', '595 Vail Valley PU-4', 'Vail', 'CO', '81657', '1181996439', 350000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/vbr/640x480/true/20260509211216479304000000-o.jpg', '2026-08-21 15:50:01', '2026-07-06 14:53:18', '2026-08-21 15:50:22');
INSERT INTO `listings` (`id`, `listing_key`, `mls_number`, `listing_type`, `market`, `address`, `city`, `state_abbr`, `postal_code`, `lofty_id`, `price`, `close_price`, `status`, `listing_broker_name`, `colist_broker_name`, `primary_photo_url`, `last_synced_at`, `created_at`, `updated_at`) VALUES
(26, '20260514194518303946000000', '1014133', 'Rental', 'Vail', '800 S Monarch Street 9', 'Aspen', 'CO', '81611', '1182405565', 45000.00, NULL, 'Withdrawn', NULL, NULL, 'https://cdn.resize.sparkplatform.com/vbr/640x480/true/20260514202055690031000000-o.jpg', '2026-08-15 20:00:01', '2026-07-06 14:53:18', '2026-08-15 20:00:13'),
(30, '20260714184017521454000000', '193802', 'Sale', 'Aspen', '450 Solar Way', 'Aspen', 'CO', '81611', '1185862939', 5595000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260715153351758802000000-o.jpg', '2026-08-21 15:50:01', '2026-07-16 23:00:52', '2026-08-21 15:50:02'),
(34, '20260715163952171897000000', '193806', 'Sale', 'Aspen', '30 Smith Hill Way', 'Woody Creek', 'CO', '81656', '1185871342', 6700000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260715163952939421000000-o.jpg', '2026-08-21 15:50:01', '2026-07-17 15:50:03', '2026-08-21 15:50:02'),
(35, '20260717181213252992000000', '1014785', 'Sale', 'Vail', '134 N Brett Trail', 'Edwards', 'CO', '81632', '1186504134', 1800000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/vbr/640x480/true/20260724171912755534000000-o.jpg', '2026-08-21 15:50:01', '2026-07-25 01:02:46', '2026-08-21 15:50:22'),
(36, '20260729201316919447000000', '194008', 'Sale', 'Aspen', '221 Wrights Road', 'Aspen', 'CO', '81611', '1186838329', 65000000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260806134729115022000000-o.jpg', '2026-08-21 15:50:01', '2026-07-31 20:00:03', '2026-08-21 15:50:02'),
(37, '20260807183727696559000000', '1014903', 'Sale', 'Vail', '2995 Basingdale Boulevard A', 'Vail', 'CO', '81657', '1187262110', 6300000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/vbr/640x480/true/20260808194536865172000000-o.jpg', '2026-08-21 15:50:01', '2026-08-08 00:00:12', '2026-08-21 15:50:22'),
(38, '20260807210857293322000000', '1014904', 'Sale', 'Vail', '2995 Basingdale Boulevard B', 'Vail', 'CO', '81657', '1187265960', 4050000.00, NULL, 'Active', NULL, NULL, 'https://cdn.resize.sparkplatform.com/vbr/640x480/true/20260810184437966122000000-o.jpg', '2026-08-21 15:50:01', '2026-08-08 00:00:12', '2026-08-21 15:50:22'),
(39, '20260820013303725421000000', '194266', 'Sale', 'Aspen', '405 Meadow Court', 'Basalt', 'CO', '81621', '1187960091', 2725000.00, NULL, 'Pending', NULL, NULL, 'https://cdn.resize.sparkplatform.com/ags/640x480/true/20260820013304687893000000-o.jpg', '2026-08-21 15:50:01', '2026-08-21 15:50:02', '2026-08-21 15:50:02');

--
-- listing_brokers
--
INSERT INTO `listing_brokers` (`id`, `listing_id`, `full_name`, `roster_id`, `sort_order`) VALUES
(346, 21, 'Bryan Cournoyer', 152, 0),
(357, 5, 'Bryan Cournoyer', 152, 0),
(2219, 27, 'Jackson Horn', 170, 0),
(2334, 6, 'Bryan Cournoyer', 152, 0),
(2446, 28, 'Jackson Horn', 170, 0),
(2594, 29, 'Jackson Horn', 170, 0),
(2738, 31, 'Jackson Horn', 170, 0),
(2776, 32, 'Jackson Horn', 170, 0),
(3070, 33, 'Jackson Horn', 170, 0),
(10599, 26, 'Bryan Cournoyer', NULL, 0),
(12462, 20, 'Emma K Casson', NULL, 0),
(12463, 20, 'Britton Skusa', NULL, 1),
(12471, 36, 'Jonathan Boxer', NULL, 0),
(12472, 36, 'Scott Weber', NULL, 1),
(12473, 1, 'A. Scott Davidson', NULL, 0),
(12474, 1, 'Jonathan Boxer', NULL, 1),
(12475, 1, 'Scott Weber', NULL, 2),
(12476, 2, 'Jonathan Boxer', NULL, 0),
(12477, 2, 'Scott Weber', NULL, 1),
(12478, 3, 'Jonathan Boxer', NULL, 0),
(12479, 3, 'Scott Weber', NULL, 1),
(12480, 4, 'Jonathan Boxer', NULL, 0),
(12481, 4, 'Scott Weber', NULL, 1),
(12482, 34, 'Jackson Horn', NULL, 0),
(12483, 30, 'Jackson Horn', NULL, 0);
INSERT INTO `listing_brokers` (`id`, `listing_id`, `full_name`, `roster_id`, `sort_order`) VALUES
(12484, 7, 'Trent Jones', NULL, 0),
(12485, 8, 'Jonathan Boxer', NULL, 0),
(12486, 8, 'Scott Weber', NULL, 1),
(12487, 8, 'Heather Sinclair', NULL, 2),
(12488, 39, 'Emma K Casson', NULL, 0),
(12489, 9, 'Jonathan Boxer', NULL, 0),
(12490, 9, 'Scott Weber', NULL, 1),
(12491, 9, 'Bineau Team', NULL, 2),
(12492, 10, 'Sara Perkowski', NULL, 0),
(12493, 10, 'Jonathan Boxer', NULL, 1),
(12494, 11, 'Sara Perkowski', NULL, 0),
(12495, 11, 'Jonathan Boxer', NULL, 1),
(12496, 12, 'Sara Perkowski', NULL, 0),
(12497, 12, 'Jonathan Boxer', NULL, 1),
(12498, 13, 'Sara Perkowski', NULL, 0),
(12499, 13, 'Jonathan Boxer', NULL, 1),
(12500, 14, 'Sara Perkowski', NULL, 0),
(12501, 14, 'Bryan Cournoyer', NULL, 1),
(12502, 15, 'Britton Skusa', NULL, 0),
(12503, 16, 'Sara Perkowski', NULL, 0),
(12504, 16, 'Jonathan Boxer', NULL, 1),
(12505, 17, 'Sara Perkowski', NULL, 0),
(12506, 17, 'Jonathan Boxer', NULL, 1),
(12507, 18, 'Sara Perkowski', NULL, 0),
(12508, 18, 'Jonathan Boxer', NULL, 1);
INSERT INTO `listing_brokers` (`id`, `listing_id`, `full_name`, `roster_id`, `sort_order`) VALUES
(12509, 19, 'Sara Perkowski', NULL, 0),
(12510, 19, 'Jonathan Boxer', NULL, 1),
(12511, 37, 'Jean-Michel Drai', NULL, 0),
(12512, 38, 'Jean-Michel Drai', NULL, 0),
(12513, 35, 'Allison Decent', NULL, 0),
(12514, 22, 'Bryan Cournoyer', NULL, 0),
(12515, 23, 'Allison Decent', NULL, 0),
(12516, 24, 'Allison Decent', NULL, 0),
(12517, 25, 'Jean-Michel Drai', NULL, 0);

--
-- marketing_intakes
--
INSERT INTO `marketing_intakes` (`id`, `agent_name`, `agent_title`, `team_name`, `mls_id_aspen`, `mls_id_vail`, `start_date`, `intake_date`, `cell_phone`, `mh_email`, `alt_email`, `email_forwarded`, `website_url`, `social_instagram`, `social_facebook`, `social_linkedin`, `social_tiktok`, `social_other`, `has_listings`, `listing_details`, `check_email_setup`, `check_email_signature`, `email_sig_dropbox_url`, `email_sig_client`, `check_mls_confirmed`, `check_b2b_system`, `check_marketing_overview`, `check_social_overview`, `check_bio_received`, `check_website_updated`, `check_social_welcome_post`, `social_welcome_date`, `check_b2b_announcement`, `b2b_announcement_date`, `check_headshot_scheduled`, `headshot_photoshoot_date`, `check_headshot_proofs`, `headshot_proofs_url`, `check_headshot_final`, `headshot_final_url`, `headshot_notes`, `assets_cv_url`, `assets_other_notes`, `bio_text`, `bio_short`, `bio_url`, `coll_business_cards`, `coll_bc_qty`, `coll_bc_front`, `coll_bc_back`, `coll_bc_chk_design`, `coll_bc_chk_proof`, `coll_bc_chk_accounting`, `coll_bc_chk_ordered`, `coll_bc_chk_delivered`, `coll_postcards`, `coll_pc_listing`, `coll_pc_target_area`, `coll_pc_design_needed`, `coll_pc_chk_design`, `coll_pc_chk_proof`, `coll_pc_chk_accounting`, `coll_pc_chk_ordered`, `coll_pc_chk_delivered`, `coll_brochures`, `coll_br_listing`, `coll_br_chk_design`, `coll_br_chk_proof`, `coll_br_chk_created`, `coll_yard_signs`, `coll_ys_qty`, `coll_ys_design`, `coll_ys_info`, `coll_oh_signs`, `coll_oh_qty`, `coll_oh_qr_code`, `coll_oh_qr_url`, `coll_oh_info`, `coll_other`, `digital_ads_interest`, `digital_ads_vail_daily`, `digital_ads_aspen_daily`, `digital_ads_aspen_times`, `digital_ads_spend`, `digital_ads_duration`, `digital_ads_use_mh`, `digital_ads_content_type`, `digital_ads_property_addr`, `other_marketing`, `created_by`, `created_at`, `updated_at`, `mh_broker_id`, `roster_id`, `is_active`, `status`, `archived_at`, `headshot_url`) VALUES
(2, 'Steve Harriage', 'Broker Associate + Managing Director, New Development Sales', NULL, NULL, NULL, NULL, '2026-07-23', '(970) 355-4646‬', 'steve.harriage@monthaus.com', NULL, 0, 'https://steveharriage.com', '@steveharriage', 'https://www.facebook.com/sharriage', 'https://www.linkedin.com/in/steve-harriage-ab8a8a11b/', NULL, NULL, 0, NULL, 1, 1, 'https://www.dropbox.com/scl/fo/l2hbd9lj8ux547r48624e/AKZ9EcMvctQyoCGeRl6EtaM?rlkey=es2dwtrbdk30pr0e1qladsiff&st=1xyjwpyw&dl=0', 'apple_mail', 1, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, '<p><br></p>', NULL, NULL, 1, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 'Direct mail letter (SBV); SH will send', 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, 'NMB to send logos to SH for personal website\r\nSH to update MLS and Website brokerage data\r\nScanned actual signature\r\nSend Bonnie updated back biz card\r\nCheck photos from SH', 2, '2026-07-23 19:25:32', '2026-08-04 14:21:07', NULL, 184, 1, 'active', NULL, NULL),
(3, 'Minette Stapleton', 'Broker Associate', NULL, '20090111180408600404000000', NULL, NULL, '2026-07-25', '970.379.1850', 'minette.stapleton@monthaus.com', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 1, 1, 'https://www.dropbox.com/scl/fo/dxehy9q92k1addederc59/AA_dCFkTKTZuIYhUtEDYXG0?rlkey=3udyssh24gqg4vcyfh94rs9mf&st=dliv9fxb&dl=0', 'both', 1, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, '<p>Minette first visited Aspen in the summer of 1989 and, like many, never left. Her passion for the outdoors and the Aspen lifestyle has given her deep roots both personally and professionally in the Colorado Rockies.</p><p>For Minette, real estate has always been a family affair. Her father was director of the School of Architecture at the University of Arizona and her mother was an engineer working in construction management. Minette literally grew up on job sites, fostering an appreciation for development, architecture, and design. Today, real estate is a full-time passion.</p><p><br></p><p>Minette brings more than twenty years of luxury retail sales experience and a loyal clientele to her real estate business. With an extraordinary attention to detail, commitment to service, and the highest level of integrity, Minette has built long-lasting business friendships critical to success in this competitive marketplace.</p><p><br></p><p>Since moving to Aspen, she has been involved in several redevelopment projects and has a flair for spotting opportunity. Green-minded, Minette draws from her early studies in energy engineering and alternative resources at the University of Arizona and applies that sensibility when advising her clients. A deep believer in philanthropy and the importance of \"giving back,\" she also served on the LIFT-UP board for over 20 years.</p><p><br></p><p>Minette is married to local sporting hero David Stapleton, a former U.S. Ski Team World Cup Racer whose pioneering ranching family homesteaded in Aspen in 1881.</p>', NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-07-25 15:30:30', '2026-08-21 13:13:33', NULL, 182, 1, 'active', NULL, NULL),
(4, 'Allison Decent', '', NULL, NULL, NULL, NULL, '2026-08-04', '970-445-8144', 'allison.decent@monthaus.com', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-04 16:48:59', '2026-08-04 16:48:59', NULL, 127, 1, 'active', NULL, NULL),
(5, 'Bryan Cournoyer', '', NULL, NULL, NULL, NULL, '2026-08-04', '970-274-1497', 'bryan.cournoyer@monthaus.com', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-04 16:51:02', '2026-08-04 16:51:02', NULL, 152, 1, 'active', NULL, NULL),
(6, 'Jackson Horn', '', NULL, NULL, NULL, NULL, '2026-08-04', '970-948-6130', 'jackson.horn@monthaus.com', NULL, 0, NULL, 'jacksonhornaspen', NULL, NULL, NULL, NULL, 0, NULL, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-04 16:51:12', '2026-08-07 20:56:20', NULL, 170, 1, 'active', NULL, NULL),
(7, 'Kimberlee Coates', '', NULL, NULL, NULL, NULL, '2026-08-04', '970-948-5310', 'kim.coates@monthaus.com', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-04 16:51:19', '2026-08-04 16:51:19', NULL, 175, 1, 'active', NULL, NULL),
(8, 'Weber Boxer Group | Jonathan Boxer Scott Weber', '', NULL, NULL, NULL, NULL, '2026-08-04', '970-948-4802', 'Jonathan.Boxer@monthaus.com', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-04 16:51:31', '2026-08-04 16:51:31', NULL, 164, 1, 'active', NULL, NULL),
(9, 'Trent Jones', '', NULL, NULL, NULL, NULL, '2026-08-04', '970-306-5855', 'Trent.Jones@monthaus.com', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-04 16:51:44', '2026-08-04 16:51:44', NULL, 163, 1, 'active', NULL, NULL),
(10, 'Britton Skusa', '', NULL, NULL, NULL, NULL, '2026-08-04', '970-390-2437', 'britton.skusa@monthaus.com', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-04 16:52:05', '2026-08-04 16:52:05', NULL, 151, 1, 'active', NULL, NULL),
(11, 'Jay Friedstein', '', NULL, NULL, NULL, NULL, '2026-08-04', '303-898-4889', 'Jay.Friedstein@monthaus.com', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-04 16:52:15', '2026-08-04 16:52:15', NULL, 155, 1, 'active', NULL, NULL),
(12, 'Lynn Emert', 'Broker Associate', NULL, NULL, NULL, NULL, '2026-08-07', '970-390-2084', NULL, NULL, 0, NULL, 'lynnemmert14', NULL, NULL, NULL, NULL, 0, NULL, 1, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, '<p><br></p>', NULL, NULL, 1, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, 'Send Outlook email signature\r\n\r\nlynnvail@vail.net', 2, '2026-08-07 21:29:39', '2026-08-09 20:20:02', NULL, 200, 1, 'active', NULL, NULL),
(13, 'Lynn Emert', 'Broker Associate', NULL, NULL, NULL, NULL, '2026-08-07', '970-390-2084', NULL, NULL, 0, NULL, 'lynnemmert14', NULL, NULL, NULL, NULL, 0, NULL, 1, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, '<p><br></p>', NULL, NULL, 1, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, 'Send Outlook email signature\r\n\r\nlynnvail@vail.net', 2, '2026-08-07 21:30:29', '2026-08-09 20:19:56', NULL, 200, 1, 'active', NULL, NULL),
(14, 'Emma Casson', 'Broker Associate', NULL, NULL, NULL, NULL, '2026-08-09', '970-948-4155', 'emma.casson@monthaus.com', NULL, 0, NULL, 'emmainaspen', 'emma.casson.14', NULL, NULL, NULL, 1, NULL, 1, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, '<p><br></p>', NULL, NULL, 1, '200', '10', '1', 1, 1, 1, 1, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 1, '5', '1', NULL, 1, '5', 1, 'https://www.dropbox.com/scl/fi/9i7db7s9goe0u79ivvj5v/Emma-listings_QR.svg?rlkey=c3sxqrh5qi2vrnlieb843z34l&st=nff0og27&dl=0', NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-09 19:59:36', '2026-08-14 03:00:02', NULL, 202, 0, 'archived', '2026-08-09 20:31:43', NULL),
(15, 'Emma Casson', 'Broker Associate', NULL, NULL, NULL, '2026-08-10', '2026-08-09', '970-948-4155', 'emma.casson@monthaus.com', 'emma@emmainaspen.com', 1, NULL, '@emmainaspen', '@emma.casson.14', NULL, NULL, NULL, 1, NULL, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, '<p><br></p>', NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-09 20:00:26', '2026-08-20 23:46:40', NULL, 202, 1, 'active', NULL, NULL),
(16, 'Jean-Michel Drai', 'President + COO | Managing Broker - Vail', NULL, NULL, NULL, '2026-05-01', '2026-08-10', '970-292-1800', 'jm.drai@monthaus.com', NULL, 0, NULL, '@jmdrai', NULL, NULL, NULL, NULL, 1, NULL, 1, 1, NULL, NULL, 1, 1, 1, 1, 0, 1, 1, NULL, 1, NULL, 0, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, '<p><br></p>', NULL, NULL, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, NULL, NULL, 0, 0, 0, 0, 0, 0, NULL, 0, 0, 0, 0, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, 2, '2026-08-10 22:37:20', '2026-08-20 22:44:48', NULL, 156, 1, 'active', NULL, NULL);

--
-- marketing_campaigns
--
INSERT INTO `marketing_campaigns` (`id`, `intake_id`, `platform`, `name`, `budget`, `start_date`, `end_date`, `status`, `notes`, `target_url`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `ad_file_url`, `sent`, `created_at`, `paid_by`, `paid_broker_amount`, `paid_mh_amount`) VALUES
(1, 6, 'aspen_daily', 'Sticky Anchor / Sneaker', NULL, '2026-07-20', NULL, 'active', NULL, 'https://monthaus.com/listing-detail/1185871342?utm_source=adn&utm_medium=web&utm_campaign=footer&utm_content=30SmithHill', 'adn', 'web', 'footer', '30SmithHill', NULL, 1, '2026-08-07 21:01:04', NULL, NULL, NULL),
(2, 6, 'aspen_daily', 'Sticky Anchor / Sneaker', NULL, '2026-07-20', NULL, 'active', NULL, 'https://monthaus.com/listing-detail/1185862939?utm_source=adn&utm_medium=web&utm_campaign=footer&utm_content=450SolarWay', 'adn', 'web', 'footer', '450SolarWay', NULL, 1, '2026-08-07 21:02:09', NULL, NULL, NULL),
(3, 16, 'vail_daily', 'Parallax Basingdale A', 212.50, '2026-08-11', '2026-08-31', 'active', 'Pickup $425 worth of impressions', 'https://monthaus.com/listing-detail/1187262110?utm_source=vaildaily&utm_medium=web&utm_campaign=parallax&utm_content=2995BasingdaleA', 'vaildaily', 'web', 'parallax', '2995BasingdaleA', NULL, 1, '2026-08-10 22:41:20', 'broker', NULL, NULL),
(4, 16, 'vail_daily', 'Parallax Basingdale B', 212.50, '2026-08-11', NULL, 'active', 'Pickup $425 worth of impressions', 'https://monthaus.com/listing-detail/1187265960?utm_source=vaildaily&utm_medium=web&utm_campaign=parallax&utm_content=2995BasingdaleB', 'vaildaily', 'web', 'parallax', '2995BasingdaleB', NULL, 1, '2026-08-10 22:41:55', 'broker', NULL, NULL),
(5, 16, 'vail_daily', 'Marquee Basingdale', 250.00, '2026-08-15', '2026-08-29', 'active', 'Vail Daily screwed up on 8/15 so only paying for 8/22 and 8/29 this month.', 'https://monthaus.com/jean-michel-drai-listings?utm_source=vaildaily&utm_medium=web&utm_campaign=marquee&utm_content=2995Basingdale', 'vaildaily', 'web', 'marquee', '2995Basingdale', NULL, 1, '2026-08-10 22:43:56', 'broker', NULL, NULL),
(6, 5, 'vail_daily', 'RoS August 2026', 400.00, '2026-08-13', '2026-08-31', 'planned', 'Pickup $400 worth of impressions', 'https://monthaus.com/listing-detail/1185155299?utm_source=vaildaily&utm_medium=web&utm_campaign=970x250&utm_content=SimbaRun', 'vaildaily', 'web', '970x250', 'SimbaRun', NULL, 0, '2026-08-13 21:12:47', NULL, NULL, NULL);

--
-- marketing_campaign_assets
--
INSERT INTO `marketing_campaign_assets` (`id`, `campaign_id`, `label`, `file_url`, `target_url`, `file_type`, `uploaded_at`) VALUES
(1, 1, 'Ad file', 'https://www.dropbox.com/scl/fi/d63lrbeeqkdhu3kq6u4kz/ADN_30SmithWay_StickyAnchor.gif?rlkey=g5bjzewvtw7h0zdak09xra14y&st=5gclz3ta&dl=0', NULL, 'file', '2026-08-13 19:58:44'),
(2, 2, 'Ad file', 'https://www.dropbox.com/scl/fi/0rvby33ogyf98cqxqp880/ADN_450SolarWay_StickyAnchor.gif?rlkey=f8g6i9yft2bks2645wglz5rpo&st=x5rwnbm7&dl=0', NULL, 'file', '2026-08-13 19:58:44'),
(3, 3, 'Ad file', 'https://www.dropbox.com/scl/fo/gpujovkxa94m3zkv9ppp2/AJUdX1vz6j0njTzQrrqCoXc?rlkey=jkmjt4uhuixlmvgcvqp5ynp4m&st=5bryheq9&dl=0', NULL, 'file', '2026-08-13 19:58:44'),
(4, 4, 'Ad file', 'https://www.dropbox.com/scl/fo/gpujovkxa94m3zkv9ppp2/AJUdX1vz6j0njTzQrrqCoXc?rlkey=jkmjt4uhuixlmvgcvqp5ynp4m&st=5bryheq9&dl=0', NULL, 'file', '2026-08-13 19:58:44'),
(5, 6, '300x250', 'https://www.dropbox.com/scl/fi/3pr1lnxvvr9xi3otr6ubv/VD_BryanC-SimbaRun_250x300.gif?rlkey=2af4gdw2ekxsosecj0ywdgh8x&st=ime1mm79&dl=0', 'https://monthaus.com/listing-detail/1185155299?utm_source=vaildaily&utm_medium=web&utm_campaign=300x250&utm_content=simbarun', 'file', '2026-08-13 21:12:47'),
(6, 6, '320x50', 'https://www.dropbox.com/scl/fi/arwuepztc609avae2wrkc/VD_BryanC-SimbaRun_320x50.gif?rlkey=63dhvmq6zpo53kba9orymc1rd&st=fs55e2ym&dl=0', 'https://monthaus.com/listing-detail/1185155299?utm_source=vaildaily&utm_medium=web&utm_campaign=320x50&utm_content=SimbaRun', 'file', '2026-08-13 21:12:47'),
(7, 6, '320x100', 'https://www.dropbox.com/scl/fi/o7qk7qfguy3qbqvcd06oi/VD_BryanC-SimbaRun_320x100.gif?rlkey=onoish59rqxx78opfilzk61dx&st=s9oaood0&dl=0', 'https://monthaus.com/listing-detail/1185155299?utm_source=vaildaily&utm_medium=web&utm_campaign=320x100&utm_content=SimbaRun', 'file', '2026-08-13 21:12:47'),
(8, 6, '728x90', 'https://www.dropbox.com/scl/fi/9fo5aq77lksslb62jwq30/VD_BryanC-SimbaRun_728x90.gif?rlkey=7cpix2qdtmb8tii02d5hmucw1&st=3qmceqyp&dl=0', 'https://monthaus.com/listing-detail/1185155299?utm_source=vaildaily&utm_medium=web&utm_campaign=728x90&utm_content=SimbaRun', 'file', '2026-08-13 21:12:47'),
(9, 6, '970x90', 'https://www.dropbox.com/scl/fi/dgbiq92rlqalqn8oau26k/VD_BryanC-SimbaRun_970x90.gif?rlkey=0ca31kgtfqb5v2fv6xla2jsaw&st=pfq4hanp&dl=0', 'https://monthaus.com/listing-detail/1185155299?utm_source=vaildaily&utm_medium=web&utm_campaign=970x90&utm_content=SimbaRun', 'file', '2026-08-13 21:12:47'),
(10, 6, '970x250', 'https://www.dropbox.com/scl/fi/zbvl7799nrg50q22wohhf/VD_Bryanc-SimbaRun_970x250.gif?rlkey=5vqwq5cn977smkjdgyfvcxkvw&st=tu6bbvks&dl=0', 'https://monthaus.com/listing-detail/1185155299?utm_source=vaildaily&utm_medium=web&utm_campaign=970x250&utm_content=SimbaRun', 'file', '2026-08-13 21:12:47'),
(11, 5, 'Basingdale Marquee', 'https://www.dropbox.com/scl/fi/kmuesxtz3gw679817h0rq/JMDrai_Basingdale-marquee_VD-sm.gif?rlkey=a9ny3is20dmawqtn353lv2d13&st=gg62jxyr&dl=0', NULL, 'file', '2026-08-20 22:18:10');

--
-- marketing_tasks
--
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(1, 2, NULL, 'Email Setup', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 1, '2026-08-02 23:42:02', '2026-08-02 23:42:06'),
(2, 2, NULL, 'Email Signature', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 2, '2026-08-02 23:42:02', '2026-08-02 23:42:14'),
(3, 2, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-02 23:42:02', NULL),
(4, 2, NULL, 'B2B System Access', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-02 23:42:02', NULL),
(5, 2, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 5, '2026-08-02 23:42:02', '2026-08-02 23:44:15'),
(6, 2, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 6, '2026-08-02 23:42:02', '2026-08-02 23:44:17'),
(7, 2, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-02 23:42:02', NULL),
(8, 2, NULL, 'MH Website Updated', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 8, '2026-08-02 23:42:02', NULL),
(9, 2, NULL, 'Headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 9, '2026-08-02 23:42:02', NULL),
(10, 2, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-02 23:42:02', NULL),
(11, 2, 2, 'Signature file received', 'onboarding', 'normal', 'done', NULL, NULL, 'https://www.dropbox.com/scl/fo/l2hbd9lj8ux547r48624e/AKZ9EcMvctQyoCGeRl6EtaM?rlkey=es2dwtrbdk30pr0e1qladsiff&st=8r66m4fv&dl=0', 1, '2026-08-02 23:42:02', '2026-08-02 23:42:16'),
(12, 2, 9, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-02 23:42:02', NULL),
(13, 2, 9, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-02 23:42:02', NULL),
(14, 2, 9, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-02 23:42:02', NULL),
(15, 2, 9, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-02 23:42:02', NULL),
(16, 2, 9, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-02 23:42:02', NULL),
(17, 2, 10, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-02 23:42:02', NULL),
(18, 2, 10, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-02 23:42:02', NULL),
(19, 2, 10, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-02 23:42:02', NULL),
(20, 3, NULL, 'Email Setup', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 1, '2026-08-03 18:22:48', '2026-08-04 03:30:21'),
(21, 3, NULL, 'Email Signature', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 2, '2026-08-03 18:22:48', '2026-08-20 21:32:48'),
(22, 3, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 3, '2026-08-03 18:22:48', '2026-08-20 21:33:33'),
(23, 3, NULL, 'B2B System Access', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 4, '2026-08-03 18:22:48', '2026-08-20 21:36:28'),
(24, 3, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 5, '2026-08-03 18:22:48', '2026-08-20 21:36:30'),
(25, 3, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 6, '2026-08-03 18:22:48', '2026-08-20 21:36:35');
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(26, 3, NULL, 'Bio Received', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 7, '2026-08-03 18:22:48', '2026-08-20 21:36:38'),
(27, 3, NULL, 'MH Website Updated', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 8, '2026-08-03 18:22:48', '2026-08-20 21:36:40'),
(28, 3, NULL, 'Headshot', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 9, '2026-08-03 18:22:48', '2026-08-20 21:36:45'),
(29, 3, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 10, '2026-08-03 18:22:48', '2026-08-20 21:38:00'),
(30, 3, NULL, 'QR Code Created', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 11, '2026-08-03 18:22:48', '2026-08-20 21:38:02'),
(31, 3, 21, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-03 18:22:48', NULL),
(32, 3, 28, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-03 18:22:48', NULL),
(33, 3, 28, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-03 18:22:48', NULL),
(34, 3, 28, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-03 18:22:48', NULL),
(35, 3, 28, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-03 18:22:48', NULL),
(36, 3, 28, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-03 18:22:48', NULL),
(37, 3, 29, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-03 18:22:48', NULL),
(38, 3, 29, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-03 18:22:48', NULL),
(39, 3, 29, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-03 18:22:48', NULL),
(40, 3, 30, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-03 18:22:48', NULL),
(41, 4, NULL, 'Email Setup', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:48:59', NULL),
(42, 4, NULL, 'Email Signature', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:48:59', NULL),
(43, 4, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:48:59', NULL),
(44, 4, NULL, 'B2B System Access', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:48:59', NULL),
(45, 4, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:48:59', NULL),
(46, 4, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 6, '2026-08-04 16:48:59', NULL),
(47, 4, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-04 16:48:59', NULL),
(48, 4, NULL, 'MH Website Updated', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 8, '2026-08-04 16:48:59', NULL),
(49, 4, NULL, 'Headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 9, '2026-08-04 16:48:59', NULL),
(50, 4, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-04 16:48:59', NULL);
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(51, 4, NULL, 'QR Code Created', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 11, '2026-08-04 16:48:59', NULL),
(52, 4, 42, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:48:59', NULL),
(53, 4, 49, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:48:59', NULL),
(54, 4, 49, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:48:59', NULL),
(55, 4, 49, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:48:59', NULL),
(56, 4, 49, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:48:59', NULL),
(57, 4, 49, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:48:59', NULL),
(58, 4, 50, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:48:59', NULL),
(59, 4, 50, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:48:59', NULL),
(60, 4, 50, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:48:59', NULL),
(61, 4, 51, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:48:59', NULL),
(62, 5, NULL, 'Email Setup', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:02', NULL),
(63, 5, NULL, 'Email Signature', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:02', NULL),
(64, 5, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:02', NULL),
(65, 5, NULL, 'B2B System Access', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:51:02', NULL),
(66, 5, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:51:02', NULL),
(67, 5, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 6, '2026-08-04 16:51:02', NULL),
(68, 5, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-04 16:51:02', NULL),
(69, 5, NULL, 'MH Website Updated', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 8, '2026-08-04 16:51:02', NULL),
(70, 5, NULL, 'Headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 9, '2026-08-04 16:51:02', NULL),
(71, 5, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-04 16:51:02', NULL),
(72, 5, NULL, 'QR Code Created', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 11, '2026-08-04 16:51:02', NULL),
(73, 5, 63, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:02', NULL),
(74, 5, 70, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:02', NULL),
(75, 5, 70, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:02', NULL);
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(76, 5, 70, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:02', NULL),
(77, 5, 70, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:51:02', NULL),
(78, 5, 70, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:51:02', NULL),
(79, 5, 71, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:02', NULL),
(80, 5, 71, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:02', NULL),
(81, 5, 71, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:02', NULL),
(82, 5, 72, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:02', NULL),
(83, 6, NULL, 'Email Setup', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 1, '2026-08-04 16:51:12', '2026-08-07 19:43:13'),
(84, 6, NULL, 'Email Signature', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 2, '2026-08-04 16:51:12', '2026-08-07 19:43:15'),
(85, 6, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 3, '2026-08-04 16:51:12', '2026-08-07 20:56:29'),
(86, 6, NULL, 'B2B System Access', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 4, '2026-08-04 16:51:12', '2026-08-07 20:56:31'),
(87, 6, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 5, '2026-08-04 16:51:12', '2026-08-07 20:56:32'),
(88, 6, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 6, '2026-08-04 16:51:12', '2026-08-07 20:56:34'),
(89, 6, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-04 16:51:12', NULL),
(90, 6, NULL, 'MH Website Updated', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 8, '2026-08-04 16:51:12', '2026-08-07 20:56:41'),
(91, 6, NULL, 'Headshot', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 9, '2026-08-04 16:51:12', '2026-08-07 20:56:57'),
(92, 6, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-04 16:51:12', NULL),
(93, 6, NULL, 'QR Code Created', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 11, '2026-08-04 16:51:12', NULL),
(94, 6, 84, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:12', NULL),
(95, 6, 91, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:12', NULL),
(96, 6, 91, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:12', NULL),
(97, 6, 91, 'Session scheduled', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 3, '2026-08-04 16:51:12', '2026-08-07 20:56:47'),
(98, 6, 91, 'Proofs received', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 4, '2026-08-04 16:51:12', '2026-08-07 20:56:51'),
(99, 6, 91, 'Final delivered', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 5, '2026-08-04 16:51:12', '2026-08-07 20:56:54'),
(100, 6, 92, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:12', NULL);
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(101, 6, 92, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:12', NULL),
(102, 6, 92, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:12', NULL),
(103, 6, 93, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:12', NULL),
(104, 7, NULL, 'Email Setup', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:19', NULL),
(105, 7, NULL, 'Email Signature', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:19', NULL),
(106, 7, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:19', NULL),
(107, 7, NULL, 'B2B System Access', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:51:19', NULL),
(108, 7, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:51:19', NULL),
(109, 7, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 6, '2026-08-04 16:51:19', NULL),
(110, 7, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-04 16:51:19', NULL),
(111, 7, NULL, 'MH Website Updated', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 8, '2026-08-04 16:51:19', NULL),
(112, 7, NULL, 'Headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 9, '2026-08-04 16:51:19', NULL),
(113, 7, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-04 16:51:19', NULL),
(114, 7, NULL, 'QR Code Created', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 11, '2026-08-04 16:51:19', NULL),
(115, 7, 105, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:19', NULL),
(116, 7, 112, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:19', NULL),
(117, 7, 112, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:19', NULL),
(118, 7, 112, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:19', NULL),
(119, 7, 112, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:51:19', NULL),
(120, 7, 112, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:51:19', NULL),
(121, 7, 113, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:19', NULL),
(122, 7, 113, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:19', NULL),
(123, 7, 113, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:19', NULL),
(124, 7, 114, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:19', NULL),
(125, 8, NULL, 'Email Setup', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:31', NULL);
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(126, 8, NULL, 'Email Signature', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:31', NULL),
(127, 8, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:31', NULL),
(128, 8, NULL, 'B2B System Access', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:51:31', NULL),
(129, 8, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:51:31', NULL),
(130, 8, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 6, '2026-08-04 16:51:31', NULL),
(131, 8, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-04 16:51:31', NULL),
(132, 8, NULL, 'MH Website Updated', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 8, '2026-08-04 16:51:31', NULL),
(133, 8, NULL, 'Headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 9, '2026-08-04 16:51:31', NULL),
(134, 8, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-04 16:51:31', NULL),
(135, 8, NULL, 'QR Code Created', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 11, '2026-08-04 16:51:31', NULL),
(136, 8, 126, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:31', NULL),
(137, 8, 133, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:31', NULL),
(138, 8, 133, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:31', NULL),
(139, 8, 133, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:31', NULL),
(140, 8, 133, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:51:31', NULL),
(141, 8, 133, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:51:31', NULL),
(142, 8, 134, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:31', NULL),
(143, 8, 134, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:31', NULL),
(144, 8, 134, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:31', NULL),
(145, 8, 135, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:31', NULL),
(146, 9, NULL, 'Email Setup', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:44', NULL),
(147, 9, NULL, 'Email Signature', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:44', NULL),
(148, 9, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:44', NULL),
(149, 9, NULL, 'B2B System Access', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:51:44', NULL),
(150, 9, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:51:44', NULL);
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(151, 9, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 6, '2026-08-04 16:51:44', NULL),
(152, 9, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-04 16:51:44', NULL),
(153, 9, NULL, 'MH Website Updated', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 8, '2026-08-04 16:51:44', NULL),
(154, 9, NULL, 'Headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 9, '2026-08-04 16:51:44', NULL),
(155, 9, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-04 16:51:44', NULL),
(156, 9, NULL, 'QR Code Created', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 11, '2026-08-04 16:51:44', NULL),
(157, 9, 147, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:44', NULL),
(158, 9, 154, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:44', NULL),
(159, 9, 154, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:44', NULL),
(160, 9, 154, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:44', NULL),
(161, 9, 154, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:51:44', NULL),
(162, 9, 154, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:51:44', NULL),
(163, 9, 155, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:44', NULL),
(164, 9, 155, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:51:44', NULL),
(165, 9, 155, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:51:44', NULL),
(166, 9, 156, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:51:44', NULL),
(167, 10, NULL, 'Email Setup', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:05', NULL),
(168, 10, NULL, 'Email Signature', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:52:05', NULL),
(169, 10, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:52:05', NULL),
(170, 10, NULL, 'B2B System Access', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:52:05', NULL),
(171, 10, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:52:05', NULL),
(172, 10, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 6, '2026-08-04 16:52:05', NULL),
(173, 10, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-04 16:52:05', NULL),
(174, 10, NULL, 'MH Website Updated', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 8, '2026-08-04 16:52:05', NULL),
(175, 10, NULL, 'Headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 9, '2026-08-04 16:52:05', NULL);
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(176, 10, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-04 16:52:05', NULL),
(177, 10, NULL, 'QR Code Created', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 11, '2026-08-04 16:52:05', NULL),
(178, 10, 168, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:05', NULL),
(179, 10, 175, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:05', NULL),
(180, 10, 175, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:52:05', NULL),
(181, 10, 175, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:52:05', NULL),
(182, 10, 175, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:52:05', NULL),
(183, 10, 175, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:52:05', NULL),
(184, 10, 176, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:05', NULL),
(185, 10, 176, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:52:05', NULL),
(186, 10, 176, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:52:05', NULL),
(187, 10, 177, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:05', NULL),
(188, 11, NULL, 'Email Setup', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:15', NULL),
(189, 11, NULL, 'Email Signature', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:52:15', NULL),
(190, 11, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:52:15', NULL),
(191, 11, NULL, 'B2B System Access', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:52:15', NULL),
(192, 11, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:52:15', NULL),
(193, 11, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 6, '2026-08-04 16:52:15', NULL),
(194, 11, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-04 16:52:15', NULL),
(195, 11, NULL, 'MH Website Updated', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 8, '2026-08-04 16:52:15', NULL),
(196, 11, NULL, 'Headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 9, '2026-08-04 16:52:15', NULL),
(197, 11, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-04 16:52:15', NULL),
(198, 11, NULL, 'QR Code Created', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 11, '2026-08-04 16:52:15', NULL),
(199, 11, 189, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:15', NULL),
(200, 11, 196, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:15', NULL);
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(201, 11, 196, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:52:15', NULL),
(202, 11, 196, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:52:15', NULL),
(203, 11, 196, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-04 16:52:15', NULL),
(204, 11, 196, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-04 16:52:15', NULL),
(205, 11, 197, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:15', NULL),
(206, 11, 197, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-04 16:52:15', NULL),
(207, 11, 197, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-04 16:52:15', NULL),
(208, 11, 198, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-04 16:52:15', NULL),
(209, 6, NULL, 'Design Postcards (Solar and Smith)', 'collateral', 'normal', 'open', '2026-08-12', NULL, NULL, 0, '2026-08-07 21:02:56', NULL),
(210, 6, NULL, 'Design Montrose Intro Agent Postcard', 'collateral', 'normal', 'open', '2026-08-14', NULL, NULL, 0, '2026-08-07 21:03:15', NULL),
(211, 15, NULL, 'Email Setup', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 1, '2026-08-09 20:00:26', '2026-08-09 20:00:32'),
(212, 15, NULL, 'Email Signature', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 2, '2026-08-09 20:00:26', '2026-08-19 20:32:25'),
(213, 15, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 3, '2026-08-09 20:00:26', '2026-08-13 20:58:33'),
(214, 15, NULL, 'B2B System Access', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 4, '2026-08-09 20:00:26', '2026-08-20 22:16:11'),
(215, 15, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 5, '2026-08-09 20:00:26', '2026-08-19 20:32:33'),
(216, 15, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 6, '2026-08-09 20:00:26', '2026-08-19 20:32:36'),
(217, 15, NULL, 'Bio Received', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 7, '2026-08-09 20:00:26', '2026-08-19 20:40:59'),
(218, 15, NULL, 'MH Website Updated', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 8, '2026-08-09 20:00:26', '2026-08-19 20:41:01'),
(219, 15, NULL, 'Headshot', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 9, '2026-08-09 20:00:26', '2026-08-19 20:41:03'),
(220, 15, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 10, '2026-08-09 20:00:26', '2026-08-20 22:16:18'),
(221, 15, NULL, 'QR Code Created', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 11, '2026-08-09 20:00:26', '2026-08-09 20:17:56'),
(222, 15, 212, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-09 20:00:26', NULL),
(223, 15, 219, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-09 20:00:26', NULL),
(224, 15, 219, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-09 20:00:26', NULL),
(225, 15, 219, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-09 20:00:26', NULL);
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(226, 15, 219, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-09 20:00:26', NULL),
(227, 15, 219, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-09 20:00:26', NULL),
(228, 15, 220, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-09 20:00:26', NULL),
(229, 15, 220, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-09 20:00:26', NULL),
(230, 15, 220, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-09 20:00:26', NULL),
(231, 15, 221, 'SVG file', 'onboarding', 'normal', 'done', NULL, NULL, 'https://www.dropbox.com/scl/fi/9i7db7s9goe0u79ivvj5v/Emma-listings_QR.svg?rlkey=c3sxqrh5qi2vrnlieb843z34l&st=fom7t6xg&dl=0', 1, '2026-08-09 20:00:26', '2026-08-09 23:23:41'),
(232, 14, NULL, 'Email Setup', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-09 20:30:47', NULL),
(233, 14, NULL, 'Email Signature', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-09 20:30:47', NULL),
(234, 14, NULL, 'MLS IDs Confirmed', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-09 20:30:47', NULL),
(235, 14, NULL, 'B2B System Access', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-09 20:30:47', NULL),
(236, 14, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-09 20:30:47', NULL),
(237, 14, NULL, 'Social Overview Meeting', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 6, '2026-08-09 20:30:47', NULL),
(238, 14, NULL, 'Bio Received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 7, '2026-08-09 20:30:47', NULL),
(239, 14, NULL, 'MH Website Updated', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 8, '2026-08-09 20:30:47', NULL),
(240, 14, NULL, 'Headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 9, '2026-08-09 20:30:47', NULL),
(241, 14, NULL, 'Corporate Welcome', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 10, '2026-08-09 20:30:47', NULL),
(242, 14, NULL, 'QR Code Created', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 11, '2026-08-09 20:30:47', NULL),
(243, 14, 233, 'Signature file received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-09 20:30:47', NULL),
(244, 14, 240, 'Using existing headshot', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-09 20:30:47', NULL),
(245, 14, 240, 'Adjusted version ready', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-09 20:30:47', NULL),
(246, 14, 240, 'Session scheduled', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-09 20:30:47', NULL),
(247, 14, 240, 'Proofs received', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 4, '2026-08-09 20:30:47', NULL),
(248, 14, 240, 'Final delivered', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 5, '2026-08-09 20:30:47', NULL),
(249, 14, 241, 'Instagram post', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-09 20:30:47', NULL),
(250, 14, 241, 'Instagram story', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 2, '2026-08-09 20:30:47', NULL);
INSERT INTO `marketing_tasks` (`id`, `intake_id`, `parent_id`, `title`, `category`, `priority`, `status`, `due_date`, `notes`, `file_url`, `sort_order`, `created_at`, `completed_at`) VALUES
(251, 14, 241, 'B2B announcement', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 3, '2026-08-09 20:30:47', NULL),
(252, 14, 242, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-09 20:30:47', NULL),
(253, 16, NULL, 'Email Setup', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 10, '2026-08-10 22:37:20', '2026-08-10 22:37:23'),
(254, 16, NULL, 'Email Signature', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 20, '2026-08-10 22:37:20', '2026-08-10 22:37:25'),
(255, 16, NULL, 'QR Code', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 30, '2026-08-10 22:37:20', '2026-08-20 22:24:47'),
(256, 16, NULL, 'Marketing Overview Meeting', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 40, '2026-08-10 22:37:20', '2026-08-10 22:37:29'),
(257, 16, NULL, 'Bio Received', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 50, '2026-08-10 22:37:20', '2026-08-10 22:37:31'),
(258, 16, NULL, 'Headshot Received', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 60, '2026-08-10 22:37:20', '2026-08-10 22:37:32'),
(259, 16, NULL, 'Added to MH Website', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 70, '2026-08-10 22:37:20', '2026-08-10 22:37:34'),
(260, 16, NULL, 'Instagram Announcement', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 80, '2026-08-10 22:37:20', '2026-08-10 22:37:37'),
(261, 16, NULL, 'B2B Announcement', 'onboarding', 'normal', 'done', NULL, NULL, NULL, 90, '2026-08-10 22:37:20', '2026-08-10 22:37:41'),
(262, 16, 254, 'Signature file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-10 22:37:20', NULL),
(263, 16, 255, 'SVG file', 'onboarding', 'normal', 'open', NULL, NULL, NULL, 1, '2026-08-10 22:37:20', NULL),
(264, 15, NULL, 'Remind Emma to send/reupload Publuu marketing brochures', 'digital', 'normal', 'open', '2026-08-21', NULL, NULL, 0, '2026-08-20 23:46:26', NULL),
(265, 15, NULL, 'Send email to Emma about ad Aspen Daily News availability: September 1', 'digital', 'normal', 'open', '2026-08-21', NULL, NULL, 0, '2026-08-20 23:46:40', NULL);

--
-- marketing_collateral_orders
--
INSERT INTO `marketing_collateral_orders` (`id`, `intake_id`, `type`, `label`, `vendor`, `vendor_url`, `order_number`, `qty`, `cost`, `billed_with_order_id`, `status`, `ordered_at`, `tracking_number`, `tracking_url`, `delivered_at`, `file_url`, `receipt_file`, `receipt_orig_name`, `receipt_uploaded_at`, `paid_by`, `paid_broker_amount`, `paid_mh_amount`, `notes`, `created_at`) VALUES
(1, 6, 'business_cards', 'Initial Order', 'Moo', 'https://moo.com', '0496911252', '100', 58.95, NULL, 'delivered', '2026-07-13', NULL, NULL, '2026-07-27', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Ordered with Kim Coates', '2026-08-07 20:59:15'),
(2, 15, 'business_cards', 'Initial Order', 'Moo', 'https://moo.com', '0269846967', '200', 145.38, NULL, 'ordered', '2026-08-09', NULL, NULL, '2026-08-18', 'https://www.dropbox.com/scl/fi/74ckdg8wcrxm10afeery0/Emma_BizCards-FINAL.pdf?rlkey=3axaw1dqiw7gxm3tbt7nreevk&st=6oqd83nn&dl=0', 'receipt_20260820_b328cfd8bd47.pdf', '269846967-en.pdf', '2026-08-20 22:15:15', 'mont_haus', NULL, NULL, 'Cost includes Standard shipping\r\nPaid for by MH', '2026-08-09 20:12:31'),
(3, 15, 'yard_signs', 'Initial Order', 'Oakley Signs', 'https://www.oakleysign.com/', 'IND-655784', '5', 1140.99, NULL, 'ordered', '2026-08-09', '528563321343', 'https://www.fedex.com/fedextrack/?trknbr=528563321343&trkqual=12031~528563321343~FDEG', NULL, 'https://www.dropbox.com/scl/fi/9fmwhzu8l6eckq0k0vipv/Emma-YardSign_2026-New.pdf?rlkey=sap33t2uw5s98a5w79mu8zta9&st=r02ybmnt&dl=0', 'receipt_20260820_b51da288b9da.pdf', 'Emma-Signs.pdf', '2026-08-20 22:15:56', 'mont_haus', NULL, NULL, 'Order and cost includes Open House sign initial order + 3 day turnaround\r\nPaid for by MH', '2026-08-09 20:15:18'),
(4, 15, 'oh_signs', 'Initial Order', 'Oakley Signs', NULL, 'IND-655784', '5', 1140.99, 3, 'ordered', '2026-08-09', NULL, NULL, NULL, 'https://www.dropbox.com/scl/fi/iu4phv5s6ppreusj3px46/EmmaC_OpenHouse-FINAL.pdf?rlkey=kfxgygr3ltqaqao0017sfomyr&st=ecmc0nud&dl=0', NULL, NULL, NULL, 'mont_haus', NULL, NULL, 'Order and cost includes Yard sign initial order + 3 day turnaround\r\nPaid for by MH', '2026-08-09 20:17:08'),
(5, 3, 'business_cards', 'Initial Order', 'Moo', 'https://moo.com', '0741410006', '200', 132.41, NULL, 'ordered', '2026-08-20', NULL, NULL, NULL, 'https://www.dropbox.com/scl/fo/pdvcwjnbf4f9coolhwkci/AEv_OUO--br6s6hVavIFLrg?rlkey=m148mddip3pyygedq6t4r7i54&st=k3znbvt0&dl=0', 'receipt_20260820_e5d47e666ef9.pdf', '741410006-en.pdf', '2026-08-20 22:12:44', 'mont_haus', NULL, NULL, NULL, '2026-08-20 21:41:20'),
(6, 3, 'yard_signs', 'Initial Order', 'Oakley Signs', 'https://www.oakleysign.com/', 'IND-656436', '2', 698.47, NULL, 'ordered', '2026-08-20', NULL, NULL, NULL, 'https://www.dropbox.com/scl/fi/w44hftjmdzd13tulnmxmw/Minette-YardSigns_2026.pdf?rlkey=y8oipul6b7mxhiossa6ibr198&st=sstiau9w&dl=0', 'receipt_20260820_48bf67fe0b34.pdf', 'Real Estate Shop Signs _ Shop R___ate Signs Online - Oakley Signs.pdf', '2026-08-20 22:13:33', 'broker', NULL, NULL, 'Cost includes Yard Signs and Open House Signs (below)', '2026-08-20 21:46:36'),
(7, 3, 'oh_signs', 'Initial Order', 'Oakley Signs', 'https://www.oakleysign.com/', 'IND-656436', '3', 698.47, 6, 'ordered', NULL, NULL, NULL, NULL, 'https://www.dropbox.com/scl/fo/oarqeu6u2t6yorevs4qfw/AI6aP7TT-nqvO7a7H8LiLf0?rlkey=c9wlvstc09wdqv01ho9yu9zat&st=8scy3dfy&dl=0', NULL, NULL, NULL, 'broker', NULL, NULL, 'Cost includes Yard Signs also (above)', '2026-08-20 21:48:34');

--
-- marketing_notes
--
INSERT INTO `marketing_notes` (`id`, `intake_id`, `content`, `created_at`, `created_by`) VALUES
(1, 15, '@emmainaspen instagram in Email Signature; emmainaspen email', '2026-08-13 20:07:22', 2),
(2, 15, 'Send email to Emma about ad Aspen Daily News availability: September 1', '2026-08-13 20:13:30', 2),
(3, 15, 'Publuu marketing brochures', '2026-08-13 20:27:07', 2),
(4, 3, 'Minette will take some of the ADN marquee space as per recruitment discussion. Talk to JB for details.', '2026-08-20 21:49:42', 2);


-- ── Indexes and auto-increment ────────────────────────────────────────

--
-- users
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- password_resets
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`);
ALTER TABLE `password_resets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- office_roster
--
ALTER TABLE `office_roster`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_key` (`agent_key`);
ALTER TABLE `office_roster`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=215;

--
-- mh_brokers
--
ALTER TABLE `mh_brokers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_full_name` (`full_name`),
  ADD KEY `idx_mb_roster` (`roster_id`);
ALTER TABLE `mh_brokers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

--
-- listings
--
ALTER TABLE `listings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `listing_key` (`listing_key`);
ALTER TABLE `listings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- listing_brokers
--
ALTER TABLE `listing_brokers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `listing_id` (`listing_id`),
  ADD KEY `idx_lb_roster` (`roster_id`);
ALTER TABLE `listing_brokers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12518;

--
-- marketing_intakes
--
ALTER TABLE `marketing_intakes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mi_roster` (`roster_id`);
ALTER TABLE `marketing_intakes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- marketing_campaigns
--
ALTER TABLE `marketing_campaigns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_campaigns_intake` (`intake_id`);
ALTER TABLE `marketing_campaigns`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- marketing_campaign_assets
--
ALTER TABLE `marketing_campaign_assets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assets_campaign` (`campaign_id`);
ALTER TABLE `marketing_campaign_assets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- marketing_tasks
--
ALTER TABLE `marketing_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tasks_intake` (`intake_id`),
  ADD KEY `idx_tasks_status` (`intake_id`,`status`);
ALTER TABLE `marketing_tasks`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=266;

--
-- marketing_collateral_orders
--
ALTER TABLE `marketing_collateral_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_coll_intake` (`intake_id`),
  ADD KEY `idx_coll_billed_with` (`billed_with_order_id`);
ALTER TABLE `marketing_collateral_orders`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- marketing_notes
--
ALTER TABLE `marketing_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notes_intake` (`intake_id`);
ALTER TABLE `marketing_notes` ADD FULLTEXT KEY `ft_notes_content` (`content`);
ALTER TABLE `marketing_notes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- marketing_agent_mls_ids
--
ALTER TABLE `marketing_agent_mls_ids`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_agent_board` (`intake_id`,`board`);
ALTER TABLE `marketing_agent_mls_ids`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;


-- ── Foreign keys ──────────────────────────────────────────────────────

--
-- marketing_campaigns
--
ALTER TABLE `marketing_campaigns`
  ADD CONSTRAINT `fk_campaigns_intake` FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`) ON DELETE CASCADE;

--
-- marketing_campaign_assets
--
ALTER TABLE `marketing_campaign_assets`
  ADD CONSTRAINT `fk_assets_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE CASCADE;

--
-- marketing_tasks
--
ALTER TABLE `marketing_tasks`
  ADD CONSTRAINT `fk_tasks_intake` FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`) ON DELETE CASCADE;

--
-- marketing_collateral_orders
--
ALTER TABLE `marketing_collateral_orders`
  ADD CONSTRAINT `fk_coll_intake` FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`) ON DELETE CASCADE;

--
-- marketing_notes
--
ALTER TABLE `marketing_notes`
  ADD CONSTRAINT `fk_notes_intake` FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`) ON DELETE CASCADE;

--
-- marketing_agent_mls_ids
--
ALTER TABLE `marketing_agent_mls_ids`
  ADD CONSTRAINT `fk_mls_ids_intake` FOREIGN KEY (`intake_id`) REFERENCES `marketing_intakes` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Verification ────────────────────────────────────────────────────────────
--
-- Every table on one collation? This should return exactly one row.
--   SELECT DISTINCT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES
--    WHERE TABLE_SCHEMA = DATABASE();
--
-- Row counts, to compare against the hub:
--   SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES
--    WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;
--
-- At least one super_admin must keep a password — that is the break-glass
-- account if the Entra client secret expires:
--   SELECT email FROM users
--    WHERE role='super_admin' AND password IS NOT NULL AND password <> '';
