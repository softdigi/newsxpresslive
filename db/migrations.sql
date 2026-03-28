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
