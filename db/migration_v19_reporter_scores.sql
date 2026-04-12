-- =============================================================================
-- Migration v19: Reporter Credibility Score + Leaderboard
-- =============================================================================
-- Run AFTER migration_v18_wallet_transactions.sql
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. reporter_scores  — current score snapshot (updated daily by cron)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reporter_scores (
  id                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id              INT             NOT NULL,
  articles_approved    INT UNSIGNED    NOT NULL DEFAULT 0,
  total_views          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  followers_count      INT UNSIGNED    NOT NULL DEFAULT 0,
  avg_article_rating   DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
  fake_news_flags      INT UNSIGNED    NOT NULL DEFAULT 0,
  blue_tick_bonus      TINYINT(1)      NOT NULL DEFAULT 0
                       COMMENT '1 if reporter currently has blue tick',
  credibility_score    DECIMAL(12,2)   NOT NULL DEFAULT 0.00
                       COMMENT '(approved*10)+(views/1000)+(followers*5)+(rating*20)+(tick?50:0)-(flags*30)',
  weekly_score         DECIMAL(12,2)   NOT NULL DEFAULT 0.00
                       COMMENT 'Score accumulated in the current ISO week',
  weekly_rank          INT UNSIGNED    DEFAULT NULL,
  all_time_rank        INT UNSIGNED    DEFAULT NULL,
  last_calculated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_user (user_id),
  INDEX idx_credibility  (credibility_score DESC),
  INDEX idx_weekly       (weekly_score DESC),
  INDEX idx_week_rank    (weekly_rank),
  INDEX idx_alltime_rank (all_time_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily-updated credibility snapshot per reporter';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. reporter_score_history  — daily archive for trend charts
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reporter_score_history (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT             NOT NULL,
  score_date       DATE            NOT NULL,
  credibility_score DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  daily_rank       INT UNSIGNED    DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_user_date (user_id, score_date),
  INDEX idx_date (score_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily score snapshots for trend analysis';
