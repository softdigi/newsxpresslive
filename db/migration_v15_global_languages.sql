-- ============================================================
-- Migration v15: Global language support + RTL
-- NewsXpressLive
-- ============================================================

-- Add text direction column (ltr / rtl)
ALTER TABLE supported_languages
  ADD COLUMN IF NOT EXISTS direction ENUM('ltr','rtl') DEFAULT 'ltr';

-- Extend supported_languages with global languages
INSERT IGNORE INTO supported_languages (code, name, native_name, script, sort_order, direction)
VALUES
  ('ur', 'Urdu',       'اردو',    'other', 13, 'rtl'),
  ('ar', 'Arabic',     'العربية', 'other', 14, 'rtl'),
  ('fa', 'Persian',    'فارسی',   'other', 15, 'rtl'),
  ('es', 'Spanish',    'Español', 'latin', 16, 'ltr'),
  ('fr', 'French',     'Français','latin', 17, 'ltr'),
  ('de', 'German',     'Deutsch', 'latin', 18, 'ltr'),
  ('pt', 'Portuguese', 'Português','latin',19, 'ltr'),
  ('ru', 'Russian',    'Русский', 'other', 20, 'ltr'),
  ('zh', 'Chinese',    '中文',    'other', 21, 'ltr'),
  ('ja', 'Japanese',   '日本語',  'other', 22, 'ltr'),
  ('ko', 'Korean',     '한국어',  'other', 23, 'ltr');

-- Ensure existing RTL Indian language (Urdu may already exist) is marked rtl
UPDATE supported_languages SET direction = 'rtl'
WHERE code IN ('ur', 'ar', 'fa');

-- Ensure all other existing languages default to ltr where not yet set
UPDATE supported_languages SET direction = 'ltr'
WHERE direction IS NULL OR direction = '';

-- Index on direction for potential RTL-specific queries
ALTER TABLE supported_languages
  ADD INDEX IF NOT EXISTS idx_direction (direction);
