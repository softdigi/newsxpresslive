#!/usr/bin/env bash
# =============================================================================
# scripts/monitor.sh
# TIER 4 — DevOps: Application monitor script
#
# Checks key health indicators and sends alerts when thresholds are exceeded.
# Designed to run every minute via cron.
#
# Checks:
#   1. HTTP health endpoint (/api/health)
#   2. MySQL connectivity
#   3. Redis connectivity
#   4. Disk space (threshold: 85%)
#   5. PHP-FPM process count
#   6. CPU / memory
#
# Alert channels (configure via environment variables):
#   ALERT_EMAIL   — email address (uses `mail` command)
#   SLACK_WEBHOOK — Slack incoming webhook URL
#
# Environment variables:
#   APP_URL         Base URL of the app  (default: http://localhost)
#   DB_HOST / DB_USER / DB_PASS / DB_NAME
#   REDIS_HOST      (default: 127.0.0.1)
#   DISK_THRESHOLD  % usage before alert (default: 85)
#   ALERT_EMAIL     (optional)
#   SLACK_WEBHOOK   (optional)
#
# Usage:
#   chmod +x scripts/monitor.sh
#   # Add to crontab:
#   # * * * * * /var/www/newsxpresslive/scripts/monitor.sh >> /var/log/nxl_monitor.log 2>&1
# =============================================================================
set -euo pipefail

APP_URL="${APP_URL:-http://localhost}"
DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-newsxpresslive}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
DISK_THRESHOLD="${DISK_THRESHOLD:-85}"
ALERT_EMAIL="${ALERT_EMAIL:-}"
SLACK_WEBHOOK="${SLACK_WEBHOOK:-}"

HOSTNAME=$(hostname)
ALERTS=()

log()   { echo "[$(date +'%Y-%m-%d %H:%M:%S')] [INFO]  $*"; }
warn()  { echo "[$(date +'%Y-%m-%d %H:%M:%S')] [WARN]  $*"; ALERTS+=("⚠️  $*"); }
alert() { echo "[$(date +'%Y-%m-%d %H:%M:%S')] [ALERT] $*"; ALERTS+=("🚨  $*"); }

# ── 1. HTTP health check ───────────────────────────────────────────────────────
log "Checking HTTP health endpoint..."
HTTP_STATUS=$(curl -sL -o /dev/null -w "%{http_code}" \
    --max-time 10 "${APP_URL}/api/health" 2>/dev/null || echo "000")

if [[ "${HTTP_STATUS}" == "200" ]]; then
    log "  HTTP health: OK (${HTTP_STATUS})"
elif [[ "${HTTP_STATUS}" == "503" ]]; then
    alert "HTTP health degraded: ${APP_URL}/api/health returned 503"
else
    alert "HTTP health FAILED: ${APP_URL}/api/health returned ${HTTP_STATUS}"
fi

# ── 2. MySQL connectivity ──────────────────────────────────────────────────────
log "Checking MySQL..."
if MYSQL_PWD="${DB_PASS}" mysqladmin ping \
    --host="${DB_HOST}" --user="${DB_USER}" --silent 2>/dev/null; then
    log "  MySQL: OK"
else
    alert "MySQL is DOWN on ${DB_HOST}"
fi

# ── 3. Redis connectivity ──────────────────────────────────────────────────────
log "Checking Redis..."
if command -v redis-cli &>/dev/null; then
    PONG=$(redis-cli -h "${REDIS_HOST}" ping 2>/dev/null || echo "FAIL")
    if [[ "${PONG}" == "PONG" ]]; then
        log "  Redis: OK"
    else
        warn "Redis unavailable on ${REDIS_HOST} (non-critical)"
    fi
fi

# ── 4. Disk space ──────────────────────────────────────────────────────────────
log "Checking disk space..."
while read -r _line; do
    USE=$(echo "${_line}" | awk '{print $5}' | tr -d '%')
    MNT=$(echo "${_line}" | awk '{print $6}')
    if (( USE >= DISK_THRESHOLD )); then
        alert "Disk usage on ${MNT} is ${USE}% (threshold: ${DISK_THRESHOLD}%)"
    fi
done < <(df -h / /var/www 2>/dev/null | tail -n +2 | sort -u)
log "  Disk: OK"

# ── 5. PHP-FPM ────────────────────────────────────────────────────────────────
FPM_PROCS=$(pgrep -c "php-fpm" 2>/dev/null || echo 0)
if (( FPM_PROCS == 0 )); then
    alert "PHP-FPM is NOT running on ${HOSTNAME}"
else
    log "  PHP-FPM: ${FPM_PROCS} processes running"
fi

# ── 6. Memory ─────────────────────────────────────────────────────────────────
if command -v free &>/dev/null; then
    MEM_AVAIL_PCT=$(free | awk '/^Mem:/{printf "%.0f", $7/$2*100}')
    if (( MEM_AVAIL_PCT < 10 )); then
        warn "Available memory is low: ${MEM_AVAIL_PCT}% free"
    else
        log "  Memory: ${MEM_AVAIL_PCT}% available"
    fi
fi

# ── Send alerts ────────────────────────────────────────────────────────────────
if [[ ${#ALERTS[@]} -gt 0 ]]; then
    ALERT_BODY="NewsXpressLive Monitor — ${HOSTNAME} @ $(date)\n\n"
    for a in "${ALERTS[@]}"; do
        ALERT_BODY+="${a}\n"
    done

    # Email
    if [[ -n "${ALERT_EMAIL}" ]] && command -v mail &>/dev/null; then
        printf "%b" "${ALERT_BODY}" | \
            mail -s "[NXL ALERT] Issues detected on ${HOSTNAME}" "${ALERT_EMAIL}"
        log "Alert email sent to ${ALERT_EMAIL}"
    fi

    # Slack
    if [[ -n "${SLACK_WEBHOOK}" ]]; then
        SLACK_TEXT=$(printf "%b" "${ALERT_BODY}" | sed 's/"/\\"/g' | tr '\n' ' ')
        curl -s -X POST -H 'Content-type: application/json' \
            --data "{\"text\": \"${SLACK_TEXT}\"}" \
            "${SLACK_WEBHOOK}" > /dev/null
        log "Alert sent to Slack"
    fi
else
    log "All checks passed ✓"
fi
