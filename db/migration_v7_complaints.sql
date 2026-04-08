-- ============================================================
-- Migration v7: Complaint / Public Voice System
-- Run once against the NewsXpressLive database.
-- ============================================================

-- ── Complaint categories ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS complaint_categories (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(100) NOT NULL,
    slug      VARCHAR(100) NOT NULL UNIQUE,
    icon      VARCHAR(60)  DEFAULT NULL,
    sort_order INT UNSIGNED DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO complaint_categories (name, slug, icon, sort_order) VALUES
    ('Roads & Infrastructure', 'roads',          'road',             1),
    ('Water Supply',            'water',          'water_drop',       2),
    ('Electricity',             'electricity',    'electric_bolt',    3),
    ('Garbage & Sanitation',    'sanitation',     'delete_sweep',     4),
    ('Public Safety',           'safety',         'shield',           5),
    ('Government Services',     'government',     'account_balance',  6),
    ('Education',               'education',      'school',           7),
    ('Health',                  'health',         'local_hospital',   8),
    ('Transport',               'transport',      'directions_bus',   9),
    ('Other',                   'other',          'more_horiz',      10);

-- ── Complaints ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS complaints (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    category_id     INT          NOT NULL DEFAULT 1,
    title           VARCHAR(255) NOT NULL,
    description     TEXT         NOT NULL,
    photo           VARCHAR(255) DEFAULT NULL,
    -- location
    district_id     INT          DEFAULT NULL,
    state_id        INT          DEFAULT NULL,
    location_text   VARCHAR(255) DEFAULT NULL,
    -- submitter (optional)
    firebase_uid    VARCHAR(128) DEFAULT NULL,
    author_name     VARCHAR(100) DEFAULT NULL,
    is_anonymous    TINYINT(1)   NOT NULL DEFAULT 0,
    -- moderation
    status          ENUM('pending','approved','under_review','resolved','rejected')
                    NOT NULL DEFAULT 'pending',
    admin_note      TEXT DEFAULT NULL,
    -- counts
    votes_count     INT UNSIGNED NOT NULL DEFAULT 0,
    -- rate-limit
    ip_hash         VARCHAR(64) DEFAULT NULL,
    -- timestamps
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_status      (status),
    INDEX idx_category    (category_id),
    INDEX idx_district    (district_id),
    INDEX idx_uid         (firebase_uid),
    INDEX idx_ip          (ip_hash),
    INDEX idx_created_at  (created_at),
    FOREIGN KEY (category_id) REFERENCES complaint_categories(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Votes (one per IP per day per complaint) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS complaint_votes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT          NOT NULL,
    ip_hash      VARCHAR(64)  NOT NULL,
    firebase_uid VARCHAR(128) DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_vote (complaint_id, ip_hash),
    FOREIGN KEY (complaint_id) REFERENCES complaints(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
