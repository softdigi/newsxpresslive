-- ============================================================
-- Migration v11: Classified & Marketplace (OLX + JustDial style)
-- ============================================================
-- Run after: migration_v10_mandi.sql

-- ── Listing Categories ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS listing_categories (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  parent_id    INT NULL,
  name         VARCHAR(100) NOT NULL,
  name_hi      VARCHAR(100) NULL,
  icon         VARCHAR(50)  NULL,
  listing_type ENUM('sell','service','rent','wanted') DEFAULT 'sell',
  sort_order   INT DEFAULT 0,
  INDEX idx_parent (parent_id)
);

INSERT INTO listing_categories (name, name_hi, icon, listing_type, sort_order) VALUES
('Property',     'संपत्ति',         'home',           'sell',    1),
('Vehicles',     'वाहन',            'directions_car', 'sell',    2),
('Electronics',  'इलेक्ट्रॉनिक्स', 'devices',        'sell',    3),
('Furniture',    'फर्नीचर',         'chair',          'sell',    4),
('Services',     'सेवाएं',          'handyman',       'service', 5),
('Agriculture',  'कृषि',            'agriculture',    'sell',    6),
('Education',    'शिक्षा',          'school',         'service', 7),
('Wanted',       'चाहिए',           'search',         'wanted',  8);

-- ── Listings ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS listings (
  id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id            VARCHAR(128) NOT NULL,
  category_id        INT NOT NULL,

  title              VARCHAR(200) NOT NULL,
  description        TEXT         NOT NULL,
  images             JSON         NULL      COMMENT 'Array of image paths/URLs',

  listing_type       ENUM('sell','service','rent','wanted') DEFAULT 'sell',
  price              DECIMAL(12,2) NULL,
  price_negotiable   TINYINT DEFAULT 0,
  price_type         ENUM('fixed','per_day','per_month','per_hour','free') DEFAULT 'fixed',

  -- Location
  state_id           INT NULL,
  district_id        INT NULL,
  city               VARCHAR(100) NULL,
  pincode            VARCHAR(10)  NULL,
  latitude           DECIMAL(10,6) NULL,
  longitude          DECIMAL(10,6) NULL,

  -- Contact
  contact_name       VARCHAR(200) NULL,
  contact_phone      VARCHAR(15)  NULL,
  show_phone         TINYINT DEFAULT 1,
  contact_whatsapp   VARCHAR(15)  NULL,

  -- Metadata
  status             ENUM('active','sold','expired','pending','rejected') DEFAULT 'pending',
  is_featured        TINYINT DEFAULT 0,
  views_count        INT DEFAULT 0,
  saves_count        INT DEFAULT 0,
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at         TIMESTAMP NULL,

  INDEX idx_category (category_id, status),
  INDEX idx_location (state_id, district_id),
  INDEX idx_user     (user_id),
  INDEX idx_featured (is_featured, created_at DESC),
  INDEX idx_status   (status, created_at DESC),
  CONSTRAINT fk_l_category FOREIGN KEY (category_id)
    REFERENCES listing_categories(id) ON DELETE RESTRICT
);

-- ── Listing Saves (bookmarks) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS listing_saves (
  user_id    VARCHAR(128) NOT NULL,
  listing_id BIGINT       NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, listing_id),
  CONSTRAINT fk_ls_listing FOREIGN KEY (listing_id)
    REFERENCES listings(id) ON DELETE CASCADE
);

-- ── Listing Inquiries ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS listing_inquiries (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  listing_id    BIGINT       NOT NULL,
  inquirer_uid  VARCHAR(128) NOT NULL,
  message       TEXT         NOT NULL,
  contact_phone VARCHAR(15)  NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_li_listing FOREIGN KEY (listing_id)
    REFERENCES listings(id) ON DELETE CASCADE,
  INDEX idx_listing (listing_id),
  INDEX idx_inquirer (inquirer_uid)
);
