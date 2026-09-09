-- ============================================================
-- Migration v5 — Geo Coordinates for Location-Based News Feed
-- Adds lat/lng to states and districts tables so that
-- web/api/local_news.php can use the Haversine formula to find
-- news articles near a user's GPS position.
--
-- Safe to re-run: all ALTER statements use IF NOT EXISTS.
-- MySQL 8.0+ required.
-- ============================================================

-- 1. states — add coordinate columns
ALTER TABLE states
    ADD COLUMN IF NOT EXISTS lat DECIMAL(10,6) DEFAULT NULL
        COMMENT 'Centroid latitude  (WGS-84)',
    ADD COLUMN IF NOT EXISTS lng DECIMAL(10,6) DEFAULT NULL
        COMMENT 'Centroid longitude (WGS-84)';

CREATE INDEX IF NOT EXISTS idx_states_lat_lng ON states (lat, lng);

-- 2. districts — add coordinate columns
ALTER TABLE districts
    ADD COLUMN IF NOT EXISTS lat DECIMAL(10,6) DEFAULT NULL
        COMMENT 'Centroid latitude  (WGS-84)',
    ADD COLUMN IF NOT EXISTS lng DECIMAL(10,6) DEFAULT NULL
        COMMENT 'Centroid longitude (WGS-84)';

CREATE INDEX IF NOT EXISTS idx_districts_lat_lng ON districts (lat, lng);

-- 3. Ensure news table has the location foreign-key and slug columns
--    that local_news.php and web/location/index.php rely on.
ALTER TABLE news
    ADD COLUMN IF NOT EXISTS state_id    INT UNSIGNED DEFAULT NULL
        COMMENT 'FK → states.id',
    ADD COLUMN IF NOT EXISTS district_id INT UNSIGNED DEFAULT NULL
        COMMENT 'FK → districts.id',
    ADD COLUMN IF NOT EXISTS city_id     INT UNSIGNED DEFAULT NULL
        COMMENT 'FK → cities.id',
    ADD COLUMN IF NOT EXISTS state_slug  VARCHAR(200) DEFAULT NULL
        COMMENT 'Denormalised slug for fast slug-based location queries',
    ADD COLUMN IF NOT EXISTS city_slug   VARCHAR(200) DEFAULT NULL
        COMMENT 'Denormalised slug for fast slug-based location queries';

CREATE INDEX IF NOT EXISTS idx_news_state_id    ON news (state_id);
CREATE INDEX IF NOT EXISTS idx_news_district_id ON news (district_id);
CREATE INDEX IF NOT EXISTS idx_news_state_slug  ON news (state_slug);
CREATE INDEX IF NOT EXISTS idx_news_city_slug   ON news (city_slug);

-- ============================================================
-- END OF MIGRATION v5
-- Verify with:
--   SHOW COLUMNS FROM states   LIKE 'lat';
--   SHOW COLUMNS FROM districts LIKE 'lng';
--   SHOW COLUMNS FROM news      LIKE 'state_id';
-- ============================================================
