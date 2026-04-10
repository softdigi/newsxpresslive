-- ============================================================
-- Migration v13: Multi-language support
-- NewsXpressLive
-- ============================================================

-- Supported languages master table
CREATE TABLE IF NOT EXISTS supported_languages (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(10)  NOT NULL UNIQUE,
  name        VARCHAR(100) NOT NULL,
  native_name VARCHAR(100) NOT NULL,
  script      ENUM('devanagari','latin','dravidian','other') DEFAULT 'devanagari',
  is_active   TINYINT      DEFAULT 1,
  sort_order  TINYINT      DEFAULT 0,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed 12 Indian languages
INSERT IGNORE INTO supported_languages (code, name, native_name, script, sort_order)
VALUES
  ('hi',  'Hindi',     'हिंदी',     'devanagari', 1),
  ('en',  'English',   'English',    'latin',      2),
  ('mr',  'Marathi',   'मराठी',     'devanagari', 3),
  ('gu',  'Gujarati',  'ગુજરાતી',   'other',      4),
  ('pa',  'Punjabi',   'ਪੰਜਾਬੀ',    'other',      5),
  ('bn',  'Bengali',   'বাংলা',      'other',      6),
  ('te',  'Telugu',    'తెలుగు',    'dravidian',  7),
  ('ta',  'Tamil',     'தமிழ்',     'dravidian',  8),
  ('kn',  'Kannada',   'ಕನ್ನಡ',     'dravidian',  9),
  ('ml',  'Malayalam', 'മലയാളം',    'dravidian',  10),
  ('or',  'Odia',      'ଓଡ଼ିଆ',      'other',      11),
  ('bho', 'Bhojpuri',  'भोजपुरी',   'devanagari', 12);

-- User language preferences (multi-select)
CREATE TABLE IF NOT EXISTS user_languages (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT          NOT NULL,
  language_code VARCHAR(10)  NOT NULL,
  is_primary    TINYINT      DEFAULT 0,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_lang (user_id, language_code),
  KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add language_code column to news (if not already present)
ALTER TABLE news
  ADD COLUMN IF NOT EXISTS language_code VARCHAR(10) DEFAULT 'hi';

-- Index for language-filtered feed queries
ALTER TABLE news
  ADD INDEX IF NOT EXISTS idx_language (language_code, status, created_at);
