-- =============================================================================
-- Migration v23: Background Jobs Table
-- =============================================================================

CREATE TABLE IF NOT EXISTS jobs (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  job_class       VARCHAR(100)     NOT NULL COMMENT 'PHP class name e.g. EmailJob',
  payload         JSON             NOT NULL,
  status          ENUM('queued','processing','completed','failed','dead') NOT NULL DEFAULT 'queued',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 3,
  error_message   TEXT             DEFAULT NULL,
  queue           VARCHAR(50)      NOT NULL DEFAULT 'default',
  available_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reserved_at     TIMESTAMP        DEFAULT NULL,
  completed_at    TIMESTAMP        DEFAULT NULL,
  created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_status     (status, available_at),
  INDEX idx_queue      (queue, status),
  INDEX idx_created    (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Background job tracking — mirrors Redis queue state';
