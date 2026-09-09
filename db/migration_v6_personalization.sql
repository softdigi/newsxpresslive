-- ============================================================
-- Migration v6 — Personalization Engine
-- Implements:
--   Feature 1 — User Interest + Preference Based Feed (user_interests)
--   Feature 2 — Explicit Read History Tracking        (user_read_history)
--   Feature 3 — Rule-Based Personalization Engine     (personalization_rules)
--
-- Safe to re-run: uses IF NOT EXISTS / IF NOT EXISTS guards.
-- MySQL 8.0+  |  utf8mb4_unicode_ci
-- ============================================================

-- ============================================================
-- FEATURE 1 — user_interests
-- Stores per-user category/tag affinity with a decimal weight.
-- Sources:  onboarding (user picked during signup)
--           explicit   (user updated in Settings > Interests)
--           behavioral (updated by read_history.php / cron engine)
-- weight range: 0.10 (almost never reads) → 5.00 (reads everything)
-- ============================================================
CREATE TABLE IF NOT EXISTS user_interests (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     VARCHAR(128) NOT NULL
                    COMMENT 'Firebase UID',
    category_id INT          DEFAULT NULL
                    COMMENT 'references categories.id; NULL when row is tag-based',
    tag         VARCHAR(100) DEFAULT NULL
                    COMMENT 'raw tag string; NULL when row is category-based',
    source      ENUM('onboarding','explicit','behavioral')
                    NOT NULL DEFAULT 'onboarding',
    weight      DECIMAL(4,2) NOT NULL DEFAULT 1.00
                    COMMENT '0.10 – 5.00; higher = stronger interest',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

    -- A user may have at most one row per (user_id, category_id) pair
    -- and one row per (user_id, tag) pair.
    UNIQUE KEY uq_user_cat (user_id, category_id),
    UNIQUE KEY uq_user_tag (user_id, tag),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate already-selected onboarding categories (if user_categories exists)
INSERT IGNORE INTO user_interests (user_id, category_id, source, weight)
SELECT user_id, category_id, 'onboarding', 1.0
FROM   user_categories
WHERE  EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE  table_schema = DATABASE()
      AND  table_name   = 'user_categories'
);

-- ============================================================
-- FEATURE 2 — user_read_history
-- Tracks every article a logged-in user has explicitly read.
-- read_percent: how far they scrolled (0-100)
-- time_spent:   seconds on the article page
-- On conflict (same user + same article) the row is updated
-- so that re-reads refresh the timestamp and improve accuracy.
-- ============================================================
CREATE TABLE IF NOT EXISTS user_read_history (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         VARCHAR(128) NOT NULL
                        COMMENT 'Firebase UID',
    news_id         INT          NOT NULL,
    read_percent    TINYINT UNSIGNED NOT NULL DEFAULT 0
                        COMMENT '0-100 % of article scrolled',
    time_spent_sec  SMALLINT UNSIGNED NOT NULL DEFAULT 0
                        COMMENT 'seconds spent on article (capped at 3600)',
    read_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    -- One row per user+article; re-reading updates the row
    UNIQUE KEY uq_user_news (user_id, news_id),

    INDEX idx_user_read_at (user_id, read_at),
    INDEX idx_news         (news_id),

    CONSTRAINT fk_urh_news FOREIGN KEY (news_id)
        REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FEATURE 3 — personalization_rules
-- Stores declarative rules processed by personalization_engine.php.
-- condition_json  — JSON object describing the trigger condition.
-- action_json     — JSON object describing the interest update to apply.
--
-- Example rule (read_boost):
--   condition: {"min_reads": 3, "window_days": 7}
--   action:    {"weight_delta": 0.50}
--   → if a user reads ≥3 articles from a category in 7 days, add 0.50
--
-- Example rule (decay):
--   condition: {"idle_days": 14}
--   action:    {"weight_factor": 0.90, "min_weight": 0.10}
--   → if no reads from a category in 14 days, multiply weight by 0.90
--
-- Example rule (trending_boost):
--   condition: {"trending": true}
--   action:    {"weight_delta": 0.20}
--   → add 0.20 weight to categories that are currently trending
-- ============================================================
CREATE TABLE IF NOT EXISTS personalization_rules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    rule_name       VARCHAR(100) NOT NULL,
    rule_type       ENUM('read_boost','decay','trending_boost','recency_boost')
                        NOT NULL DEFAULT 'read_boost',
    condition_json  JSON         NOT NULL
                        COMMENT 'Trigger conditions as JSON object',
    action_json     JSON         NOT NULL
                        COMMENT 'Weight adjustments as JSON object',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    priority        SMALLINT     NOT NULL DEFAULT 0
                        COMMENT 'Lower number = applied first',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_type_active (rule_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default rules (skip if already present)
INSERT IGNORE INTO personalization_rules
    (rule_name, rule_type, condition_json, action_json, is_active, priority)
VALUES
    (
        'Read Boost — 3 articles in 7 days',
        'read_boost',
        '{"min_reads": 3, "window_days": 7}',
        '{"weight_delta": 0.50, "max_weight": 5.00}',
        1, 10
    ),
    (
        'Read Boost — 7 articles in 7 days',
        'read_boost',
        '{"min_reads": 7, "window_days": 7}',
        '{"weight_delta": 1.00, "max_weight": 5.00}',
        1, 20
    ),
    (
        'Decay — Idle 14 days',
        'decay',
        '{"idle_days": 14}',
        '{"weight_factor": 0.90, "min_weight": 0.10}',
        1, 5
    ),
    (
        'Decay — Idle 30 days',
        'decay',
        '{"idle_days": 30}',
        '{"weight_factor": 0.75, "min_weight": 0.10}',
        1, 6
    ),
    (
        'Trending Category Boost',
        'trending_boost',
        '{"min_trending_articles": 2}',
        '{"weight_delta": 0.20, "max_weight": 5.00}',
        1, 30
    );
