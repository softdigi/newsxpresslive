<?php
/**
 * web/api/referral_leaderboard.php
 *
 * GET ?limit=20
 * Returns top referrers ranked by referral_points.
 * Public endpoint — no auth required.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/referral.php';

$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

$board = getLeaderboard($pdo, $limit);

// Mask partial email; only expose first name initial + last name
$out = [];
foreach ($board as $rank => $row) {
    $nameParts = explode(' ', $row['name']);
    $display   = $nameParts[0];                // first name only
    if (count($nameParts) > 1) {
        $display .= ' ' . mb_substr(end($nameParts), 0, 1) . '.';
    }
    $out[] = [
        'rank'             => $rank + 1,
        'display_name'     => $display,
        'referral_points'  => (int)$row['referral_points'],
        'direct_referrals' => (int)$row['direct_referrals'],
    ];
}

echo json_encode(['leaderboard' => $out]);
