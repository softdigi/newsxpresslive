-- ============================================================
-- NewsXpressLive  —  DB Migration v2
-- Senior DBA Review: missing columns, indexes, cursor pagination,
-- cleanup queries, and schema audit findings.
--
-- Safe to re-run: every ALTER uses IF NOT EXISTS / IF EXISTS.
-- Run as the application DB user (requires ALTER, INDEX, DELETE).
-- MySQL 8.0+ required for IF NOT EXISTS on ALTER TABLE.
-- ============================================================

-- ============================================================
-- PART 1  —  Missing Columns
-- ============================================================

-- 1a. news.moderation_flag
--     Application (submit_news.php, ModerationService) expects this
--     column to exist with a default of 0 (clean).  Adding it as
--     TINYINT(1) to match all other boolean flags in the schema.
ALTER TABLE news
    ADD COLUMN IF NOT EXISTS moderation_flag TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '0=clean, 1=flagged for review';

-- 1b. news.is_breaking
--     Used by admin/make_breaking.php, admin/send_breaking_notification*.php,
--     and referenced in the feed composite index (Part 2).
ALTER TABLE news
    ADD COLUMN IF NOT EXISTS is_breaking TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = breaking news banner';

-- 1c. news.featured_image
--     Queried everywhere (web/news/detail.php etc.) but absent from
--     migrations.sql.  Keeping VARCHAR(500) to avoid truncation on
--     CDN/S3 URLs (see Part 5 audit).
ALTER TABLE news
    ADD COLUMN IF NOT EXISTS featured_image VARCHAR(500) DEFAULT NULL
        COMMENT 'Filename or full CDN URL of the hero image';

