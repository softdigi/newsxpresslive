-- =============================================================================
-- Migration v20: Email Logs Table
-- =============================================================================

CREATE TABLE IF NOT EXISTS email_logs (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  to_email        VARCHAR(320)     NOT NULL,
  to_name         VARCHAR(200)     DEFAULT NULL,
  subject         VARCHAR(500)     NOT NULL,
  template        VARCHAR(100)     NOT NULL COMMENT 'Template key e.g. verification_approved',
  user_id         INT              DEFAULT NULL,
  status          ENUM('queued','sent','failed','bounced') NOT NULL DEFAULT 'queued',
  sendgrid_msg_id VARCHAR(200)     DEFAULT NULL,
  error_message   TEXT             DEFAULT NULL,
  retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  metadata        JSON             DEFAULT NULL COMMENT 'Extra data for audit',
  sent_at         TIMESTAMP        DEFAULT NULL,
  created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_to_email  (to_email),
  INDEX idx_user      (user_id),
  INDEX idx_status    (status),
  INDEX idx_template  (template),
  INDEX idx_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log of all outbound emails sent via SendGrid';
