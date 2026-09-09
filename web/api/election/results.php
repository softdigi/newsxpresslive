<?php
/**
 * web/api/election/results.php
 * Public API — election party summary + constituency results.
 *
 * GET /web/api/election/results.php?election_id=1[&district_id=5]
 *
 * Response:
 *   { success, election:{name,status,last_updated}, majority_mark,
 *     party_summary:[...], constituency_results:[...] }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../helpers/cors.php';
require_once __DIR__ . '/../../../helpers/security_headers.php';
require_once __DIR__ . '/../../../helpers/cache.php';
require_once __DIR__ . '/../../../web/includes/config.php';

corsHeaders(['GET', 'OPTIONS']);
setSecurityHeaders('api');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$election_id = (int)($_GET['election_id'] ?? 0);
$district_id = (int)($_GET['district_id'] ?? 0);

if ($election_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'election_id required']);
    exit;
}

$cache_key = "election:results:{$election_id}:district:{$district_id}";
$cache = ApiCache::getInstance();

$result = $cache->remember($cache_key, 60, function () use ($pdo, $election_id, $district_id): array {
    // ── Election details ──────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, name, name_hi, election_type, status, election_date, result_date,
                (SELECT MAX(updated_at) FROM election_results WHERE election_id = e.id) AS last_updated
         FROM elections e
         WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$election_id]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$election) {
        return ['found' => false];
    }

    // ── Party summary ─────────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT party_name, party_short, party_color, seats_won, seats_leading,
                total_votes, vote_share
         FROM election_party_summary
         WHERE election_id = ?
         ORDER BY seats_won DESC, seats_leading DESC'
    );
    $stmt->execute([$election_id]);
    $party_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Constituency results ──────────────────────────────────────────────
    $sql = 'SELECT constituency_name, constituency_no, winning_candidate,
                   winning_party, winning_party_short, winning_votes,
                   winning_margin, runner_candidate, runner_party,
                   runner_votes, total_votes, voter_turnout, result_status
            FROM election_results
            WHERE election_id = ?';
    $params = [$election_id];

    if ($district_id > 0) {
        $sql .= ' AND district_id = ?';
        $params[] = $district_id;
    }
    $sql .= ' ORDER BY constituency_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $constituencies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total seats for majority mark
    $total_seats = (int)$pdo->query(
        "SELECT COUNT(*) FROM election_results WHERE election_id = {$election_id}"
    )->fetchColumn();
    $majority_mark = (int)ceil($total_seats / 2) + 1;

    return [
        'found'                => true,
        'election'             => [
            'name'         => $election['name'],
            'name_hi'      => $election['name_hi'],
            'election_type'=> $election['election_type'],
            'status'       => $election['status'],
            'election_date'=> $election['election_date'],
            'result_date'  => $election['result_date'],
            'last_updated' => $election['last_updated'],
        ],
        'majority_mark'        => $majority_mark,
        'party_summary'        => $party_summary,
        'constituency_results' => $constituencies,
    ];
});

if (empty($result['found'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Election not found']);
    exit;
}

echo json_encode(array_merge(['success' => true], $result));
