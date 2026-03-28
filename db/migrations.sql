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
