-- ============================================================
-- NewsXpressLive  —  Migration v3: News Agency Partner Feature
-- ============================================================
-- Adds full "News Agency Partner" support:
--   • Agency accounts with API key access
--   • Bulk article submission (API + CSV)
--   • AdMob revenue sharing (default 40% agency / 60% platform)
--   • Wallet, withdrawal, and transaction ledger
--
-- Prerequisites: migrations.sql + migration_v2.sql already applied.
-- MySQL 8.0+ required (IF NOT EXISTS on ALTER TABLE).
-- Safe to re-run: all CREATE TABLE / ALTER TABLE use IF NOT EXISTS.
-- Run as: mysql -u <user> -p <db_name> < migration_v3_agency_partner.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ============================================================
-- PART 1  —  New Tables
-- ============================================================

-- ------------------------------------------------------------
-- 1.1  agencies
--      Core account for each news agency partner.
--      api_key  : UUID used as the public REST identifier.
--      api_secret: bcrypt/sha256 hash — never stored in plain text.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agencies (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(255)    NOT NULL,
    email                   VARCHAR(255)    NOT NULL,
    phone                   VARCHAR(30)     DEFAULT NULL,
    logo_url                VARCHAR(500)    DEFAULT NULL,

    -- REST API credentials
    api_key                 CHAR(36)        NOT NULL COMMENT 'UUID v4',
    api_secret              VARCHAR(255)    NOT NULL COMMENT 'Hashed secret',

    status                  ENUM('pending','active','suspended')
                                            NOT NULL DEFAULT 'pending',

    -- Revenue
    revenue_share_percent   DECIMAL(5,2)    NOT NULL DEFAULT 40.00
                                            COMMENT 'Agency cut out of gross AdMob revenue',
    wallet_balance          DECIMAL(10,2)   NOT NULL DEFAULT 0.00
                                            COMMENT 'Withdrawable balance (INR)',
    total_earned            DECIMAL(10,2)   NOT NULL DEFAULT 0.00
                                            COMMENT 'Lifetime earnings',

    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_agency_email   (email),
    UNIQUE KEY uq_agency_api_key (api_key),
    INDEX      idx_agency_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered news agency partner accounts';


-- ------------------------------------------------------------
-- 1.2  agency_articles
--      Links an agency submission to a row in the news table.
--      external_id  : agency's own article identifier —
--                     (external_id, agency_id) UNIQUE prevents
--                     duplicate imports across uploads.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agency_articles (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    agency_id               INT UNSIGNED    NOT NULL,
    news_id                 INT UNSIGNED    DEFAULT NULL
                                            COMMENT 'NULL until approved & published',
    source_url              VARCHAR(1000)   DEFAULT NULL
                                            COMMENT 'Original article URL at the agency',
    external_id             VARCHAR(255)    DEFAULT NULL
                                            COMMENT 'Agency\'s own article ID',
    submitted_via           ENUM('api','csv_upload')
                                            NOT NULL DEFAULT 'api',
    status                  ENUM('pending','approved','rejected')
                                            NOT NULL DEFAULT 'pending',
    rejection_reason        TEXT            DEFAULT NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_aa_agency
        FOREIGN KEY (agency_id) REFERENCES agencies(id)    ON DELETE CASCADE,
    CONSTRAINT fk_aa_news
        FOREIGN KEY (news_id)   REFERENCES news(id)        ON DELETE SET NULL,

    INDEX      idx_aa_agency_status  (agency_id, status),
    -- UNIQUE on (external_id, agency_id): added in PART 3 via CREATE UNIQUE INDEX
    -- because CREATE TABLE UNIQUE KEY can't use IF NOT EXISTS guard.
    INDEX      idx_aa_external       (external_id, agency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Agency article submissions linked to published news rows';


-- ------------------------------------------------------------
-- 1.3  agency_revenue
--      Daily revenue record per (agency, article).
--      cpm_rate / cpc_rate are copied from the AdMob snapshot for
--      that day so historical records remain accurate even if rates change.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agency_revenue (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    agency_id               INT UNSIGNED    NOT NULL,
    news_id                 INT UNSIGNED    NOT NULL,
    date                    DATE            NOT NULL,

    -- AdMob metrics
    impressions             INT UNSIGNED    NOT NULL DEFAULT 0,
    clicks                  INT UNSIGNED    NOT NULL DEFAULT 0,
    cpm_rate                DECIMAL(6,4)    NOT NULL DEFAULT 0.0000
                                            COMMENT 'USD per 1 000 impressions that day',
    cpc_rate                DECIMAL(6,4)    NOT NULL DEFAULT 0.0000
                                            COMMENT 'USD per click that day',

    -- Calculated fields (populated by calculate_agency_revenue procedure)
    gross_revenue           DECIMAL(10,4)   NOT NULL DEFAULT 0.0000
                                            COMMENT '(impressions/1000)*cpm_rate + clicks*cpc_rate',
    agency_share            DECIMAL(10,4)   NOT NULL DEFAULT 0.0000
                                            COMMENT 'gross * agency.revenue_share_percent/100',
    platform_share          DECIMAL(10,4)   NOT NULL DEFAULT 0.0000
                                            COMMENT 'gross * (1 - revenue_share_percent/100)',

    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ar_agency
        FOREIGN KEY (agency_id) REFERENCES agencies(id)    ON DELETE CASCADE,
    CONSTRAINT fk_ar_news
        FOREIGN KEY (news_id)   REFERENCES news(id)        ON DELETE CASCADE,

    -- No UNIQUE KEY here — if a recalculation is needed, rows are
    -- replaced via INSERT … ON DUPLICATE KEY UPDATE (use idx below).
    INDEX idx_ar_agency_date   (agency_id, date),
    INDEX idx_ar_news_date     (news_id,   date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily AdMob revenue records broken down by agency and article';


-- ------------------------------------------------------------
-- 1.4  agency_transactions
--      Immutable ledger for every wallet movement.
--      balance_before + amount = balance_after is enforced by the
--      calculate_agency_revenue and process_monthly_payouts procedures.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agency_transactions (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    agency_id               INT UNSIGNED    NOT NULL,
    type                    ENUM('credit','withdrawal','adjustment')
                                            NOT NULL,
    amount                  DECIMAL(10,2)   NOT NULL
                                            COMMENT 'Positive for credit, negative for debit',
    balance_before          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    balance_after           DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    reference_id            INT UNSIGNED    DEFAULT NULL
                                            COMMENT 'agency_revenue.id or agency_withdrawals.id',
    note                    TEXT            DEFAULT NULL,
    status                  ENUM('pending','completed','failed')
                                            NOT NULL DEFAULT 'completed',
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_at_agency
        FOREIGN KEY (agency_id) REFERENCES agencies(id)    ON DELETE CASCADE,

    INDEX idx_at_agency_created (agency_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Wallet ledger — every credit, withdrawal, and adjustment';


-- ------------------------------------------------------------
-- 1.5  agency_withdrawals
--      Withdrawal requests. account_details stored as JSON so
--      the same table handles UPI, bank transfer, and PayPal.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agency_withdrawals (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    agency_id               INT UNSIGNED    NOT NULL,
    amount                  DECIMAL(10,2)   NOT NULL,
    method                  ENUM('upi','bank','paypal')
                                            NOT NULL,
    account_details         JSON            NOT NULL
                                            COMMENT 'e.g. {"upi_id":"name@upi"} or {"account_no":"...","ifsc":"...","name":"..."}',
    status                  ENUM('requested','processing','completed','failed')
                                            NOT NULL DEFAULT 'requested',
    requested_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at            DATETIME        DEFAULT NULL,
    completed_at            DATETIME        DEFAULT NULL,
    admin_note              TEXT            DEFAULT NULL,
    transaction_id          INT UNSIGNED    DEFAULT NULL
                                            COMMENT 'agency_transactions.id — set after processing',

    CONSTRAINT fk_aw_agency
        FOREIGN KEY (agency_id)      REFERENCES agencies(id)             ON DELETE CASCADE,
    CONSTRAINT fk_aw_transaction
        FOREIGN KEY (transaction_id) REFERENCES agency_transactions(id)  ON DELETE SET NULL,

    INDEX idx_aw_agency_status (agency_id, status),
    INDEX idx_aw_requested_at  (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Withdrawal requests from agency wallet';


-- ------------------------------------------------------------
-- 1.6  agency_bulk_uploads
--      Tracks each CSV / file upload batch.
--      error_log stores per-row failure details so the agency
--      can download and fix their submission.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agency_bulk_uploads (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    agency_id               INT UNSIGNED    NOT NULL,
    filename                VARCHAR(255)    NOT NULL,
    file_path               VARCHAR(500)    NOT NULL,
    total_rows              INT UNSIGNED    NOT NULL DEFAULT 0,
    success_count           INT UNSIGNED    NOT NULL DEFAULT 0,
    failed_count            INT UNSIGNED    NOT NULL DEFAULT 0,
    duplicate_count         INT UNSIGNED    NOT NULL DEFAULT 0,
    status                  ENUM('queued','processing','completed','failed')
                                            NOT NULL DEFAULT 'queued',
    error_log               JSON            DEFAULT NULL
                                            COMMENT '[{"row":3,"reason":"missing title"}, …]',
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at            DATETIME        DEFAULT NULL,

    CONSTRAINT fk_abu_agency
        FOREIGN KEY (agency_id) REFERENCES agencies(id)    ON DELETE CASCADE,

    INDEX idx_abu_agency_status (agency_id, status),
    INDEX idx_abu_created       (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CSV / bulk upload job tracking per agency';


-- ============================================================
-- PART 2  —  Modify Existing Tables
-- ============================================================

-- 2.1  news table — agency ownership columns
ALTER TABLE news
    ADD COLUMN IF NOT EXISTS agency_id          INT UNSIGNED    DEFAULT NULL
        COMMENT 'FK → agencies.id; NULL = reporter/admin article'
        AFTER id,
    ADD COLUMN IF NOT EXISTS is_agency_content  TINYINT(1)      NOT NULL DEFAULT 0
        COMMENT '1 when submitted by an agency partner'
        AFTER agency_id,
    ADD COLUMN IF NOT EXISTS total_impressions  INT UNSIGNED    NOT NULL DEFAULT 0
        COMMENT 'Cumulative AdMob impressions on this article'
        AFTER is_agency_content,
    ADD COLUMN IF NOT EXISTS total_clicks       INT UNSIGNED    NOT NULL DEFAULT 0
        COMMENT 'Cumulative AdMob clicks on this article'
        AFTER total_impressions,
    ADD COLUMN IF NOT EXISTS total_revenue      DECIMAL(10,4)   NOT NULL DEFAULT 0.0000
        COMMENT 'Cumulative gross AdMob revenue (USD) for this article'
        AFTER total_clicks;

-- 2.2  Add FK from news → agencies
--      Done separately after the column exists.
ALTER TABLE news
    ADD CONSTRAINT IF NOT EXISTS fk_news_agency
        FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE SET NULL;


-- ============================================================
-- PART 3  —  Performance Indexes
-- ============================================================

-- agency_revenue: (agency_id, date) and (news_id, date) already
-- defined inline in CREATE TABLE above.  Skip duplicates.

-- UNIQUE constraint on agency_articles.(external_id, agency_id)
-- Guards against duplicate imports from the same agency.
CREATE UNIQUE INDEX IF NOT EXISTS uq_aa_external_agency
    ON agency_articles (external_id, agency_id);

-- agency_transactions: (agency_id, created_at) — already in CREATE TABLE.

-- news: (agency_id, status, created_at)
--       Speeds up "agency content awaiting review" feed queries.
CREATE INDEX IF NOT EXISTS idx_news_agency_status_date
    ON news (agency_id, status, created_at);


-- ============================================================
-- PART 4  —  Stored Procedures
-- ============================================================

DELIMITER $$

-- ------------------------------------------------------------
-- 4.1  calculate_agency_revenue(p_date DATE)
--
--      For every agency article that was published and active on
--      p_date, this procedure:
--        a) Reads impressions & clicks from news (updated by the
--           AdMob webhook / cron that calls UPDATE news SET
--           total_impressions=?, total_clicks=? each day).
--        b) Uses the CPM / CPC rates passed in (or stored in a
--           settings table); falls back to 0 if not provided.
--        c) Writes / updates a row in agency_revenue.
--        d) Credits agency wallet and inserts a transaction record.
--
--      USAGE (run daily via cron):
--        CALL calculate_agency_revenue('2025-01-15');
--
--      Parameters:
--        p_date      DATE   — the settlement date (usually yesterday)
--        p_cpm_rate  DECIMAL(6,4)  — platform-wide CPM for that day (USD/1000 imp)
--        p_cpc_rate  DECIMAL(6,4)  — platform-wide CPC for that day (USD/click)
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS calculate_agency_revenue$$

CREATE PROCEDURE calculate_agency_revenue(
    IN p_date      DATE,
    IN p_cpm_rate  DECIMAL(6,4),
    IN p_cpc_rate  DECIMAL(6,4)
)
proc: BEGIN
    -- Cursor variables
    DECLARE v_done          INT DEFAULT 0;
    DECLARE v_agency_id     INT UNSIGNED;
    DECLARE v_news_id       INT UNSIGNED;
    DECLARE v_impressions   INT UNSIGNED;
    DECLARE v_clicks        INT UNSIGNED;
    DECLARE v_rev_pct       DECIMAL(5,2);

    -- Calculated
    DECLARE v_gross         DECIMAL(10,4);
    DECLARE v_agency_share  DECIMAL(10,4);
    DECLARE v_platform_share DECIMAL(10,4);
    DECLARE v_bal_before    DECIMAL(10,2);
    DECLARE v_bal_after     DECIMAL(10,2);
    DECLARE v_rev_id        INT UNSIGNED;

    -- Cursor: all active agency articles published on or before p_date
    DECLARE cur_articles CURSOR FOR
        SELECT  n.agency_id,
                n.id            AS news_id,
                n.total_impressions,
                n.total_clicks,
                ag.revenue_share_percent
        FROM    news n
        JOIN    agencies ag ON ag.id = n.agency_id
        WHERE   n.is_agency_content = 1
          AND   ag.status = 'active'
          AND   DATE(n.created_at) <= p_date
          -- Skip articles already settled on this date
          AND   NOT EXISTS (
                    SELECT 1
                    FROM   agency_revenue ar
                    WHERE  ar.news_id   = n.id
                      AND  ar.agency_id = n.agency_id
                      AND  ar.date      = p_date
                );

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    -- Validate inputs
    IF p_date IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'calculate_agency_revenue: p_date cannot be NULL';
    END IF;

    IF p_cpm_rate IS NULL THEN SET p_cpm_rate = 0.0000; END IF;
    IF p_cpc_rate IS NULL THEN SET p_cpc_rate = 0.0000; END IF;

    OPEN cur_articles;

    read_loop: LOOP
        FETCH cur_articles INTO
            v_agency_id, v_news_id, v_impressions, v_clicks, v_rev_pct;

        IF v_done = 1 THEN
            LEAVE read_loop;
        END IF;

        -- Calculate revenue
        SET v_gross          = (v_impressions / 1000.0) * p_cpm_rate
                               + v_clicks * p_cpc_rate;
        SET v_agency_share   = ROUND(v_gross * (v_rev_pct / 100.0), 4);
        SET v_platform_share = ROUND(v_gross - v_agency_share, 4);

        -- Skip zero-revenue rows (no traffic that day)
        IF v_gross = 0 THEN
            ITERATE read_loop;
        END IF;

        -- Insert revenue record
        INSERT INTO agency_revenue
            (agency_id, news_id, date,
             impressions, clicks,
             cpm_rate, cpc_rate,
             gross_revenue, agency_share, platform_share,
             created_at)
        VALUES
            (v_agency_id, v_news_id, p_date,
             v_impressions, v_clicks,
             p_cpm_rate, p_cpc_rate,
             v_gross, v_agency_share, v_platform_share,
             NOW());

        SET v_rev_id = LAST_INSERT_ID();

        -- Snapshot wallet balance before credit
        SELECT wallet_balance INTO v_bal_before
        FROM   agencies
        WHERE  id = v_agency_id
        FOR UPDATE;

        SET v_bal_after = v_bal_before + v_agency_share;

        -- Credit agency wallet
        UPDATE agencies
        SET    wallet_balance = v_bal_after,
               total_earned   = total_earned + v_agency_share,
               updated_at     = NOW()
        WHERE  id = v_agency_id;

        -- Ledger entry
        INSERT INTO agency_transactions
            (agency_id, type, amount,
             balance_before, balance_after,
             reference_id, note, status, created_at)
        VALUES
            (v_agency_id, 'credit', v_agency_share,
             v_bal_before, v_bal_after,
             v_rev_id,
             CONCAT('Revenue share for article #', v_news_id, ' on ', p_date),
             'completed', NOW());

    END LOOP;

    CLOSE cur_articles;
END$$


-- ------------------------------------------------------------
-- 4.2  process_monthly_payouts()
--
--      Intended to run on the 1st of each month (via MySQL Event
--      Scheduler or a cron calling CALL process_monthly_payouts()).
--
--      Logic:
--        a) Find all active agencies whose wallet_balance >= 500.
--        b) Auto-create an agency_withdrawals row (status='requested').
--        c) Deduct from wallet_balance immediately (balance reserved).
--        d) Insert a withdrawal transaction into agency_transactions.
--        e) Insert a row into email_queue so the notification system
--           sends a "payout initiated" email (table assumed to exist;
--           safe-guarded with IF EXISTS check via INSERT IGNORE).
--
--      Minimum payout threshold: ₹500 (adjust v_min_payout as needed).
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS process_monthly_payouts$$

CREATE PROCEDURE process_monthly_payouts()
proc: BEGIN
    DECLARE v_done          INT DEFAULT 0;
    DECLARE v_agency_id     INT UNSIGNED;
    DECLARE v_agency_email  VARCHAR(255);
    DECLARE v_agency_name   VARCHAR(255);
    DECLARE v_balance       DECIMAL(10,2);
    DECLARE v_payout_amt    DECIMAL(10,2);
    DECLARE v_bal_after     DECIMAL(10,2);
    DECLARE v_withdrawal_id INT UNSIGNED;
    DECLARE v_txn_id        INT UNSIGNED;

    DECLARE v_min_payout    DECIMAL(10,2) DEFAULT 500.00;

    -- Cursor: agencies eligible for payout
    DECLARE cur_agencies CURSOR FOR
        SELECT id, email, name, wallet_balance
        FROM   agencies
        WHERE  status         = 'active'
          AND  wallet_balance >= v_min_payout
        FOR UPDATE;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur_agencies;

    payout_loop: LOOP
        FETCH cur_agencies INTO
            v_agency_id, v_agency_email, v_agency_name, v_balance;

        IF v_done = 1 THEN
            LEAVE payout_loop;
        END IF;

        SET v_payout_amt = v_balance;   -- pay out full balance
        SET v_bal_after  = 0.00;

        -- 1. Create withdrawal request
        INSERT INTO agency_withdrawals
            (agency_id, amount, method,
             account_details, status, requested_at)
        VALUES
            (v_agency_id, v_payout_amt, 'bank',
             JSON_OBJECT(
                 'note', 'Auto-generated monthly payout',
                 'initiated_at', NOW()
             ),
             'requested', NOW());

        SET v_withdrawal_id = LAST_INSERT_ID();

        -- 2. Deduct from wallet (reserve funds)
        UPDATE agencies
        SET    wallet_balance = v_bal_after,
               updated_at     = NOW()
        WHERE  id = v_agency_id;

        -- 3. Ledger entry (withdrawal)
        INSERT INTO agency_transactions
            (agency_id, type, amount,
             balance_before, balance_after,
             reference_id, note, status, created_at)
        VALUES
            (v_agency_id, 'withdrawal', -v_payout_amt,
             v_balance, v_bal_after,
             v_withdrawal_id,
             CONCAT('Monthly auto-payout — ', DATE_FORMAT(NOW(), '%M %Y')),
             'pending', NOW());

        SET v_txn_id = LAST_INSERT_ID();

        -- 4. Link transaction back to withdrawal row
        UPDATE agency_withdrawals
        SET    transaction_id = v_txn_id
        WHERE  id = v_withdrawal_id;

        -- 5. Queue email notification
        --    (email_queue table must already exist — created in migrations.sql)
        INSERT IGNORE INTO email_queue
            (recipient_email, subject, body_html, status, created_at)
        VALUES (
            v_agency_email,
            CONCAT('NewsXpressLive — Monthly Payout of ₹', FORMAT(v_payout_amt, 2), ' Initiated'),
            CONCAT(
                '<p>Dear ', v_agency_name, ',</p>',
                '<p>Your monthly payout of <strong>₹', FORMAT(v_payout_amt, 2), '</strong>',
                ' has been initiated and is being processed.</p>',
                '<p>You will receive the funds within 3–5 business days.</p>',
                '<p>Reference ID: #', v_withdrawal_id, '</p>',
                '<p>— NewsXpressLive Team</p>'
            ),
            'queued',
            NOW()
        );

    END LOOP;

    CLOSE cur_agencies;
END$$

DELIMITER ;


-- ============================================================
-- PART 5  —  MySQL Event Scheduler (optional, enable if needed)
-- ============================================================

-- Enable the scheduler (one-time, requires SUPER privilege):
-- SET GLOBAL event_scheduler = ON;

-- Daily revenue calculation (runs every day at 02:00 AM UTC).
-- CPM and CPC rates below are placeholders — update via your
-- AdMob webhook or admin panel before enabling.
DROP EVENT IF EXISTS evt_daily_agency_revenue;
CREATE EVENT IF NOT EXISTS evt_daily_agency_revenue
    ON SCHEDULE EVERY 1 DAY
    STARTS (TIMESTAMP(CURDATE(), '02:00:00') + INTERVAL 1 DAY)
    DO
        CALL calculate_agency_revenue(
            CURDATE() - INTERVAL 1 DAY,
            0.5000,   -- replace with actual CPM fetched from AdMob API
            0.0500    -- replace with actual CPC fetched from AdMob API
        );

-- Monthly auto-payout (runs on the 1st of every month at 06:00 AM UTC).
DROP EVENT IF EXISTS evt_monthly_agency_payout;
CREATE EVENT IF NOT EXISTS evt_monthly_agency_payout
    ON SCHEDULE EVERY 1 MONTH
    STARTS (DATE_FORMAT(NOW() + INTERVAL 1 MONTH, '%Y-%m-01 06:00:00'))
    DO
        CALL process_monthly_payouts();


-- ============================================================
-- VERIFICATION QUERIES
-- Run these after applying the migration to confirm success:
-- ============================================================
--
-- SHOW TABLES LIKE 'agenc%';
-- SHOW COLUMNS FROM agencies;
-- SHOW COLUMNS FROM agency_articles;
-- SHOW COLUMNS FROM agency_revenue;
-- SHOW COLUMNS FROM agency_transactions;
-- SHOW COLUMNS FROM agency_withdrawals;
-- SHOW COLUMNS FROM agency_bulk_uploads;
-- SHOW COLUMNS FROM news LIKE 'agency%';
-- SHOW COLUMNS FROM news LIKE 'total_%';
-- SHOW INDEX FROM agency_revenue;
-- SHOW INDEX FROM agency_articles;
-- SHOW INDEX FROM news WHERE Key_name LIKE '%agency%';
-- SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name LIKE '%agency%';
-- SHOW EVENTS WHERE Name LIKE '%agency%';
--
-- ============================================================
-- END OF MIGRATION v3
-- ============================================================
