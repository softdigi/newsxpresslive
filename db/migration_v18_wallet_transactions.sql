-- ============================================================
-- Migration v18: Wallet Transactions
-- NewsXpressLive
--
-- Features:
--   • wallets table — one row per reporter/agency, tracks live balance
--   • transactions_log table — immutable ledger of every wallet movement
--   • Indexes tuned for the WalletTransactionManager SELECT FOR UPDATE
--     access pattern (lookup by user_id + user_type)
--
-- Prerequisites: migrations.sql + v2–v17 applied.
-- MySQL 8.0+ required (IF NOT EXISTS on ALTER TABLE).
-- Safe to re-run: all CREATE TABLE statements use IF NOT EXISTS.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ============================================================
-- 1.  Wallets
-- ============================================================
-- One row per (user_id, user_type) pair.
-- balance     — current available balance (DECIMAL to avoid floating-point drift)
-- total_earned — lifetime credit total (audit convenience)
-- The row is locked with SELECT … FOR UPDATE during every payout so that
-- concurrent payouts cannot read a stale balance.

CREATE TABLE IF NOT EXISTS wallets (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id      INT              NOT NULL,
  user_type    ENUM('reporter','agency') NOT NULL,
  balance      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  total_earned DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE  KEY uq_user        (user_id, user_type),
  INDEX        idx_user_type (user_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One balance row per reporter/agency; locked FOR UPDATE on payout';

-- ============================================================
-- 2.  Transactions log
-- ============================================================
-- Append-only ledger.  Application code must never UPDATE or DELETE rows.
-- reference_id — UUID shared by all rows that belong to the same atomic
--                payout operation (reporter credit + agency cut).
-- related_user_id — the other party in the payout (links the two rows).

CREATE TABLE IF NOT EXISTS transactions_log (
  id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  reference_id     CHAR(36)         NOT NULL
                   COMMENT 'UUID grouping all rows from one atomic payout',
  wallet_id        BIGINT UNSIGNED  NOT NULL,
  user_id          INT              NOT NULL,
  user_type        ENUM('reporter','agency') NOT NULL,
  type             ENUM('credit','debit')    NOT NULL,
  amount           DECIMAL(12,2)   NOT NULL,
  balance_before   DECIMAL(12,2)   NOT NULL,
  balance_after    DECIMAL(12,2)   NOT NULL,
  description      VARCHAR(500)    NOT NULL DEFAULT '',
  related_user_id  INT             DEFAULT NULL
                   COMMENT 'The counter-party in the payout',
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_reference  (reference_id),
  INDEX idx_wallet     (wallet_id),
  INDEX idx_user       (user_id, user_type),
  INDEX idx_created    (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable payout ledger — INSERT only, no UPDATE/DELETE';
