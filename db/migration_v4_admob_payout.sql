-- ============================================================
-- Migration v4 — AdMob Rate Tracking & Payout Error Logging
-- Run once against the newsxpresslive database.
-- ============================================================

-- 1. admob_rates
--    Stores daily CPM/CPC rates set by admin (used when direct
--    AdMob API integration is not available).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admob_rates (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    rate_date       DATE            NOT NULL,
    cpm_rate        DECIMAL(10,4)   NOT NULL DEFAULT 0.0000
                    COMMENT 'Cost per 1000 impressions in INR',
    cpc_rate        DECIMAL(10,4)   NOT NULL DEFAULT 0.0000
                    COMMENT 'Cost per click in INR',
    platform        VARCHAR(50)     NOT NULL DEFAULT 'admob'
                    COMMENT 'admob | manual | firebase',
    notes           TEXT            DEFAULT NULL,
    created_by      INT UNSIGNED    DEFAULT NULL
                    COMMENT 'Admin user_id who set this rate',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_rate_date_platform (rate_date, platform),
    INDEX  idx_rate_date             (rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. agency_payout_errors
--    Records any agency-level errors that occur during the
--    monthly automated payout run.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agency_payout_errors (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    payout_run_date DATE            NOT NULL
                    COMMENT 'The 1st-of-month date that triggered this run',
    agency_id       INT UNSIGNED    NOT NULL,
    error_code      VARCHAR(50)     DEFAULT NULL,
    error_message   TEXT            NOT NULL,
    context         JSON            DEFAULT NULL
                    COMMENT 'Arbitrary key/value payload for debugging',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ape_agency
        FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE CASCADE,

    INDEX idx_ape_run_date  (payout_run_date),
    INDEX idx_ape_agency    (agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
