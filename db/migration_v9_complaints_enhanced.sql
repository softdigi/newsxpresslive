-- ============================================================
-- migration_v9_complaints_enhanced.sql
-- Enhanced Public Voice / Complaint System
-- Run once against the NewsXpressLive database.
-- ============================================================

-- ── 1. Extend complaint_categories ───────────────────────────────────────────
ALTER TABLE complaint_categories
  ADD COLUMN IF NOT EXISTS name_hi    VARCHAR(100) DEFAULT NULL AFTER name,
  ADD COLUMN IF NOT EXISTS department VARCHAR(100) DEFAULT NULL AFTER icon;

-- Update existing rows with Hindi names and departments
UPDATE complaint_categories SET name_hi='सड़क',       department='PWD'                  WHERE slug='roads';
UPDATE complaint_categories SET name_hi='पानी',       department='Jal Board'            WHERE slug='water';
UPDATE complaint_categories SET name_hi='बिजली',      department='Power Department'     WHERE slug='electricity';
UPDATE complaint_categories SET name_hi='सफाई',       department='Municipal Corporation' WHERE slug='sanitation';
UPDATE complaint_categories SET name_hi='सुरक्षा',    department='Police'               WHERE slug='safety';
UPDATE complaint_categories SET name_hi='सरकारी',     department='Government'           WHERE slug='government';
UPDATE complaint_categories SET name_hi='शिक्षा',     department='Education Department' WHERE slug='education';
UPDATE complaint_categories SET name_hi='स्वास्थ्य',  department='Health Department'    WHERE slug='health';
UPDATE complaint_categories SET name_hi='परिवहन',     department='Transport Department' WHERE slug='transport';
UPDATE complaint_categories SET name_hi='अन्य',       department='General'              WHERE slug='other';

-- Insert new categories that are in the spec but not yet seeded
INSERT IGNORE INTO complaint_categories (name, name_hi, slug, icon, department, sort_order) VALUES
  ('Street Lights', 'स्ट्रीट लाइट', 'street_lights', 'light',              'Electricity Board',   11),
  ('Healthcare',    'स्वास्थ्य',     'healthcare',    'health_and_safety',  'Health Department',   12);

-- ── 2. Extend complaints table ────────────────────────────────────────────────
-- Add columns that the enhanced system needs while keeping old columns intact
-- so the existing complaint_submit.php / complaints.php APIs keep working.

ALTER TABLE complaints
  -- Authenticated submitter (Firebase UID) — mirrors existing firebase_uid
  ADD COLUMN IF NOT EXISTS user_id           VARCHAR(128) DEFAULT NULL   AFTER id,
  -- Multi-image support (JSON array of URLs)
  ADD COLUMN IF NOT EXISTS images            JSON         DEFAULT NULL   AFTER photo,
  -- Optional video
  ADD COLUMN IF NOT EXISTS video_url         VARCHAR(500) DEFAULT NULL   AFTER images,
  -- GPS
  ADD COLUMN IF NOT EXISTS latitude          DECIMAL(10,6) DEFAULT NULL  AFTER video_url,
  ADD COLUMN IF NOT EXISTS longitude         DECIMAL(10,6) DEFAULT NULL  AFTER latitude,
  ADD COLUMN IF NOT EXISTS address           TEXT          DEFAULT NULL   AFTER longitude,
  ADD COLUMN IF NOT EXISTS city              VARCHAR(100)  DEFAULT NULL   AFTER address,
  ADD COLUMN IF NOT EXISTS pincode           VARCHAR(10)   DEFAULT NULL   AFTER city,
  -- Engagement counters
  ADD COLUMN IF NOT EXISTS upvotes_count     INT           DEFAULT 0     AFTER votes_count,
  ADD COLUMN IF NOT EXISTS comments_count    INT           DEFAULT 0     AFTER upvotes_count,
  ADD COLUMN IF NOT EXISTS views_count       INT           DEFAULT 0     AFTER comments_count,
  ADD COLUMN IF NOT EXISTS shares_count      INT           DEFAULT 0     AFTER views_count,
  ADD COLUMN IF NOT EXISTS is_viral          TINYINT       DEFAULT 0     AFTER shares_count,
  -- Admin workflow
  ADD COLUMN IF NOT EXISTS assigned_to       VARCHAR(100)  DEFAULT NULL  AFTER admin_note,
  ADD COLUMN IF NOT EXISTS admin_response    TEXT          DEFAULT NULL  AFTER assigned_to,
  ADD COLUMN IF NOT EXISTS resolved_at       TIMESTAMP     DEFAULT NULL  AFTER admin_response,
  ADD COLUMN IF NOT EXISTS rejection_reason  VARCHAR(500)  DEFAULT NULL  AFTER resolved_at,
  -- Flags
  ADD COLUMN IF NOT EXISTS is_verified_location TINYINT   DEFAULT 0     AFTER is_anonymous;

