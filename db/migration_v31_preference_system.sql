-- ============================================================
-- NewsXpressLive — Migration v31: User Preference System
-- ============================================================
-- Run after: migration_v30_reward_phase3.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
-- 1. user_preferences  (primary preferences table)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `user_id`                    VARCHAR(128)  NOT NULL,
  `onboarding_completed`       TINYINT       NOT NULL DEFAULT 0,
  `onboarding_completed_at`    TIMESTAMP     NULL     DEFAULT NULL,
  `onboarding_categories`      JSON          NULL,
  `onboarding_languages`       JSON          NULL,
  `onboarding_location_state`  INT           NULL     DEFAULT NULL,
  `onboarding_location_district` INT         NULL     DEFAULT NULL,
  `last_mood_set_at`           TIMESTAMP     NULL     DEFAULT NULL,
  `last_mood_categories`       JSON          NULL,
  `last_mood_context`          VARCHAR(50)   NULL     DEFAULT NULL,
  `popup_skip_streak`          INT           NOT NULL DEFAULT 0,
  `popup_snoozed_until`        TIMESTAMP     NULL     DEFAULT NULL,
  `popup_total_shown`          INT           NOT NULL DEFAULT 0,
  `popup_total_engaged`        INT           NOT NULL DEFAULT 0,
  `behavior_weights`           JSON          NULL,
  `behavior_updated_at`        TIMESTAMP     NULL     DEFAULT NULL,
  `preferred_state_id`         INT           NULL     DEFAULT NULL,
  `preferred_district_id`      INT           NULL     DEFAULT NULL,
  `location_auto_detected`     TINYINT       NOT NULL DEFAULT 0,
  `last_location_update`       TIMESTAMP     NULL     DEFAULT NULL,
  `created_at`                 TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                 TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. user_mood_log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_mood_log` (
  `id`                     BIGINT        NOT NULL AUTO_INCREMENT,
  `user_id`                VARCHAR(128)  NOT NULL,
  `selected_categories`    JSON          NOT NULL,
  `context`                VARCHAR(50)   NULL     DEFAULT NULL,
  `time_slot`              ENUM(
                             'early_morning','morning','afternoon',
                             'evening','night','late_night'
                           )             NULL     DEFAULT NULL,
  `day_of_week`            TINYINT       NOT NULL,
  `session_before_minutes` SMALLINT      NOT NULL DEFAULT 0,
  `was_skipped`            TINYINT       NOT NULL DEFAULT 0,
  `created_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user`    (`user_id`, `created_at` DESC),
  INDEX `idx_context` (`user_id`, `context`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. user_category_behavior
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_category_behavior` (
  `user_id`              VARCHAR(128)   NOT NULL,
  `category_id`          INT            NOT NULL,
  `articles_read`        INT            NOT NULL DEFAULT 0,
  `articles_completed`   INT            NOT NULL DEFAULT 0,
  `total_read_seconds`   INT            NOT NULL DEFAULT 0,
  `shares_count`         INT            NOT NULL DEFAULT 0,
  `bookmarks_count`      INT            NOT NULL DEFAULT 0,
  `comments_count`       INT            NOT NULL DEFAULT 0,
  `likes_count`          INT            NOT NULL DEFAULT 0,
  `mood_selected_count`  INT            NOT NULL DEFAULT 0,
  `behavior_weight`      DECIMAL(4,3)   NOT NULL DEFAULT 0.500,
  `weight_updated_at`    TIMESTAMP      NULL     DEFAULT NULL,
  `hourly_pattern`       JSON           NULL,
  PRIMARY KEY (`user_id`, `category_id`),
  INDEX `idx_weight` (`user_id`, `behavior_weight` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. preference_popup_analytics
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `preference_popup_analytics` (
  `id`                       BIGINT        NOT NULL AUTO_INCREMENT,
  `user_id`                  VARCHAR(128)  NOT NULL,
  `popup_type`               ENUM('onboarding','daily_mood','category_change') NOT NULL,
  `trigger_score`            TINYINT       NULL     DEFAULT NULL,
  `trigger_reasons`          JSON          NULL,
  `shown_at`                 TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `action`                   ENUM('submitted','skipped','dismissed','timeout')
                                           NOT NULL DEFAULT 'submitted',
  `time_to_action_seconds`   SMALLINT      NULL     DEFAULT NULL,
  `categories_before`        JSON          NULL,
  `categories_after`         JSON          NULL,
  `feed_ctr_after`           DECIMAL(5,4)  NULL     DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_user` (`user_id`, `shown_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. onboarding_progress
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `onboarding_progress` (
  `user_id`                VARCHAR(128)  NOT NULL,
  `step_current`           TINYINT       NOT NULL DEFAULT 1,
  `step_language_done`     TINYINT       NOT NULL DEFAULT 0,
  `step_location_done`     TINYINT       NOT NULL DEFAULT 0,
  `step_category_done`     TINYINT       NOT NULL DEFAULT 0,
  `step_notification_done` TINYINT       NOT NULL DEFAULT 0,
  `started_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`           TIMESTAMP     NULL     DEFAULT NULL,
  `device_type`            VARCHAR(50)   NULL     DEFAULT NULL,
  `app_version`            VARCHAR(20)   NULL     DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. categories table — add emoji / color / mood / sort columns
-- ------------------------------------------------------------
ALTER TABLE `categories`
  ADD COLUMN IF NOT EXISTS `emoji`            VARCHAR(10)  NULL    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `color_hex`        VARCHAR(7)   NULL    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_mood_category` TINYINT      NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `sort_order`       INT          NOT NULL DEFAULT 0;

-- Seed emoji + color per category slug
UPDATE `categories` SET `emoji` = '🏛️', `color_hex` = '#4A90D9' WHERE `slug` = 'politics';
UPDATE `categories` SET `emoji` = '⚽',  `color_hex` = '#1D9E75' WHERE `slug` = 'sports';
UPDATE `categories` SET `emoji` = '🎬', `color_hex` = '#7F77DD' WHERE `slug` = 'entertainment';
UPDATE `categories` SET `emoji` = '💼', `color_hex` = '#EF9F27' WHERE `slug` = 'business';
UPDATE `categories` SET `emoji` = '💻', `color_hex` = '#D85A30' WHERE `slug` = 'technology';
UPDATE `categories` SET `emoji` = '🌾', `color_hex` = '#639922' WHERE `slug` = 'agriculture';
UPDATE `categories` SET `emoji` = '🏥', `color_hex` = '#E24B4A' WHERE `slug` = 'health';
UPDATE `categories` SET `emoji` = '🎓', `color_hex` = '#534AB7' WHERE `slug` = 'education';
UPDATE `categories` SET `emoji` = '🌍', `color_hex` = '#0F6E56' WHERE `slug` = 'international';
UPDATE `categories` SET `emoji` = '⚖️',  `color_hex` = '#993C1D' WHERE `slug` = 'crime';

SET foreign_key_checks = 1;
