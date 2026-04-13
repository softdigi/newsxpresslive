# NewsXpressLive — Complete Deployment Guide

> **Language:** Hinglish + English mixed for easy understanding  
> **For:** cPanel / Shared Hosting + VPS (Ubuntu 22.04, Nginx)  
> **DB Schema:** Migrations up to `v30` (Referral Reward System Phase 3)

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Database Setup](#2-database-setup)
3. [OPTION A — cPanel Deployment](#3-option-a--cpanel-deployment)
4. [OPTION B — VPS Deployment (Ubuntu 22.04)](#4-option-b--vps-deployment-ubuntu-2204)
5. [Environment Variables](#5-environment-variables)
6. [Firebase Setup](#6-firebase-setup)
7. [Flutter App Build](#7-flutter-app-build)
8. [Cron Jobs](#8-cron-jobs)
9. [Runtime Files & Folder Structure](#9-runtime-files--folder-structure)
10. [Security Checklist](#10-security-checklist)
11. [Post-Deploy Checklist](#11-post-deploy-checklist)
12. [Common Errors & Fixes](#12-common-errors--fixes)
13. [Quick Reference](#13-quick-reference)

---

## 1. Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.1+ (8.2 recommended) |
| MySQL | 5.7+ or 8.0+ |
| Redis | 6.0+ |
| Nginx / Apache | latest stable |
| Flutter SDK | 3.19+ |
| PHP Extensions | pdo_mysql, redis, gd, curl, mbstring, xml, zip, intl, openssl |

**cPanel check:** Software → PHP Version → select 8.1+ → enable extensions listed above.

---

## 2. Database Setup

### Step 2.1 — Database create karo

```sql
CREATE DATABASE newsxpresslive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nxl_user'@'localhost' IDENTIFIED BY 'StrongPassword123!';
GRANT ALL PRIVILEGES ON newsxpresslive.* TO 'nxl_user'@'localhost';
FLUSH PRIVILEGES;
```

> ⚠️ **Never** use `root` in production.

### Step 2.2 — Migrations run karo (order important!)

cPanel → phpMyAdmin → apna DB select → **Import** tab se ek ek karo:

```
db/migrations.sql            ← Base schema
db/migration_v2.sql
db/migration_v3_agency_partner.sql
db/migration_v4_admob_payout.sql
db/migration_v5_geo_coordinates.sql
db/migration_v6_personalization.sql
db/migration_v7_complaints.sql
db/migration_v8_social.sql
db/migration_v9_complaints_enhanced.sql
db/migration_v20_email_logs.sql
db/migration_v21_refunds_disputes.sql
db/migration_v22_moderation_strikes.sql
db/migration_v23_jobs_queue.sql
db/migration_v24_gamification.sql
db/migration_v25_reporter_withdrawals.sql
db/migration_v26_kyc_subscriptions.sql
db/migration_v27_growth.sql
db/migration_v28_platform_polish.sql
db/migration_v29_referral_system.sql     ← 13 new tables + reward_config defaults
db/migration_v30_reward_phase3.sql       ← Phase 3 schema additions
```

**VPS pe ek command mein:**
```bash
for f in db/migrations.sql db/migration_v2.sql db/migration_v3_agency_partner.sql \
  db/migration_v4_admob_payout.sql db/migration_v5_geo_coordinates.sql \
  db/migration_v6_personalization.sql db/migration_v7_complaints.sql \
  db/migration_v8_social.sql db/migration_v9_complaints_enhanced.sql \
  db/migration_v20_email_logs.sql db/migration_v21_refunds_disputes.sql \
  db/migration_v22_moderation_strikes.sql db/migration_v23_jobs_queue.sql \
  db/migration_v24_gamification.sql db/migration_v25_reporter_withdrawals.sql \
  db/migration_v26_kyc_subscriptions.sql db/migration_v27_growth.sql \
  db/migration_v28_platform_polish.sql db/migration_v29_referral_system.sql \
  db/migration_v30_reward_phase3.sql; do
  mysql -u nxl_user -p newsxpresslive < "$f"
done
```

**Verify:** Tables should be **40+**:
```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'newsxpresslive';
```

---

## 3. OPTION A — cPanel Deployment

### Step 3.1 — File Structure

Upload to `public_html/`:

```
public_html/
├── index.php          ← web frontend entry
├── web/               ← PHP web pages
├── api/               ← Flutter API (v1)
├── admin_panel/       ← Admin UI
├── assets/            ← CSS, JS, images
└── .htaccess          ← URL routing + env vars
```

**Private files** (public_html ke BAHAR — secure):
```
/home/username/private/
├── verification/      ← KYC documents
├── podcasts_src/      ← Audio source files
└── exports/           ← CSV exports
```

### Step 3.2 — .htaccess (public_html mein)

```apache
# Block access to sensitive files
<FilesMatch "\.(env|sql|log|sh|json|md|lock)$">
  Order allow,deny
  Deny from all
</FilesMatch>

# Block direct access to helpers and config
<FilesMatch "^(config|database|bootstrap)\.php$">
  Order allow,deny
  Deny from all
</FilesMatch>

# PHP settings
php_value upload_max_filesize 20M
php_value post_max_size 25M
php_value max_execution_time 60
php_value memory_limit 256M

# Security headers
Header always set X-Frame-Options "DENY"
Header always set X-Content-Type-Options "nosniff"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# URL rewriting (optional — for clean URLs)
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/(.*)$ api/$1 [L]
```

### Step 3.3 — Cron Jobs (cPanel → Advanced → Cron Jobs)

See full cron table in [Section 8](#8-cron-jobs).

---

## 4. OPTION B — VPS Deployment (Ubuntu 22.04)

### Step 4.1 — Server Setup

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install LEMP stack
sudo apt install -y nginx php8.2-fpm php8.2-mysql \
  php8.2-redis php8.2-gd php8.2-curl php8.2-mbstring \
  php8.2-xml php8.2-zip php8.2-intl \
  mysql-server redis-server \
  certbot python3-certbot-nginx \
  ffmpeg imagemagick

# Composer (if needed)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### Step 4.2 — Nginx Config

`/etc/nginx/sites-available/newsxpresslive`:

```nginx
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;
    root /var/www/newsxpress/public_html;
    index index.php;

    # SSL (Certbot will populate these)
    # ssl_certificate ...
    # ssl_certificate_key ...

    # Security headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # API routes
    location /api/ {
        try_files $uri $uri/ /api/index.php?$query_string;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 60;
    }

    # Admin panel — IP whitelist karo production pe
    location /admin_panel/ {
        # Uncomment and replace with your IP:
        # allow YOUR_OFFICE_IP;
        # deny all;
        try_files $uri $uri/ =404;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Static assets cache
    location ~* \.(jpg|jpeg|png|gif|webp|svg|css|js|woff2|mp3)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # PHP files
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block private directory
    location ~ /private/ {
        deny all;
        return 404;
    }

    # Block KYC uploads — served via PHP only
    location /uploads/verification/ {
        deny all;
        return 404;
    }

    # Block sensitive file extensions
    location ~* \.(env|sql|log|sh|md|lock)$ {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ =404;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/newsxpresslive /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### Step 4.3 — SSL

```bash
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
```

### Step 4.4 — File Permissions

```bash
sudo chown -R www-data:www-data /var/www/newsxpress/
sudo chmod 600 /var/www/newsxpress/.env
sudo find /var/www/newsxpress/public_html -type f -name "*.php" -exec chmod 644 {} \;
sudo find /var/www/newsxpress/public_html -type d -exec chmod 755 {} \;
sudo chmod -R 755 /var/www/newsxpress/public_html/uploads/
sudo mkdir -p /var/www/newsxpress/private/{verification,podcasts_src,exports}
sudo chmod 700 /var/www/newsxpress/private/
```

### Step 4.5 — Redis Config

`/etc/redis/redis.conf`:
```conf
bind 127.0.0.1
requirepass StrongRedisPassword123
maxmemory 512mb
maxmemory-policy allkeys-lru
```

```bash
sudo systemctl restart redis-server
```

### Step 4.6 — MySQL Optimization

`/etc/mysql/mysql.conf.d/mysqld.cnf`:
```ini
[mysqld]
innodb_buffer_pool_size = 512M
max_connections = 200
slow_query_log = ON
long_query_time = 1
slow_query_log_file = /var/log/mysql/slow.log
```

```bash
sudo systemctl restart mysql
```

---

## 5. Environment Variables

### cPanel — .htaccess mein add karo

```apache
SetEnv DB_HOST localhost
SetEnv DB_NAME newsxpresslive
SetEnv DB_USER nxl_user
SetEnv DB_PASS StrongPassword123!
SetEnv SITE_URL https://yourdomain.com
SetEnv ADMIN_BASE_PATH /admin_panel
SetEnv RAZORPAY_KEY_ID rzp_live_xxx
SetEnv RAZORPAY_KEY_SECRET xxx
SetEnv RAZORPAY_PAYOUT_ACCOUNT 4564563546773452
SetEnv RAZORPAY_WEBHOOK_SECRET xxx
SetEnv STRIPE_SECRET_KEY sk_live_xxx
SetEnv FIREBASE_PROJECT_ID newsxpresslive-xxx
SetEnv REDIS_HOST 127.0.0.1
SetEnv REDIS_PORT 6379
SetEnv REDIS_PASS StrongRedisPassword123
SetEnv SENTRY_DSN https://xxx@sentry.io/xxx
SetEnv OPENWEATHER_API_KEY xxx
SetEnv WHATSAPP_TOKEN xxx
SetEnv WHATSAPP_PHONE_NUMBER_ID xxx
SetEnv MAIL_FROM noreply@yourdomain.com
SetEnv SENDGRID_API_KEY SG.xxx
SetEnv CDN_URL https://cdn.yourdomain.com
SetEnv ALERT_EMAIL admin@yourdomain.com
SetEnv SLACK_WEBHOOK https://hooks.slack.com/services/xxx
```

### VPS — `/var/www/newsxpress/.env`

Same variables as above in `KEY=value` format. Then:
```bash
chmod 600 .env
chown www-data:www-data .env
```

> ⚠️ `.env` file ko **kabhi** Git mein commit mat karo. `.gitignore` mein add hai.

---

## 6. Firebase Setup

### Step 6.1 — Project create karo
1. [console.firebase.google.com](https://console.firebase.google.com) → Add project
2. Authentication → Sign-in method → Enable: **Phone**, **Google**, **Email/Password**
3. Realtime Database → Create database (Asia South East for India)

### Step 6.2 — Service Account Key
1. Project Settings → Service accounts → Generate new private key
2. Download JSON → rename to `firebase_service_key.json`
3. Upload to `helpers/firebase_service_key.json` on server
4. `chmod 600 helpers/firebase_service_key.json`

> 📁 Template: `helpers/firebase_service_key.demo.json`

### Step 6.3 — FCM (Push Notifications)
1. Project Settings → Cloud Messaging → Server Key note karo

---

## 7. Flutter App Build

### Step 7.1 — FlutterFire configure
```bash
dart pub global activate flutterfire_cli
cd flutter_app/
flutterfire configure --project=your-firebase-project-id
```

### Step 7.2 — API URL set karo
`flutter_app/lib/core/constants/api_endpoints.dart`:
```dart
static const String baseUrl = 'https://yourdomain.com';
```

> ⚠️ Change `http://localhost/web` to production HTTPS URL before building.

### Step 7.3 — Build

```bash
# Android App Bundle (Play Store)
flutter build appbundle --release

# Android APK (direct install)
flutter build apk --release

# iOS
flutter build ios --release
```

---

## 8. Cron Jobs

### cPanel — Advanced → Cron Jobs

| Schedule | Command | Purpose |
|---|---|---|
| `*/15 * * * *` | `php /home/user/public_html/cron/update_viral_scores.php` | Viral scores |
| `*/15 * * * *` | `php /home/user/public_html/cron/process_milestones.php` | Milestone grants |
| `0 * * * *` | `php /home/user/public_html/cron/budget_alert.php` | Budget threshold alerts |
| `0 1 * * *` | `php /home/user/public_html/cron/calculate_reporter_scores.php` | Reporter scores |
| `0 2 * * *` | `php /home/user/public_html/cron/fraud_daily_report.php` | Fraud daily report |
| `0 3 * * *` | `php /home/user/public_html/cron/lifetime_revenue_share.php` | Lifetime referral share |
| `0 4 * * *` | `bash /home/user/public_html/scripts/backup.sh` | DB backup |
| `0 7 * * *` | `php /home/user/public_html/cron/generate_audio_digest.php` | Audio digest |
| `0 8 * * *` | `php /home/user/public_html/cron/send_whatsapp_digest.php` | WhatsApp digest |
| `0 9 * * *` | `php /home/user/public_html/cron/check_mandi_alerts.php` | Mandi price alerts |
| `0 23 * * *` | `php /home/user/public_html/cron/track_agency_revenue.php` | Agency revenue |
| `0 0 1 * *` | `php /home/user/public_html/cron/monthly_agency_payout.php` | Monthly payouts |
| `0 0 1 * *` | `php /home/user/public_html/cron/reset_monthly_caps.php` | Reset reward caps |
| `0 * * * *` | `php /home/user/public_html/cron/cleanup_stories.php` | Story cleanup |
| `*/5 * * * *` | `bash /home/user/public_html/scripts/monitor.sh` | Health monitor |

### VPS — `/etc/cron.d/newsxpresslive`

```cron
*/15 * * * * www-data php /var/www/newsxpress/public_html/cron/update_viral_scores.php >> /var/log/nxl_cron.log 2>&1
*/15 * * * * www-data php /var/www/newsxpress/public_html/cron/process_milestones.php >> /var/log/nxl_cron.log 2>&1
0 * * * * www-data php /var/www/newsxpress/public_html/cron/budget_alert.php >> /var/log/nxl_cron.log 2>&1
0 1 * * * www-data php /var/www/newsxpress/public_html/cron/calculate_reporter_scores.php >> /var/log/nxl_cron.log 2>&1
0 2 * * * www-data php /var/www/newsxpress/public_html/cron/fraud_daily_report.php >> /var/log/nxl_cron.log 2>&1
0 3 * * * www-data php /var/www/newsxpress/public_html/cron/lifetime_revenue_share.php >> /var/log/nxl_cron.log 2>&1
0 4 * * * root bash /var/www/newsxpress/scripts/backup.sh >> /var/log/nxl_backup.log 2>&1
0 7 * * * www-data php /var/www/newsxpress/public_html/cron/generate_audio_digest.php >> /var/log/nxl_cron.log 2>&1
0 8 * * * www-data php /var/www/newsxpress/public_html/cron/send_whatsapp_digest.php >> /var/log/nxl_cron.log 2>&1
0 9 * * * www-data php /var/www/newsxpress/public_html/cron/check_mandi_alerts.php >> /var/log/nxl_cron.log 2>&1
0 23 * * * www-data php /var/www/newsxpress/public_html/cron/track_agency_revenue.php >> /var/log/nxl_cron.log 2>&1
0 0 1 * * www-data php /var/www/newsxpress/public_html/cron/monthly_agency_payout.php >> /var/log/nxl_cron.log 2>&1
0 0 1 * * www-data php /var/www/newsxpress/public_html/cron/reset_monthly_caps.php >> /var/log/nxl_cron.log 2>&1
0 * * * * www-data php /var/www/newsxpress/public_html/cron/cleanup_stories.php >> /var/log/nxl_cron.log 2>&1
*/5 * * * * root bash /var/www/newsxpress/scripts/monitor.sh >> /var/log/nxl_monitor.log 2>&1
```

---

## 9. Runtime Files & Folder Structure

> Yeh files runtime mein generate hongi. Git mein **nahi** dalni — `.gitignore` mein add hain.

```
/var/www/newsxpress/
├── public_html/           ← Git se deploy
│   ├── uploads/           ← REALTIME (not in Git)
│   │   ├── news/          ← Reporter images (YYYY/MM/)
│   │   ├── reels/         ← Video files
│   │   ├── profiles/      ← Profile photos
│   │   ├── agency/        ← Agency logos
│   │   ├── complaints/    ← Complaint images
│   │   ├── listings/      ← Marketplace images
│   │   ├── podcasts/      ← Audio digests
│   │   ├── og_cards/      ← Generated share OG images
│   │   └── stories/       ← 24hr story media
│   ├── assets/cache/      ← REALTIME (sitemap.xml etc.)
│   └── logs/              ← REALTIME (app.log, error.log)
│
├── private/               ← OUTSIDE public_html (not in Git)
│   ├── verification/      ← KYC documents (aadhar/pan/kyc/)
│   └── exports/           ← Admin CSV exports
│
└── backups/               ← REALTIME DB + uploads backups
```

### File Naming Conventions

| Type | Pattern |
|---|---|
| News images | `news_{uid}_{timestamp}.webp` |
| Profile photos | `profile_{uid}_{type}.jpg` |
| Story media | `story_{id}_{timestamp}.{ext}` |
| Audio digest | `digest_{date}_{lang}.mp3` |
| OG share card | `og_article_{id}_{lang}.png` |
| KYC docs | `{entity}_{uid}_{type}_{timestamp}.{ext}` |
| DB backups | `backup_{YYYY-MM-DD_HH-MM}.sql.gz` |

### Upload Security Rules

- Max file sizes: Images 5MB, Videos 50MB, Documents 10MB, Audio 20MB
- Allowed formats: Images jpg/jpeg/png/webp/gif, Videos mp4/webm, Documents pdf/jpg/png, Audio mp3/wav/m4a
- Storage path constants:
  ```php
  define('UPLOAD_BASE', '/var/www/newsxpress/public_html/uploads/');
  define('PRIVATE_BASE', '/var/www/newsxpress/private/');
  ```
- All uploads: UUID filename (never user-supplied name)
  ```php
  $filename = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
  ```
- Images: Convert to WebP with GD for smaller size

---

## 10. Security Checklist

### PHP / Backend

- [x] All DB queries use PDO prepared statements (no string interpolation)
- [x] CSRF token on all POST forms (`verify_csrf()`)
- [x] All HTML output escaped with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
- [x] Role checks on all admin pages (`requireRole` / `can()`)
- [x] Firebase JWT verified on all user API endpoints
- [x] File uploads: extension whitelist + UUID rename + MIME check
- [x] KYC documents stored in `private/` (outside public_html)
- [x] Razorpay webhook HMAC verified (`verifyRazorpayWebhook()`)
- [x] UTR numbers validated (`/^[A-Za-z0-9]{8,22}$/`) before DB write
- [x] Rate limiting on auth endpoints (`auth/rate_limit.php`)
- [x] Sensitive config files blocked by Nginx/Apache rules
- [x] `.env` not accessible from browser
- [ ] Admin panel IP whitelist karo (Nginx `allow`/`deny` or cPanel IP restrict)
- [ ] 2FA enable karo all admin accounts (`admin_panel/security/2fa_setup.php`)

### Reward System Specific

- [x] Budget checks before every reward grant (`RewardConfig::checkBudget()`)
- [x] Fraud score checks before referral grant (`ReferralFraudDetector`)
- [x] Per-user monthly cap enforced
- [x] Withdrawal delay enforced (`min_withdrawal_delay_days`)
- [x] Large withdrawal threshold flagged for manual review
- [x] Duplicate payout prevention (status check before Razorpay call)
- [ ] Razorpay live mode enable karo (aur test mode disable)
- [ ] `RAZORPAY_PAYOUT_ACCOUNT` set karo `.env` mein

### Network / Infrastructure

- [ ] SSL A+ rating (ssllabs.com)
- [ ] HSTS header set (Nginx config mein hai)
- [ ] Redis password set (`requirepass`)
- [ ] MySQL: root remote login disabled
- [ ] Server firewall: only 80, 443, 22 open
- [ ] SSH: password login disable karo, key-only

---

## 11. Post-Deploy Checklist

```
[ ] https://yourdomain.com → 200 OK
[ ] https://yourdomain.com/api/health → {"status":"healthy"}
[ ] Admin panel login → https://yourdomain.com/admin_panel/
[ ] Admin Rewards Dashboard → /admin_panel/rewards/dashboard.php
[ ] Admin Rewards Config → /admin_panel/rewards/config.php (super_admin only)
[ ] Admin Withdrawals → /admin_panel/rewards/withdrawals.php
[ ] Admin Audit Log → /admin_panel/rewards/config_audit.php
[ ] Firebase Auth → token verify working
[ ] Redis → redis-cli ping → PONG
[ ] MySQL → table count 40+
[ ] File upload → test image upload in admin
[ ] Payment → Razorpay test mode checkout
[ ] FCM → test notification from Firebase console
[ ] Cron jobs → verify all running (check logs/)
[ ] SSL → A+ rating at ssllabs.com
[ ] Legal pages → /web/legal/about, contact, grievance, privacy, terms live
[ ] .env → https://yourdomain.com/.env → 403 Forbidden
[ ] private/ → https://yourdomain.com/private/ → 404
[ ] Sitemap → https://yourdomain.com/sitemap.xml
[ ] Google Search Console → submit sitemap
[ ] 2FA → all admin accounts mein enable karo
[ ] Razorpay → live mode activate karo (legal pages ke baad)
[ ] Backup → scripts/backup.sh manually run karke verify karo
[ ] Monitor → scripts/monitor.sh manually run karke verify karo
```

---

## 12. Common Errors & Fixes

### ❌ "Database connection error"
- `.env` / `.htaccess` mein DB credentials check karo
- MySQL user ko DB ka access diya hai? (`GRANT ALL PRIVILEGES`)

### ❌ "firebase_service_key.json not found"
- Upload to `helpers/firebase_service_key.json`
- `chmod 600 helpers/firebase_service_key.json`

### ❌ Reward config page 403
- Sirf `super_admin` role wala admin dekh sakta hai
- Admin panel → Users → apna role check karo

### ❌ Withdrawals Razorpay payout fail
- `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_PAYOUT_ACCOUNT` set hain?
- Razorpay dashboard pe Payouts feature enabled hai? (requires KYC)
- Test mode vs Live mode check karo

### ❌ Milestones not granting
- `cron/process_milestones.php` running hai? `cron/budget_alert.php` se budget exhaust nahi hua?
- Redis connected hai? (`RewardConfig` 3-layer cache check karo)
- `reward_config` table mein `referral_system_active = 1` hai?

### ❌ Redis unavailable
- `redis-server` running hai? (`sudo systemctl status redis`)
- Redis password set hai toh `REDIS_PASS` env var set karo

### ❌ FCM notifications nahi ja rahi
- `firebase_service_key.json` mein `client_email` + `private_key` correct hain?
- PHP OpenSSL extension enabled hai?

### ❌ Flutter "FirebaseApp not initialized"
- `flutterfire configure` dobara run karo
- `google-services.json` sahi folder mein hai? (`android/app/`)

### ❌ Images nahi dikh rahi
- `uploads/` folder permission: `chmod -R 755 uploads/`
- `CDN_URL` set hai? `.env` check karo

---

## 13. Quick Reference

| File | Purpose |
|---|---|
| `helpers/firebase_service_key.json` | Firebase Admin SDK key (server-side) |
| `helpers/firebase_service_key.demo.json` | Template (safe to commit) |
| `.env` / `.env.example` | Environment variables |
| `config/database.php` | DB connection |
| `helpers/reward_config.php` | Reward config (3-layer cache) |
| `helpers/milestone_engine.php` | Milestone grant logic |
| `helpers/referral_engine.php` | Referral code + bonus logic |
| `helpers/article_reward_engine.php` | Article reward logic |
| `helpers/referral_fraud.php` | Fraud detection |
| `admin_panel/rewards/config.php` | Reward config panel (super_admin) |
| `admin_panel/rewards/dashboard.php` | Rewards dashboard |
| `admin_panel/rewards/withdrawals.php` | Withdrawal management |
| `admin_panel/rewards/config_audit.php` | Config change audit log |
| `admin_panel/security/2fa_setup.php` | Admin 2FA setup |
| `web/api/wallet/reward_status.php` | Flutter wallet API |
| `db/migration_v29_referral_system.sql` | Phase 2 DB schema |
| `db/migration_v30_reward_phase3.sql` | Phase 3 DB schema |
| `scripts/backup.sh` | Automated backup |
| `scripts/monitor.sh` | Health monitor |

---

> 💡 **Tip:** Agar koi problem aaye toh pehle PHP error log check karo.  
> `tail -f logs/error.log` ya cPanel → Error Logs.  
> 90% issues wahan milte hain.
