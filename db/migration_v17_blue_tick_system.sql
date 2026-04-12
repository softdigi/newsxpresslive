-- ============================================================
-- Migration v17: Blue Tick Verification System
-- NewsXpressLive
--
-- Features:
--   • Blue-tick plans (free early-bird + paid) for reporters & agencies
--   • Verification document submission + admin review workflow
--   • Agency ↔ Reporter relationship with revenue-share tracking
--   • Blue-tick assignment by agencies to their reporters
--   • Early-bird counter (first 100 reporters free, first 10 agencies free)
--   • Media-channel dropdown pre-filled with 20 popular outlets
--
-- Prerequisites: migrations.sql + v2–v16 applied.
-- MySQL 8.0+ required (IF NOT EXISTS on ALTER TABLE).
-- Safe to re-run: all CREATE TABLE / ALTER TABLE use IF NOT EXISTS.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ============================================================
-- 1.  Blue-tick plans
-- ============================================================

CREATE TABLE IF NOT EXISTS blue_tick_plans (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  plan_type     ENUM('reporter_free','reporter_paid',
                     'agency_free','agency_paid') NOT NULL,
  name          VARCHAR(100) NOT NULL,
  price         DECIMAL(10,2) DEFAULT 0.00,
  duration_type ENUM('lifetime','monthly','yearly')
                DEFAULT 'lifetime',
  max_reporters INT DEFAULT 0
                COMMENT 'Free reporter ticks bundled with agency plan',
  can_assign_ticks TINYINT DEFAULT 0
                COMMENT '1 = agency may assign ticks to reporters',
  assign_limit  INT DEFAULT 0
                COMMENT 'Number of free assignments included',
  assign_price  DECIMAL(10,2) DEFAULT 0.00
                COMMENT 'Cost per assignment beyond free limit',
  is_active     TINYINT DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_plan_type (plan_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO blue_tick_plans
  (id, plan_type, name, price, duration_type,
   max_reporters, can_assign_ticks, assign_limit, assign_price, is_active)
VALUES
-- First 100 reporters: FREE lifetime
(1,'reporter_free','Early Reporter – Free',
  0.00,'lifetime', 0,0,0,0.00,1),
-- Regular reporter: ₹99 lifetime
(2,'reporter_paid','Reporter Blue Tick',
  99.00,'lifetime', 0,0,0,0.00,1),
-- First 10 agencies: FREE (5 included reporter ticks + assign)
(3,'agency_free','Early Agency – Free',
  0.00,'lifetime', 5,1,5,0.00,1),
-- Regular agency: ₹299 lifetime (5 free + ₹49/extra)
(4,'agency_paid','Agency Blue Tick',
  299.00,'lifetime', 5,1,5,49.00,1);

-- ============================================================
-- 2.  Early-bird counters
-- ============================================================

CREATE TABLE IF NOT EXISTS early_bird_counters (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  type       ENUM('reporter','agency') NOT NULL UNIQUE,
  count      INT DEFAULT 0,
  free_limit INT NOT NULL
             COMMENT 'Slots that qualify for free tier (reporter=100, agency=10)',
  paid_limit INT NOT NULL
             COMMENT 'Max early-bird paid slots (reporter=1500, agency=50)',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
             ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO early_bird_counters (id, type, count, free_limit, paid_limit)
VALUES
(1,'reporter', 0, 100, 1500),
(2,'agency',   0,  10,   50);

-- ============================================================
-- 3.  Popular media-channel dropdown
-- ============================================================

CREATE TABLE IF NOT EXISTS media_channels (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(200) NOT NULL,
  type       ENUM('tv','digital','print','radio','online') DEFAULT 'digital',
  is_active  TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (name),
  INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO media_channels (name, type) VALUES
('Aaj Tak',       'tv'),
('NDTV',          'tv'),
('Zee News',      'tv'),
('India TV',      'tv'),
('News18',        'tv'),
('ABP News',      'tv'),
('TV9 Bharatvarsh','tv'),
('Republic Bharat','tv'),
('News24',        'tv'),
('Sahara Samay',  'tv'),
('Dainik Bhaskar','print'),
('Amar Ujala',    'print'),
('Hindustan',     'print'),
('Navbharat Times','print'),
('Jagran',        'print'),
('The Wire',      'online'),
('Scroll.in',     'online'),
('The Print',     'online'),
('Lallantop',     'digital'),
('Quint Hindi',   'digital');

-- ============================================================
-- 4.  Verification documents
-- ============================================================

CREATE TABLE IF NOT EXISTS verification_documents (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id      VARCHAR(128) NOT NULL COMMENT 'Firebase UID',
  account_type ENUM('reporter','agency') NOT NULL,

  -- Reporter identity documents
  aadhar_number VARCHAR(12)  DEFAULT NULL,
  aadhar_doc_url VARCHAR(500) DEFAULT NULL,
  pan_number    VARCHAR(10)  DEFAULT NULL,
  pan_doc_url   VARCHAR(500) DEFAULT NULL,

  -- Reporter channel affiliation
  channel_id    INT          DEFAULT NULL,
  channel_name  VARCHAR(200) DEFAULT NULL
                COMMENT 'Free-text if not in media_channels',
  channel_type  ENUM('existing','other') DEFAULT 'existing',

  -- Agency documents
  msme_number   VARCHAR(50)  DEFAULT NULL,
  msme_doc_url  VARCHAR(500) DEFAULT NULL,
  business_name VARCHAR(200) DEFAULT NULL,
  website       VARCHAR(500) DEFAULT NULL,
  contact_name  VARCHAR(200) DEFAULT NULL,
  contact_phone VARCHAR(15)  DEFAULT NULL,
  reporter_count SMALLINT    DEFAULT 0,
  legal_doc_url VARCHAR(500) DEFAULT NULL,

  -- Review workflow
  status          ENUM('pending','under_review','approved','rejected')
                  DEFAULT 'pending',
  rejection_reason TEXT       DEFAULT NULL,
  reviewed_by     VARCHAR(128) DEFAULT NULL COMMENT 'Admin Firebase UID',
  reviewed_at     TIMESTAMP    DEFAULT NULL,
  submitted_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_user   (user_id),
  INDEX idx_status (status, submitted_at),
  INDEX idx_type   (account_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5.  Blue-tick purchases
-- ============================================================

CREATE TABLE IF NOT EXISTS blue_tick_purchases (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id          VARCHAR(128) NOT NULL COMMENT 'Firebase UID',
  plan_id          INT          NOT NULL,

  payment_gateway  ENUM('razorpay','stripe','free') DEFAULT 'free',
  payment_id       VARCHAR(200) DEFAULT NULL,
  order_id         VARCHAR(200) DEFAULT NULL,
  amount           DECIMAL(10,2) DEFAULT 0.00,
  currency         VARCHAR(3)   DEFAULT 'INR',

  status           ENUM('pending','completed','failed','refunded')
                   DEFAULT 'pending',

  is_early_bird    TINYINT      DEFAULT 0,
  early_bird_slot  INT          DEFAULT NULL
                   COMMENT 'Sequential slot number within the early-bird window',

  purchased_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_user    (user_id),
  INDEX idx_status  (status),
  INDEX idx_plan    (plan_id),
  INDEX idx_gateway (payment_gateway, payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.  Blue-tick assignments (agency → reporter)
-- ============================================================

CREATE TABLE IF NOT EXISTS blue_tick_assignments (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  agency_id        VARCHAR(128) NOT NULL COMMENT 'Agency Firebase UID',
  reporter_id      VARCHAR(128) NOT NULL COMMENT 'Reporter Firebase UID',

  assignment_type  ENUM('free','paid') DEFAULT 'free',
  amount_charged   DECIMAL(10,2) DEFAULT 0.00,
  payment_id       VARCHAR(200)  DEFAULT NULL,

  status           ENUM('active','revoked') DEFAULT 'active',
  assigned_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  revoked_at       TIMESTAMP DEFAULT NULL,

  UNIQUE KEY uq_assign (agency_id, reporter_id),
  INDEX idx_agency   (agency_id),
  INDEX idx_reporter (reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7.  Agency ↔ Reporter relationships + revenue sharing
-- ============================================================

CREATE TABLE IF NOT EXISTS agency_reporters (
  id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
  agency_id             VARCHAR(128) NOT NULL COMMENT 'Agency Firebase UID',
  reporter_id           VARCHAR(128) NOT NULL COMMENT 'Reporter Firebase UID',

  join_type             ENUM('self_registered','agency_added')
                        DEFAULT 'self_registered',
  status                ENUM('active','removed') DEFAULT 'active',

  -- Revenue share: agency earns this % of the reporter's ad revenue
  revenue_share_percent DECIMAL(5,2) DEFAULT 10.00,

  joined_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  removed_at            TIMESTAMP DEFAULT NULL,
  removed_by            VARCHAR(128) DEFAULT NULL,

  UNIQUE KEY uq_relation (agency_id, reporter_id),
  INDEX idx_agency   (agency_id, status),
  INDEX idx_reporter (reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8.  Extend users table (blue-tick + verification columns)
-- ============================================================

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS account_type ENUM('user','reporter','agency')
    DEFAULT 'user'
    COMMENT 'Registered role for this Firebase user',
  ADD COLUMN IF NOT EXISTS is_blue_tick TINYINT DEFAULT 0
    COMMENT '1 = has active blue tick',
  ADD COLUMN IF NOT EXISTS blue_tick_type
    ENUM('free','paid','agency_assigned') DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS blue_tick_plan_id INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS blue_tick_granted_at TIMESTAMP DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS verification_status
    ENUM('unverified','pending','approved','rejected')
    DEFAULT 'unverified',
  ADD COLUMN IF NOT EXISTS profile_complete TINYINT DEFAULT 0
    COMMENT 'Boolean — all required profile fields filled',
  ADD INDEX IF NOT EXISTS idx_users_account_type (account_type),
  ADD INDEX IF NOT EXISTS idx_users_blue_tick    (is_blue_tick);

-- ============================================================
-- 9.  Extend user_profiles table (social / professional fields)
-- ============================================================

ALTER TABLE user_profiles
  ADD COLUMN IF NOT EXISTS website         VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS twitter         VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS instagram       VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS youtube         VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS location        VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS specialization  VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS years_experience TINYINT     DEFAULT 0,
  ADD COLUMN IF NOT EXISTS articles_count  INT          DEFAULT 0,
  ADD COLUMN IF NOT EXISTS followers_count INT          DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_views     BIGINT       DEFAULT 0;

-- ============================================================
-- 10.  Extend agency_revenue table (reporter-level splits)
--      Only executed if the table already exists (safe guard).
-- ============================================================

SET @tbl_exists := (
  SELECT COUNT(*)
  FROM   information_schema.TABLES
  WHERE  TABLE_SCHEMA = DATABASE()
    AND  TABLE_NAME   = 'agency_revenue'
);

SET @sql_rev1 := IF(
  @tbl_exists > 0,
  'ALTER TABLE agency_revenue
     ADD COLUMN IF NOT EXISTS reporter_id   VARCHAR(128) DEFAULT NULL,
     ADD COLUMN IF NOT EXISTS agency_cut    DECIMAL(10,4) DEFAULT 0
       COMMENT ''10% of reporter earnings goes to agency'',
     ADD COLUMN IF NOT EXISTS reporter_net  DECIMAL(10,4) DEFAULT 0
       COMMENT ''90% reporter retains after agency cut''',
  'SELECT "agency_revenue table not found – skipping"'
);
PREPARE stm_rev FROM @sql_rev1;
EXECUTE stm_rev;
DEALLOCATE PREPARE stm_rev;
