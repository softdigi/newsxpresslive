-- ============================================================
-- NewsXpressLive Database Migrations
-- Run these SQL statements to add new tables and columns
-- ============================================================

-- Add views column to news table (tracks article views)
-- Note: MySQL 8.0+ supports IF NOT EXISTS for ALTER, older versions need manual check
ALTER TABLE news ADD COLUMN IF NOT EXISTS views INT DEFAULT 0;

-- Add reads_completed column to news table (tracks reading completion)
ALTER TABLE news ADD COLUMN IF NOT EXISTS reads_completed INT DEFAULT 0;

-- ============================================================
-- Subscribers Table (Newsletter)
-- ============================================================
CREATE TABLE IF NOT EXISTS subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_hash VARCHAR(64),
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_email (email),
    INDEX idx_ip_time (ip_hash, subscribed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tags System
-- ============================================================
CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_tags (
    news_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (news_id, tag_id),
    INDEX idx_tag (tag_id),
    FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Agency Applications (Self-Registration)
-- ============================================================
CREATE TABLE IF NOT EXISTS agency_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agency_name VARCHAR(255) NOT NULL,
    agency_type ENUM('national','international','local','digital') NOT NULL,
    country VARCHAR(100),
    website VARCHAR(255),
    contact_name VARCHAR(255),
    contact_email VARCHAR(255),
    contact_phone VARCHAR(50),
    designation VARCHAR(100),
    coverage_areas TEXT,
    description TEXT,
    logo_filename VARCHAR(255),
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewer_notes TEXT,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME,
    INDEX idx_status (status),
    INDEX idx_email (contact_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Push Notification Subscribers (Web Push)
-- ============================================================
CREATE TABLE IF NOT EXISTS push_subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint TEXT NOT NULL,
    p256dh_key TEXT,
    auth_key TEXT,
    user_agent VARCHAR(500),
    subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_notified_at DATETIME,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AI Digest: ai_summary column on news table
-- ============================================================
ALTER TABLE news ADD COLUMN IF NOT EXISTS ai_summary TEXT DEFAULT NULL;

-- ============================================================
-- AI News Digests Table
-- Stores every digest that was generated and sent.
-- ============================================================
CREATE TABLE IF NOT EXISTS news_digests (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    digest_type  ENUM('local_district','local_state','national','international') NOT NULL,
    location_id  INT DEFAULT NULL,              -- district_id or state_id; NULL for national/international
    digest_title VARCHAR(255) NOT NULL,
    digest_text  TEXT NOT NULL,                 -- AI-generated summary sent as notification body
    news_ids     TEXT,                          -- comma-separated news.id values included
    sent_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    fcm_response TEXT,                          -- raw FCM API response (for debugging)
    INDEX idx_type_loc  (digest_type, location_id),
    INDEX idx_type_time (digest_type, sent_at),
    INDEX idx_sent_at   (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- categories.scope column (national / international)
-- Required by national_digest.php to split national vs intl.
-- ============================================================
ALTER TABLE categories ADD COLUMN IF NOT EXISTS scope ENUM('national','international') DEFAULT 'national';

-- ============================================================
-- Settings table (for storing OpenAI / Gemini API keys)
-- Already likely exists; included for completeness.
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Comments Table
-- Supports threaded replies via parent_id.
-- status: pending (awaiting moderation), approved, spam
-- ============================================================
CREATE TABLE IF NOT EXISTS comments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    news_id     INT NOT NULL,
    parent_id   INT DEFAULT NULL,
    author_name VARCHAR(100) NOT NULL,
    author_email VARCHAR(255) DEFAULT NULL,
    content     TEXT NOT NULL,
    status      ENUM('pending','approved','spam') DEFAULT 'pending',
    ip_address  VARCHAR(64) DEFAULT NULL,   -- stores SHA-256 hash of IP
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_news_status (news_id, status),
    INDEX idx_parent (parent_id),
    CONSTRAINT fk_comment_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Comment Rate Limit Table (tracks submissions per IP hash)
-- ============================================================
CREATE TABLE IF NOT EXISTS comment_rate_limit (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ip_hash    VARCHAR(64) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Media Optimization: image_sizes JSON column
-- Stores thumbnail / medium / original WebP URLs as JSON object.
-- e.g. {"thumbnail":"https://cdn.../thumb/img.webp","medium":"...","original":"..."}
-- image column kept for backward compatibility (holds medium URL after migration).
-- ============================================================
ALTER TABLE news ADD COLUMN IF NOT EXISTS image_sizes JSON DEFAULT NULL;

-- ============================================================
-- Ad Settings — default rows (empty; admin pastes code via UI)
-- ============================================================
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('ad_header',     ''),
    ('ad_in_content', ''),
    ('ad_sidebar',    '');

-- ============================================================
-- Sample Tags (optional - for testing)
-- ============================================================
-- INSERT IGNORE INTO tags (name, slug) VALUES
-- ('Politics', 'politics'),
-- ('Sports', 'sports'),
-- ('Technology', 'technology'),
-- ('Business', 'business'),
-- ('Entertainment', 'entertainment'),
-- ('Health', 'health'),
-- ('World', 'world'),
-- ('Science', 'science'),
-- ('Education', 'education'),
-- ('Environment', 'environment');

-- ============================================================
-- AI Personalized Feed – User Behavior Tracking
-- ============================================================

-- Raw per-event behavior log
CREATE TABLE IF NOT EXISTS user_behavior (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id   VARCHAR(64)  NOT NULL,          -- SHA-256 of IP+UA; anonymous
    news_id      INT          NOT NULL,
    event_type   ENUM('click','read','scroll','share') NOT NULL DEFAULT 'click',
    time_spent   SMALLINT UNSIGNED DEFAULT 0,    -- seconds on page
    scroll_depth TINYINT UNSIGNED DEFAULT 0,     -- 0-100 %
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session   (session_id),
    INDEX idx_news      (news_id),
    INDEX idx_session_news (session_id, news_id),
    INDEX idx_created   (created_at),
    CONSTRAINT fk_ub_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggregated category + tag interest profile per session
CREATE TABLE IF NOT EXISTS user_profiles (
    session_id      VARCHAR(64) NOT NULL,
    category_id     INT         DEFAULT NULL,
    tag_id          INT         DEFAULT NULL,
    interest_score  FLOAT       NOT NULL DEFAULT 0,  -- weighted rolling sum
    last_updated    DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id, category_id, tag_id),
    INDEX idx_session_score (session_id, interest_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Monetization System
-- ============================================================

-- Premium / Sponsored columns on news table
ALTER TABLE news ADD COLUMN IF NOT EXISTS is_premium   TINYINT(1) DEFAULT 0;
ALTER TABLE news ADD COLUMN IF NOT EXISTS is_sponsored TINYINT(1) DEFAULT 0;

-- Subscriber / registered user accounts
CREATE TABLE IF NOT EXISTS users (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(150) NOT NULL,
    email                VARCHAR(255) UNIQUE NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    subscription_status  ENUM('free','active','expired','cancelled') DEFAULT 'free',
    subscription_plan    ENUM('monthly','yearly') DEFAULT NULL,
    subscription_expires DATETIME DEFAULT NULL,
    ad_frequency_cap     TINYINT UNSIGNED DEFAULT 5,  -- max ads per session; 0 = default
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email  (email),
    INDEX idx_status (subscription_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscription payment records
CREATE TABLE IF NOT EXISTS subscriptions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    plan        ENUM('monthly','yearly') NOT NULL,
    amount      DECIMAL(8,2) NOT NULL,
    currency    CHAR(3) DEFAULT 'USD',
    status      ENUM('pending','active','expired','cancelled','refunded') DEFAULT 'pending',
    gateway     VARCHAR(50) DEFAULT 'manual',  -- stripe|razorpay|manual
    gateway_ref VARCHAR(255) DEFAULT NULL,
    starts_at   DATETIME NOT NULL,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_status (status),
    CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscription plan pricing (stored in settings table)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('plan_monthly_price',  '4.99'),
    ('plan_monthly_label',  'Monthly'),
    ('plan_yearly_price',   '39.99'),
    ('plan_yearly_label',   'Yearly'),
    ('plan_currency',       'USD'),
    ('ad_default_freq_cap', '5'),     -- ads per session for free users
    ('premium_teaser_pct',  '30');    -- % of premium article shown before paywall

-- ============================================================
-- Short Video Reels Feature
-- ============================================================

CREATE TABLE IF NOT EXISTS video_reels (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id   INT DEFAULT NULL,
    title         VARCHAR(255) NOT NULL,
    description   TEXT DEFAULT NULL,
    video_file    VARCHAR(255) NOT NULL,
    thumbnail     VARCHAR(255) DEFAULT NULL,
    category_id   INT DEFAULT NULL,
    likes_count   INT UNSIGNED DEFAULT 0,
    views_count   INT UNSIGNED DEFAULT 0,
    comments_count INT UNSIGNED DEFAULT 0,
    status        ENUM('pending','published','rejected') DEFAULT 'pending',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status     (status),
    INDEX idx_reporter   (reporter_id),
    INDEX idx_category   (category_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reel_likes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    reel_id    INT NOT NULL,
    ip_hash    VARCHAR(64) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reel_ip (reel_id, ip_hash),
    INDEX idx_reel (reel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reel_comments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    reel_id     INT NOT NULL,
    author_name VARCHAR(100) NOT NULL,
    content     TEXT NOT NULL,
    status      ENUM('pending','approved') DEFAULT 'pending',
    ip_hash     VARCHAR(64) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reel   (reel_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate-limit table for reel comments
CREATE TABLE IF NOT EXISTS reel_comment_rate_limit (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ip_hash    VARCHAR(64) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip   (ip_hash),
    INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Viral Referral System
-- ============================================================

-- Add referral columns to users
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS referral_code   VARCHAR(12)  UNIQUE DEFAULT NULL AFTER ad_frequency_cap,
    ADD COLUMN IF NOT EXISTS referred_by_id  INT          DEFAULT NULL        AFTER referral_code,
    ADD COLUMN IF NOT EXISTS referral_points INT UNSIGNED DEFAULT 0           AFTER referred_by_id,
    ADD INDEX IF NOT EXISTS idx_referral_code (referral_code),
    ADD INDEX IF NOT EXISTS idx_referred_by  (referred_by_id);

-- Multi-level referral rewards log
CREATE TABLE IF NOT EXISTS referral_rewards (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,              -- who earned the reward
    referred_id  INT NOT NULL,              -- the new user who triggered it
    level        TINYINT UNSIGNED NOT NULL, -- 1 = direct, 2 = indirect level-2, 3 = level-3
    event        ENUM('install','signup','subscription') NOT NULL,
    points       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id    (user_id),
    INDEX idx_referred   (referred_id),
    INDEX idx_level      (level),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-IP install-click log (for deduplication)
CREATE TABLE IF NOT EXISTS referral_clicks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    referral_code VARCHAR(12) NOT NULL,
    ip_hash     VARCHAR(64) NOT NULL,
    user_agent  VARCHAR(500) DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (referral_code),
    INDEX idx_ip   (ip_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Referral reward point values (configurable via settings table)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('referral_points_install',      '5'),
    ('referral_points_signup',       '10'),
    ('referral_points_subscription', '50'),
    ('referral_max_levels',          '3'),
    ('referral_level2_pct',          '50'),   -- level-2 earns 50% of level-1 points
    ('referral_level3_pct',          '25');   -- level-3 earns 25% of level-1 points

-- ============================================================
-- Viral Score System
-- ============================================================

-- Computed viral score stored on news row for fast ORDER BY
ALTER TABLE news ADD COLUMN IF NOT EXISTS viral_score   FLOAT        NOT NULL DEFAULT 0;
-- Share count tracked separately (views already exist)
ALTER TABLE news ADD COLUMN IF NOT EXISTS shares_count  INT UNSIGNED NOT NULL DEFAULT 0;
-- Flag set automatically when viral_score exceeds threshold
ALTER TABLE news ADD COLUMN IF NOT EXISTS is_trending   TINYINT(1)   NOT NULL DEFAULT 0;
-- Timestamp of the last viral score recalculation
ALTER TABLE news ADD COLUMN IF NOT EXISTS viral_score_updated_at DATETIME DEFAULT NULL;

-- Index for feed-boost queries (published + score)
CREATE INDEX IF NOT EXISTS idx_news_viral    ON news (status, viral_score);
CREATE INDEX IF NOT EXISTS idx_news_trending ON news (status, is_trending);

-- Configurable viral-score weights and trending threshold (admin-editable)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('viral_weight_view',      '1.0'),   -- points per view
    ('viral_weight_share',     '3.0'),   -- points per share (higher value = social reach)
    ('viral_weight_comment',   '2.0'),   -- points per approved comment
    ('viral_weight_watch_min', '1.5'),   -- points per minute of watch/read time
    ('viral_trending_threshold', '50'),  -- min score to auto-mark as trending
    ('viral_decay_hours',        '72'),  -- articles older than N hours lose 50% of score
    ('viral_feed_boost_pct',     '20');  -- % of feed slots filled with top-viral articles

-- ============================================================
-- Fake News Detection System
-- ============================================================

-- Computed analysis columns on the news row
ALTER TABLE news ADD COLUMN IF NOT EXISTS fake_score    FLOAT        NOT NULL DEFAULT 0
    COMMENT '0-100 risk score from NLP detector';
ALTER TABLE news ADD COLUMN IF NOT EXISTS fake_flags    TEXT         DEFAULT NULL
    COMMENT 'JSON array of triggered signal names';
ALTER TABLE news ADD COLUMN IF NOT EXISTS fake_verdict  ENUM('clean','suspicious','likely_fake')
    NOT NULL DEFAULT 'clean';
ALTER TABLE news ADD COLUMN IF NOT EXISTS fake_reviewed TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT '1 once an admin has reviewed this article';
ALTER TABLE news ADD COLUMN IF NOT EXISTS fake_scanned_at DATETIME   DEFAULT NULL;

-- Review queue: one row per article that needs human eyes
CREATE TABLE IF NOT EXISTS fake_news_queue (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id      INT UNSIGNED NOT NULL,
    fake_score   FLOAT        NOT NULL DEFAULT 0,
    fake_verdict ENUM('suspicious','likely_fake') NOT NULL DEFAULT 'suspicious',
    fake_flags   TEXT         DEFAULT NULL,
    reviewed     TINYINT(1)   NOT NULL DEFAULT 0,
    reviewed_by  INT UNSIGNED DEFAULT NULL   COMMENT 'admin_users.id',
    review_note  TEXT         DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at  DATETIME     DEFAULT NULL,
    UNIQUE KEY  uq_queue_news (news_id),
    INDEX idx_queue_reviewed (reviewed, fake_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Index for fast flagged-article queries
CREATE INDEX IF NOT EXISTS idx_news_fake_verdict ON news (fake_verdict, fake_reviewed);
