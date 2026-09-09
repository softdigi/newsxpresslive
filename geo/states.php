<?php
// ============================================================
// FIXED: geo/states.php
// ISSUES:
//   1. isset($_GET['country_id']) passes for value "0" or ""
//      (int)"abc" = 0 — invalid but passes through to query.
//      Fixed: validate > 0
//   2. No caching — states for a country rarely change.
//      Added Cache-Control.
// ============================================================
header("Content-Type: application/json");
header("Cache-Control: public, max-age=3600");

require __DIR__ . "/config.php";
require __DIR__ . "/response.php";

$countryId = (int)($_GET['country_id'] ?? 0);

if ($countryId <= 0) {
    jsonResponse(false, [], "valid country_id required");
}

$stmt = $pdo->prepare(
    "SELECT id, name FROM states WHERE country_id = ? ORDER BY name ASC"
);
$stmt->execute([$countryId]);

jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC), "");
