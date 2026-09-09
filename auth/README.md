# auth/ — Authentication & Authorization

Ye folder poore system ki auth layer hai.

---

## Files

| File | Kaam |
|------|------|
| `firebase.php` | Mobile app API auth — Firebase token verify + user load |
| `session.php`  | Admin panel auth — PHP session management |
| `rate_limit.php` | Rate limiting — brute force / flood protection |

---

## Integration Guide

### 1. Admin Panel (PHP Session)

**`admin_panel/includes/config.php`** mein add karo:
```php
require_once __DIR__ . '/../../auth/session.php';
// session.php ke saath config.php mein session_start() hata sako
// session.php wali adminSessionStart() use karo
```

**`admin_panel/includes/auth.php`** replace karo:
```php
<?php
require_once __DIR__ . '/../../auth/session.php';
requireAdminAuth(); // session check + timeout + IP binding
```

**Login ke baad** (`login.php`):
```php
if ($user && password_verify($password, $user['password'])) {
    setAdminSession($user); // session_regenerate_id bhi karta hai
    header('Location: dashboard.php');
    exit;
}
```

**Role check** (har module mein):
```php
requireRole(['super_admin', 'admin']); // users/ module
requireRole(['super_admin', 'admin', 'editor']); // news/ module
```

**Logout** (`logout.php`):
```php
require_once __DIR__ . '/../auth/session.php';
destroyAdminSession();
header('Location: login.php');
exit;
```

---

### 2. Mobile App API (Firebase)

**Existing API files** mein inline auth replace karo:

```php
// PURANA (12 files mein copy-paste):
$stmt = $pdo->prepare("SELECT id FROM users WHERE firebase_uid=? AND role='admin'");
$stmt->execute([$input['admin_uid']]);
if (!$stmt->fetch()) { jsonResponse(false,[],"unauthorized"); }

// NAYA (1 line):
require_once __DIR__ . '/../../auth/firebase.php';
$admin = requireAppAdmin($pdo, $input['admin_uid'] ?? '');
// $admin = ['id'=>1, 'role'=>'admin', ...]
```

**Reporter/User endpoints** ke liye:
```php
$user = requireAppUser($pdo, $input['id_token'] ?? '', $input['firebase_uid'] ?? '');
// $user = ['id'=>5, 'role'=>'reporter', 'status'=>'active', ...]
```

---

### 3. Rate Limiting

**Login** (`admin_panel/login.php`):
```php
require_once __DIR__ . '/../auth/rate_limit.php';

// POST se pehle check karo:
$ip = $_SERVER['REMOTE_ADDR'];
rateLimit($pdo, 'admin_login', $ip, 5, 900); // 5 attempts per 15 min

// Successful login ke baad reset karo:
resetRateLimit($pdo, 'admin_login', $ip);
```

**API endpoints**:
```php
rateLimit($pdo, 'submit_news',    $user['id'],  10,  3600); // 10/hour
rateLimit($pdo, 'wallet_withdraw',$user['id'],   3, 86400); // 3/day
rateLimit($pdo, 'app_login',      $_SERVER['REMOTE_ADDR'], 10, 900);
```

---

### 4. DB Table Required (rate_limit only)

```sql
CREATE TABLE IF NOT EXISTS rate_limits (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action       VARCHAR(64)  NOT NULL,
    identifier   VARCHAR(128) NOT NULL,
    attempts     INT UNSIGNED NOT NULL DEFAULT 1,
    window_start DATETIME     NOT NULL,
    INDEX idx_rl_lookup (action, identifier, window_start)
);
```

---

## Firebase ID Token vs UID

| Method | Security | Notes |
|--------|----------|-------|
| `id_token` (JWT) | ✅ Strong | App se signed JWT — Google verify karta hai |
| `firebase_uid` (string) | ⚠️ Weak | Sirf DB lookup — UID leak ho to impersonate possible |

**Recommendation**: App ka nayi version `id_token` bheje.
Legacy support ke liye `firebase_uid` abhi bhi kaam karta hai.
