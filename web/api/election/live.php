<?php
/**
 * web/api/election/live.php
 * Server-Sent Events — real-time election result updates.
 *
 * GET /web/api/election/live.php?election_id=1
 *
 * Streams party_summary every 30 seconds until client disconnects.
 * No auth required (public).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../web/includes/config.php';

$election_id = (int)($_GET['election_id'] ?? 0);

if ($election_id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'election_id required']);
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
// Allow browser connections from configured origins
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$raw = getenv('ALLOWED_ORIGINS') ?: (defined('SITE_URL') ? SITE_URL : '');
$allowed = array_filter(array_map('trim', explode(',', $raw)));
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

set_time_limit(300);

$stmt = $pdo->prepare(
    'SELECT party_short, party_name, party_color, seats_won, seats_leading, vote_share
     FROM election_party_summary
     WHERE election_id = ?
     ORDER BY seats_won DESC, seats_leading DESC'
);

while (true) {
    $stmt->execute([$election_id]);
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [
        'election_id'   => $election_id,
        'party_summary' => $summary,
        'timestamp'     => date('c'),
    ];

    echo "data: " . json_encode($data) . "\n\n";

    if (ob_get_level()) {
        ob_flush();
    }
    flush();

    if (connection_aborted()) {
        break;
    }

    sleep(30);
}
