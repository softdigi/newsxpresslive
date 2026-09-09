-- ============================================================
-- Migration v16: Performance — FULLTEXT search + DB indexes
-- NewsXpressLive
-- ============================================================
-- FIX 3: Search FULLTEXT index on news(title, content)
-- The current search.php uses LIKE '%keyword%' which:
--   1. Cannot use any index → full table scan on every search
--   2. Scales to O(n) with table size
--   3. Does not rank results by relevance
-- FULLTEXT with MATCH..AGAINST provides:
--   - Index-backed search (fast even on millions of rows)
--   - Relevance scoring (most relevant results first)
--   - Natural language mode (handles common words, stemming)
-- ============================================================

-- ── FULLTEXT index for news search ──────────────────────────────────────────
-- Drop any existing FULLTEXT index first (safe if it does not exist)
SET @exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'news'
    AND INDEX_NAME   = 'ft_news_search'
);

SET @sql := IF(
  @exists > 0,
  'SELECT "fulltext index already exists"',
  'ALTER TABLE news ADD FULLTEXT INDEX ft_news_search (title, content)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Additional performance indexes ─────────────────────────────────────────
-- news table: most common query patterns
ALTER TABLE news
  ADD INDEX IF NOT EXISTS idx_news_status_date   (status, created_at DESC),
  ADD INDEX IF NOT EXISTS idx_news_category_date (category_id, status, created_at DESC),
  ADD INDEX IF NOT EXISTS idx_news_breaking       (is_breaking, status),
  ADD INDEX IF NOT EXISTS idx_news_views          (views DESC),
  ADD INDEX IF NOT EXISTS idx_news_slug           (slug);

-- comments table
ALTER TABLE comments
  ADD INDEX IF NOT EXISTS idx_comments_news_status (news_id, status, created_at DESC),
  ADD INDEX IF NOT EXISTS idx_comments_parent       (parent_id);

-- users table
ALTER TABLE users
  ADD INDEX IF NOT EXISTS idx_users_firebase_uid (firebase_uid),
  ADD INDEX IF NOT EXISTS idx_users_role_status  (role, status);

-- notifications table (if exists)
ALTER TABLE notifications
  ADD INDEX IF NOT EXISTS idx_notif_user_read (user_id, is_read, created_at DESC);

-- user_read_history (personalization queries)
ALTER TABLE user_read_history
  ADD INDEX IF NOT EXISTS idx_readhist_user_date (user_id, read_at DESC);

-- user_interests
ALTER TABLE user_interests
  ADD INDEX IF NOT EXISTS idx_interests_user (user_id, weight DESC);

-- fraud_flags
ALTER TABLE fraud_flags
  ADD INDEX IF NOT EXISTS idx_fraud_user_date (user_id, created_at DESC),
  ADD INDEX IF NOT EXISTS idx_fraud_context   (context);

-- comment_rate_limit (used by comment_submit.php)
ALTER TABLE comment_rate_limit
  ADD INDEX IF NOT EXISTS idx_crl_ip_time (ip_hash, created_at);

-- ── Connection pool hint ─────────────────────────────────────────────────────
-- MySQL: increase max_connections in my.cnf / my.ini
-- [mysqld]
-- max_connections = 200
-- wait_timeout    = 60
-- interactive_timeout = 60
-- PDO persistent connections are enabled in config.php via
-- PDO::ATTR_PERSISTENT => true