-- Backfill user_id from firebase_uid for existing rows
UPDATE complaints SET user_id = firebase_uid WHERE user_id IS NULL AND firebase_uid IS NOT NULL;

-- Extend status ENUM to include the new statuses (keeps old ones)
-- MySQL requires a full MODIFY COLUMN to change ENUM values
ALTER TABLE complaints
  MODIFY COLUMN status ENUM(
    'pending',
    'approved',
    'acknowledged',
    'under_review',
    'in_progress',
    'resolved',
    'rejected'
  ) NOT NULL DEFAULT 'pending';

-- Add performance indexes
ALTER TABLE complaints
  ADD INDEX IF NOT EXISTS idx_viral    (is_viral, upvotes_count),
  ADD INDEX IF NOT EXISTS idx_location (latitude, longitude),
  ADD INDEX IF NOT EXISTS idx_user_id  (user_id);

-- ── 3. Complaint upvotes (per Firebase user, not per IP) ─────────────────────
CREATE TABLE IF NOT EXISTS complaint_upvotes (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id      VARCHAR(128) NOT NULL,
  complaint_id BIGINT       NOT NULL,
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_upvote (user_id, complaint_id),
  INDEX  idx_complaint (complaint_id),
  FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Complaint comments ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS complaint_comments (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT       NOT NULL,
  user_id      VARCHAR(128) NOT NULL,
  comment      TEXT         NOT NULL,
  is_official  TINYINT      DEFAULT 0,
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_complaint (complaint_id),
  FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Complaint status timeline ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS complaint_updates (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  complaint_id BIGINT        NOT NULL,
  old_status   VARCHAR(50)   DEFAULT NULL,
  new_status   VARCHAR(50)   DEFAULT NULL,
  update_note  TEXT          DEFAULT NULL,
  updated_by   VARCHAR(128)  DEFAULT NULL,
  created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_complaint (complaint_id),
  FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Viral trigger ──────────────────────────────────────────────────────────
-- When a new upvote is inserted via complaint_upvotes, increment
-- complaints.upvotes_count and set is_viral = 1 when 50+ upvotes.

DROP TRIGGER IF EXISTS after_complaint_upvote_insert;

DELIMITER $$

CREATE TRIGGER after_complaint_upvote_insert
AFTER INSERT ON complaint_upvotes
FOR EACH ROW
BEGIN
  UPDATE complaints
  SET    upvotes_count = upvotes_count + 1,
         is_viral      = IF(upvotes_count + 1 >= 50, 1, 0)
  WHERE  id = NEW.complaint_id;
END$$

-- When an upvote is removed, decrement count and clear viral if below threshold.
DROP TRIGGER IF EXISTS after_complaint_upvote_delete$$

CREATE TRIGGER after_complaint_upvote_delete
AFTER DELETE ON complaint_upvotes
FOR EACH ROW
BEGIN
  UPDATE complaints
  SET    upvotes_count = GREATEST(upvotes_count - 1, 0),
         is_viral      = IF(GREATEST(upvotes_count - 1, 0) >= 50, 1, 0)
  WHERE  id = OLD.complaint_id;
END$$

DELIMITER ;
