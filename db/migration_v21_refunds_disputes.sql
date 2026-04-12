-- =============================================================================
-- Migration v21: Refunds and Disputes
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. refunds table
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS refunds (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id         BIGINT UNSIGNED DEFAULT NULL COMMENT 'Internal payments.id if applicable',
  razorpay_payment_id VARCHAR(64)   NOT NULL   COMMENT 'pay_xxx from Razorpay',
  razorpay_refund_id  VARCHAR(64)   DEFAULT NULL COMMENT 'rfnd_xxx returned by Razorpay',
  user_id            INT            NOT NULL,
  amount_paise       INT UNSIGNED   NOT NULL   COMMENT 'Amount in paise (₹99 = 9900)',
  reason             ENUM(
                       'verification_rejected',
                       'duplicate_payment',
                       'technical_failure',
                       'manual_request',
                       'early_bird_upgrade'
                     ) NOT NULL,
  status             ENUM('pending','initiated','processed','failed') NOT NULL DEFAULT 'pending',
  initiated_by       ENUM('system','admin') NOT NULL DEFAULT 'system',
  notes              VARCHAR(500)   DEFAULT NULL,
  error_message      VARCHAR(500)   DEFAULT NULL,
  processed_at       TIMESTAMP      DEFAULT NULL,
  created_at         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_razorpay_payment (razorpay_payment_id, reason),
  INDEX idx_user    (user_id),
  INDEX idx_status  (status),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. disputes table
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS disputes (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  razorpay_payment_id  VARCHAR(64)   NOT NULL,
  razorpay_dispute_id  VARCHAR(64)   DEFAULT NULL,
  refund_id           BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK → refunds.id',
  user_id             INT            DEFAULT NULL,
  dispute_type        VARCHAR(100)   DEFAULT NULL,
  amount_paise        INT UNSIGNED   DEFAULT NULL,
  status              ENUM('open','under_review','won','lost','closed') NOT NULL DEFAULT 'open',
  accept_status       ENUM('pending','accepted','rejected') DEFAULT 'pending',
  razorpay_payload    JSON           DEFAULT NULL,
  admin_notes         VARCHAR(500)   DEFAULT NULL,
  created_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_payment (razorpay_payment_id),
  INDEX idx_status  (status),
  INDEX idx_user    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
