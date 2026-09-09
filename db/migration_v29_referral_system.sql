-- =============================================================================
-- migration_v29_referral_system.sql
--
-- Smart Referral Reward System — Phase 1 Foundation
-- Saare tables aur default reward_config values.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. reward_config  — admin-configurable reward settings
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reward_config (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    config_key   VARCHAR(80)     NOT NULL,
    config_value VARCHAR(255)    NOT NULL,
    value_type   ENUM('inr','coins','percent','days','count','bool')
                                 NOT NULL DEFAULT 'count',
    config_group VARCHAR(40)     NOT NULL DEFAULT 'general',
    description  VARCHAR(255)    NULL,
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. reward_budget_tracking  — daily/monthly budget spend track
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reward_budget_tracking (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    period_type  ENUM('daily','monthly') NOT NULL,
    period_key   VARCHAR(10)     NOT NULL COMMENT 'YYYY-MM-DD or YYYY-MM',
    amount_spent DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_period (period_type, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. reward_config_audit  — config change history
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reward_config_audit (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    config_key  VARCHAR(80)  NOT NULL,
    old_value   VARCHAR(255) NOT NULL,
    new_value   VARCHAR(255) NOT NULL,
    changed_by  VARCHAR(36)  NOT NULL COMMENT 'admin uid',
    reason      TEXT         NULL,
    changed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_config_key (config_key),
    KEY idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. referral_codes  — har user ka unique referral code
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS referral_codes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_uid     VARCHAR(36)  NOT NULL,
    user_type    ENUM('reader','reporter') NOT NULL DEFAULT 'reader',
    code         VARCHAR(16)  NOT NULL,
    total_uses   INT UNSIGNED NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_uid  (user_uid),
    UNIQUE KEY uq_code      (code),
    KEY idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. referrals  — referrer-referee relationships
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS referrals (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    referrer_uid       VARCHAR(36)  NOT NULL,
    referee_uid        VARCHAR(36)  NOT NULL,
    referral_code      VARCHAR(16)  NOT NULL,
    device_fingerprint VARCHAR(64)  NULL,
    ip_address         VARCHAR(45)  NULL,
    country            VARCHAR(2)   NULL,
    status             ENUM('pending','active','rewarded','fraudulent') NOT NULL DEFAULT 'pending',
    fraud_score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activated_at       DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_referee (referee_uid),
    KEY idx_referrer   (referrer_uid),
    KEY idx_status     (status),
    KEY idx_device     (device_fingerprint),
    KEY idx_ip         (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. user_reward_progress  — milestone progress tracking
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_reward_progress (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_uid        VARCHAR(36)  NOT NULL,
    milestone_type  ENUM('7day','30day','90day') NOT NULL,
    start_date      DATE         NOT NULL,
    active_days     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    articles_read   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    shares_done     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_completed    TINYINT(1)   NOT NULL DEFAULT 0,
    completed_at    DATETIME     NULL,
    reward_issued   TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_milestone (user_uid, milestone_type),
    KEY idx_is_completed (is_completed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. reward_transactions  — har reward ka ledger entry
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reward_transactions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_uid        VARCHAR(36)     NOT NULL,
    reward_type     ENUM('milestone_7day','milestone_30day','milestone_90day',
                         'referral_bonus','article_read','lifetime_share',
                         'withdrawal','adjustment') NOT NULL,
    currency        ENUM('INR','COINS') NOT NULL,
    amount          DECIMAL(12,4)   NOT NULL,
    direction       ENUM('credit','debit') NOT NULL,
    reference_id    VARCHAR(64)     NULL COMMENT 'referral id / article id etc',
    note            VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_uid    (user_uid),
    KEY idx_reward_type (reward_type),
    KEY idx_created_at  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 8. inr_wallets  — India users ka INR wallet
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inr_wallets (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uid        VARCHAR(36)     NOT NULL,
    balance         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    lifetime_earned DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    total_withdrawn DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_uid (user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 9. coins_wallets  — Global users ka Coins wallet
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS coins_wallets (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uid        VARCHAR(36)     NOT NULL,
    balance         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lifetime_earned BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_redeemed  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_uid (user_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 10. user_daily_activity  — daily articles/shares/minutes tracking
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_daily_activity (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uid         VARCHAR(36)     NOT NULL,
    activity_date    DATE            NOT NULL,
    articles_read    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    shares_done      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    minutes_active   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_date (user_uid, activity_date),
    KEY idx_activity_date (activity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 11. reward_withdrawals  — withdrawal requests
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reward_withdrawals (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uid        VARCHAR(36)     NOT NULL,
    amount_inr      DECIMAL(12,2)   NOT NULL,
    upi_id          VARCHAR(100)    NOT NULL,
    status          ENUM('pending','processing','completed','rejected') NOT NULL DEFAULT 'pending',
    admin_note      VARCHAR(255)    NULL,
    requested_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at    DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_user_uid (user_uid),
    KEY idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 12. referral_fraud_flags  — fraud detection flags per referral
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS referral_fraud_flags (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id  INT UNSIGNED NOT NULL,
    flag_type    VARCHAR(40)  NOT NULL COMMENT 'vpn_detected, same_ip, same_device, rapid_install, country_mismatch',
    score_delta  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    detail       VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_referral_id (referral_id),
    KEY idx_flag_type   (flag_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 13. referral_fraud_reviews  — manual review tracking
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS referral_fraud_reviews (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id  INT UNSIGNED NOT NULL,
    reviewed_by  VARCHAR(36)  NOT NULL COMMENT 'admin uid',
    decision     ENUM('clear','confirmed_fraud','escalate') NOT NULL,
    notes        TEXT         NULL,
    reviewed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_referral_id (referral_id),
    KEY idx_reviewed_at (reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- DEFAULT reward_config values (32 rows)
-- INSERT IGNORE — safe to re-run
-- =============================================================================

INSERT IGNORE INTO reward_config
    (config_key, config_value, value_type, config_group, description) VALUES

-- INR Rewards
('reward_7day_inr',             '2.00',  'inr',     'inr_rewards',   '7-day milestone INR reward'),
('reward_30day_inr',            '5.00',  'inr',     'inr_rewards',   '30-day milestone INR reward'),
('reward_90day_inr',            '15.00', 'inr',     'inr_rewards',   '90-day milestone INR reward'),
('reward_article_inr',          '5.00',  'inr',     'inr_rewards',   'Per article read INR reward'),
('reward_article_monthly_cap',  '50.00', 'inr',     'inr_rewards',   'Monthly cap on article rewards'),
('reward_referral_inr',         '3.00',  'inr',     'inr_rewards',   'Referral bonus INR'),
('lifetime_share_percent',      '2.00',  'percent', 'inr_rewards',   'Lifetime share % of referee earnings'),
('lifetime_share_months',       '12',    'count',   'inr_rewards',   'Months to pay lifetime share'),

-- Coins Rewards
('reward_7day_coins',           '15',    'coins',   'coins_rewards', '7-day milestone coins reward'),
('reward_30day_coins',          '35',    'coins',   'coins_rewards', '30-day milestone coins reward'),
('reward_90day_coins',          '100',   'coins',   'coins_rewards', '90-day milestone coins reward'),
('reward_referral_coins',       '10',    'coins',   'coins_rewards', 'Referral bonus coins'),
('coin_inr_value',              '0.05',  'inr',     'coins_rewards', 'INR value of 1 coin'),

-- Activity Requirements
('req_7day_min_days',           '7',     'days',    'requirements',  'Min days active for 7-day milestone'),
('req_7day_min_articles',       '21',    'count',   'requirements',  'Min articles for 7-day milestone'),
('req_7day_min_shares',         '1',     'count',   'requirements',  'Min shares for 7-day milestone'),
('req_30day_min_active',        '20',    'days',    'requirements',  'Min active days for 30-day milestone'),
('req_30day_min_articles',      '50',    'count',   'requirements',  'Min articles for 30-day milestone'),
('req_90day_min_active',        '60',    'days',    'requirements',  'Min active days for 90-day milestone'),

-- Budget Controls
('monthly_reward_budget',       '20000.00', 'inr',  'budget',        'Total monthly INR reward budget'),
('daily_reward_budget',         '2000.00',  'inr',  'budget',        'Daily INR reward budget'),
('per_user_monthly_cap',        '50.00',    'inr',  'budget',        'Per-user monthly INR cap'),

-- Fraud Controls
('fraud_block_threshold',       '70',    'count',   'fraud',         'Score threshold to block referral'),
('fraud_flag_threshold',        '40',    'count',   'fraud',         'Score threshold to flag for review'),
('max_installs_per_ip_daily',   '3',     'count',   'fraud',         'Max installs from same IP per day'),
('rapid_install_threshold',     '5',     'count',   'fraud',         'Max installs per referrer per hour'),

-- Withdrawal
('min_withdrawal_inr',          '100.00', 'inr',    'withdrawal',    'Minimum withdrawal amount in INR'),
('min_withdrawal_delay_days',   '7',      'days',   'withdrawal',    'Min days before first withdrawal'),
('large_withdrawal_threshold',  '500.00', 'inr',    'withdrawal',    'Amount requiring extra review'),

-- Feature Toggles
('referral_system_active',      '1',     'bool',    'features',      'Master toggle for referral system'),
('milestone_rewards_active',    '1',     'bool',    'features',      'Toggle milestone rewards'),
('article_rewards_active',      '1',     'bool',    'features',      'Toggle article read rewards');
