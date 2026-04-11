<?php
/**
 * helpers/response.php
 *
 * TIER 3 — Code Quality: Response helper standardize
 *
 * Provides a single, consistent JSON response envelope used by all API
 * endpoints.  Before this helper, each file built its own response shape
 * leading to inconsistent field names (success vs ok, data vs items, etc.)
 * and missing HTTP status codes.
 *
 * Standard envelope:
 *   Success:  { "success": true,  "data": {...}, "meta": {...} }
 *   Error:    { "success": false, "message": "...", "code": 422 }
 *
 * Usage:
 *   require_once __DIR__ . '/response.php';
 *
 *   // Send a 200 success response
 *   apiSuccess(['items' => $articles, 'total' => 42]);
 *
 *   // Send a success response with pagination meta
 *   apiSuccess($items, ['page' => 1, 'has_more' => true]);
 *
 *   // Send an error response
 *   apiError('Article not found', 404);
 *
 *   // Paginated list helper
 *   apiList($items, $total, $page, $perPage);
 */

/**
 * Send a standardised success JSON response and exit.
 *
 * @param  mixed  $data     Response payload (array, object, or scalar)
 * @param  array  $meta     Optional metadata (pagination, cursor, etc.)
 * @param  int    $status   HTTP status code (default 200)
 */
function apiSuccess(mixed $data = null, array $meta = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    $body = ['success' => true, 'data' => $data];
    if (!empty($meta)) {
        $body['meta'] = $meta;
    }

    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send a standardised error JSON response and exit.
 *
 * @param  string $message  Human-readable error message
 * @param  int    $status   HTTP status code (default 400)
 * @param  array  $errors   Optional field-level validation errors
 */
function apiError(string $message, int $status = 400, array $errors = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    $body = ['success' => false, 'message' => $message, 'code' => $status];
    if (!empty($errors)) {
        $body['errors'] = $errors;
    }

    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send a paginated list response and exit.
 *
 * @param  array $items    List of items for the current page
 * @param  int   $total    Total number of items across all pages
 * @param  int   $page     Current 1-based page number
 * @param  int   $perPage  Items per page
 */
function apiList(array $items, int $total, int $page, int $perPage): never
{
    $totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;

    apiSuccess($items, [
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'has_more'    => $page < $totalPages,
    ]);
}

/**
 * Convenience: 401 Unauthorized.
 */
function apiUnauthorized(string $message = 'Unauthorized'): never
{
    apiError($message, 401);
}

/**
 * Convenience: 403 Forbidden.
 */
function apiForbidden(string $message = 'Forbidden'): never
{
    apiError($message, 403);
}

/**
 * Convenience: 404 Not Found.
 */
function apiNotFound(string $message = 'Not found'): never
{
    apiError($message, 404);
}

/**
 * Convenience: 422 Unprocessable Entity (validation error).
 */
function apiValidationError(string $message, array $errors = []): never
{
    apiError($message, 422, $errors);
}

/**
 * Convenience: 429 Too Many Requests.
 */
function apiRateLimit(string $message = 'Too many requests', int $retryAfter = 60): never
{
    header('Retry-After: ' . $retryAfter);
    apiError($message, 429);
}

/**
 * Convenience: 500 Internal Server Error.
 * Logs the actual exception but returns a safe generic message to the client.
 */
function apiServerError(string $clientMessage = 'Server error', ?Throwable $ex = null): never
{
    if ($ex !== null) {
        error_log('API 500: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    }
    apiError($clientMessage, 500);
}
