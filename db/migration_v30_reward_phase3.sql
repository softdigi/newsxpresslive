-- =============================================================================
-- migration_v30_reward_phase3.sql
--
-- Phase 3: Admin Panel schema additions for Reward System.
-- Adds missing columns to reward_withdrawals and creates correct
-- working schema for tables used by Phase 2 engines.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. reward_withdrawals — add missing columns (safe ALTER IGNORE)
-- ─────────────────────────────────────────────────────────────────────────────

-- Add transaction_ref (UTR/payout ID)
ALTER TABLE reward_withdrawals
    ADD COLUMN IF NOT EXISTS transaction_ref VARCHAR(100) NULL
                             AFTER processed_at;

-- Add updated_at
ALTER TABLE reward_withdrawals
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL
                             DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP
                             AFTER transaction_ref;

-- Extend status ENUM to include 'failed'
-- MySQL does not support IF NOT EXISTS for ENUM changes; guard with IGNORE
ALTER TABLE reward_withdrawals
    MODIFY COLUMN status
        ENUM('pending','processing','completed','rejected','failed')
        NOT NULL DEFAULT 'pending';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. user_reward_progress — drop migration schema, recreate as used by engine
--    (engine uses: user_id, wallet_type, totals, milestones, last_active_date)
-- ─────────────────────────────────────────────────────────────────────────────

-- Only run if old schema exists (has milestone_type column from v29)
-- We use a stored procedure trick to check-and-alter safely
DROP PROCEDURE IF EXISTS _fix_user_reward_progress;
DELIMITER //
CREATE PROCEDURE _fix_user_reward_progress()
BEGIN
    -- Check if the v29 (wrong) schema exists
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'user_reward_progress'
          AND COLUMN_NAME  = 'milestone_type'
    ) THEN
        DROP TABLE user_reward_progress;
        CREATE TABLE user_reward_progress (
            id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            user_id             VARCHAR(36)     NOT NULL COMMENT 'Firebase UID',
            wallet_type         ENUM('inr','coins') NOT NULL DEFAULT 'coins',
            total_active_days   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            total_articles_read INT UNSIGNED     NOT NULL DEFAULT 0,
            total_shares        INT UNSIGNED     NOT NULL DEFAULT 0,
            milestone_7day_done  TINYINT(1)      NOT NULL DEFAULT 0,
            milestone_7day_date  DATETIME        NULL,
            milestone_30day_done TINYINT(1)      NOT NULL DEFAULT 0,
            milestone_30day_date DATETIME        NULL,
            milestone_90day_done TINYINT(1)      NOT NULL DEFAULT 0,
            milestone_90day_date DATETIME        NULL,
            article_rewards_this_month DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reward_month        VARCHAR(7)       NULL COMMENT 'YYYY-MM',
            total_rewards_this_month DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            last_active_date    DATE             NULL,
            created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_id (user_id),
            KEY idx_last_active (last_active_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;
END//
DELIMITER ;
CALL _fix_user_reward_progress();
DROP PROCEDURE IF EXISTS _fix_user_reward_progress;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. user_daily_activity — add missing columns used by engine
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE user_daily_activity
    ADD COLUMN IF NOT EXISTS shares_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER shares_done,
    ADD COLUMN IF NOT EXISTS articles_published SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER shares_count;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. referral_fraud_flags — add columns used by referral_engine.php
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE referral_fraud_flags
    ADD COLUMN IF NOT EXISTS referrer_uid VARCHAR(36)  NULL   AFTER referral_id,
    ADD COLUMN IF NOT EXISTS referee_uid  VARCHAR(36)  NULL   AFTER referrer_uid,
    ADD COLUMN IF NOT EXISTS fraud_score  TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER referee_uid,
    ADD COLUMN IF NOT EXISTS fraud_flags  JSON         NULL   AFTER fraud_score,
    ADD COLUMN IF NOT EXISTS status       VARCHAR(30)  NOT NULL DEFAULT 'pending_review' AFTER fraud_flags;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. referral_codes — add columns used by referral_engine.php
-- ─────────────────────────────────────────────────────────────────────────────

-- Engine uses user_id and referral_code columns (not user_uid/code from v29)
ALTER TABLE referral_codes
    ADD COLUMN IF NOT EXISTS user_id             VARCHAR(36)  NULL   COMMENT 'alias for user_uid',
    ADD COLUMN IF NOT EXISTS referral_code       VARCHAR(16)  NULL   COMMENT 'alias for code',
    ADD COLUMN IF NOT EXISTS total_referrals     INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS successful_referrals INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP;

-- Copy existing data to alias columns
UPDATE referral_codes SET user_id = user_uid WHERE user_id IS NULL;
UPDATE referral_codes SET referral_code = code WHERE referral_code IS NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. referrals — add columns used by referral_engine.php
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE referrals
    ADD COLUMN IF NOT EXISTS referral_code_id   INT UNSIGNED NULL   AFTER referral_code,
    ADD COLUMN IF NOT EXISTS referee_ip_hash     VARCHAR(64)  NULL   AFTER ip_address,
    ADD COLUMN IF NOT EXISTS referee_country     VARCHAR(2)   NULL   AFTER country,
    ADD COLUMN IF NOT EXISTS fraud_flags         JSON         NULL   AFTER fraud_score,
    ADD COLUMN IF NOT EXISTS updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP;

-- Extend referrals.status ENUM
ALTER TABLE referrals
    MODIFY COLUMN status
        ENUM('pending','active','rewarded','fraudulent','blocked','flagged','reward_paid')
        NOT NULL DEFAULT 'pending';

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. reward_transactions — add columns used by engines
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE reward_transactions
    ADD COLUMN IF NOT EXISTS user_id               VARCHAR(36)  NULL   AFTER user_uid,
    ADD COLUMN IF NOT EXISTS wallet_type           ENUM('inr','coins') NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS transaction_type      VARCHAR(40)  NULL   AFTER wallet_type,
    ADD COLUMN IF NOT EXISTS reward_config_snapshot JSON        NULL   AFTER reference_id,
    ADD COLUMN IF NOT EXISTS status                VARCHAR(20)  NOT NULL DEFAULT 'completed' AFTER reward_config_snapshot;

-- Copy user_uid to user_id for existing rows
UPDATE reward_transactions SET user_id = user_uid WHERE user_id IS NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 8. inr_wallets — add missing columns
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE inr_wallets
    ADD COLUMN IF NOT EXISTS user_id              VARCHAR(36)  NULL   AFTER user_uid,
    ADD COLUMN IF NOT EXISTS total_earned         DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER balance,
    ADD COLUMN IF NOT EXISTS total_withdrawn      DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER total_earned,
    ADD COLUMN IF NOT EXISTS lifetime_share_earned DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER total_withdrawn;

UPDATE inr_wallets SET user_id = user_uid WHERE user_id IS NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 9. coins_wallets — add missing columns
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE coins_wallets
    ADD COLUMN IF NOT EXISTS user_id     VARCHAR(36)  NULL   AFTER user_uid,
    ADD COLUMN IF NOT EXISTS total_earned BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER balance;

UPDATE coins_wallets SET user_id = user_uid WHERE user_id IS NULL;
