# NewsXpressLive — Complete Deployment Guide

> **Language:** Hinglish + English mixed for easy understanding  
> **For:** cPanel / Shared Hosting + VPS (Apache/Nginx) + Flutter App

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Database Setup](#2-database-setup)
3. [Server pe Files Upload Karo](#3-server-pe-files-upload-karo)
4. [Environment Variables Set Karo](#4-environment-variables-set-karo)
5. [Firebase Setup](#5-firebase-setup)
6. [Flutter App Firebase Setup](#6-flutter-app-firebase-setup)
7. [Web Config (config.php)](#7-web-config-configphp)
8. [Cron Jobs](#8-cron-jobs)
9. [Final Check](#9-final-check)
10. [Common Errors & Fixes](#10-common-errors--fixes)

---

## 1. Requirements

| Requirement | Minimum Version |
|-------------|----------------|
| PHP         | 8.0+           |
| MySQL       | 5.7+ or 8.0+  |
| OpenSSL PHP extension | enabled |
| cURL PHP extension    | enabled |
| Flutter SDK | 3.19+          |

**cPanel pe check karo:** Software → PHP Version → make sure 8.0+ selected hai

---

## 2. Database Setup

### Step 2.1 — Database banao (cPanel)
1. cPanel → **MySQL Databases**
2. "Create New Database" → naam likho: `newsxpresslive`
3. "Create New User" → username + strong password set karo
4. "Add User to Database" → **ALL PRIVILEGES** do

### Step 2.2 — Tables import karo
1. cPanel → **phpMyAdmin** → apna database select karo
2. **Import** tab → `db/migrations.sql` select karo → Go
3. Phir dobara → `db/migration_v2.sql` import karo

> ⚠️ Dono files import karni hain — pehle `migrations.sql`, phir `migration_v2.sql`

---

## 3. Server pe Files Upload Karo

### Option A — cPanel File Manager
1. cPanel → **File Manager** → `public_html/` folder open karo
2. **Upload** → zip banao project ka → extract karo
3. Folder structure aisi honi chahiye:
   ```
   public_html/
   ├── web/           ← website frontend
   ├── api/           ← mobile app API
   ├── admin/
   ├── admin_panel/
   ├── auth/
   ├── config/
   ├── helpers/
   ├── cron/
   └── ...
   ```

### Option B — FTP / SFTP
```bash
# FileZilla ya scp use karo
scp -r /local/project/* user@yourserver.com:/public_html/
```

### Option C — Git (VPS)
```bash
cd /var/www/html
git clone https://github.com/softdigi/newsxpresslive.git .
```

---

## 4. Environment Variables Set Karo

### Method A — `.htaccess` (cPanel / Shared Hosting) ✅ Recommended

`public_html/.htaccess` file mein ye lines add karo:

```apache
SetEnv DB_HOST     localhost
SetEnv DB_NAME     newsxpresslive
SetEnv DB_USER     your_db_username
SetEnv DB_PASS     your_db_password

SetEnv FIREBASE_PROJECT_ID  your-firebase-project-id
SetEnv FIREBASE_DB_URL      https://your-firebase-project-id-default-rtdb.firebaseio.com
```

### Method B — `.env` file (VPS / Nginx)

```bash
cp .env.example .env
nano .env   # apni values fill karo
```

Phir `config/database.php` ke upar ye code add karo agar `.env` loading chahiye:
```php
if (file_exists(__DIR__ . '/../.env')) {
    foreach (file(__DIR__ . '/../.env') as $line) {
        $line = trim($line);
        if ($line && $line[0] !== '#' && strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            putenv(trim($k) . '=' . trim($v));
        }
    }
}
```

### Method C — cPanel PHP Environment Variables (easiest)
1. cPanel → **Software** → **PHP Environment Variables** (ya MultiPHP INI Editor)
2. Variables add karo:
   - `DB_HOST` → `localhost`
   - `DB_NAME` → `newsxpresslive`
   - `DB_USER` → aapka username
   - `DB_PASS` → aapka password
   - `FIREBASE_PROJECT_ID` → aapka project ID
   - `FIREBASE_DB_URL` → aapki RTDB URL

---

## 5. Firebase Setup

### Step 5.1 — Firebase Console mein Project banao
1. [console.firebase.google.com](https://console.firebase.google.com) jaao
2. **Add project** → naam: `newsxpresslive` (ya koi bhi naam)
3. Google Analytics enable karo (recommended)

### Step 5.2 — Authentication enable karo
1. Firebase Console → **Authentication** → **Sign-in method**
2. Enable karo:
   - **Google** (recommended)
   - **Email/Password** (optional)
   - **Phone** (optional)

### Step 5.3 — Realtime Database banao
1. Firebase Console → **Realtime Database** → **Create database**
2. Location: `us-central1` (ya Asia South East agar India ke liye)
3. Start in **test mode** (baad mein rules set karo)
4. Database URL note karo: `https://your-project-id-default-rtdb.firebaseio.com`

### Step 5.4 — Service Account Key download karo (server ke liye)
1. Firebase Console → **Project Settings** (gear icon)
2. **Service accounts** tab → **Generate new private key**
3. JSON file download hogi (real key file)
4. Is file ko rename karo: `firebase_service_key.json`
5. Server pe upload karo: `helpers/firebase_service_key.json`
6. **IMPORTANT:** Ye file kabhi bhi Git mein commit mat karo!

```bash
# Verify karo ki file sahi jagah hai
ls -la helpers/firebase_service_key.json
```

**firebase_service_key.json ka structure aisa hoga:**
```json
{
  "type": "service_account",
  "project_id": "your-actual-project-id",
  "private_key_id": "abc123...",
  "private_key": "-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----\n",
  "client_email": "firebase-adminsdk-xxxxx@your-project.iam.gserviceaccount.com",
  "client_id": "123456789",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "databaseURL": "https://your-project-default-rtdb.firebaseio.com"
}
```

> 📁 Demo/Template file dekho: `helpers/firebase_service_key.demo.json`  
> Wahan har field explain ki gayi hai.

### Step 5.5 — FCM (Push Notifications) setup
1. Firebase Console → **Project Settings** → **Cloud Messaging** tab
2. **Server key** copy karo (ya new key generate karo)
3. Ye key aapke `helpers/notification.php` ke liye use hoti hai
4. Android app ke liye `google-services.json` download karo
5. iOS app ke liye `GoogleService-Info.plist` download karo

---

## 6. Flutter App Firebase Setup

### Step 6.1 — FlutterFire CLI install karo
```bash
dart pub global activate flutterfire_cli
```

### Step 6.2 — App configure karo
```bash
cd flutter_app/
flutterfire configure --project=your-firebase-project-id
```

Ye automatically banayega:
- `android/app/google-services.json`
- `ios/Runner/GoogleService-Info.plist`
- `lib/firebase_options.dart`

### Step 6.3 — API URL set karo
`flutter_app/lib/core/constants/api_constants.dart` (ya similar file) mein:
```dart
static const String baseUrl = 'https://yourdomain.com/api';
```

### Step 6.4 — App build karo
```bash
# Android APK
flutter build apk --release

# Android App Bundle (Play Store ke liye)
flutter build appbundle --release

# iOS
flutter build ios --release
```

---

## 7. Web Config (config.php)

`web/includes/config.php` mein apna domain set karo:

```php
define('SITE_URL', 'https://yourdomain.com/web');
define('SITE_NAME', 'NewsXpressLive');
```

> ⚠️ Trailing slash mat lagao SITE_URL mein

---

## 8. Cron Jobs

cPanel → **Cron Jobs** mein ye add karo:

| Job | Command | Frequency |
|-----|---------|-----------|
| Rate limit cleanup | `php /home/USER/public_html/cron/cleanup.php` | Every hour |
| Viral score update | `php /home/USER/public_html/cron/viral_score.php` | Every 15 min |

**cPanel mein add karne ka tarika:**
1. cPanel → Advanced → **Cron Jobs**
2. "Add New Cron Job"
3. Minute: `0`, Hour: `*`, Day: `*`, Month: `*`, Weekday: `*`
4. Command: `php /home/yourusername/public_html/cron/cleanup.php`

---

## 9. Final Check

Deployment ke baad ye URLs check karo:

- ✅ Homepage: `https://yourdomain.com/web/`
- ✅ News Detail: `https://yourdomain.com/web/news/detail.php?slug=test`
- ✅ API Test: `https://yourdomain.com/api/latest_breaking.php`
- ✅ Admin Panel: `https://yourdomain.com/admin_panel/`

**PHP Error log check karo:**
```bash
tail -f /home/username/public_html/logs/error.log
# ya cPanel → Error Logs
```

---

## 10. Common Errors & Fixes

### ❌ "Database connection error"
- `.htaccess` mein DB credentials check karo
- cPanel mein user ko database ka access diya hai ya nahi

### ❌ "firebase_service_key.json not found"
- File `helpers/firebase_service_key.json` pe upload karo
- Permission check karo: `chmod 600 helpers/firebase_service_key.json`

### ❌ "FCM notifications nahi ja rahi"
- `firebase_service_key.json` mein `client_email` aur `private_key` correct hain?
- PHP OpenSSL extension enable hai?
- `helpers/notification.php` error log check karo

### ❌ Flutter "FirebaseApp not initialized"
- `flutterfire configure` dobara run karo
- `google-services.json` sahi folder mein hai? (`android/app/`)

### ❌ News list empty aa raha hai
- Database mein news ka `status = 'approved'` hona chahiye
- Admin panel se news approve karo

### ❌ Images nahi dikh rahi
- `web/uploads/` folder ka permission: `chmod 755 web/uploads/`
- CDN_BASE_URL set hai? `.htaccess` check karo

---

## Quick Reference — Important File Locations

| File | Purpose |
|------|---------|
| `helpers/firebase_service_key.json` | Firebase Admin SDK key (server-side) |
| `helpers/firebase_service_key.demo.json` | Template/demo (safe to share) |
| `.env.example` | All environment variables template |
| `web/includes/config.php` | Website URL, DB settings |
| `config/database.php` | API/backend DB connection |
| `db/migrations.sql` | Main database schema |
| `db/migration_v2.sql` | Additional columns & indexes |
| `flutter_app/lib/` | Flutter app source code |

---

> 💡 **Tip:** Agar koi problem aaye toh pehle PHP error log check karo. 90% issues wahan milte hain.
