-- ============================================================
-- db/migration_v28_platform_polish.sql
-- v28: Platform Polish – Admin 2FA (TOTP)
-- Run once: mysql -u root -p newsxpresslive < migration_v28_platform_polish.sql
-- ============================================================

-- ── Admin 2FA (TOTP) ─────────────────────────────────────────────────────────
ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS totp_secret       VARCHAR(64)  NULL,
  ADD COLUMN IF NOT EXISTS totp_enabled      TINYINT      NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS totp_backup_codes JSON         NULL;
