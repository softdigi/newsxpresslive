-- ============================================================
-- Migration v12: Live News Streaming
-- ============================================================
-- Run after: migration_v11_listings.sql

-- ── Live Streams ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS live_streams (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  reporter_id    INT          NULL,
  agency_id      INT          NULL,
  title          VARCHAR(200) NOT NULL,
  description    TEXT         NULL,
  thumbnail      VARCHAR(500) NULL,
  stream_key     VARCHAR(100) NOT NULL UNIQUE,
  stream_url     VARCHAR(500) NULL COMMENT 'HLS .m3u8 URL or RTMP ingest URL',
  playback_url   VARCHAR(500) NULL COMMENT 'HLS playback URL for viewers',
  status         ENUM('scheduled','live','ended','cancelled') DEFAULT 'scheduled',
  category_id    INT          NULL COMMENT 'news category',
  scheduled_at   DATETIME     NULL,
  started_at     DATETIME     NULL,
  ended_at       DATETIME     NULL,
  viewer_count   INT          NOT NULL DEFAULT 0,
  peak_viewers   INT          NOT NULL DEFAULT 0,
  reaction_count INT          NOT NULL DEFAULT 0,
  is_featured    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status     (status),
  INDEX idx_reporter   (reporter_id),
  INDEX idx_agency     (agency_id),
  INDEX idx_scheduled  (scheduled_at),
  INDEX idx_featured   (is_featured)
);

-- ── Live Viewer Heartbeats ────────────────────────────────────
-- Each viewer pings every ~30 s while watching; rows expire via a cron job.
CREATE TABLE IF NOT EXISTS live_viewers (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  stream_id  BIGINT      NOT NULL,
  session_id VARCHAR(64) NOT NULL COMMENT 'client-generated UUID; no auth required',
  user_id    INT         NULL,
  ip_hash    VARCHAR(64) NOT NULL,
  last_ping  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session (stream_id, session_id),
  INDEX idx_stream  (stream_id),
  INDEX idx_ping    (last_ping)
);

-- ── Live Reactions ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS live_reactions (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  stream_id  BIGINT     NOT NULL,
  session_id VARCHAR(64) NOT NULL,
  emoji      VARCHAR(10) NOT NULL DEFAULT '❤️',
  created_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stream (stream_id),
  INDEX idx_ts     (created_at)
);

-- ── Stream Chat Messages ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS live_chat (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  stream_id  BIGINT       NOT NULL,
  session_id VARCHAR(64)  NOT NULL,
  user_id    INT          NULL,
  author     VARCHAR(100) NOT NULL DEFAULT 'Viewer',
  message    VARCHAR(500) NOT NULL,
  is_pinned  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stream (stream_id),
  INDEX idx_ts     (created_at)
);

-- ── Demo data ─────────────────────────────────────────────────
INSERT IGNORE INTO live_streams (title, description, stream_key, playback_url, status, is_featured, scheduled_at) VALUES
('Breaking: Live Coverage — Parliament Session',
 'Live coverage of the ongoing Parliament session.',
 'demo-key-parliament',
 'https://demo.newsxpresslive.in/live/parliament/index.m3u8',
 'scheduled',
 1,
 DATE_ADD(NOW(), INTERVAL 1 HOUR)),

('Exclusive Interview: Chief Minister Press Conference',
 'Chief Minister addresses the media on development projects.',
 'demo-key-cm-presser',
 'https://demo.newsxpresslive.in/live/cm-presser/index.m3u8',
 'scheduled',
 0,
 DATE_ADD(NOW(), INTERVAL 3 HOUR));
