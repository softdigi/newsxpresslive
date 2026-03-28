<?php
// ============================================================
// FIXED: geo/districts.php
// ISSUES:
//   1. isset($_GET['state_id']) passes for "0"/"abc"
//      Fixed: validate > 0
//   2. No caching
// ============================================================
header("Content-Type: application/json");
header("Cache-Control: public, max-age=3600");

require __DIR__ . "/config.php";
require __DIR__ . "/response.php";

$stateId = (int)($_GET['state_id'] ?? 0);

if ($stateId <= 0) {
    jsonResponse(false, [], "valid state_id required");
}

$stmt = $pdo->prepare(
    "SELECT id, name FROM districts WHERE state_id = ? ORDER BY name ASC"
);
$stmt->execute([$stateId]);

jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC), "");
