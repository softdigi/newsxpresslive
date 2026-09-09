<?php
// ============================================================
// FIXED: geo/response.php
// BUGS:
//   1. jsonResponse() — no http_response_code() set on error.
//      When status=false, HTTP code stays 200.
//      Client apps get 200 OK with error body — confusing.
//
//   2. sendResponse() — data array_merge() can OVERWRITE
//      the 'success' key:
//        sendResponse(true, ['success' => false, ...])
//        → response['success'] becomes false silently!
//      Fixed: set 'success' AFTER merge.
//
//   3. Both functions call exit — good, kept as-is.
//
//   4. No Content-Type header in response functions —
//      if caller forgets header(), JSON is served as text/html.
//      Added defensive header() in both functions.
// ============================================================

/**
 * Legacy response function — used by geo endpoints & older APIs.
 * @param bool   $status
 * @param mixed  $data
 * @param string $message
 */
function jsonResponse(bool $status, $data = [], string $message = ""): void
{
    // FIXED: set proper HTTP code on error
    if (!$status) {
        http_response_code(400);
    }
    header('Content-Type: application/json'); // defensive
    echo json_encode([
        "status"  => $status,
        "message" => $message,
        "data"    => $data,
    ]);
    exit;
}

/**
 * New-style response — used by viral boost APIs & admin endpoints.
 * @param bool        $success
 * @param array|null  $data
 * @param string      $message
 * @param int         $statusCode
 */
function sendResponse(bool $success, $data = null, string $message = '', int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json'); // defensive

    $response = ['success' => $success];

    if ($message !== '') {
        $response['message'] = $message;
    }

    if ($data !== null) {
        if (is_array($data)) {
            $response = array_merge($response, $data);
            // FIXED: re-set success AFTER merge so data can't overwrite it
            $response['success'] = $success;
        } else {
            $response['data'] = $data;
        }
    }

    echo json_encode($response);
    exit;
}
