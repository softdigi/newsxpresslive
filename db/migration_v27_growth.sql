-- ============================================================
-- db/migration_v27_growth.sql
-- v27: Growth Features – Elections, Audio Digests, Stories,
--      Fact Checks, and badge catalogue expansions.
-- Run once: mysql -u root -p newsxpresslive < migration_v27_growth.sql
-- ============================================================

-- ── Elections ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS elections (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(200) NOT NULL,
  name_hi       VARCHAR(200) NOT NULL,
  election_type ENUM('lok_sabha','vidhan_sabha','municipal','panchayat','bypolls') NOT NULL,
  state_id      INT NULL,
  election_date DATE NOT NULL,
  result_date   DATE NULL,
  status        ENUM('upcoming','voting','counting','declared') DEFAULT 'upcoming',
  is_active     TINYINT DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS election_results (
  id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
  election_id          INT NOT NULL,
  constituency_name    VARCHAR(200) NOT NULL,
  constituency_no      VARCHAR(20) NULL,
  state_id             INT NULL,
  district_id          INT NULL,
  winning_candidate    VARCHAR(200) NULL,
  winning_party        VARCHAR(200) NULL,
  winning_party_short  VARCHAR(20) NULL,
  winning_votes        INT DEFAULT 0,
  winning_margin       INT DEFAULT 0,
  runner_candidate     VARCHAR(200) NULL,
  runner_party         VARCHAR(200) NULL,
  runner_votes         INT DEFAULT 0,
  total_votes          INT DEFAULT 0,
  voter_turnout        DECIMAL(5,2) DEFAULT 0,
  result_status        ENUM('leading','won','counting') DEFAULT 'counting',
  updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_election    (election_id),
  INDEX idx_constituency (election_id, constituency_name),
  INDEX idx_district    (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS election_party_summary (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  election_id  INT NOT NULL,
  party_name   VARCHAR(200) NOT NULL,
  party_short  VARCHAR(20) NOT NULL,
  party_color  VARCHAR(7) DEFAULT '#333333',
  seats_won    INT DEFAULT 0,
  seats_leading INT DEFAULT 0,
  total_votes  BIGINT DEFAULT 0,
  vote_share   DECIMAL(5,2) DEFAULT 0,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_party (election_id, party_short)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Audio Digests ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audio_digests (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  digest_date      DATE NOT NULL UNIQUE,
  language_code    VARCHAR(10) DEFAULT 'hi',
  duration_seconds INT DEFAULT 0,
  file_url         VARCHAR(500) NOT NULL,
  article_ids      JSON NOT NULL,
  play_count       INT DEFAULT 0,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── News Stories (24-hour ephemeral stories) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS news_stories (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  reporter_uid     VARCHAR(128) NOT NULL,
  agency_id        INT NULL,
  story_type       ENUM('image','video','text') DEFAULT 'image',
  media_url        VARCHAR(500) NULL,
  thumbnail_url    VARCHAR(500) NULL,
  text_content     VARCHAR(500) NULL,
  background_color VARCHAR(7) DEFAULT '#1a1a2e',
  linked_article_id INT NULL,
  duration_seconds TINYINT DEFAULT 5,
  views_count      INT DEFAULT 0,
  expires_at       TIMESTAMP NOT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reporter (reporter_uid, expires_at),
  INDEX idx_expires  (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS story_views (
  story_id   BIGINT NOT NULL,
  viewer_uid VARCHAR(128) NOT NULL,
  viewed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (story_id, viewer_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Fact Checks ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fact_checks (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  article_id  INT NOT NULL,
  checked_by  VARCHAR(128) NOT NULL,
  verdict     ENUM('true','false','misleading','unverified','satire') NOT NULL,
  explanation TEXT NOT NULL,
  sources     JSON NULL,
  checked_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_article (article_id),
  INDEX idx_verdict (verdict)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Extend news table ─────────────────────────────────────────────────────────
ALTER TABLE news
  ADD COLUMN IF NOT EXISTS fact_check_verdict ENUM('true','false','misleading','unverified','satire') NULL,
  ADD COLUMN IF NOT EXISTS fact_check_id BIGINT NULL;

-- ── Badge catalogue additions ─────────────────────────────────────────────────
INSERT IGNORE INTO badges (slug, name, description, trigger_type, trigger_value) VALUES
  ('first_article', 'Pehli Khabar',    'First article published',             'early_bird',      1),
  ('views_1k',      '1K Views Club',   'Total views crossed 1,000',           'views_milestone', 1000),
  ('views_10k',     '10K Views Club',  'Total views crossed 10,000',          'views_milestone', 10000),
  ('views_100k',    'Lakh Club',       'Total views crossed 1,00,000',        'views_milestone', 100000),
  ('articles_10',   'Regular Reporter','10 articles published',               'early_bird',      10),
  ('articles_50',   'Senior Reporter', '50 articles published',               'early_bird',      50),
  ('articles_100',  'Star Reporter',   '100 articles published',              'early_bird',      100),
  ('blue_tick',     'Verified Reporter','Blue tick verified',                 'early_bird',      1),
  ('top_weekly',    'Weekly Champion', 'Ranked #1 on weekly leaderboard',     'leaderboard_top1',1),
  ('no_fake_news',  'Truth Warrior',   'Zero fake news strikes',              'early_bird',      1);
