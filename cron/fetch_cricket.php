<?php
/**
 * cron/fetch_cricket.php
 * CLI cron — fetch live cricket match scores via CricAPI.com.
 *
 * Run every 2 minutes: * /2 * * * * php /path/to/cron/fetch_cricket.php
 *
 * Required env vars:
 *   CRICAPI_KEY  — CricAPI.com API key (free: 100 calls/day)
 *   CRICKET_WIDGET_ENABLED — set to 'false' to disable
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../helpers/cache.php';

if (getenv('CRICKET_WIDGET_ENABLED') === 'false') {
    exit(0);
}

$api_key = getenv('CRICAPI_KEY');
if (!$api_key) {
    echo "CRICAPI_KEY not set. Exiting.\n";
    exit(1);
}

$url = "https://api.cricapi.com/v1/currentMatches?apikey={$api_key}&offset=0";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err || $http_code !== 200 || !$response) {
    echo "CricAPI error (HTTP {$http_code}): {$curl_err}\n";
    exit(1);
}

$data = json_decode($response, true);
if (!isset($data['data']) || !is_array($data['data'])) {
    echo "Invalid CricAPI response.\n";
    exit(1);
}

$upsert = $pdo->prepare(
    'INSERT INTO cricket_matches
       (match_id, match_type, series_name, team1_name, team1_short, team1_score,
        team2_name, team2_short, team2_score, status, status_text,
        match_date, venue, toss_winner, match_winner, raw_data)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       team1_score  = VALUES(team1_score),
       team2_score  = VALUES(team2_score),
       status       = VALUES(status),
       status_text  = VALUES(status_text),
       match_winner = VALUES(match_winner),
       raw_data     = VALUES(raw_data)'
);

$updated = 0;
foreach ($data['data'] as $m) {
    if (empty($m['id'])) continue;

    $match_id   = (string)$m['id'];
    $team1      = $m['teams'][0] ?? 'TBA';
    $team2      = $m['teams'][1] ?? 'TBA';
    $score1     = !empty($m['score'][0]) ? ($m['score'][0]['r'] . '/' . $m['score'][0]['w'] . ' (' . $m['score'][0]['o'] . ' ov)') : null;
    $score2     = !empty($m['score'][1]) ? ($m['score'][1]['r'] . '/' . $m['score'][1]['w'] . ' (' . $m['score'][1]['o'] . ' ov)') : null;
    $is_live    = !empty($m['matchStarted']) && empty($m['matchEnded']);
    $is_ended   = !empty($m['matchEnded']);
    $status     = $is_live ? 'live' : ($is_ended ? 'completed' : 'upcoming');
    $match_date = !empty($m['date']) ? date('Y-m-d H:i:s', strtotime($m['date'])) : null;

    // Derive short team names from abbreviation or first word
    $t1_short = mb_substr(strtoupper($team1), 0, 3);
    $t2_short = mb_substr(strtoupper($team2), 0, 3);
    // Use teamInfo if available
    if (!empty($m['teamInfo'])) {
        foreach ($m['teamInfo'] as $ti) {
            if (($ti['name'] ?? '') === $team1) $t1_short = strtoupper($ti['shortname'] ?? $t1_short);
            if (($ti['name'] ?? '') === $team2) $t2_short = strtoupper($ti['shortname'] ?? $t2_short);
        }
    }

    $upsert->execute([
        $match_id,
        $m['matchType']   ?? null,
        $m['series_id']   ?? $m['name'] ?? null,
        $team1,
        $t1_short,
        $score1,
        $team2,
        $t2_short,
        $score2,
        $status,
        $m['status']      ?? null,
        $match_date,
        $m['venue']       ?? null,
        null,
        $is_ended && !empty($m['status']) ? $m['status'] : null,
        json_encode($m, JSON_UNESCAPED_UNICODE),
    ]);
    $updated++;
}

// Invalidate Redis cache so live.php serves fresh data immediately
$cache = ApiCache::getInstance();
$cache->delete('cricket:live_matches');

echo "Cricket: {$updated} matches upserted.\n";
