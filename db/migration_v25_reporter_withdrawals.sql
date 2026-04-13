-- ============================================================
-- Migration v25 — Reporter Withdrawals
-- NewsXpressLive
-- ============================================================

CREATE TABLE IF NOT EXISTS reporter_withdrawals (
  id               BIGINT          AUTO_INCREMENT PRIMARY KEY,
  user_id          INT             NOT NULL,
  reporter_uid     VARCHAR(128)    NOT NULL DEFAULT '',
  amount           DECIMAL(10,2)   NOT NULL,
  method           ENUM('upi','bank','paytm') DEFAULT 'upi',
  upi_id           VARCHAR(200)    NULL,
  bank_account     VARCHAR(20)     NULL,
  bank_ifsc        VARCHAR(11)     NULL,
  bank_name        VARCHAR(200)    NULL,
  account_holder   VARCHAR(200)    NULL,
  status           ENUM('requested','processing','completed','failed','cancelled')
                   DEFAULT 'requested',
  admin_note       TEXT            NULL,
  transaction_ref  VARCHAR(200)    NULL,
  requested_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at     TIMESTAMP       NULL,
  INDEX idx_user      (user_id, status),
  INDEX idx_reporter  (reporter_uid, status),
  INDEX idx_status    (status, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Grievances table (for grievance.php complaint form)
-- ============================================================

CREATE TABLE IF NOT EXISTS grievances (
  id           BIGINT       AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(200) NOT NULL,
  email        VARCHAR(200) NOT NULL,
  phone        VARCHAR(20)  NULL,
  order_id     VARCHAR(200) NULL,
  issue_type   VARCHAR(50)  NOT NULL,
  description  TEXT         NOT NULL,
  status       ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
  admin_note   TEXT         NULL,
  assigned_to  INT          NULL,
  submitted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status, submitted_at),
  INDEX idx_email  (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
