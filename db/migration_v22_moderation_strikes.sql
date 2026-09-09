-- =============================================================================
-- Migration v22: Content Moderation 3-Strikes + Appeals
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. reporter_strikes
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reporter_strikes (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id      INT             NOT NULL,
  article_id       BIGINT UNSIGNED DEFAULT NULL,
  reason           ENUM(
                     'fake_news',
                     'misinformation',
                     'plagiarism',
                     'hate_speech',
                     'obscene_content',
                     'copyright_violation',
                     'spam',
                     'other'
                   ) NOT NULL,
  strike_number    TINYINT UNSIGNED NOT NULL COMMENT '1, 2, or 3',
  consequence      ENUM(
                     'warning',
                     'review_queue',
                     'blue_tick_revoked_account_suspended'
                   ) NOT NULL,
  details          TEXT            DEFAULT NULL,
  issued_by        INT             DEFAULT NULL COMMENT 'admin user_id',
  appeal_id        BIGINT UNSIGNED DEFAULT NULL,
  expires_at       TIMESTAMP       NOT NULL COMMENT '6 months from issuance',
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_reporter  (reporter_id, created_at),
  INDEX idx_article   (article_id),
  INDEX idx_expires   (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. moderation_actions (audit log)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation_actions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  strike_id    BIGINT UNSIGNED DEFAULT NULL,
  reporter_id  INT             NOT NULL,
  article_id   BIGINT UNSIGNED DEFAULT NULL,
  action_type  ENUM(
                 'strike_issued',
                 'article_unpublished',
                 'forced_review_queue',
                 'blue_tick_revoked',
                 'account_suspended',
                 'account_reinstated',
                 'appeal_submitted',
                 'appeal_approved',
                 'appeal_rejected'
               ) NOT NULL,
  performed_by INT             DEFAULT NULL,
  notes        VARCHAR(500)    DEFAULT NULL,
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_reporter (reporter_id),
  INDEX idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. strike_appeals
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS strike_appeals (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  strike_id     BIGINT UNSIGNED NOT NULL,
  reporter_id   INT             NOT NULL,
  appeal_reason TEXT            NOT NULL,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by   INT             DEFAULT NULL,
  review_notes  VARCHAR(500)    DEFAULT NULL,
  reviewed_at   TIMESTAMP       DEFAULT NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_strike (strike_id),
  INDEX idx_reporter (reporter_id),
  INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
