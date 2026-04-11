<?php
/**
 * helpers/csrf.php
 *
 * FIX 7: CSRF protection for public endpoints.
 *
 * Browser-submitted forms (comment submission, contact forms, vote endpoints)
 * on the public web pages were unprotected against Cross-Site Request
 * Forgery.  Any malicious page could silently POST to these endpoints
 * while the victim's browser sent their session cookie.
 *
 * APPROACH
 * --------
 * Synchroniser-token pattern:
 *   1. On each page load call csrfToken() to generate (or reuse) a token
 *      stored in the session.
 *   2. Embed the token in every HTML form as a hidden field named _csrf.
 *   3. On POST call verifyCsrf() before processing any data.
 *
 * API-only endpoints consumed exclusively by the Flutter app
 * (which uses the Authorization: Bearer id_token header) are exempt —
 * they are protected by Firebase JWT verification, which already prevents
 * CSRF because browsers cannot attach a Bearer header to cross-origin
 * requests without a CORS pre-flight.
 *
 * Usage in a form handler (web page, not API):
 *   require_once __DIR__ . '/../helpers/csrf.php';
 *   // In the GET handler — render the form:
 *   $token = csrfToken();
 *   // In the POST handler — verify before processing:
 *   verifyCsrf();
 *
 * Usage in a form template:
 *   <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken()) ?>">
 */

/**
 * Generate (or return existing) CSRF token for the current session.
 */
function csrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from POST body or X-CSRF-Token header.
 * Exits with 403 JSON if token is absent or invalid.
 *
 * @param string $field  Name of the POST field holding the token (default: _csrf)
 */
function verifyCsrf(string $field = '_csrf'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $expected = $_SESSION['csrf_token'] ?? '';

    // Accept from POST body or X-CSRF-Token header (for AJAX)
    $provided = $_POST[$field]
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token.']);
        exit;
    }

    // Rotate token after successful verification
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Render a hidden CSRF input field for HTML forms.
 * Echoes the field directly.
 */
function csrfField(string $field = '_csrf'): void
{
    echo '<input type="hidden" name="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8')
       . '" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}
