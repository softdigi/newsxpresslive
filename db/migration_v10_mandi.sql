-- ============================================================
-- Migration v10: Mandi Bhav (Agricultural Market Rates)
-- ============================================================
-- Run after: migration_v9_complaints_enhanced.sql

-- ── Commodities ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS commodities (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  name_hi     VARCHAR(100) NOT NULL,
  category    ENUM('grain','vegetable','fruit','spice','oilseed','other') DEFAULT 'grain',
  unit        VARCHAR(20)  DEFAULT 'quintal',
  icon        VARCHAR(50)  NULL,
  msp         DECIMAL(10,2) NULL COMMENT 'Minimum Support Price per unit',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO commodities (name, name_hi, category, unit, msp) VALUES
('Wheat',      'गेहूं',    'grain',     'quintal', 2275.00),
('Rice',       'चावल',    'grain',     'quintal', 2183.00),
('Maize',      'मक्का',   'grain',     'quintal', 1962.00),
('Soybean',    'सोयाबीन', 'oilseed',   'quintal', 4600.00),
('Mustard',    'सरसों',   'oilseed',   'quintal', 5650.00),
('Potato',     'आलू',     'vegetable', 'quintal', NULL),
('Onion',      'प्याज',   'vegetable', 'quintal', NULL),
('Tomato',     'टमाटर',  'vegetable', 'quintal', NULL),
('Garlic',     'लहसुन',   'spice',     'quintal', NULL),
('Turmeric',   'हल्दी',   'spice',     'quintal', NULL),
('Cotton',     'कपास',    'other',     'quintal', 7020.00),
('Sugarcane',  'गन्ना',   'other',     'quintal', 315.00);

-- ── Mandis ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mandis (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(200) NOT NULL,
  name_hi     VARCHAR(200) NULL,
  state_id    INT NOT NULL,
  district_id INT NOT NULL,
  city        VARCHAR(100) NULL,
  latitude    DECIMAL(10,6) NULL,
  longitude   DECIMAL(10,6) NULL,
  is_active   TINYINT DEFAULT 1,
  INDEX idx_district (state_id, district_id),
  INDEX idx_location (latitude, longitude)
);

-- Sample mandis (Uttar Pradesh state_id=9 as per geo table convention)
INSERT INTO mandis (name, name_hi, state_id, district_id, city, latitude, longitude) VALUES
('Lucknow Mandi',    'लखनऊ मंडी',      9, 901, 'Lucknow',    26.846694, 80.946166),
('Kanpur Mandi',     'कानपुर मंडी',    9, 902, 'Kanpur',     26.449923, 80.331871),
('Agra Mandi',       'आगरा मंडी',      9, 903, 'Agra',       27.176670, 78.008072),
('Varanasi Mandi',   'वाराणसी मंडी',  9, 904, 'Varanasi',   25.317645, 83.010511),
('Allahabad Mandi',  'प्रयागराज मंडी', 9, 905, 'Prayagraj',  25.435700, 81.846310),
('Meerut Mandi',     'मेरठ मंडी',      9, 906, 'Meerut',     28.984456, 77.706413),
('Gorakhpur Mandi',  'गोरखपुर मंडी',  9, 907, 'Gorakhpur',  26.760587, 83.373161),
('Bareilly Mandi',   'बरेली मंडी',     9, 908, 'Bareilly',   28.367050, 79.415581),
('Aligarh Mandi',    'अलीगढ़ मंडी',   9, 909, 'Aligarh',    27.882420, 78.082060),
('Moradabad Mandi',  'मुरादाबाद मंडी', 9, 910, 'Moradabad',  28.838930, 78.776100);

-- ── Mandi Rates ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mandi_rates (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  mandi_id         INT NOT NULL,
  commodity_id     INT NOT NULL,
  rate_date        DATE NOT NULL,
  min_price        DECIMAL(10,2) NOT NULL,
  max_price        DECIMAL(10,2) NOT NULL,
  modal_price      DECIMAL(10,2) NOT NULL,
  arrivals_tonnes  DECIMAL(10,2) NULL,
  source           ENUM('manual','api','scraper') DEFAULT 'manual',
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rate (mandi_id, commodity_id, rate_date),
  INDEX idx_date      (rate_date DESC),
  INDEX idx_commodity (commodity_id, rate_date DESC),
  INDEX idx_mandi     (mandi_id, rate_date DESC),
  CONSTRAINT fk_mr_mandi     FOREIGN KEY (mandi_id)     REFERENCES mandis(id)      ON DELETE CASCADE,
  CONSTRAINT fk_mr_commodity FOREIGN KEY (commodity_id) REFERENCES commodities(id) ON DELETE CASCADE
);

-- ── Price Alerts ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mandi_alerts (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id          VARCHAR(128) NOT NULL,
  commodity_id     INT NOT NULL,
  mandi_id         INT NOT NULL,
  alert_type       ENUM('above','below') DEFAULT 'above',
  target_price     DECIMAL(10,2) NOT NULL,
  is_active        TINYINT DEFAULT 1,
  last_triggered   TIMESTAMP NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alert (user_id, commodity_id, mandi_id, alert_type),
  CONSTRAINT fk_ma_commodity FOREIGN KEY (commodity_id) REFERENCES commodities(id) ON DELETE CASCADE,
  CONSTRAINT fk_ma_mandi     FOREIGN KEY (mandi_id)     REFERENCES mandis(id)      ON DELETE CASCADE
);
