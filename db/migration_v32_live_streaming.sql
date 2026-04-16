-- ============================================================
-- Migration v32: Live Streaming (Agora.io upgrade)
-- ============================================================
-- Run after: migration_v31_preference_system.sql
--
-- Adds Agora.io columns to existing live_streams table and
-- creates three new tables for viewer tracking, super chats,
-- and structured comments (replaces legacy live_chat for
-- reporter-owned streams).
-- ============================================================

-- ── Extend live_streams with Agora + revenue columns ─────────
-- Columns are added with IF NOT EXISTS guards so the migration
-- is safe to re-run or apply on top of v12.

ALTER TABLE `live_streams`
  ADD COLUMN IF NOT EXISTS `reporter_uid`             VARCHAR(128)         NULL    AFTER `reporter_id`,
  ADD COLUMN IF NOT EXISTS `agora_channel_name`        VARCHAR(200)         NULL    COMMENT 'Unique Agora channel identifier',
  ADD COLUMN IF NOT EXISTS `agora_token`               TEXT                 NULL    COMMENT 'Latest generated RTC token',
  ADD COLUMN IF NOT EXISTS `agora_token_expires`       TIMESTAMP            NULL    COMMENT 'Token expiry timestamp',
  ADD COLUMN IF NOT EXISTS `duration_seconds`          INT          NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `total_viewers`             INT          NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `total_comments`            INT          NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `total_super_chats`         INT          NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `total_super_chat_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `recording_url`             VARCHAR(500)         NULL,
  ADD COLUMN IF NOT EXISTS `is_recorded`               TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `platform_earnings`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `reporter_earnings`         DECIMAL(10,2) NOT NULL DEFAULT 0.00;

-- Add unique index on agora_channel_name (idempotent)
ALTER TABLE `live_streams`
  ADD UNIQUE KEY IF NOT EXISTS `uq_agora_channel` (`agora_channel_name`);

-- ── New: live_stream_viewers ──────────────────────────────────
-- Per-stream viewer session tracking (UID-based, replaces
-- anonymous live_viewers for authenticated streams).
CREATE TABLE IF NOT EXISTS `live_stream_viewers` (
  `stream_id`      BIGINT       NOT NULL,
  `user_uid`       VARCHAR(128) NOT NULL,
  `joined_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `left_at`        TIMESTAMP    NULL,
  `watch_seconds`  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`stream_id`, `user_uid`),
  INDEX `idx_stream` (`stream_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── New: live_super_chats ─────────────────────────────────────
-- Super-chat (paid highlight) payments during live streams.
-- Platform takes 30 %, reporter takes 70 %.
CREATE TABLE IF NOT EXISTS `live_super_chats` (
  `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
  `stream_id`       BIGINT        NOT NULL,
  `sender_uid`      VARCHAR(128)  NOT NULL,
  `amount`          DECIMAL(10,2) NOT NULL,
  `message`         VARCHAR(200)  NULL,
  `currency`        VARCHAR(3)    NOT NULL DEFAULT 'INR',
  `payment_id`      VARCHAR(200)  NULL     COMMENT 'Razorpay / gateway payment ID',
  `platform_cut`    DECIMAL(10,2) NOT NULL COMMENT '30 % of amount',
  `reporter_cut`    DECIMAL(10,2) NOT NULL COMMENT '70 % of amount',
  `status`          ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_stream` (`stream_id`),
  INDEX `idx_sender` (`sender_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── New: live_stream_comments ─────────────────────────────────
-- Authenticated comments tied to Firebase UIDs (distinguishes
-- super-chat highlights from regular chat).
CREATE TABLE IF NOT EXISTS `live_stream_comments` (
  `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
  `stream_id`       BIGINT        NOT NULL,
  `user_uid`        VARCHAR(128)  NOT NULL,
  `message`         VARCHAR(300)  NOT NULL,
  `is_super_chat`   TINYINT(1)    NOT NULL DEFAULT 0,
  `is_pinned`       TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_stream_ts` (`stream_id`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
