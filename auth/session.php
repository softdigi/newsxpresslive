<?php
// ============================================================
// auth/session.php
// Admin Panel Session Authentication Helper
//
// PROBLEM THIS SOLVES:
//   Admin panel currently has auth.php which only checks:
//     if (!isset($_SESSION['admin'])) { redirect to login; }
//   Missing:
//   1. Session fixation protection (regenerate after login)
//   2. Session timeout
//   3. IP binding (hijacking detection)
//   4. Role checking scattered across every file
//   5. No centralized "require role X" function
//
// THIS FILE provides:
//   - adminSessionStart()   : Secure session_start()
//   - requireAdminAuth()    : Check logged in + timeout + IP
//   - requireRole()         : Check specific role(s)
//   - setAdminSession()     : Called after successful login
//   - destroyAdminSession() : Clean logout
// ============================================================

/**
 * Start session with secure settings.
 * Call ONCE at top of config.php — not needed if already there.
 */
function adminSessionStart(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,   // HTTPS only
        'httponly' => true,   // no JS access
        'samesite' => 'Strict',
    ]);

    session_start();
}

/**
 * Require authenticated admin session.
 * Call at top of every protected admin page.
 * Exits with redirect if not authenticated.
 *
 * @param bool $api  If true, return JSON 401 instead of redirect
 */
function requireAdminAuth(bool $api = false): void
{
    if (empty($_SESSION['admin'])) {
        _authFail('Not logged in', $api);
    }

    // IP binding — detect session hijacking
    $current_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (
        !empty($_SESSION['admin']['_ip']) &&
        $_SESSION['admin']['_ip'] !== $current_ip
    ) {
        session_destroy();
        _authFail('Session invalid', $api, 'session_invalid');
    }

    // Idle timeout: 2 hours
    $last_active = $_SESSION['admin']['_last_active'] ?? 0;
    if ($last_active && (time() - $last_active) > 7200) {
        session_destroy();
        _authFail('Session expired', $api, 'timeout');
    }

    // Refresh last active time
    $_SESSION['admin']['_last_active'] = time();
}

/**
 * Require specific role(s).
 * Call after requireAdminAuth().
 *
 * @param string|array $roles  Allowed role(s)
 * @param bool         $api    If true, return JSON 403 instead of die()
 */
function requireRole(string|array $roles, bool $api = false): void
{
    $roles = (array)$roles;
    $role  = $_SESSION['admin']['role'] ?? '';

    // super_admin can do everything
    if ($role === 'super_admin') return;

    if (!in_array($role, $roles, true)) {
        if ($api) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        http_response_code(403);
        die('Access denied. Insufficient privileges.');
    }
}

/**
 * Set admin session after successful login.
 * Call in login.php after password_verify() passes.
 *
 * @param array $user  User row from admin_users table
 */
function setAdminSession(array $user): void
{
    // Regenerate session ID — prevents session fixation
    session_regenerate_id(true);

    $_SESSION['admin'] = [
        'id'           => (int)$user['id'],
        'name'         => $user['name'],
        'role'         => $user['role'],
        'agency_id'    => isset($user['agency_id']) ? (int)$user['agency_id'] : null,
        '_ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
        '_last_active' => time(),
    ];
}

/**
 * Destroy admin session cleanly.
 * Call on logout.
 */
function destroyAdminSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Get current admin's role.
 */
function adminRole(): string
{
    return $_SESSION['admin']['role'] ?? '';
}

/**
 * Get current admin's ID.
 */
function adminId(): int
{
    return (int)($_SESSION['admin']['id'] ?? 0);
}

/**
 * Check if current admin has a role (without dying).
 * Use for conditional UI elements.
 */
function can(string|array $roles): bool
{
    $role = adminRole();
    if ($role === 'super_admin') return true;
    return in_array($role, (array)$roles, true);
}

// ---- Internal -----------------------------------------------

function _authFail(string $msg, bool $api, string $reason = ''): never
{
    if ($api) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    $redirect = defined('ADMIN_URL') ? ADMIN_URL : '/admin_panel';
    $qs       = $reason ? '?reason=' . urlencode($reason) : '';
    header("Location: {$redirect}/login.php{$qs}");
    exit;
}
