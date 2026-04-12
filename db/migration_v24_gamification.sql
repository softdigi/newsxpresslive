-- =============================================================================
-- Migration v24: Reporter Gamification — Badges, Milestones
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. badges catalogue
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS badges (
  id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  slug         VARCHAR(50)     NOT NULL,
  name         VARCHAR(100)    NOT NULL,
  description  VARCHAR(300)    NOT NULL,
  icon_url     VARCHAR(300)    DEFAULT NULL,
  trigger_type ENUM(
                 'early_bird',
                 'views_milestone',
                 'viral_story',
                 'followers_milestone',
                 'leaderboard_top1'
               ) NOT NULL,
  trigger_value INT UNSIGNED   DEFAULT NULL COMMENT 'e.g. 10000 for 10K views',
  is_active    TINYINT(1)      NOT NULL DEFAULT 1,
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed badge catalogue
INSERT IGNORE INTO badges (slug, name, description, trigger_type, trigger_value) VALUES
('early_bird',   'Early Bird',       'One of the first 100 reporters on NewsXpressLive', 'early_bird',          1),
('views_10k',    '10K Views Club',   'An article crossed 10,000 views',                  'views_milestone',     10000),
('viral_story',  'Viral Story',      'An article crossed 50,000 views',                  'viral_story',         50000),
('rising_star',  'Rising Star',      'Reached 500 followers',                            'followers_milestone', 500),
('top_reporter', 'Top Reporter',     'Ranked #1 on the weekly leaderboard',              'leaderboard_top1',    1);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. reporter_badges (earned badges)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reporter_badges (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id  INT             NOT NULL,
  badge_id     INT UNSIGNED    NOT NULL,
  article_id   BIGINT UNSIGNED DEFAULT NULL COMMENT 'Article that triggered the badge',
  unlocked_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_reporter_badge (reporter_id, badge_id),
  INDEX idx_reporter (reporter_id),
  INDEX idx_badge    (badge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. milestones (granular view/follower thresholds per reporter per article)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS milestones (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id  INT             NOT NULL,
  milestone_type ENUM('article_views','total_followers') NOT NULL,
  threshold    INT UNSIGNED    NOT NULL,
  article_id   BIGINT UNSIGNED DEFAULT NULL,
  achieved_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_milestone (reporter_id, milestone_type, threshold, article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
