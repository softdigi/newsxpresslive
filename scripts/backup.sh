#!/usr/bin/env bash
# =============================================================================
# scripts/backup.sh
# TIER 4 — DevOps: Automated backup script
#
# Creates a timestamped backup of:
#   1. MySQL database (mysqldump compressed with gzip)
#   2. User uploads directory
#   3. Application configuration (.env)
#
# Backups are stored locally and optionally uploaded to an S3-compatible
# bucket using the AWS CLI (s3cmd also supported).
#
# Environment variables (set in .env or export before running):
#   DB_HOST         MySQL host         (default: localhost)
#   DB_NAME         Database name      (required)
#   DB_USER         MySQL user         (required)
#   DB_PASS         MySQL password     (required)
#   APP_DIR         App root directory (default: /var/www/newsxpresslive)
#   BACKUP_DIR      Local backup dir   (default: /var/backups/newsxpresslive)
#   BACKUP_S3_URI   s3://bucket/path   (optional, uploads when set)
#   BACKUP_RETAIN   Days to keep       (default: 7)
#
# Usage:
#   chmod +x scripts/backup.sh
#   ./scripts/backup.sh
#   # Or add to cron:
#   # 0 2 * * * /var/www/newsxpresslive/scripts/backup.sh >> /var/log/nxl_backup.log 2>&1
# =============================================================================
set -euo pipefail

# ── Configuration ─────────────────────────────────────────────────────────────
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-newsxpresslive}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
APP_DIR="${APP_DIR:-/var/www/newsxpresslive}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/newsxpresslive}"
BACKUP_S3_URI="${BACKUP_S3_URI:-}"
BACKUP_RETAIN="${BACKUP_RETAIN:-7}"

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_SET="${BACKUP_DIR}/${TIMESTAMP}"

log() { echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*"; }

# ── Pre-flight checks ─────────────────────────────────────────────────────────
log "Starting backup — set: ${TIMESTAMP}"
mkdir -p "${BACKUP_SET}"

if [[ -z "${DB_NAME}" ]] || [[ -z "${DB_USER}" ]]; then
    log "ERROR: DB_NAME and DB_USER must be set"
    exit 1
fi

# ── 1. Database dump ──────────────────────────────────────────────────────────
log "Dumping database '${DB_NAME}'..."
DB_DUMP="${BACKUP_SET}/db_${DB_NAME}_${TIMESTAMP}.sql.gz"

MYSQL_PWD="${DB_PASS}" mysqldump \
    --host="${DB_HOST}" \
    --user="${DB_USER}" \
    --single-transaction \
    --quick \
    --lock-tables=false \
    --routines \
    --triggers \
    "${DB_NAME}" | gzip -9 > "${DB_DUMP}"

log "  Database dump: $(du -sh "${DB_DUMP}" | cut -f1)"

# ── 2. Uploads directory ──────────────────────────────────────────────────────
UPLOADS_DIR="${APP_DIR}/web/uploads"
if [[ -d "${UPLOADS_DIR}" ]]; then
    log "Archiving uploads directory..."
    UPLOADS_ARCHIVE="${BACKUP_SET}/uploads_${TIMESTAMP}.tar.gz"
    tar -czf "${UPLOADS_ARCHIVE}" -C "${APP_DIR}/web" uploads/
    log "  Uploads archive: $(du -sh "${UPLOADS_ARCHIVE}" | cut -f1)"
else
    log "  WARN: Uploads directory not found at ${UPLOADS_DIR} — skipping"
fi

# ── 3. Configuration files ────────────────────────────────────────────────────
for cfg in ".env" ".env.production" "web/includes/config.php"; do
    src="${APP_DIR}/${cfg}"
    if [[ -f "${src}" ]]; then
        cp "${src}" "${BACKUP_SET}/$(basename "${cfg}" .php)_${TIMESTAMP}.conf"
        log "  Backed up: ${cfg}"
    fi
done

# ── 4. Create a manifest ──────────────────────────────────────────────────────
{
    echo "backup_timestamp=${TIMESTAMP}"
    echo "db_name=${DB_NAME}"
    echo "db_host=${DB_HOST}"
    echo "app_dir=${APP_DIR}"
    echo "hostname=$(hostname)"
    echo "files:"
    ls -1 "${BACKUP_SET}/"
} > "${BACKUP_SET}/MANIFEST.txt"

# ── 5. Optional S3 upload ─────────────────────────────────────────────────────
if [[ -n "${BACKUP_S3_URI}" ]]; then
    log "Uploading to S3: ${BACKUP_S3_URI}..."
    if command -v aws &>/dev/null; then
        aws s3 sync "${BACKUP_SET}/" "${BACKUP_S3_URI}/${TIMESTAMP}/"
        log "  S3 upload complete"
    elif command -v s3cmd &>/dev/null; then
        s3cmd put "${BACKUP_SET}/"* "${BACKUP_S3_URI}/${TIMESTAMP}/"
        log "  S3 upload complete (s3cmd)"
    else
        log "  WARN: Neither 'aws' nor 's3cmd' found — skipping S3 upload"
    fi
fi

# ── 6. Prune old local backups ────────────────────────────────────────────────
log "Pruning backups older than ${BACKUP_RETAIN} days..."
find "${BACKUP_DIR}" -maxdepth 1 -mindepth 1 -type d \
    -mtime +"${BACKUP_RETAIN}" -exec rm -rf {} +

REMAINING=$(find "${BACKUP_DIR}" -maxdepth 1 -mindepth 1 -type d | wc -l)
log "  Remaining backup sets: ${REMAINING}"

log "Backup complete: ${BACKUP_SET}"
