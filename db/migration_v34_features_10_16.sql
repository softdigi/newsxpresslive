-- ============================================================
-- Migration v34 — Horoscope, Cricket, Offline Packs,
--                 News Quiz, AR Markers, Bot Reporter
-- NewsXpressLive
-- ============================================================

-- ── Feature 10: Horoscope / Rashifal ─────────────────────────

CREATE TABLE IF NOT EXISTS `daily_horoscope` (
  `id`             BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `zodiac_sign`    VARCHAR(20)  NOT NULL,
  `zodiac_hi`      VARCHAR(20)  NOT NULL,
  `horoscope_date` DATE         NOT NULL,
  `language_code`  VARCHAR(10)  NOT NULL DEFAULT 'hi',
  `content`        TEXT         NOT NULL,
  `lucky_number`   TINYINT      NULL,
  `lucky_color`    VARCHAR(50)  NULL,
  `lucky_time`     VARCHAR(50)  NULL,
  `general_score`  TINYINT      NULL,
  `love_score`     TINYINT      NULL,
  `career_score`   TINYINT      NULL,
  `health_score`   TINYINT      NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_horoscope` (`zodiac_sign`, `horoscope_date`, `language_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_zodiac` (
  `user_uid`    VARCHAR(128) NOT NULL PRIMARY KEY,
  `zodiac_sign` VARCHAR(20)  NOT NULL,
  `birth_date`  DATE         NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feature 11: Cricket Live Score ───────────────────────────

CREATE TABLE IF NOT EXISTS `cricket_matches` (
  `id`           INT          AUTO_INCREMENT PRIMARY KEY,
  `match_id`     VARCHAR(100) NOT NULL,
  `match_type`   VARCHAR(50)  NULL,
  `series_name`  VARCHAR(300) NULL,
  `team1_name`   VARCHAR(100) NOT NULL,
  `team1_short`  VARCHAR(10)  NOT NULL,
  `team1_score`  VARCHAR(50)  NULL,
  `team2_name`   VARCHAR(100) NOT NULL,
  `team2_short`  VARCHAR(10)  NOT NULL,
  `team2_score`  VARCHAR(50)  NULL,
  `status`       ENUM('upcoming','live','completed') NOT NULL DEFAULT 'upcoming',
  `status_text`  VARCHAR(200) NULL,
  `match_date`   TIMESTAMP    NULL,
  `venue`        VARCHAR(200) NULL,
  `toss_winner`  VARCHAR(100) NULL,
  `match_winner` VARCHAR(100) NULL,
  `raw_data`     JSON         NULL,
  `last_updated` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_match_id` (`match_id`),
  INDEX `idx_status` (`status`, `match_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feature 12: Offline Download Packs ───────────────────────

CREATE TABLE IF NOT EXISTS `offline_packs` (
  `id`             BIGINT      AUTO_INCREMENT PRIMARY KEY,
  `pack_date`      DATE        NOT NULL,
  `language_code`  VARCHAR(10) NOT NULL DEFAULT 'hi',
  `state_id`       INT         NULL,
  `articles_count` INT         NOT NULL DEFAULT 0,
  `pack_size_kb`   INT         NOT NULL DEFAULT 0,
  `pack_url`       VARCHAR(500) NULL,
  `is_ready`       TINYINT     NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pack` (`pack_date`, `language_code`, `state_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feature 13: News Trivia Quiz ──────────────────────────────

CREATE TABLE IF NOT EXISTS `news_quiz_sessions` (
  `id`                   BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `user_uid`             VARCHAR(128) NOT NULL,
  `quiz_date`            DATE         NOT NULL,
  `questions_attempted`  INT          NOT NULL DEFAULT 0,
  `correct_answers`      INT          NOT NULL DEFAULT 0,
  `time_taken_seconds`   INT          NOT NULL DEFAULT 0,
  `score`                INT          NOT NULL DEFAULT 0,
  `rank_daily`           INT          NULL,
  `coins_earned`         INT          NOT NULL DEFAULT 0,
  `completed`            TINYINT      NOT NULL DEFAULT 0,
  `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_session` (`user_uid`, `quiz_date`),
  INDEX `idx_date_score` (`quiz_date`, `score` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `news_quiz_questions` (
  `id`             BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `quiz_date`      DATE         NOT NULL,
  `article_id`     INT          NULL,
  `question`       TEXT         NOT NULL,
  `option_a`       VARCHAR(300) NOT NULL,
  `option_b`       VARCHAR(300) NOT NULL,
  `option_c`       VARCHAR(300) NOT NULL,
  `option_d`       VARCHAR(300) NOT NULL,
  `correct_option` ENUM('a','b','c','d') NOT NULL,
  `explanation`    TEXT         NULL,
  `difficulty`     ENUM('easy','medium','hard') NOT NULL DEFAULT 'easy',
  `sort_order`     TINYINT      NOT NULL DEFAULT 0,
  INDEX `idx_date` (`quiz_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feature 14: AR News Markers ──────────────────────────────

CREATE TABLE IF NOT EXISTS `ar_news_markers` (
  `id`            BIGINT           AUTO_INCREMENT PRIMARY KEY,
  `article_id`    INT              NOT NULL,
  `latitude`      DECIMAL(10,6)    NOT NULL,
  `longitude`     DECIMAL(10,6)    NOT NULL,
  `marker_type`   ENUM('news','complaint','event','mandi') NOT NULL DEFAULT 'news',
  `title`         VARCHAR(200)     NOT NULL,
  `thumbnail_url` VARCHAR(500)     NULL,
  `expires_at`    TIMESTAMP        NULL,
  INDEX `idx_location` (`latitude`, `longitude`),
  INDEX `idx_article`  (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feature 15: AI Reporter Bot Sources ──────────────────────

CREATE TABLE IF NOT EXISTS `bot_news_sources` (
  `id`                    INT          AUTO_INCREMENT PRIMARY KEY,
  `source_name`           VARCHAR(200) NOT NULL,
  `source_url`            VARCHAR(500) NOT NULL,
  `source_type`           ENUM('rss','api','scraper') NOT NULL DEFAULT 'rss',
  `category_id`           INT          NULL,
  `language_code`         VARCHAR(10)  NOT NULL DEFAULT 'hi',
  `is_active`             TINYINT      NOT NULL DEFAULT 1,
  `fetch_interval_minutes` INT         NOT NULL DEFAULT 60,
  `last_fetched`          TIMESTAMP    NULL,
  INDEX `idx_active` (`is_active`, `last_fetched`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `bot_news_sources`
  (`id`, `source_name`, `source_url`, `source_type`, `category_id`, `language_code`, `is_active`, `fetch_interval_minutes`, `last_fetched`)
VALUES
  (1, 'PIB India RSS',       'https://pib.gov.in/RssMain.aspx',               'rss', NULL, 'hi', 1, 60,  NULL),
  (2, 'Supreme Court Orders','https://main.sci.gov.in/rss.php',                'rss', NULL, 'en', 1, 120, NULL),
  (3, 'Weather Dept RSS',    'https://mausam.imd.gov.in/rss/weather.xml',      'rss', NULL, 'hi', 1, 60,  NULL);

-- ── Feature 15: Track processed bot source items ─────────────

CREATE TABLE IF NOT EXISTS `bot_processed_items` (
  `id`          BIGINT       AUTO_INCREMENT PRIMARY KEY,
  `source_id`   INT          NOT NULL,
  `item_guid`   VARCHAR(500) NOT NULL,
  `processed_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_source_guid` (`source_id`, `item_guid`(255)),
  INDEX `idx_source` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feature 15: ALTER news table for bot support ──────────────

ALTER TABLE `news`
  MODIFY COLUMN `status` ENUM(
    'pending','approved','rejected','archived',
    'bot_draft'
  ) NOT NULL DEFAULT 'pending';

ALTER TABLE `news`
  ADD COLUMN IF NOT EXISTS `bot_source_name` VARCHAR(200) NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `bot_quality_score` TINYINT NULL AFTER `bot_source_name`;