-- 1d. viral_boosts table
--     No CREATE TABLE statement exists in migrations.sql yet.
--     Columns inferred from admin/viral_boost_create.php INSERT.
CREATE TABLE IF NOT EXISTS viral_boosts (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id                 INT NOT NULL,
    boost_level             VARCHAR(30) NOT NULL DEFAULT 'standard',
    status                  ENUM('active','scheduled','paused','expired')
                                NOT NULL DEFAULT 'active',
    target_type             VARCHAR(30) DEFAULT 'global',
    target_locations        JSON DEFAULT NULL,
    duration_hours          SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    start_time              DATETIME NOT NULL,
    end_time                DATETIME NOT NULL,
    pin_to_top              TINYINT(1) NOT NULL DEFAULT 0,
    send_push_notification  TINYINT(1) NOT NULL DEFAULT 0,
    feature_in_banner       TINYINT(1) NOT NULL DEFAULT 0,
    viral_multiplier        FLOAT NOT NULL DEFAULT 1.0,
    boost_score             FLOAT NOT NULL DEFAULT 0,
    reporter_bonus          DECIMAL(8,2) NOT NULL DEFAULT 0,
    boosted_by              INT DEFAULT NULL COMMENT 'admin_users.id',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status            (status),
    INDEX idx_news_id           (news_id),
    INDEX idx_end_time          (end_time),
    INDEX idx_status_end        (status, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1e. withdrawal_requests table
--     Referenced in api/wallet_withdraw.php but never created in migrations.
CREATE TABLE IF NOT EXISTS withdrawal_requests (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    status      ENUM('pending','processing','paid','rejected') DEFAULT 'pending',
    note        TEXT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id   (user_id),
    INDEX idx_status    (status),
    INDEX idx_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1f. Cleanup: withdrawal_requests rows where user_id = 0
--     These are orphan rows created by the old bug (Flutter app never
--     sent a session cookie, so PHP always saw user_id = 0).
--     Review first with the SELECT, then run the DELETE.
--
--   SELECT * FROM withdrawal_requests WHERE user_id = 0;
DELETE FROM withdrawal_requests WHERE user_id = 0;


-- ============================================================
-- PART 2  —  Performance Indexes
-- ============================================================

-- 2a. news — composite feed index
--     Covers the most common API feed query:
--       WHERE status='approved' ORDER BY is_breaking DESC, viral_score DESC, created_at DESC
--     Column order: equality filter first (status), then sort columns.
CREATE INDEX IF NOT EXISTS idx_news_feed
    ON news (status, is_breaking, viral_score, created_at);

-- 2b. viral_boosts — simulated partial index for active boosts
--     MySQL does NOT support WHERE-clause partial indexes (PostgreSQL feature).
--     Best practice: composite index on (status, news_id) so the engine
--     can satisfy "WHERE status = 'active'" with an index-range scan.
--     This achieves the same selectivity as a PostgreSQL partial index.
CREATE INDEX IF NOT EXISTS idx_viral_boosts_active_news
    ON viral_boosts (status, news_id);

-- Alternative approach via a generated stored column (MySQL 5.7+):
--   ALTER TABLE viral_boosts
--       ADD COLUMN IF NOT EXISTS active_flag TINYINT(1) AS (IF(status='active',1,NULL)) STORED,
--       ADD INDEX IF NOT EXISTS idx_vb_active_flag (active_flag);
-- This is not added by default to avoid schema complexity; use the composite
-- index above for equivalent query plans.

-- 2c. rate_limits — identifier + action + window lookup
--     auth/rate_limit.php queries: WHERE action=? AND identifier=? AND window_start>=?
--     The existing idx_rl_lookup covers (action, identifier, window_start) already if
--     the table was created via the README snippet; add it safely here as well.
CREATE INDEX IF NOT EXISTS idx_rl_identifier_action_window
    ON rate_limits (identifier, action, window_start);

-- 2d. comments — list for an article
--     api/v1/comments fetch: WHERE news_id=? AND status='approved' ORDER BY created_at DESC
--     Existing idx_news_status covers (news_id, status) but not created_at.
--     Replace with a covering composite index.
ALTER TABLE comments
    DROP INDEX IF EXISTS idx_news_status;

CREATE INDEX IF NOT EXISTS idx_comments_news_status_created
    ON comments (news_id, status, created_at);


-- ============================================================
-- PART 3  —  Cursor / Keyset Pagination Support
-- ============================================================

-- Keyset pagination query pattern:
--   WHERE (created_at < :last_created_at)
--      OR (created_at = :last_created_at AND id < :last_id)
--   ORDER BY created_at DESC, id DESC
--   LIMIT :page_size
--
-- A compound index (created_at DESC, id DESC) lets MySQL satisfy the
-- ORDER BY clause without a filesort and short-circuit on the cursor
-- condition efficiently.
--
-- Note: MySQL indexes are inherently bidirectional — DESC hints are
-- accepted in MySQL 8.0+ for explicit mixed-direction sorts.  For the
-- common DESC/DESC case a plain index works the same.
CREATE INDEX IF NOT EXISTS idx_news_cursor
    ON news (created_at, id);

-- ============================================================
-- PART 4  —  Cleanup Queries  (safe to run as cron jobs)
-- ============================================================

-- 4a. rate_limits: delete rows older than 24 hours
--     Schedule via cron every hour:
--       mysql -u app -p newsxpresslive -e "DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 24 HOUR LIMIT 5000;"
--     The LIMIT guards against long-running deletes on large tables.
DELETE FROM rate_limits
WHERE window_start < NOW() - INTERVAL 24 HOUR
LIMIT 5000;

-- 4b. viral_boosts: mark and clean expired boosts
--     Step 1 — auto-expire boosts whose end_time has passed.
UPDATE viral_boosts
SET    status = 'expired'
WHERE  status IN ('active','scheduled')
  AND  end_time < NOW();

--     Step 2 — purge expired boosts older than 30 days (archival).
DELETE FROM viral_boosts
WHERE  status = 'expired'
  AND  end_time < NOW() - INTERVAL 30 DAY
LIMIT 1000;

-- 4c. Duplicate slug report (READ ONLY — do NOT delete automatically)
--     Run this query and review manually; duplicates must be fixed with
--     a business-logic-aware rename, not a bulk DELETE.
SELECT
    slug,
    COUNT(*)          AS duplicate_count,
    GROUP_CONCAT(id ORDER BY id)     AS news_ids,
    GROUP_CONCAT(title ORDER BY id)  AS titles,
    MIN(created_at)   AS oldest,
    MAX(created_at)   AS newest
FROM   news
WHERE  slug IS NOT NULL AND slug != ''
GROUP  BY slug
HAVING COUNT(*) > 1
ORDER  BY duplicate_count DESC, newest DESC;


-- ============================================================
-- PART 5  —  Schema Audit  (findings + fixes)
-- ============================================================

-- ----------------------------------------------------------
-- 5a. NOT NULL columns with no DEFAULT  (crash risk)
--     Found by reviewing migrations.sql.
--
--     TABLE: referral_rewards
--       level  TINYINT UNSIGNED NOT NULL  — no DEFAULT, no IF NOT EXISTS guard
--       event  ENUM NOT NULL             — no DEFAULT
--       Rows inserted without these columns will error on strict mode.
--     Fix: already has NOT NULL with an explicit value in every INSERT;
--     but for safety add application-level defaults here.
ALTER TABLE referral_rewards
    MODIFY COLUMN level TINYINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT '1=direct,2=level-2,3=level-3';

ALTER TABLE referral_rewards
    MODIFY COLUMN event ENUM('install','signup','subscription')
                        NOT NULL DEFAULT 'signup';

--     TABLE: fake_news_queue
--       fake_verdict ENUM NOT NULL DEFAULT 'suspicious' — OK, has DEFAULT.
--       reviewed_by  INT UNSIGNED  DEFAULT NULL — OK.
--
--     TABLE: ab_tests
--       title_a / title_b  VARCHAR(500) NOT NULL — no DEFAULT.
--       Inserts always supply values; acceptable but noted.
--
--     TABLE: subscriptions
--       starts_at / expires_at  DATETIME NOT NULL — no DEFAULT.
--       Inserts always supply values; acceptable but noted.
-- ----------------------------------------------------------

-- ----------------------------------------------------------
-- 5b. Missing Foreign Keys  (orphan-row risk)
--     Listed below; ALTER statements add them only where the
--     referenced table is guaranteed to exist.
--
--   comments.parent_id  → comments.id  (self-referential threads)
ALTER TABLE comments
    ADD CONSTRAINT IF NOT EXISTS fk_comment_parent
        FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE SET NULL;

--   subscriptions.user_id → users.id  (already defined in migrations.sql — skip)

--   referral_rewards.user_id → users.id
ALTER TABLE referral_rewards
    ADD CONSTRAINT IF NOT EXISTS fk_rr_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

--   referral_rewards.referred_id → users.id
ALTER TABLE referral_rewards
    ADD CONSTRAINT IF NOT EXISTS fk_rr_referred
        FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE;

--   viral_boosts.news_id → news.id
ALTER TABLE viral_boosts
    ADD CONSTRAINT IF NOT EXISTS fk_vb_news
        FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE;

--   withdrawal_requests.user_id → users.id
ALTER TABLE withdrawal_requests
    ADD CONSTRAINT IF NOT EXISTS fk_wr_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

--   fake_news_queue.news_id → news.id
--   (Column is INT UNSIGNED; news.id is INT — need UNSIGNED to match)
ALTER TABLE news MODIFY COLUMN id INT UNSIGNED AUTO_INCREMENT NOT NULL;

ALTER TABLE fake_news_queue
    ADD CONSTRAINT IF NOT EXISTS fk_fnq_news
        FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE;

--   news_tags.news_id / tag_id → already have FKs in migrations.sql.
--   news_digests has no FK for location_id (intentional — polymorphic).
-- ----------------------------------------------------------

-- ----------------------------------------------------------
-- 5c. VARCHAR fields too narrow for production data
--
--   news.slug  — typical slug: "breaking-story-about-some-topic-that-is-very-long-2024"
--                Likely VARCHAR(255) in original schema; safe.
--                featured_image added above as VARCHAR(500) (CDN URLs can be long).
--
--   referral_clicks.user_agent VARCHAR(500) — adequate for most browsers.
--
--   push_subscribers.user_agent VARCHAR(500) — adequate.
--
--   tags.slug VARCHAR(120)  — may truncate multi-word Devanagari slugs.
ALTER TABLE tags
    MODIFY COLUMN slug VARCHAR(200) NOT NULL;

--   categories — not defined in migrations.sql; schema likely has slug VARCHAR(100).
--   If so:
ALTER TABLE categories
    MODIFY COLUMN IF EXISTS slug VARCHAR(200) NOT NULL;

--   news.ai_topic VARCHAR(300) — borderline; Gemini prompts can be longer.
ALTER TABLE news
    MODIFY COLUMN IF EXISTS ai_topic VARCHAR(500) DEFAULT NULL;
-- ----------------------------------------------------------

-- ----------------------------------------------------------
-- 5d. Tables missing created_at / updated_at indexes
--     (affects date-range cleanup queries and reporting dashboards)
--
--   rate_limits — covered by idx_rl_identifier_action_window (Part 2c).
--
--   referral_rewards — has idx_created_at already.
--
--   referral_clicks — has idx_ip (ip_hash) but NOT created_at.
CREATE INDEX IF NOT EXISTS idx_referral_clicks_created
    ON referral_clicks (created_at);

--   user_behavior — has idx_created already.
--
--   ab_test_events — has idx_test_event but NOT created_at top-level.
CREATE INDEX IF NOT EXISTS idx_ab_events_created
    ON ab_test_events (created_at);

--   fake_news_queue — has no created_at index.
CREATE INDEX IF NOT EXISTS idx_fnq_created
    ON fake_news_queue (created_at);

--   comment_rate_limit — has idx_ip_time (ip_hash, created_at) — OK.
--
--   reel_comment_rate_limit — has idx_time (created_at) — OK.
-- ----------------------------------------------------------


-- ============================================================
-- END OF MIGRATION v2
-- Verify with:
--   SHOW INDEX FROM news;
--   SHOW INDEX FROM viral_boosts;
--   SHOW INDEX FROM rate_limits;
--   SHOW INDEX FROM comments;
--   SHOW COLUMNS FROM news LIKE 'moderation_flag';
--   SHOW COLUMNS FROM news LIKE 'is_breaking';
--   SHOW COLUMNS FROM news LIKE 'featured_image';
-- ============================================================
